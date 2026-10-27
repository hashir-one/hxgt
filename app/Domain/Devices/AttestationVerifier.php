<?php

namespace App\Domain\Devices;

use Symfony\Component\Process\Process;
use Throwable;

final class AttestationVerifier
{
    private const FAILURE_CODES = [
        'CHAIN_INVALID', 'ROOT_UNTRUSTED', 'CERTIFICATE_REVOKED', 'EXTENSION_POSITION',
        'CERTIFICATE_TIME', 'CHALLENGE_MISMATCH', 'EXTENSION_INVALID', 'HARDWARE_POLICY',
        'APP_IDENTITY', 'PUBLIC_KEY_MISMATCH', 'KEY_POLICY', 'BOOT_POLICY', 'OS_POLICY',
        'PATCH_POLICY', 'ATTESTATION_MALFORMED',
    ];

    public function __construct(private AndroidAttestationTrust $trust) {}

    public function policy(): array
    {
        $environment = config('app.env');
        $staging = in_array($environment, ['staging', 'testing'], true);
        if (! config('attestation.enabled') || (! $staging && $environment !== 'production')
            || ($staging && ! config('attestation.staging_identity_enabled'))) {
            throw new AttestationFailure('ATTESTATION_NOT_CONFIGURED', 503);
        }
        $prefix = $staging ? 'staging' : 'release';
        $package = config("attestation.{$prefix}_package");
        $signer = strtolower((string) config("attestation.{$prefix}_signer_sha256"));
        if (! is_string($package) || ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)+\z/', $package)
            || strlen($package) > 150 || ! preg_match('/\A[0-9a-f]{64}\z/', $signer)
            || (! $staging && (str_ends_with($package, '.debug') || $package === config('attestation.staging_package')
                || $signer === strtolower((string) config('attestation.staging_signer_sha256'))
                || $signer === 'a03c8ede4bafd2b22af3da3e5677de01ef5ff60411be27cbfb8b996ab8d07588'))) {
            throw new AttestationFailure('ATTESTATION_NOT_CONFIGURED', 503);
        }
        $policy = ['package' => $package, 'signer_sha256' => $signer];
        foreach (['min_os_version', 'min_os_patch', 'min_vendor_patch', 'min_boot_patch', 'max_patch_age_months'] as $key) {
            $configured = config("attestation.{$key}");
            $value = filter_var($configured, FILTER_VALIDATE_INT);
            if ($configured === null || $value === false || ($key !== 'max_patch_age_months' && $value < 1)) {
                throw new AttestationFailure('ATTESTATION_NOT_CONFIGURED', 503);
            }
            $policy[$key] = $value;
        }
        if ($policy['max_patch_age_months'] < 0 || $policy['max_patch_age_months'] > 12 || $policy['min_os_version'] < 130000) {
            throw new AttestationFailure('ATTESTATION_NOT_CONFIGURED', 503);
        }
        foreach (['min_os_patch', 'min_vendor_patch', 'min_boot_patch'] as $key) {
            $date = (string) $policy[$key];
            $length = $key === 'min_os_patch' ? 6 : 8;
            if (strlen($date) !== $length || ! checkdate((int) substr($date, 4, 2), $length === 6 ? 1 : (int) substr($date, 6, 2), (int) substr($date, 0, 4))) {
                throw new AttestationFailure('ATTESTATION_NOT_CONFIGURED', 503);
            }
        }

        if ($environment === 'staging' && config('attestation.staging_vendor_patch_exception.enabled') === true) {
            $exception = config('attestation.staging_vendor_patch_exception');
            $fingerprint = strtolower((string) ($exception['spki_sha256'] ?? ''));
            $minimum = filter_var($exception['min_vendor_patch'] ?? null, FILTER_VALIDATE_INT);
            $maxAge = filter_var($exception['max_age_months'] ?? null, FILTER_VALIDATE_INT);
            $date = (string) $minimum;
            if (! preg_match('/\A[0-9a-f]{64}\z/', $fingerprint)
                || strlen($date) !== 8
                || ! checkdate((int) substr($date, 4, 2), (int) substr($date, 6, 2), (int) substr($date, 0, 4))
                || $maxAge === false || $maxAge < 0 || $maxAge > 12) {
                throw new AttestationFailure('ATTESTATION_NOT_CONFIGURED', 503);
            }
            $policy['staging_vendor_patch_exception'] = [
                'spki_sha256' => $fingerprint, 'min_vendor_patch' => $minimum, 'max_age_months' => $maxAge,
            ];
        }

