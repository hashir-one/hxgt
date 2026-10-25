<?php

namespace HxDev\UpiMerchant;

final class TwoOfTwoVerifier
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly int $maxWebhookAgeSeconds = 300,
    ) {}

    public function verifyWebhook(string $rawBody, string $timestamp, string $signature): bool
    {
        if ($this->webhookSecret === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $this->maxWebhookAgeSeconds) {
            return false;
        }

        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->webhookSecret);

        return hash_equals($expected, $provided);
    }

    /**
     * @param  array<string,mixed>  $webhook
     * @param  array<string,mixed>  $pulledOrder
     * @param  array<string,mixed>  $expected
     */
    public function verifyFulfillment(array $webhook, array $pulledOrder, array $expected): VerificationResult
    {
        if (! is_int($pulledOrder['payable_amount_minor'] ?? null) || $pulledOrder['payable_amount_minor'] < 1
            || ! is_int($pulledOrder['event_sequence'] ?? null) || $pulledOrder['event_sequence'] < 1
            || ! is_int($webhook['sequence_number'] ?? null) || ! is_int($expected['amount_minor'] ?? null)
            || ! is_string($pulledOrder['payment_reference_hash'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $pulledOrder['payment_reference_hash'])) {
            return new VerificationResult(false, 'mismatch', 'invalid_payment_fields');
        }
        if (! in_array($webhook['event_type'] ?? null, ['order.paid', 'order.manual_confirmed'], true)) {
            return new VerificationResult(false, 'not_fulfillable', 'event_not_paid');
        }

        if (($pulledOrder['status'] ?? null) !== 'paid') {
            return new VerificationResult(false, 'not_fulfillable', 'order_not_paid');
        }

        if (($webhook['event_type'] === 'order.manual_confirmed' || ($pulledOrder['confirmation_method'] ?? null) === 'manual_confirmed')
            && ($expected['accept_manual_confirmation'] ?? false) !== true) {
            return new VerificationResult(false, 'not_fulfillable', 'manual_confirmation_not_accepted');
        }

        $proof = hash('sha256', json_encode([
            $pulledOrder['id'] ?? null, (int) ($pulledOrder['payable_amount_minor'] ?? 0), $pulledOrder['currency'] ?? null,
            $pulledOrder['payment_reference_hash'] ?? null, (int) ($pulledOrder['event_sequence'] ?? 0),
            $pulledOrder['status'] ?? null, $pulledOrder['confirmation_method'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (! is_string($webhook['verification_hash'] ?? null) || ! hash_equals($proof, $webhook['verification_hash'])) {
            return new VerificationResult(false, 'mismatch', 'verification_hash');
        }

        $pairs = [
            'order_id' => [$webhook['order_id'] ?? null, $pulledOrder['id'] ?? null, $expected['order_id'] ?? null],
            'amount_minor' => [$pulledOrder['payable_amount_minor'] ?? null, $expected['amount_minor'] ?? null],
            'currency' => [$pulledOrder['currency'] ?? null, $expected['currency'] ?? 'INR'],
            'reference_number_hash' => [$pulledOrder['payment_reference_hash'] ?? null, $expected['reference_number_hash'] ?? null],
            'event_sequence' => [$webhook['sequence_number'] ?? null, $pulledOrder['event_sequence'] ?? null],
        ];

        foreach ($pairs as $field => $values) {
            $first = array_shift($values);
            foreach ($values as $value) {
                if ($first === null || ! hash_equals((string) $first, (string) $value)) {
                    return new VerificationResult(false, 'mismatch', $field);
                }
            }
        }

        return new VerificationResult(true, 'verified');
    }
}
