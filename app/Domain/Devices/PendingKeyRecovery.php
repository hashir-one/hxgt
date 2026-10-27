<?php

namespace App\Domain\Devices;

use App\Models\Device;
use App\Models\Merchant;
use App\Models\PairingRecovery;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PendingKeyRecovery
{
    public const PATH = '/api/device/v1/pair/recovery';

    public const PROTOCOL = 'hx-pair-recovery-v1';

    public function __construct(private PairingKeyRegistry $keys, private AuditLogger $audit) {}

    public function origin(): string
    {
        $origin = rtrim((string) config('gateway.device_domain'), '/');
        $url = parse_url($origin);
        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || ! empty($url['path'])
            || strlen($origin) > 240 || ! filter_var($origin, FILTER_VALIDATE_URL)) {
            $this->fail('RECOVERY_NOT_CONFIGURED', 503);
        }

        return $origin;
    }

    public function issue(User $owner, string $pem): array
    {
        $material = PairingKeyRegistry::material($pem);
        if ($material === null) {
            $this->fail('RECOVERY_KEY_INVALID', 422);
        }
        if ($owner->role !== 'owner' || $owner->disabled_at !== null || $owner->merchant?->status !== 'active') {
            $this->fail('RECOVERY_UNAVAILABLE', 403);
        }
        $recovery = PairingRecovery::create($material + [
            'public_id' => (string) Str::uuid(), 'merchant_id' => $owner->merchant_id,
            'requested_by' => $owner->id, 'origin' => $this->origin(),
            'nonce' => bin2hex(random_bytes(32)), 'expires_at' => now()->startOfSecond()->addMinutes(5),
        ]);
        $this->audit->record('device.recovery_authorized', $owner->merchant_id, PairingRecovery::class,
            $recovery->id, includeRequestContext: false);

        return $this->challenge($recovery, $owner->merchant);
    }

    public function challenge(PairingRecovery $recovery, Merchant $merchant): array
    {
        return [
            'protocol' => self::PROTOCOL, 'recovery_id' => $recovery->public_id,
            'origin' => $recovery->origin, 'method' => 'POST', 'path' => self::PATH,
            'scope' => 'key_all_pairings', 'action' => 'resolve_and_close_if_absent',
            'merchant_id' => $merchant->public_id, 'spki_sha256' => $recovery->spki_sha256,
            'nonce' => $recovery->nonce, 'expires_at' => $recovery->expires_at->toIso8601String(),
            'expires_unix' => $recovery->expires_at->timestamp,
            'signing_payload' => $this->signingPayload($recovery, $merchant),
        ];
    }

    private function signingPayload(PairingRecovery $recovery, Merchant $merchant): string
    {
        return implode("\n", [self::PROTOCOL, 'POST', $recovery->origin, self::PATH,
            $recovery->public_id, $merchant->public_id, $recovery->spki_sha256,
            'key_all_pairings', 'resolve_and_close_if_absent', $recovery->nonce,
            (string) $recovery->expires_at->timestamp]);
    }

    public function resolve(string $id, string $signature): array
    {
        $snapshot = PairingRecovery::where('public_id', $id)->first();
        if ($snapshot === null) {
            $this->fail('RECOVERY_UNAVAILABLE', 404);
        }
        $bytes = base64_decode($signature, true);
        if ($bytes === false || strlen($bytes) < 8 || strlen($bytes) > 80) {
            $this->fail('RECOVERY_PROOF_INVALID', 401);
        }

        return DB::transaction(function () use ($snapshot, $bytes): array {
            $key = $this->keys->lock($snapshot->spki_sha256);
            $merchant = Merchant::whereKey($snapshot->merchant_id)->lockForUpdate()->first();
            $owner = User::whereKey($snapshot->requested_by)->lockForUpdate()->first();
            $recovery = PairingRecovery::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($merchant === null || $merchant->status !== 'active' || $owner === null
                || $owner->role !== 'owner' || $owner->disabled_at !== null || $owner->merchant_id !== $merchant->id) {
                $this->fail('RECOVERY_UNAVAILABLE', 403);
            }
            if ($recovery->origin !== $this->origin()) {
                $this->fail('RECOVERY_UNAVAILABLE', 409);
            }
            if (@openssl_verify($this->signingPayload($recovery, $merchant), $bytes, $recovery->public_key, OPENSSL_ALGO_SHA256) !== 1) {
                $this->fail('RECOVERY_PROOF_INVALID', 401);
            }
            if ($recovery->consumed_at !== null) {
                $this->fail('RECOVERY_ALREADY_USED', 409);
            }
            if ($recovery->expires_at->lte(now())) {
                $this->fail('RECOVERY_EXPIRED', 410);
            }
            $registration = null;
            if ($key->device_id !== null) {
                $device = Device::whereKey($key->device_id)->lockForUpdate()->first();
                if ($device === null || $device->merchant_id !== $merchant->id) {
                    $this->fail('RECOVERY_UNAVAILABLE', 404);
                }
                // Corrupt/inconsistent historical state never becomes a negative result.
                $material = PairingKeyRegistry::material($device->public_key);
                if ($material === null || ! hash_equals($recovery->spki_sha256, $material['spki_sha256'])) {
                    $this->fail('RECOVERY_STATE_INVALID', 503);
                }
                $outcome = $device->revoked_at !== null || $device->status === 'revoked' ? 'revoked' : 'registered';
                $registration = [
                    'device_id' => $device->public_id, 'status' => $device->status,
                    'attestation_status' => $device->attestation_status,
                    'revoked_at' => $device->revoked_at?->toIso8601String(),
                    'can_activate' => $outcome === 'registered' && $device->attestation_status === 'verified'
                        && in_array($device->status, ['offline', 'online', 'degraded'], true),
                ];
            } else {
                $outcome = 'not_registered_closed';
                DB::table('device_key_states')->where('spki_sha256', $recovery->spki_sha256)
                    ->update(['closed_at' => $key->closed_at ?? now()]);
            }
            $recovery->update(['consumed_at' => now(), 'outcome' => $outcome]);
            $this->audit->record('device.recovery_resolved', $merchant->id, PairingRecovery::class,
                $recovery->id, reason: $outcome, includeRequestContext: false);

            return [
                'protocol' => self::PROTOCOL, 'recovery_id' => $recovery->public_id,
                'merchant_id' => $merchant->public_id, 'spki_sha256' => $recovery->spki_sha256,
                'scope' => 'key_all_pairings', 'outcome' => $outcome,
                'registration' => $registration, 'resolved_at' => now()->toIso8601String(),
                'key_closed' => $outcome === 'not_registered_closed',
            ];
        }, 3);
    }

    private function fail(string $code, int $status): never
    {
        throw new HttpResponseException(response()->json(['error' => $code], $status)
            ->header('Cache-Control', 'no-store, private'));
    }
}
