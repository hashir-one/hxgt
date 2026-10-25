<?php

namespace App\Console\Commands;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentSignal;
use App\Support\AlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckPaymentIntegrity extends Command
{
    protected $signature = 'gateway:check-integrity';

    protected $description = 'Recheck paid evidence and require yesterday bank reconciliation';

    public function handle(AlertService $alerts): int
    {
        Order::with('payment')->where('status', 'paid')->where('paid_at', '>=', now()->subDay())->orderBy('id')->chunkById(100, function ($orders) use ($alerts): void {
            foreach ($orders as $order) {
                $valid = $order->payment && $order->payment->amount_minor === $order->payable_amount_minor;
                if ($valid && $order->confirmation_method !== 'manual_confirmed') {
                    $signals = PaymentSignal::whereHas('device', fn ($q) => $q->where('merchant_id', $order->merchant_id))->where('reference_number_hash', $order->payment->reference_number_hash)->where('amount_minor', $order->payable_amount_minor)->whereBetween('occurred_at', [$order->created_at, $order->expires_at])->where('processing_status', 'matched')->get();
                    $valid = false;
                    foreach ($signals->where('source', 'sms') as $sms) {
                        foreach ($signals->where('source', 'notification') as $notification) {
                            if (abs($sms->occurred_at->timestamp - $notification->occurred_at->timestamp) <= config('gateway.matching.signal_grace_seconds')) {
                                $valid = true;
                            }
                        }
                    }
                }
                if (! $valid) {
                    $alerts->raise($order->merchant_id, 'payment.integrity_mismatch', 'Paid order '.$order->public_id.' lacks matching bank evidence.', 'integrity:'.$order->id);
                }
            }
        });
        $date = now()->subDay()->toDateString();
        Merchant::whereHas('orders', fn ($q) => $q->where('status', 'paid')->whereDate('paid_at', $date))->each(function ($merchant) use ($date, $alerts): void {
            if (! DB::table('reconciliation_runs')->where('merchant_id', $merchant->id)->where('statement_date', $date)->exists()) {
                $alerts->raise($merchant->id, 'reconciliation.missing', 'Import a bank credit statement for '.$date.'.', 'statement-missing:'.$merchant->id.':'.$date);
            }
        });

        return self::SUCCESS;
    }
}
