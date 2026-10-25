<?php

namespace App\Support;

final class PaymentProof
{
    public static function hash(array $order): string
    {
        return hash('sha256', json_encode([
            $order['id'], (int) $order['payable_amount_minor'], $order['currency'],
            $order['payment_reference_hash'], (int) $order['event_sequence'],
            $order['status'], $order['confirmation_method'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
