<?php

namespace Tests\Feature;

use App\Domain\Devices\GatewayHealthService;
use App\Domain\Orders\OrderService;
use App\Domain\Payments\PaymentMatchingService;
use App\Models\ApiCredential;
use App\Models\CircuitBreaker;
use App\Models\Claim;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GatewayFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_api_is_idempotent_and_rejects_when_gateway_has_no_online_device(): void
    {
        $merchant = $this->merchant();
        $token = $this->apiToken($merchant, 'create', ['orders:write']);
        $payload = [
            'merchant_order_id' => 'checkout-1001',
            'base_amount_minor' => 49900,
            'currency' => 'INR',
        ];

        $this->postJson('/api/v1/orders', $payload, $this->signedHeaders($token, $payload))
            ->assertStatus(503)
            ->assertJsonPath('error', 'GATEWAY_UNAVAILABLE');

        $this->onlineDevice($merchant);

        $first = $this->postJson('/api/v1/orders', $payload, $this->signedHeaders($token, $payload))
            ->assertCreated()
            ->assertJsonPath('payable_amount_minor', 49901);

        $this->postJson('/api/v1/orders', $payload, $this->signedHeaders($token, $payload))
            ->assertCreated()
            ->assertJson($first->json());
    }

    public function test_only_matching_sms_and_notification_signals_confirm_an_order(): void
    {
        $merchant = $this->merchant();
        $device = $this->onlineDevice($merchant);
        $this->assertSame('online', $device->fresh()->status);
        $this->assertTrue($device->fresh()->last_heartbeat_at->gt(now()->subSeconds(90)));
        $this->assertTrue($merchant->fresh()->devices()->whereNull('revoked_at')->where('status', 'online')->exists());
        $this->assertSame('active', $merchant->fresh()->status);
        $this->assertSame('closed', $merchant->fresh()->circuitBreaker->state);
        $this->assertTrue(app(GatewayHealthService::class)->canAcceptOrders($merchant));
        $order = app(OrderService::class)->create($merchant, [
            'merchant_order_id' => 'checkout-1002',
            'base_amount_minor' => 49900,
            'currency' => 'INR',
        ], 'flow-key')['id'];
        $order = Order::where('public_id', $order)->firstOrFail();
        $occurredAt = $order->created_at->copy()->addSeconds(5);

        $first = app(PaymentMatchingService::class)->ingest($device, [
            'client_signal_id' => 'sms-1',
            'source' => 'sms',
            'amount_minor' => $order->payable_amount_minor,
            'reference_number' => '123456789012',
            'occurred_at' => $occurredAt->toIso8601String(),
        ]);

        $this->assertSame('detected', $first['status']);
        $this->assertSame('detected', $order->fresh()->status);

        $second = app(PaymentMatchingService::class)->ingest($device, [
            'client_signal_id' => 'notification-1',
            'source' => 'notification',
            'amount_minor' => $order->payable_amount_minor,
            'reference_number' => '123456789012',
            'occurred_at' => $occurredAt->copy()->addSeconds(1)->toIso8601String(),
        ]);

        $this->assertSame('paid', $second['status']);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(['order.created', 'order.detected', 'order.paid'], $order->fresh()->events()->orderBy('sequence_number')->pluck('event_type')->all());
    }

    public function test_device_requests_require_a_valid_signature_and_single_use_nonce(): void
    {
        $merchant = $this->merchant();
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $device = Device::create([
            'merchant_id' => $merchant->id,
            'slot_number' => 1,
            'public_key' => base64_encode($publicKey),
            'attestation_status' => 'verified',
        ]);
        $payload = [
            'observed_at' => now()->toIso8601String(),
            'sms_listener_healthy' => true,
            'notification_listener_healthy' => true,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = 'nonce-1';
        $bodyHash = hash('sha256', $body);
        $canonical = "POST\n/api/device/v1/heartbeat\n{$timestamp}\n{$nonce}\n{$bodyHash}";
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, $secretKey));
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEVICE_ID' => $device->public_id,
            'HTTP_X_DEVICE_TIMESTAMP' => $timestamp,
            'HTTP_X_DEVICE_NONCE' => $nonce,
            'HTTP_X_DEVICE_BODY_SHA256' => $bodyHash,
            'HTTP_X_DEVICE_SIGNATURE' => $signature,
        ];

        $this->call('POST', '/api/device/v1/heartbeat', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/device/v1/heartbeat', [], [], [], $headers, $body)->assertUnauthorized();
    }

    public function test_unverified_device_cannot_submit_payment_signals(): void
    {
        $merchant = $this->merchant();
        $device = Device::create([
            'merchant_id' => $merchant->id,
            'slot_number' => 1,
            'public_key' => 'pending-key-'.uniqid(),
            'attestation_status' => 'pending_review',
        ]);

        $result = app(PaymentMatchingService::class)->ingest($device, [
            'client_signal_id' => 'pending-signal',
            'source' => 'sms',
            'amount_minor' => 49901,
            'reference_number' => '123456789012',
            'occurred_at' => now()->toIso8601String(),
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('device_attestation_required', $result['reason']);
    }

    public function test_claims_do_not_confirm_an_order_with_only_one_bank_source(): void
    {
        $merchant = $this->merchant();
        $device = $this->onlineDevice($merchant);
        $order = Order::where('public_id', app(OrderService::class)->create($merchant, [
            'merchant_order_id' => 'claim-one-source',
            'base_amount_minor' => 49900,
            'currency' => 'INR',
        ], 'claim-key')['id'])->firstOrFail();
        $occurredAt = $order->created_at->copy()->addSecond();

        app(PaymentMatchingService::class)->ingest($device, [
            'client_signal_id' => 'claim-sms',
            'source' => 'sms',
            'amount_minor' => $order->payable_amount_minor,
            'reference_number' => '123456789012',
            'occurred_at' => $occurredAt->toIso8601String(),
        ]);

        $result = app(PaymentMatchingService::class)->resolveClaim($order, '123456789012');

        $this->assertSame('open', $result['status']);
        $this->assertSame('detected', $order->fresh()->status);
        $this->assertSame(1, Claim::query()->count());
    }

    public function test_a_reference_cannot_cross_merchant_boundaries(): void
    {
        $firstMerchant = $this->merchant('first');
        $firstDevice = $this->onlineDevice($firstMerchant);
        $firstOrder = Order::where('public_id', app(OrderService::class)->create($firstMerchant, [
            'merchant_order_id' => 'first-order',
            'base_amount_minor' => 49900,
            'currency' => 'INR',
        ], 'first-key')['id'])->firstOrFail();
        $occurredAt = $firstOrder->created_at->copy()->addSecond();

        app(PaymentMatchingService::class)->ingest($firstDevice, [
            'client_signal_id' => 'first-sms',
            'source' => 'sms',
            'amount_minor' => $firstOrder->payable_amount_minor,
            'reference_number' => '123456789012',
            'occurred_at' => $occurredAt->toIso8601String(),
        ]);

        $secondMerchant = $this->merchant('second');
        $secondDevice = $this->onlineDevice($secondMerchant);
        $secondOrder = Order::where('public_id', app(OrderService::class)->create($secondMerchant, [
            'merchant_order_id' => 'second-order',
            'base_amount_minor' => 49900,
            'currency' => 'INR',
        ], 'second-key')['id'])->firstOrFail();

        $result = app(PaymentMatchingService::class)->ingest($secondDevice, [
            'client_signal_id' => 'second-sms',
            'source' => 'sms',
            'amount_minor' => $secondOrder->payable_amount_minor,
            'reference_number' => '123456789012',
            'occurred_at' => $secondOrder->created_at->copy()->addSecond()->toIso8601String(),
        ]);

        $this->assertSame('reference_collision', $result['reason']);
        $this->assertSame('pending', $secondOrder->fresh()->status);
    }

    public function test_checkout_renders_a_qr_and_hides_payment_actions_after_expiry(): void
    {
        $this->freezeTime();
        $merchant = $this->merchant();
        $this->onlineDevice($merchant);
        $result = app(OrderService::class)->create($merchant, ['merchant_order_id' => 'qr-order', 'base_amount_minor' => 10000, 'currency' => 'INR'], 'qr-key');
        $this->get($result['checkout_url'])->assertOk()->assertSee('<svg', false)->assertSee('100.01');
        $this->travel(11)->minutes();
        $this->get($result['checkout_url'])->assertOk()->assertDontSee('<svg', false)->assertSee('This order is no longer accepting payments');
    }

    public function test_delayed_signal_cannot_revert_a_paid_order(): void
    {
        $this->freezeTime();
        $merchant = $this->merchant();
        $device = $this->onlineDevice($merchant);
        $result = app(OrderService::class)->create($merchant, ['merchant_order_id' => 'immutable-order', 'base_amount_minor' => 10000, 'currency' => 'INR'], 'immutable-key');
        $order = Order::where('public_id', $result['id'])->firstOrFail();
        $matching = app(PaymentMatchingService::class);
        $signal = ['amount_minor' => $order->payable_amount_minor, 'reference_number' => '123456789012', 'occurred_at' => now()->addSecond()->toIso8601String()];
        $matching->ingest($device, $signal + ['source' => 'sms', 'client_signal_id' => 'paid-sms']);
        $matching->ingest($device, $signal + ['source' => 'notification', 'client_signal_id' => 'paid-notification']);
        $this->travel(11)->minutes();
        $result = $matching->ingest($device, array_replace($signal, ['source' => 'sms', 'client_signal_id' => 'late-repeat', 'occurred_at' => now()->toIso8601String()]));
        $this->assertSame('duplicate', $result['status']);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->payment->status);
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'event_type' => 'payment.late']);
    }

    public function test_auto_confirmation_over_the_order_cap_goes_to_review(): void
    {
        $this->freezeTime();
        $merchant = $this->merchant();
        $merchant->settings->update(['auto_confirm_order_cap_minor' => 10000]);
        $device = $this->onlineDevice($merchant);
        $result = app(OrderService::class)->create($merchant, ['merchant_order_id' => 'cap-order', 'base_amount_minor' => 10000, 'currency' => 'INR'], 'cap-key');
        $order = Order::where('public_id', $result['id'])->firstOrFail();
        $matching = app(PaymentMatchingService::class);
        $signal = ['amount_minor' => 10001, 'reference_number' => '123456789012', 'occurred_at' => now()->addSecond()->toIso8601String()];
        $matching->ingest($device, $signal + ['source' => 'sms', 'client_signal_id' => 'cap-sms']);
        $result = $matching->ingest($device, $signal + ['source' => 'notification', 'client_signal_id' => 'cap-notification']);
        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertDatabaseHas('disputes', ['order_id' => $order->id, 'status' => 'open']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'order.needs_review']);
    }

    public function test_daily_cap_counts_previously_confirmed_orders(): void
    {
        $this->freezeTime();
        $merchant = $this->merchant();
        $merchant->settings->update(['auto_confirm_day_cap_minor' => 15000]);
        $device = $this->onlineDevice($merchant);
        $matching = app(PaymentMatchingService::class);
        foreach ([1, 2] as $index) {
            $created = app(OrderService::class)->create($merchant, ['merchant_order_id' => 'daily-'.$index, 'base_amount_minor' => 10000, 'currency' => 'INR'], 'daily-'.$index);
            $order = Order::where('public_id', $created['id'])->firstOrFail();
            $signal = ['amount_minor' => $order->payable_amount_minor, 'reference_number' => '12345678901'.$index, 'occurred_at' => now()->addSecond()->toIso8601String()];
            $matching->ingest($device, $signal + ['source' => 'sms', 'client_signal_id' => 'daily-sms-'.$index]);
            $matching->ingest($device, $signal + ['source' => 'notification', 'client_signal_id' => 'daily-notification-'.$index]);
        }
        $this->assertDatabaseHas('orders', ['merchant_order_id' => 'daily-1', 'status' => 'paid']);
        $this->assertDatabaseHas('orders', ['merchant_order_id' => 'daily-2', 'status' => 'needs_review']);
    }

    public function test_order_detail_is_visible_only_to_its_merchant(): void
    {
        $merchant = $this->merchant();
        $this->onlineDevice($merchant);
        $created = app(OrderService::class)->create($merchant, ['merchant_order_id' => 'detail-order', 'base_amount_minor' => 10000, 'currency' => 'INR'], 'detail-key');
        $order = Order::where('public_id', $created['id'])->firstOrFail();
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $other = User::factory()->create(['merchant_id' => $this->merchant('other')->id, 'role' => 'owner']);
        $this->actingAs($owner)->get(route('panel.payments.show', $order))->assertOk()->assertSee('Order timeline');
        $this->actingAs($other)->get(route('panel.payments.show', $order))->assertNotFound();
    }

    public function test_adjacent_base_amounts_never_share_a_payable_amount(): void
    {
        $merchant = $this->merchant();
        $this->onlineDevice($merchant);
        $service = app(OrderService::class);
        $amounts = [];
        foreach ([10000, 10000, 10001] as $index => $base) {
            $result = $service->create($merchant, ['merchant_order_id' => 'overlap-'.$index, 'base_amount_minor' => $base, 'currency' => 'INR'], 'overlap-'.$index);
            $amounts[] = $result['payable_amount_minor'];
        }
        $this->assertSame([10001, 10002, 10003], $amounts);
    }

    private function merchant(string $suffix = 'test'): Merchant
    {
        $merchant = Merchant::create([
            'name' => 'Test Merchant',
            'slug' => $suffix.'-merchant-'.uniqid(),
            'upi_vpa' => 'test@kotak',
            'return_url_allowlist' => ['merchant.test'],
        ]);
        MerchantSetting::create(['merchant_id' => $merchant->id]);
        CircuitBreaker::create(['merchant_id' => $merchant->id, 'state' => 'closed']);

        return $merchant;
    }

    private function onlineDevice(Merchant $merchant): Device
    {
        return Device::create([
            'merchant_id' => $merchant->id,
            'slot_number' => 1,
            'public_key' => 'test-key-'.uniqid(),
            'attestation_status' => 'verified',
            'status' => 'online',
            'last_heartbeat_at' => now(),
            'sms_listener_healthy' => true,
            'notification_listener_healthy' => true,
            'consecutive_good_heartbeats' => 2,
        ]);
    }

    private function signedHeaders(string $token, array $payload): array
    {
        $timestamp = (string) now()->timestamp;
        $nonce = bin2hex(random_bytes(16));
        $secret = explode('_', $token, 4)[3];
        $canonical = implode("\n", ['POST', '/api/v1/orders', $timestamp, $nonce, hash('sha256', json_encode($payload))]);

        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'same-request', 'X-UPI-Timestamp' => $timestamp, 'X-UPI-Nonce' => $nonce, 'X-UPI-Signature' => hash_hmac('sha256', $canonical, $secret)];
    }

    private function apiToken(Merchant $merchant, string $type, array $scopes): string
    {
        $prefix = 'test'.strtolower(substr(md5((string) mt_rand()), 0, 8));
        $secret = 'secret-'.strtolower(substr(md5((string) mt_rand()), 0, 16));
        ApiCredential::create([
            'merchant_id' => $merchant->id,
            'name' => 'test',
            'type' => $type,
            'key_prefix' => $prefix,
            'secret_hash' => Hash::make($secret),
            'signing_secret_encrypted' => $secret,
            'scopes' => $scopes,
        ]);

        return "upi_live_{$prefix}_{$secret}";
    }
}
