<?php

namespace Tests\Unit;

use HxDev\UpiMerchant\TwoOfTwoVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../packages/hx-upi-merchant-php/src/VerificationResult.php';
require_once __DIR__.'/../../packages/hx-upi-merchant-php/src/TwoOfTwoVerifier.php';

class MerchantVerificationTest extends TestCase
{
    public function test_matching_fields_cannot_authorize_fulfillment_of_an_unpaid_order(): void
    {
        [$webhook, $order, $expected] = $this->payloads();
        $order['status'] = 'expired';
        $result = (new TwoOfTwoVerifier('test-secret'))->verifyFulfillment($webhook, $order, $expected);
        $this->assertFalse($result->verified);
    }

    public function test_matching_paid_order_is_fulfillable(): void
    {
        [$webhook, $order, $expected] = $this->payloads();
        $this->assertTrue((new TwoOfTwoVerifier('test-secret'))->verifyFulfillment($webhook, $order, $expected)->verified);
    }

    public function test_manual_confirmation_requires_explicit_merchant_acceptance(): void
    {
        [$webhook, $order, $expected] = $this->payloads();
        $webhook['event_type'] = 'order.manual_confirmed';
        $order['confirmation_method'] = 'manual_confirmed';
        $webhook['verification_hash'] = hash('sha256', '["order-1",10001,"INR","aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",2,"paid","manual_confirmed"]');
        $verifier = new TwoOfTwoVerifier('test-secret');
        $this->assertFalse($verifier->verifyFulfillment($webhook, $order, $expected)->verified);
        $this->assertTrue($verifier->verifyFulfillment($webhook, $order, $expected + ['accept_manual_confirmation' => true])->verified);
    }

    private function payloads(): array
    {
        return [
            ['event_type' => 'order.paid', 'order_id' => 'order-1', 'sequence_number' => 2, 'verification_hash' => hash('sha256', '["order-1",10001,"INR","aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",2,"paid","automatic"]')],
            ['id' => 'order-1', 'status' => 'paid', 'confirmation_method' => 'automatic', 'payable_amount_minor' => 10001, 'currency' => 'INR', 'payment_reference_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'event_sequence' => 2],
            ['order_id' => 'order-1', 'amount_minor' => 10001, 'currency' => 'INR', 'reference_number_hash' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ];
    }
}
