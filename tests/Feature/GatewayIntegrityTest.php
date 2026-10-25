<?php

namespace Tests\Feature;

use App\Domain\Devices\GatewayHealthService;
use App\Domain\Orders\ApiCredentialService;
use App\Domain\Orders\OrderEventService;
use App\Domain\Orders\OrderService;
use App\Domain\Webhooks\WebhookService;
use App\Jobs\DeliverOrderEventWebhook;
use App\Mail\OperationalAlertMail;
use App\Models\AuditLog;
use App\Models\CircuitBreaker;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use HxDev\UpiMerchant\RequestSigner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

require_once __DIR__.'/../../packages/hx-upi-merchant-php/src/RequestSigner.php';

class GatewayIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_order_cannot_be_downgraded_with_a_direct_database_query(): void
    {
        $order = $this->order($this->merchant());
        $this->expectException(QueryException::class);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'expired']);
    }

    public function test_offline_transition_is_audited_and_not_repeated_for_unchanged_state(): void
    {
        $merchant = $this->merchant();
        $device = Device::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'public_key' => 'fixture', 'attestation_status' => 'verified', 'status' => 'online', 'last_heartbeat_at' => now()->subMinutes(5)]);
        app(GatewayHealthService::class)->refreshDeviceStates($merchant);
        app(GatewayHealthService::class)->refreshDeviceStates($merchant);
        $this->assertSame('offline', $device->fresh()->status);
        $audit = AuditLog::where('action', 'state.changed')->where('target_type', Device::class)->sole();
        $this->assertSame('online', $audit->metadata['before']['status']);
        $this->assertSame('offline', $audit->metadata['after']['status']);
    }

    public function test_anomaly_alerts_are_deduplicated_and_queued_to_owner(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 20:00:00', 'UTC'));
        config(['gateway.anomalies.payment_count' => 2, 'gateway.anomalies.tiny_count' => 2]);
        Mail::fake();
        $merchant = $this->merchant();
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $this->order($merchant, 'a', 101);
        $this->order($merchant, 'b', 101);
        $this->artisan('gateway:maintain')->assertSuccessful();
        $this->artisan('gateway:maintain')->assertSuccessful();
        $this->assertDatabaseCount('operational_alerts', 3);
        foreach (['payment.velocity', 'payment.tiny_burst', 'payment.quiet_hours'] as $type) {
            $this->assertDatabaseHas('operational_alerts', ['merchant_id' => $merchant->id, 'type' => $type]);
        }
        Mail::assertQueued(OperationalAlertMail::class, 3);
        Mail::assertQueued(OperationalAlertMail::class, fn ($mail) => $mail->hasTo($owner->email));
        $this->assertSame(2, $merchant->orders()->where('status', 'paid')->count());
    }

    public function test_delayed_webhook_can_pull_its_sequence_after_a_separate_refund_event(): void
    {
        Queue::fake([DeliverOrderEventWebhook::class]);
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        Payment::create(['merchant_id' => $merchant->id, 'order_id' => $order->id, 'reference_number_normalized' => '123456789012', 'reference_number_hash' => hash('sha256', '123456789012'), 'amount_minor' => 10001, 'currency' => 'INR', 'status' => 'confirmed', 'first_seen_at' => now()]);
        $paid = app(OrderEventService::class)->append($order, 'order.paid');
        app(OrderEventService::class)->append($order, 'refund.recorded');
        [, $token] = app(ApiCredentialService::class)->issue($merchant, 'Read', 'read', ['orders:read']);
        $path = '/api/v1/orders/'.$order->public_id.'?event_id='.$paid->id;
        $this->getJson($path, RequestSigner::headers($token, 'GET', $path, '[]'))->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('event_sequence', 1);
        $other = $this->order($merchant, 'other');
        $wrong = app(OrderEventService::class)->append($other, 'order.paid');
        $path = '/api/v1/orders/'.$order->public_id.'?event_id='.$wrong->id;
        $this->getJson($path, RequestSigner::headers($token, 'GET', $path, '[]'))->assertNotFound();
    }

    public function test_idempotent_create_retry_returns_existing_order_after_device_goes_offline(): void
    {
        $merchant = $this->merchant();
        $device = Device::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'public_key' => 'fixture', 'attestation_status' => 'verified', 'status' => 'online', 'last_heartbeat_at' => now(), 'consecutive_good_heartbeats' => 2, 'sms_listener_healthy' => true, 'notification_listener_healthy' => true]);
        $payload = ['merchant_order_id' => 'retry-order', 'base_amount_minor' => 10000, 'currency' => 'INR'];
        $first = app(OrderService::class)->create($merchant, $payload, 'same-request');
        $device->update(['last_heartbeat_at' => now()->subMinutes(5)]);
        $this->assertSame($first, app(OrderService::class)->create($merchant, $payload, 'same-request'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_device_fault_reports_are_signed_private_and_idempotent(): void
    {
        $this->freezeTime();
        $merchant = $this->merchant();
        $pair = sodium_crypto_sign_keypair();
        $device = Device::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'attestation_status' => 'verified']);
        $send = function (array $payload) use ($pair, $device) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $timestamp = (string) now()->timestamp;
            $nonce = bin2hex(random_bytes(16));
            $hash = hash('sha256', $body);
            $canonical = "POST\n/api/device/v1/faults\n{$timestamp}\n{$nonce}\n{$hash}";

            return $this->call('POST', '/api/device/v1/faults', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_DEVICE_ID' => $device->public_id,
                'HTTP_X_DEVICE_TIMESTAMP' => $timestamp, 'HTTP_X_DEVICE_NONCE' => $nonce,
                'HTTP_X_DEVICE_BODY_SHA256' => $hash,
                'HTTP_X_DEVICE_SIGNATURE' => base64_encode(sodium_crypto_sign_detached($canonical, sodium_crypto_sign_secretkey($pair))),
            ], $body);
        };
        $payload = ['client_fault_id' => '019a1460-f8c0-7000-8000-000000000001', 'type' => 'parser_failure', 'source' => 'sms', 'parser_rule_version' => 1, 'occurred_at' => now()->toIso8601String()];
        $this->postJson('/api/device/v1/faults', $payload)->assertUnauthorized();
        $send($payload)->assertCreated()->assertJsonPath('duplicate', false);
        $send($payload)->assertOk()->assertJsonPath('duplicate', true);
        $send(array_merge($payload, ['type' => 'sync_error']))->assertStatus(409);
        $send($payload + ['raw_sms' => 'Private message must not be accepted'])->assertStatus(422);
        $this->assertDatabaseCount('device_fault_reports', 1);
        $this->assertDatabaseCount('fault_events', 1);
        $device->update(['revoked_at' => now()]);
        $send($payload)->assertUnauthorized();
    }

    public function test_only_the_allocated_probe_can_close_a_half_open_breaker(): void
    {
        $merchant = $this->merchant();
        $probe = $this->order($merchant, 'probe');
        $earlier = $this->order($merchant, 'earlier');
        $breaker = CircuitBreaker::create(['merchant_id' => $merchant->id, 'state' => 'half_open', 'probe_order_id' => $probe->id]);
        app(GatewayHealthService::class)->recordHealthyConfirmation($merchant, $earlier->id);
        $this->assertSame('half_open', $breaker->fresh()->state);
        app(GatewayHealthService::class)->recordHealthyConfirmation($merchant, $probe->id);
        $this->assertSame('closed', $breaker->fresh()->state);
    }

    public function test_a_single_system_fault_reopens_a_half_open_breaker(): void
    {
        $merchant = $this->merchant();
        $merchant->settings()->create(['breaker_consecutive_threshold' => 100, 'breaker_rolling_threshold' => 100]);
        $breaker = CircuitBreaker::create(['merchant_id' => $merchant->id, 'state' => 'half_open', 'trip_count_24h' => 1, 'trip_window_started_at' => now()]);
        app(GatewayHealthService::class)->recordFault($merchant, 'sync_error');
        $this->assertSame('open', $breaker->fresh()->state);
        $this->assertSame(2, $breaker->fresh()->trip_count_24h);
    }

    public function test_webhooks_reject_mapped_internal_and_shared_network_addresses(): void
    {
        $service = app(WebhookService::class);
        foreach (['https://[::ffff:127.0.0.1]/hook', 'https://100.64.0.1/hook', 'https://[64:ff9b::7f00:1]/hook', 'https://[2002:7f00:1::]/hook'] as $url) {
            try {
                $service->assertSafeUrl($url);
                $this->fail('Unsafe webhook accepted: '.$url);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('private or reserved', $exception->getMessage());
            }
        }
        $this->assertSame('8.8.8.8', $service->assertSafeUrl('https://8.8.8.8/hook'));
    }

    public function test_production_requires_https_correct_surface_and_admin_network(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['gateway.checkout_domain' => 'https://checkout.example', 'gateway.api_domain' => 'https://api.example', 'gateway.admin_domain' => 'https://console.example', 'gateway.security.admin_ips' => ['203.0.113.10']]);
        $this->order($this->merchant());
        $this->get('http://checkout.example/checkout/a')->assertForbidden();
        $this->get('https://api.example/checkout/a')->assertForbidden();
        $response = $this->get('https://checkout.example/checkout/a')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->get('https://console.example/admin')->assertForbidden();
    }

    private function merchant(): Merchant
    {
        return Merchant::create(['name' => 'Integrity merchant', 'slug' => 'integrity', 'upi_vpa' => 'test@kotak']);
    }

    private function order(Merchant $merchant, string $id = 'a', int $amount = 10001): Order
    {
        return Order::create(['merchant_id' => $merchant->id, 'merchant_order_id' => $id, 'requested_amount_minor' => $amount - 1, 'payable_amount_minor' => $amount, 'slot_paise' => 1, 'currency' => 'INR', 'status' => 'paid', 'confirmation_method' => 'automatic', 'paid_at' => now(), 'checkout_token_hash' => hash('sha256', $id), 'expires_at' => now()->addMinutes(10)]);
    }
}
