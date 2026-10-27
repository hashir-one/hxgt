<?php

namespace App\Console\Commands;

use App\Domain\Devices\AndroidAttestationTrust;
use App\Domain\Devices\AttestationFailure;
use App\Domain\Devices\AttestationVerifier;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class CheckAndroidAttestation extends Command
{
    protected $signature = 'gateway:attestation-check';

    protected $description = 'Read-only check of Android policy, verifier runtime and fresh Google trust information';

    public function handle(AttestationVerifier $verifier, AndroidAttestationTrust $trust): int
    {
        try {
            $policy = $verifier->policy();
            $data = $trust->fetch();
            $process = new Process(['/usr/bin/java', '-Xmx128m', '-cp',
                base_path('packages/android-attestation/build/install/hx-android-attestation/lib/*'),
                'in.hxdev.attestation.MainKt'], input: '{}', timeout: 15);
            $process->run();
            if (! $process->isSuccessful() || json_decode($process->getOutput(), true) !== ['verified' => false, 'code' => 'ATTESTATION_MALFORMED']) {
                throw new AttestationFailure('VERIFIER_UNAVAILABLE', 503);
            }
            $this->line(json_encode([
                'environment' => config('app.env'), 'package' => $policy['package'],
                'roots' => count($data['roots']), 'listed_serials' => count($data['revoked_serials']),
                'runtime' => 'ready', 'trust' => 'fresh', 'physical_device_verified' => false,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (AttestationFailure $e) {
            $this->error($e->failureCode);
        } catch (Throwable) {
            $this->error('VERIFIER_UNAVAILABLE');
        }

        return self::FAILURE;
    }
}
