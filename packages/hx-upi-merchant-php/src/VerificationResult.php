<?php

namespace HxDev\UpiMerchant;

final readonly class VerificationResult
{
    public function __construct(
        public bool $verified,
        public string $state,
        public ?string $reason = null,
    ) {}
}