        return $policy;
    }

    /** Returns only the canonical public key and an allowlist of safe evidence. */
    public function verify(?array $chain, string $publicKey, string $pairingToken): array
    {
        if ($chain === null || $chain === []) {
            throw new AttestationFailure('ATTESTATION_REQUIRED');
        }
        $policy = $this->policy();
        $key = @openssl_pkey_get_public($publicKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new AttestationFailure('KEY_POLICY');
        }
        $canonicalKey = $details['key'];
        $der = preg_replace('/-----[^-]+-----|\s/', '', $canonicalKey);
        $exception = $policy['staging_vendor_patch_exception'] ?? null;
        unset($policy['staging_vendor_patch_exception']);
        $exceptionEvidence = [];
        // The Java verifier must still authenticate the chain and bind it to this key.
        if ($exception !== null && hash_equals($exception['spki_sha256'], hash('sha256', base64_decode($der, true)))) {
            $policy['min_vendor_patch'] = $exception['min_vendor_patch'];
            $policy['vendor_max_patch_age_months'] = $exception['max_age_months'];
            $exceptionEvidence = ['vendor_patch_exception' => [
                'scope' => 'staging_attested_key',
                'minimum' => $exception['min_vendor_patch'], 'max_age_months' => $exception['max_age_months'],
            ]];
        }
        $trust = $this->trust->fetch();
        $input = $trust + $policy + [
            'certificates' => array_values($chain),
            'public_key' => $der,
            'challenge' => base64_encode(hash('sha256', "hx-pair-v1\n".$pairingToken, true)),
            'now' => now()->timestamp,
        ];
        try {
            $process = new Process([
                '/usr/bin/java', '-Xmx128m', '-cp',
                base_path('packages/android-attestation/build/install/hx-android-attestation/lib/*'),
                'in.hxdev.attestation.MainKt',
            ], input: json_encode($input, JSON_THROW_ON_ERROR), timeout: 15);
            $process->run();
            if (! $process->isSuccessful() || strlen($process->getOutput()) > 4096) {
                throw new AttestationFailure('VERIFIER_UNAVAILABLE', 503);
            }
            $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            if (($result['verified'] ?? null) !== true || ($result['code'] ?? null) !== 'VERIFIED') {
                throw new AttestationFailure(in_array($result['code'] ?? null, self::FAILURE_CODES, true) ? $result['code'] : 'ATTESTATION_MALFORMED',
                    details: is_array($result['details'] ?? null) ? $result['details'] : []);
            }
        } catch (AttestationFailure $e) {
            throw $e;
        } catch (Throwable) {
            throw new AttestationFailure('VERIFIER_UNAVAILABLE', 503);
        }
        if ($trust['valid_until'] <= now()->timestamp) {
            throw new AttestationFailure('TRUST_STALE', 503);
        }

        return ['public_key' => $canonicalKey, 'trust_valid_until' => $trust['valid_until'], 'evidence' => [
            'policy_version' => 'hx-pair-v1',
            'security_level' => $result['security_level'],
            'os_patch' => $result['os_patch'], 'vendor_patch' => $result['vendor_patch'], 'boot_patch' => $result['boot_patch'],
            'verified_at' => now()->toIso8601String(),
            'trust_checked_at' => now()->toIso8601String(),
            'root_fingerprints' => array_map(fn (string $root): string => openssl_x509_fingerprint($root, 'sha256'), $trust['roots']),
        ] + $exceptionEvidence];
    }
}
