<?php

namespace App\Domain\Devices;

use Illuminate\Support\Facades\DB;
use stdClass;

final class PairingKeyRegistry
{
    /** @return array{public_key: string, spki_sha256: string}|null */
    public static function material(string $pem): ?array
    {
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return null;
        }
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $details['key']), true);

        return ['public_key' => $details['key'], 'spki_sha256' => hash('sha256', $der)];
    }

    /** Call inside a transaction, before merchant, pairing, recovery, or device locks. */
    public function lock(string $fingerprint): stdClass
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Pending key locks require a transaction.');
        }
        // The unique insert serializes even the first use of an absent key on PostgreSQL.
        DB::table('device_key_states')->insertOrIgnore([
            'spki_sha256' => $fingerprint, 'created_at' => now(),
        ]);

        return DB::table('device_key_states')->where('spki_sha256', $fingerprint)->lockForUpdate()->firstOrFail();
    }
}
