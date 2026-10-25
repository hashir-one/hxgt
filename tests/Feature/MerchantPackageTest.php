<?php

namespace Tests\Feature;

use HxDev\UpiMerchant\LaravelPaymentHandler;
use HxDev\UpiMerchant\MerchantServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

foreach (['VerificationResult', 'TwoOfTwoVerifier', 'RequestSigner', 'LaravelPaymentHandler', 'ReconcilePayments', 'MerchantServiceProvider'] as $class) {
    require_once __DIR__.'/../../packages/hx-upi-merchant-php/src/'.$class.'.php';
}

class MerchantPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_webhook_commits_fulfillment_once_and_retries_acknowledgement(): void
    {
        [$body, $headers, $order] = $this->integration();
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.example/api/v1/orders/order-1?event_id=7' => Http::response($order),
            'https://gateway.example/api/v1/events/ack' => Http::sequence()->push('', 503)->push(['acknowledged' => 7]),
        ]);
        $this->postJson('/merchant-hook', json_decode($body, true), $headers)->assertStatus(500);
        $this->assertDatabaseCount('merchant_fulfillment_outbox', 1);
        $this->postJson('/merchant-hook', json_decode($body, true), $headers)->assertOk()->assertJsonPath('committed', false);
        $this->assertDatabaseCount('hx_verified_payments', 1);
        $this->assertDatabaseCount('merchant_fulfillment_outbox', 1);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->hasHeader('X-UPI-Signature') && str_contains($request->url(), '?event_id=7'));
    }

    public function test_mismatch_never_commits_or_acknowledges(): void
    {
        [$body, $headers, $order] = $this->integration();
        $order['payable_amount_minor'] = 10002;
        Http::preventStrayRequests();
        Http::fake(['https://gateway.example/api/v1/orders/order-1?event_id=7' => Http::response($order)]);
        $this->postJson('/merchant-hook', json_decode($body, true), $headers)->assertStatus(409);
        $this->assertDatabaseCount('hx_verified_payments', 0);
        $this->assertDatabaseCount('merchant_fulfillment_outbox', 0);
        Http::assertSentCount(1);
    }

    public function test_failed_fulfillment_transaction_can_be_retried_without_losing_the_order(): void
    {
        [$body, $headers, $order] = $this->integration();
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.example/api/v1/orders/order-1?event_id=7' => Http::response($order),
            'https://gateway.example/api/v1/events/ack' => Http::response(['acknowledged' => 7]),
        ]);
        config(['hx-test.fail_fulfillment' => true]);
        $this->postJson('/merchant-hook', json_decode($body, true), $headers)->assertStatus(500);
        $this->assertDatabaseCount('hx_verified_payments', 0);
        $this->assertDatabaseCount('merchant_fulfillment_outbox', 0);
        config(['hx-test.fail_fulfillment' => false]);
        $this->postJson('/merchant-hook', json_decode($body, true), $headers)->assertOk()->assertJsonPath('committed', true);
        $this->assertDatabaseCount('merchant_fulfillment_outbox', 1);
        Http::assertSentCount(3);
    }

    public function test_forged_webhook_is_rejected_before_contacting_gateway(): void
    {
        [$body, $headers] = $this->integration();
        $headers['X-UPI-Event-Signature'] = str_repeat('0', 64);
        Http::preventStrayRequests();
        Http::fake();
        $this->postJson('/merchant-hook', json_decode($body, true), $headers)->assertUnauthorized();
        $this->assertDatabaseCount('hx_verified_payments', 0);
        Http::assertNothingSent();
    }

    public function test_daily_merchant_check_alerts_when_a_fulfilled_order_is_no_longer_paid(): void
    {
        [$body, $headers, $order] = $this->integration();
        Http::preventStrayRequests();
        Http::fake([
            'https://gateway.example/api/v1/orders/order-1?event_id=7' => Http::response($order),
            'https://gateway.example/api/v1/events/ack' => Http::response(['acknowledged' => 7]),
            'https://gateway.example/api/v1/orders/order-1' => Http::response(array_merge($order, ['status' => 'expired'])),
        ]);
        $this->postJson('/merchant-hook', json_decode($body, true), $headers)->assertOk();
        Event::fake(['hx-upi.verification_mismatch']);
        $this->artisan('hx-upi:reconcile')->assertFailed();
        Event::assertDispatched('hx-upi.verification_mismatch', 1);
        $this->assertDatabaseCount('merchant_fulfillment_outbox', 1);
        Http::assertSentCount(3);
    }

    private function integration(): array
    {
        $this->app->register(MerchantServiceProvider::class);
        config(['hx-upi.api_url' => 'https://gateway.example', 'hx-upi.read_key' => 'upi_live_read_fixture-secret', 'hx-upi.webhook_secret' => 'fixture-webhook-secret']);
        (require __DIR__.'/../../packages/hx-upi-merchant-php/database/migrations/2026_10_24_000000_create_hx_verified_payments.php')->up();
        Schema::create('merchant_fulfillment_outbox', function (Blueprint $table): void {
            $table->string('order_id')->primary();
        });
        Route::post('/merchant-hook', function (Request $request, LaravelPaymentHandler $handler) {
            $committed = $handler->handle($request, ['order_id' => 'order-1', 'amount_minor' => 10001, 'currency' => 'INR'], function (array $order): void {
                DB::table('merchant_fulfillment_outbox')->insert(['order_id' => $order['id']]);
                if (config('hx-test.fail_fulfillment')) {
                    throw new \RuntimeException('Outbox unavailable');
                }
            });

            return response()->json(['committed' => $committed]);
        });
        $reference = str_repeat('a', 64);
        $order = ['id' => 'order-1', 'payable_amount_minor' => 10001, 'currency' => 'INR', 'payment_reference_hash' => $reference, 'event_sequence' => 2, 'status' => 'paid', 'confirmation_method' => 'automatic'];
        $proof = hash('sha256', json_encode(['order-1', 10001, 'INR', $reference, 2, 'paid', 'automatic']));
        $body = json_encode(['event_id' => 7, 'order_id' => 'order-1', 'event_type' => 'order.paid', 'sequence_number' => 2, 'verification_hash' => $proof]);
        $timestamp = (string) time();
        $headers = ['X-UPI-Event-Timestamp' => $timestamp, 'X-UPI-Event-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'fixture-webhook-secret')];

        return [$body, $headers, $order];
    }
}
