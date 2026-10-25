<?php

namespace Tests\Feature;

use App\Domain\Payments\PaymentMatchingService;
use App\Jobs\DeliverOrderEventWebhook;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LivePaymentTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_generate_an_actual_one_rupee_transfer_qr_without_a_capture_device(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $merchant = $this->merchant();
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $merchant->id]);

        $response = $this->actingAs($owner)->post(route('panel.preview.transfer'), ['live_amount' => '1.00']);

        $response->assertOk()->assertSee('Scan and pay ₹1.00')->assertSee('Automatic confirmation is not available')
            ->assertViewHas('amountMinor', 100)->assertSee('<svg', false);
        parse_str(parse_url($response->viewData('upiUri'), PHP_URL_QUERY), $params);
        $this->assertSame('upi', parse_url($response->viewData('upiUri'), PHP_URL_SCHEME));
        $this->assertSame('pay', parse_url($response->viewData('upiUri'), PHP_URL_HOST));
        $this->assertSame('preview@kotak', $params['pa']);
        $this->assertSame('1.00', $params['am']);
        $this->assertSame('INR', $params['cu']);
        $this->assertSame($merchant->name, $params['pn']);
        foreach (['orders', 'payments', 'order_events', 'webhook_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
    }

    public function test_transfer_qr_uses_the_signed_in_merchants_recipient_and_escapes_its_name(): void
    {
        $merchant = $this->merchant();
        $merchant->update(['name' => '<script>alert(1)</script> & Store']);
        $other = Merchant::create(['name' => 'Other Store', 'slug' => 'other', 'upi_vpa' => 'other@kotak']);
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $merchant->id]);

        $this->actingAs($owner)->post(route('panel.preview.transfer'), [
            'live_amount' => '1.25', 'merchant_id' => $other->id, 'upi_vpa' => 'attacker@upi', 'status' => 'paid',
        ])->assertOk()->assertViewHas('amountMinor', 125)
            ->assertSee($merchant->name)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('attacker@upi')->assertDontSee('other@kotak');
        $this->post(route('admin.merchants.preview.transfer', $other), ['live_amount' => '1'])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_guests_staff_disabled_users_and_suspended_merchants_cannot_generate_real_qrs(): void
    {
        $this->post(route('panel.preview.transfer'), ['live_amount' => '1'])->assertRedirect(route('login'));
        $merchant = $this->merchant();
        $staff = User::factory()->create(['role' => 'staff', 'merchant_id' => $merchant->id]);
        $this->actingAs($staff)->post(route('panel.preview.transfer'), ['live_amount' => '1'])->assertForbidden();
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $merchant->id, 'disabled_at' => now()]);
        $this->actingAs($owner)->post(route('panel.preview.transfer'), ['live_amount' => '1'])->assertForbidden();
        $owner->forceFill(['disabled_at' => null])->save();
        $merchant->update(['status' => 'suspended']);
        $this->post(route('panel.preview.transfer'), ['live_amount' => '1'])->assertForbidden();
    }

    public function test_admin_can_generate_an_actual_qr_only_after_mfa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $merchant = $this->merchant();
        $url = route('admin.merchants.preview.transfer', $merchant);

        $this->actingAs($admin)->post($url, ['live_amount' => '1'])->assertRedirect(route('security.totp'));
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])
            ->post($url, ['live_amount' => '1'])->assertOk()->assertSee('₹1.00');
    }

    #[DataProvider('invalidAmounts')]
    public function test_real_test_amounts_are_limited_to_one_through_ten_rupees(string $amount): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $this->merchant()->id]);

        $this->actingAs($owner)->post(route('panel.preview.transfer'), ['live_amount' => $amount])
            ->assertSessionHasErrors('live_amount');
        $this->assertDatabaseCount('orders', 0);
    }

    public static function invalidAmounts(): array
    {
        return ['below minimum' => ['0.99'], 'above maximum' => ['10.01'], 'negative' => ['-1'], 'fractional paise' => ['1.001'], 'scientific' => ['1e0'], 'missing' => ['']];
    }

    public function test_tracked_checkout_still_requires_capture_and_does_not_fake_confirmation(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $this->merchant()->id]);

        $this->actingAs($owner)->post(route('panel.preview.live'), $this->payload())
            ->assertSessionHasErrors(['live_amount' => 'Live QR unavailable: a verified capture device with healthy SMS and notification listeners must be online, and the gateway must be accepting payments.']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        Queue::assertNothingPushed();
    }

    public function test_tracked_test_is_idempotent_and_confirms_only_from_matching_bank_evidence(): void
    {
        $this->freezeTime();
        Queue::fake([DeliverOrderEventWebhook::class]);
        Http::preventStrayRequests();
        $merchant = $this->merchant();
        $device = $this->device($merchant);
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $merchant->id]);
        $payload = $this->payload();

        $first = $this->actingAs($owner)->post(route('panel.preview.live'), $payload + ['status' => 'paid', 'return_url' => 'https://attacker.example']);
        $first->assertRedirect();
        $checkoutUrl = $first->headers->get('Location');
        $this->post(route('panel.preview.live'), $payload)->assertRedirect($checkoutUrl);
        $this->assertDatabaseCount('orders', 1);
        $order = Order::firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertSame(101, $order->payable_amount_minor);
        $this->assertSame('live_test', $order->metadata['purpose']);
        $this->assertNull($order->return_url);
        $token = basename(parse_url($checkoutUrl, PHP_URL_PATH));
        $this->get(route('checkout.show', $token))->assertOk()->assertSee('Real payment test.')->assertSee('<svg', false);
        $this->getJson(route('checkout.status', $token))->assertJsonPath('state', 'waiting');
        $this->assertDatabaseCount('payments', 0);
        $signal = ['client_signal_id' => 'test-sms', 'source' => 'sms', 'amount_minor' => 101, 'reference_number' => '123456789012', 'occurred_at' => now()->toIso8601String()];
        app(PaymentMatchingService::class)->ingest($device, $signal);
        $this->getJson(route('checkout.status', $token))->assertJsonPath('state', 'detected');
        app(PaymentMatchingService::class)->ingest($device, array_replace($signal, ['client_signal_id' => 'test-notification', 'source' => 'notification']));
        $this->getJson(route('checkout.status', $token))->assertJsonPath('state', 'confirmed');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid', 'confirmation_method' => 'automatic']);
        Queue::assertPushed(DeliverOrderEventWebhook::class);
    }

    public function test_changing_amount_on_a_retried_tracked_request_cannot_create_another_order(): void
    {
        Queue::fake([DeliverOrderEventWebhook::class]);
        $merchant = $this->merchant();
        $this->device($merchant);
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $merchant->id]);
        $payload = $this->payload();
        $this->actingAs($owner)->post(route('panel.preview.live'), $payload)->assertRedirect();

        $this->post(route('panel.preview.live'), array_replace($payload, ['live_amount' => '2.00']))
            ->assertSessionHasErrors(['live_amount' => 'This test request was already used with a different amount. Reload the preview before starting a new test.']);
        $this->assertDatabaseCount('orders', 1);
        Queue::assertPushed(DeliverOrderEventWebhook::class, 1);
    }

    private function merchant(): Merchant
    {
        return Merchant::create(['name' => 'Real Transfer Store', 'slug' => 'real-transfer', 'upi_vpa' => 'preview@kotak']);
    }

    private function payload(): array
    {
        return ['live_amount' => '1.00', 'request_id' => (string) Str::uuid()];
    }

    private function device(Merchant $merchant): Device
    {
        return Device::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'public_key' => 'test-public-key', 'attestation_status' => 'verified', 'status' => 'online', 'last_heartbeat_at' => now(), 'sms_listener_healthy' => true, 'notification_listener_healthy' => true, 'consecutive_good_heartbeats' => 2]);
    }
}
