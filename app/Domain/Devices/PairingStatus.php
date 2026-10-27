<?php

namespace App\Domain\Devices;

use App\Models\AuditLog;
use App\Models\DevicePairing;

final class PairingStatus
{
    public function forPairing(DevicePairing $pairing): array
    {
        $state = match (true) {
            $pairing->consumed_at !== null => 'paired',
            $pairing->approval_status !== 'approved' => 'unavailable',
            $pairing->expires_at->lte(now()) => 'expired',
            default => 'waiting',
        };
        $message = match ($state) {
            'paired' => 'Pairing completed. The device still needs healthy signed heartbeats.',
            'unavailable' => 'This pairing is no longer available. Request a new owner-authorized QR.',
            'expired' => 'This QR has expired. Request a new owner email code and generate a fresh QR.',
            default => 'Waiting for the capture app to submit its hardware attestation.',
        };
        $failure = $state === 'paired' ? null : AuditLog::query()
            ->where('merchant_id', $pairing->merchant_id)->where('target_type', DevicePairing::class)
            ->where('target_id', $pairing->id)->where('action', 'device.attestation_rejected')->latest('id')->first();
        $code = null;
        $details = [];
        if ($failure) {
            $code = in_array($failure->reason, [
                'PATCH_POLICY', 'OS_POLICY', 'BOOT_POLICY', 'HARDWARE_POLICY', 'KEY_POLICY', 'APP_IDENTITY',
                'ATTESTATION_REQUIRED', 'ATTESTATION_MALFORMED', 'CHALLENGE_MISMATCH', 'PUBLIC_KEY_MISMATCH',
                'CHAIN_INVALID', 'ROOT_UNTRUSTED', 'EXTENSION_POSITION', 'EXTENSION_INVALID', 'CERTIFICATE_TIME',
                'CERTIFICATE_REVOKED', 'TRUST_UNAVAILABLE', 'TRUST_STALE', 'TRUST_DATA_INVALID',
                'ATTESTATION_NOT_CONFIGURED', 'VERIFIER_UNAVAILABLE',
            ], true) ? $failure->reason : 'ATTESTATION_REJECTED';
            $rawDetails = $failure->metadata['details'] ?? [];
            $details = $code === 'PATCH_POLICY' && is_array($rawDetails) ? AttestationFailure::safePatchDetails($rawDetails) : [];
            $explanation = match ($code) {
                'PATCH_POLICY' => 'An Android, vendor, or boot security-patch check failed. Check the reported details and install available system updates.',
                'APP_IDENTITY' => 'The APK package or signing certificate does not match the configured app identity.',
                'CHALLENGE_MISMATCH' => 'Generate a new hardware key using the current QR challenge.',
                'PUBLIC_KEY_MISMATCH' => 'The public key and attestation certificate do not belong to the same key.',
                'BOOT_POLICY' => 'The phone must report locked and verified boot.',
                'HARDWARE_POLICY', 'KEY_POLICY' => 'The required hardware-backed signing-key policy was not satisfied.',
                'ATTESTATION_REQUIRED' => 'The app did not submit its hardware attestation certificate chain.',
                default => 'The server rejected the pairing. Use the failure code to resolve the problem before retrying.',
            };
            if ($state === 'waiting') {
                $state = 'rejected';
                $message = 'Scan received, but attestation was rejected. '.$explanation;
            } else {
                $message .= ' Last attempt: '.$explanation;
            }
        }

        return [
            'state' => $state, 'message' => $message, 'code' => $code, 'details' => $details,
            'expires_at' => $pairing->expires_at->toIso8601String(),
            'last_attempt_at' => $failure?->created_at?->toIso8601String(),
            'poll' => in_array($state, ['waiting', 'rejected'], true),
        ];
    }
}
