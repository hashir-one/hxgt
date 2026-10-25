<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function formatInr(int $minor): string
    {
        return '₹'.number_format($minor / 100, 2, '.', ',');
    }

    public static function parseInr(string $amount): int
    {
        $normalized = str_replace([',', '₹', 'INR', 'Rs', 'rs', ' '], '', trim($amount));
        if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/', $normalized)) {
            throw new InvalidArgumentException('Amount must be a valid INR decimal.');
        }

        [$rupees, $paise] = array_pad(explode('.', $normalized, 2), 2, '0');

        return ((int) $rupees * 100) + (int) str_pad($paise, 2, '0');
    }

    public static function normalizeReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', trim($reference));

        return $normalized === '' ? null : $normalized;
    }

    public static function assertReference(?string $reference): string
    {
        $normalized = self::normalizeReference($reference);
        $length = (int) config('gateway.matching.reference_length', 12);

        if ($normalized === null || strlen($normalized) !== $length) {
            throw new InvalidArgumentException("Reference must contain exactly {$length} digits.");
        }

        return $normalized;
    }

    public static function referenceHash(string $reference): string
    {
        return hash('sha256', self::assertReference($reference));
    }

    public static function requestHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
