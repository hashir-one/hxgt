<?php

namespace App\Domain\Devices;

final class AttestationVerifier
{
    /**
     * Production Android Key Attestation validation remains a Phase 0/Android-client
     * integration boundary. Pairing stays untrusted; there is no manual override.
     */
    public function status(?array $certificateChain, ?string $expectedPackage = null): string
    {
        if ($certificateChain === null || $certificateChain === []) {
            return 'pending_review';
        }

        return 'submitted';
    }
}
