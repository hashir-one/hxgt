<?php

namespace Tests\Feature;

use App\Domain\Devices\ApprovalService;
use App\Domain\Devices\DevicePairingService;
use App\Domain\Orders\ApiCredentialService;
use App\Domain\Orders\OrderEventService;
use App\Domain\Payments\PaymentMatchingService;
use App\Domain\Reconciliation\ReconciliationService;
use App\Domain\Webhooks\WebhookService;
use App\Jobs\DeliverOrderEventWebhook;
use App\Mail\OperationalAlertMail;
use App\Models\AuditLog;
use App\Models\CircuitBreaker;
use App\Models\Device;
use App\Models\Dispute;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OtpChallenge;
use App\Models\Payment;
use App\Models\PaymentSignal;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\CaptchaVerifier;
use App\Support\TotpService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GatewayCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_matches_the_rfc_vector_and_rejects_replay(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(59));
        $service = app(TotpService::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', $service->code($secret, 1));
        $user = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($service->verify($user, '287082', $secret));
        $this->assertFalse($service->verify($user, '287082'));
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('totp_secret_encrypted'));
    }

    public function test_admin_cannot_enter_console_before_second_factor_and_can_after_enrollment(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => 'admin']);
        $secret = app(TotpService::class)->secret();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('security.totp'));
        $this->withSession(['totp_enrollment_secret' => $secret])->post(route('security.totp.verify'), ['code' => app(TotpService::class)->code($secret, intdiv(now()->timestamp, 30))])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.operations'))->assertOk()->assertSee('Approval queue');
    }

    public function test_breaker_reopen_needs_two_distinct_admin_approvals(): void
    {
        $merchant = $this->merchant();
        $requester = User::factory()->create(['role' => 'admin']);
        $first = User::factory()->create(['role' => 'admin']);
        $breaker = CircuitBreaker::create(['merchant_id' => $merchant->id, 'state' => 'open']);
        $approval = app(ApprovalService::class)->request($merchant, $requester, 'breaker_reopen', $breaker->id);
        $this->withSession(['mfa_user_id' => $requester->id, 'mfa_at' => now()->timestamp])->actingAs($requester)
            ->post(route('admin.approvals.vote', $approval), ['decision' => 'approve', 'reason' => 'Own request'])->assertForbidden();
        $this->assertSame('open', $breaker->fresh()->state);
        $this->withSession(['mfa_user_id' => $first->id, 'mfa_at' => now()->timestamp])->actingAs($first)
            ->post(route('admin.approvals.vote', $approval), ['decision' => 'approve', 'reason' => 'Independent review'])->assertRedirect();
        $this->assertSame('half_open', $breaker->fresh()->state);
        $this->assertSame('executed', $approval->fresh()->status);
    }

    public function test_audit_history_cannot_be_changed_using_a_direct_database_query(): void
    {
        $audit = AuditLog::create(['action' => 'test.action', 'actor_type' => 'system', 'created_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $audit->id)->update(['action' => 'tampered']);
    }

    public function test_order_event_cannot_be_deleted_using_a_direct_database_query(): void
    {
        $order = $this->order($this->merchant());
        $event = app(OrderEventService::class)->append($order, 'order.created');
        $this->expectException(QueryException::class);
        DB::table('order_events')->where('id', $event->id)->delete();
    }

    public function test_webhook_requires_exactly_200_and_binds_the_immutable_payment_snapshot(): void
    {
        Queue::fake([DeliverOrderEventWebhook::class]);
        Http::preventStrayRequests();
        Http::fake(['https://8.8.8.8/hook' => Http::sequence()->push('', 204)->push('', 200)]);
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $endpoint = WebhookEndpoint::create(['merchant_id' => $merchant->id, 'url' => 'https://8.8.8.8/hook', 'secret_encrypted' => 'webhook-secret', 'status' => 'active']);
        $event = app(OrderEventService::class)->append($order, 'order.created');
        $service = app(WebhookService::class);
        $this->assertNull($service->deliver($event, $endpoint)->delivered_at);
        $this->assertNotNull($service->deliver($event, $endpoint)->delivered_at);
        Http::assertSent(fn ($request) => isset($request['verification_hash']) && strlen($request['verification_hash']) === 64 && ! isset($request['amount_minor']));
    }

    public function test_private_webhook_url_is_logged_as_failure_without_outbound_traffic(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $endpoint = WebhookEndpoint::create(['merchant_id' => $merchant->id, 'url' => 'https://127.0.0.1/hook', 'secret_encrypted' => 'secret', 'status' => 'active']);
        $event = app(OrderEventService::class)->append($order, 'order.created');
        $this->assertNotNull(app(WebhookService::class)->deliver($event, $endpoint)->failed_at);
        Http::assertNothingSent();
    }

    public function test_signed_api_request_is_accepted_once_and_replay_is_rejected(): void
    {
        $merchant = $this->merchant();
        [$credential, $token] = app(ApiCredentialService::class)->issue($merchant, 'Read', 'read', ['events:read']);
        $timestamp = (string) now()->timestamp;
        $nonce = 'unique-nonce-123456';
        $canonical = implode("\n", ['GET', '/api/v1/events', $timestamp, $nonce, hash('sha256', '[]')]);
        $headers = ['Authorization' => 'Bearer '.$token, 'X-UPI-Timestamp' => $timestamp, 'X-UPI-Nonce' => $nonce, 'X-UPI-Signature' => hash_hmac('sha256', $canonical, $credential->signing_secret_encrypted)];
        $this->getJson('/api/v1/events', $headers)->assertOk();
        $this->getJson('/api/v1/events', $headers)->assertUnauthorized()->assertJsonPath('error', 'REQUEST_REPLAYED');
        $this->getJson('/api/v1/events', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_turnstile_rejects_a_success_for_the_wrong_hostname(): void
    {
        config(['gateway.claims.captcha_secret' => 'secret', 'gateway.claims.captcha_hosts' => ['checkout.example']]);
        Http::preventStrayRequests();
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'action' => 'payment-claim', 'hostname' => 'attacker.example'])]);
        $this->assertFalse(app(CaptchaVerifier::class)->verify('token', '127.0.0.1'));
        Http::assertSentCount(1);
    }

    public function test_claim_limit_is_shared_across_client_ips(): void
    {
        config(['gateway.claims.captcha_secret' => 'secret', 'gateway.claims.captcha_hosts' => ['checkout.example'], 'gateway.claims.max_attempts' => 1]);
        Http::preventStrayRequests();
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true, 'action' => 'payment-claim', 'hostname' => 'checkout.example'])]);
        $order = $this->order($this->merchant());
        $this->postJson('/checkout/test-token/claims', ['reference_number' => '123456789012', 'cf-turnstile-response' => 'token'])->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->postJson('/checkout/test-token/claims', ['reference_number' => '123456789013', 'cf-turnstile-response' => 'token2'])->assertStatus(429);
        $this->assertSame(1, $order->fresh()->claim_attempts);
    }

    public function test_claim_cannot_combine_bank_signals_far_apart_in_time(): void
    {
        $this->freezeTime();
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $device = Device::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'public_key' => 'test', 'attestation_status' => 'verified']);
        Payment::create(['merchant_id' => $merchant->id, 'order_id' => $order->id, 'reference_number_normalized' => '123456789012', 'reference_number_hash' => hash('sha256', '123456789012'), 'amount_minor' => 10001, 'currency' => 'INR', 'first_seen_at' => now()]);
        foreach (['sms' => 1, 'notification' => 300] as $source => $seconds) {
            PaymentSignal::create(['device_id' => $device->id, 'source' => $source, 'client_signal_id' => $source, 'amount_minor' => 10001, 'reference_number_normalized' => '123456789012', 'reference_number_hash' => hash('sha256', '123456789012'), 'occurred_at' => now()->addSeconds($seconds), 'captured_at' => now(), 'processing_status' => 'unmatched']);
        }
        $result = app(PaymentMatchingService::class)->resolveClaim($order, '123456789012');
        $this->assertSame('open', $result['status']);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_late_payment_can_be_resolved_by_owner_with_fresh_otp_and_audit(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $order->update(['status' => 'late_payment']);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $dispute = Dispute::create(['order_id' => $order->id, 'status' => 'open', 'failure_reasons' => ['late_payment'], 'created_at' => now()]);
        $challenge = OtpChallenge::create(['user_id' => $owner->id, 'email' => $owner->email, 'purpose' => 'manual_confirm', 'challenge_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)]);
        $this->actingAs($owner)->withSession(['action_challenge_id' => $challenge->id])->post(route('panel.disputes.confirm', $dispute), ['reference_number' => '123456789012', 'otp' => '123456', 'reason' => 'Confirmed credit in bank statement'])->assertRedirect();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('resolved', $dispute->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.manual_confirmed', 'merchant_id' => $merchant->id]);
    }

    public function test_reconciliation_flags_missing_gateway_credit_and_is_idempotent(): void
    {
        $this->freezeTime();
        $merchant = $this->merchant();
        $owner = User::factory()->create(['merchant_id' => $merchant->id]);
        $file = tempnam(sys_get_temp_dir(), 'statement');
        file_put_contents($file, "reference_number,amount_minor,occurred_at\n123456789012,10001,".now()->toIso8601String()."\n");
        try {
            $service = app(ReconciliationService::class);
            $id = $service->import($merchant, $file, now()->toDateString(), $owner->id);
            $this->assertSame($id, $service->import($merchant, $file, now()->toDateString(), $owner->id));
            $this->assertDatabaseHas('reconciliation_entries', ['reconciliation_run_id' => $id, 'result' => 'bank_credit_without_order']);
            $this->assertDatabaseCount('reconciliation_runs', 1);
            $this->assertDatabaseHas('operational_alerts', ['merchant_id' => $merchant->id, 'type' => 'reconciliation.mismatch']);
        } finally {
            unlink($file);
        }
    }

    public function test_scheduler_escalates_old_disputes_without_marking_orders_paid(): void
    {
        Mail::fake();
        $this->freezeTime();
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $dispute = Dispute::create(['order_id' => $order->id, 'status' => 'open', 'created_at' => now()->subHours(25)]);
        $this->artisan('gateway:maintain')->assertSuccessful();
        $this->assertNotNull($dispute->fresh()->escalated_at);
        $this->assertSame('pending', $order->fresh()->status);
        Mail::assertQueued(OperationalAlertMail::class);
    }

    public function test_new_operations_pages_and_translated_checkout_render(): void
    {
        $merchant = $this->merchant();
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $order = $this->order($merchant);
        $this->actingAs($owner);
        foreach (['reports', 'security', 'devices', 'developer'] as $page) {
            $this->get(route('panel.'.$page))->assertOk();
        }
        $this->get('/checkout/test-token?lang=hi')->assertOk()->assertSee('UPI से भुगतान करें')->assertSee('lang="hi"', false);
        $this->get('/checkout/test-token?lang=mr')->assertOk()->assertSee('UPI ने पैसे भरा');
        $this->get('/checkout/test-token?lang=invalid')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_staff_disable_revokes_an_existing_session(): void
    {
        $merchant = $this->merchant();
        $staff = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'staff']);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $this->actingAs($staff)->get(route('panel.reports'))->assertOk();
        $this->actingAs($owner)->delete(route('panel.staff.disable', $staff))->assertRedirect();
        $this->actingAs($staff->fresh())->get(route('panel.reports'))->assertForbidden();
    }

    public function test_refunds_preserve_paid_status_and_cannot_exceed_received_funds(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $order->update(['status' => 'paid', 'paid_at' => now()]);
        Payment::create(['merchant_id' => $merchant->id, 'order_id' => $order->id, 'reference_number_normalized' => '123456789012', 'reference_number_hash' => hash('sha256', '123456789012'), 'amount_minor' => 10001, 'currency' => 'INR', 'first_seen_at' => now()]);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $payload = ['amount_minor' => 6000, 'bank_reference' => 'refund-1', 'reason' => 'Partial return', 'refunded_at' => now()->toIso8601String()];
        $this->actingAs($owner)->post(route('panel.refunds.record', $order), $payload)->assertRedirect();
        $this->post(route('panel.refunds.record', $order), array_replace($payload, ['bank_reference' => 'refund-2']))->assertStatus(422);
        $this->assertDatabaseCount('refund_records', 1);
        $this->assertSame('paid', $order->fresh()->status);
        $this->get(route('panel.payments.show', $order))->assertOk()->assertSee('refund-1');
    }

    public function test_payment_export_respects_filters_and_neutralizes_spreadsheet_formulas(): void
    {
        $merchant = $this->merchant();
        $order = $this->order($merchant);
        $order->update(['merchant_order_id' => '=HYPERLINK("https://bad.example")']);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $response = $this->actingAs($owner)->get(route('panel.payments.export', ['min_amount_minor' => 10001]));
        $response->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
        $filtered = $this->get(route('panel.payments.export', ['min_amount_minor' => 10002]))->assertOk();
        $this->assertStringNotContainsString('HYPERLINK', $filtered->streamedContent());
    }

    public function test_pairing_requires_a_fresh_owner_code_and_approved_extra_slot(): void
    {
        $merchant = $this->merchant();
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $challenge = OtpChallenge::create(['user_id' => $owner->id, 'email' => $owner->email, 'purpose' => 'pair_device', 'challenge_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)]);
        $this->actingAs($owner)->post(route('panel.devices.pair'), ['slot_number' => 1])->assertSessionHasErrors('otp');
        $this->withSession(['pairing_challenge_id' => $challenge->id])->post(route('panel.devices.pair'), ['slot_number' => 1, 'otp' => '123456'])->assertRedirect()->assertSessionHas('pairing_token');
        $this->get(route('panel.devices'))->assertOk()->assertSee('Scan to pair');
        $this->assertDatabaseCount('device_pairings', 1);
        $this->post(route('panel.devices.pair'), ['slot_number' => 1, 'otp' => '123456'])->assertStatus(422);
        $this->expectException(\RuntimeException::class);
        app(DevicePairingService::class)->create($merchant, $owner, 2);
    }

    public function test_approved_migration_revokes_old_key_only_when_replacement_pairs(): void
    {
        $merchant = $this->merchant();
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $old = Device::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'public_key' => 'old-key']);
        $approval = app(ApprovalService::class)->request($merchant, $owner, 'migration', $old->id);
        app(ApprovalService::class)->vote($approval, User::factory()->create(['role' => 'admin']), true, 'First review');
        app(ApprovalService::class)->vote($approval, User::factory()->create(['role' => 'admin']), true, 'Second review');
        [$pairing, $token] = app(DevicePairingService::class)->create($merchant, $owner, 1, $old->id);
        $this->assertNull($old->fresh()->revoked_at);
        $key = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        $this->postJson('/api/device/v1/pair', ['pairing_token' => $token, 'public_key' => $key])->assertCreated();
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->postJson('/api/device/v1/pair', ['pairing_token' => $token, 'public_key' => $key])->assertStatus(422);
        $this->assertDatabaseCount('devices', 2);
    }

    private function merchant(): Merchant
    {
        return Merchant::create(['name' => 'Test Merchant', 'slug' => 'test-merchant', 'upi_vpa' => 'test@kotak']);
    }

    private function order(Merchant $merchant): Order
    {
        return Order::create(['merchant_id' => $merchant->id, 'merchant_order_id' => 'test-order', 'requested_amount_minor' => 10000, 'payable_amount_minor' => 10001, 'slot_paise' => 1, 'currency' => 'INR', 'status' => 'pending', 'checkout_token_hash' => hash('sha256', 'test-token'), 'expires_at' => now()->addMinutes(10)]);
    }
}
