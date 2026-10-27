<?php

namespace App\Domain\Devices;

use RuntimeException;

final class AttestationFailure extends RuntimeException
{
    public readonly array $details;

    public function __construct(public readonly string $failureCode, public readonly int $httpStatus = 422, array $details = [])
    {
        $this->details = $failureCode === 'PATCH_POLICY' ? self::safePatchDetails($details) : [];
        parent::__construct($failureCode);
    }

    public static function safePatchDetails(array $details): array
    {
        if (! in_array($details['field'] ?? null, ['os_patch', 'vendor_patch', 'boot_patch'], true)
            || ! in_array($details['reason'] ?? null, ['MISSING', 'INVALID_FORMAT', 'INVALID_DATE', 'FUTURE', 'BELOW_MINIMUM', 'TOO_OLD'], true)) {
            return [];
        }
        $safe = array_intersect_key($details, array_flip(['field', 'reason']));
        foreach (['actual', 'minimum'] as $key) {
            $value = $details[$key] ?? null;
            if ((is_int($value) || is_string($value)) && preg_match('/\A(?:[0-9]{6}|[0-9]{8})\z/', (string) $value)) {
                $safe[$key] = (string) $value;
            }
        }
        if (is_int($details['max_age_months'] ?? null) && $details['max_age_months'] >= 0 && $details['max_age_months'] <= 12) {
            $safe['max_age_months'] = $details['max_age_months'];
        }
        if (is_string($details['server_date'] ?? null) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $details['server_date'])) {
            $safe['server_date'] = $details['server_date'];
        }

        return $safe;
    }
}
