<?php

namespace HxDev\UpiMerchant;

use Illuminate\Console\Command;

final class ReconcilePayments extends Command
{
    protected $signature = 'hx-upi:reconcile';

    protected $description = 'Check the last day of verified merchant payments against current gateway records';

    public function handle(LaravelPaymentHandler $handler): int
    {
        $mismatches = $handler->reconcile();
        $this->line($mismatches.' integrity mismatches; see the critical log and hx-upi.verification_mismatch listener.');

        return $mismatches === 0 ? self::SUCCESS : self::FAILURE;
    }
}
