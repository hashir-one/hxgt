<?php

namespace Tests\Feature;

use App\Domain\Payments\EmailPaymentMatchingService;
use App\Models\CircuitBreaker;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailPaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_bank_email_is_review_evidence_and_cannot_confirm_an_order(): void
    {
        config([
            'gateway.email.enabled' => true,
            'gateway.email.sender_allowlist' => ['alerts@kotak.example'],
            'gateway.email.minimum_amount_minor' => 50000,
        ]);
        $merchant = Merchant::create([
            'name' => 'Email Merchant',
            'slug' => 'email-merchant',
            'upi_vpa' => 'email@kotak',
        ]);
        MerchantSetting::create(['merchant_id' => $merchant->id]);
        CircuitBreaker::create(['merchant_id' => $merchant->id, 'state' => 'closed']);
        $order = Order::create([
            'merchant_id' => $merchant->id,
            'merchant_order_id' => 'email-order',
            'requested_amount_minor' => 50000,
            'payable_amount_minor' => 50037,
            'slot_paise' => 37,
            'currency' => 'INR',
            'status' => 'pending',
            'checkout_token_hash' => hash('sha256', 'email-token'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $result = app(EmailPaymentMatchingService::class)->ingest($merchant, [
            'message_id' => '<bank-message-1@example>',
            'sender_email' => 'alerts@kotak.example',
            'subject' => 'UPI credit alert',
            'amount_minor' => 50037,
            'reference_number' => '123456789012',
            'occurred_at' => now()->addSecond()->toIso8601String(),
            'authentication' => ['spf' => true, 'dkim' => true, 'dmarc' => true],
        ]);

        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('order_events', 0);
        $this->assertNull($order->fresh()->confirmation_method);
    }
}
