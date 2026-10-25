<?php

namespace App\Domain\Reconciliation;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Support\AlertService;
use App\Support\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReconciliationService
{
    public function import(Merchant $merchant, string $path, string $date, int $actor): int
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        if ($header !== ['reference_number', 'amount_minor', 'occurred_at']) {
            fclose($handle);
            throw ValidationException::withMessages(['statement' => 'CSV headers must be reference_number,amount_minor,occurred_at. Upload bank credits only.']);
        }
        $rows = [];
        $seen = [];
        try {
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 3 || ! preg_match('/^\d{12}$/', $row[0]) || ! ctype_digit($row[1]) || (int) $row[1] < 1 || strlen($row[1]) > 12 || isset($seen[$row[0]]) || count($rows) >= 10000) {
                    throw ValidationException::withMessages(['statement' => 'Invalid or duplicate reference/amount, or more than 10,000 rows.']);
                }
                try {
                    $at = CarbonImmutable::parse($row[2]);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['statement' => 'Invalid credit timestamp.']);
                }
                if ($at->toDateString() !== $date) {
                    throw ValidationException::withMessages(['statement' => 'Every credit must belong to the selected statement date.']);
                }
                $seen[$row[0]] = true;
                $rows[] = ['reference' => $row[0], 'amount' => (int) $row[1]];
            }
        } finally {
            fclose($handle);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['statement' => 'The statement has no credits.']);
        }

        return DB::transaction(function () use ($merchant, $path, $date, $actor, $rows): int {
            Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            $hash = hash_file('sha256', $path);
            $existing = DB::table('reconciliation_runs')->where('merchant_id', $merchant->id)->where('file_hash', $hash)->first();
            if ($existing) {
                return $existing->id;
            }
            $run = DB::table('reconciliation_runs')->insertGetId(['merchant_id' => $merchant->id, 'statement_date' => $date, 'file_hash' => $hash, 'created_by' => $actor, 'status' => 'review', 'created_at' => now(), 'updated_at' => now()]);
            $matched = 0;
            $mismatches = 0;
            $references = [];
            foreach ($rows as $row) {
                $referenceHash = Money::referenceHash($row['reference']);
                $references[] = $referenceHash;
                $payment = Payment::with('order')->where('merchant_id', $merchant->id)->where('reference_number_hash', $referenceHash)->first();
                $result = match (true) {
                    ! $payment?->order => 'bank_credit_without_order',
                    $payment->amount_minor !== $row['amount'] => 'amount_mismatch',
                    $payment->order->status !== 'paid' => 'received_not_confirmed',
                    default => 'matched',
                };
                $result === 'matched' ? $matched++ : $mismatches++;
                DB::table('reconciliation_entries')->insert(['reconciliation_run_id' => $run, 'order_id' => $payment?->order_id, 'reference_hash' => $referenceHash, 'amount_minor' => $row['amount'], 'result' => $result, 'created_at' => now(), 'updated_at' => now()]);
            }
            $paid = Order::with('payment')->where('merchant_id', $merchant->id)->where('status', 'paid')->whereDate('paid_at', $date)->get();
            foreach ($paid as $order) {
                if ($order->payment && in_array($order->payment->reference_number_hash, $references, true)) {
                    continue;
                }
                $mismatches++;
                DB::table('reconciliation_entries')->insert(['reconciliation_run_id' => $run, 'order_id' => $order->id, 'reference_hash' => $order->payment?->reference_number_hash ?? '', 'amount_minor' => $order->payable_amount_minor, 'result' => 'paid_missing_from_statement', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('reconciliation_runs')->where('id', $run)->update(['status' => $mismatches ? 'review' : 'matched', 'matched_count' => $matched, 'mismatch_count' => $mismatches]);
            app(AuditLogger::class)->record('reconciliation.imported', $merchant->id, 'reconciliation_run', $run, metadata: ['mismatches' => $mismatches]);
            if ($mismatches) {
                app(AlertService::class)->raise($merchant->id, 'reconciliation.mismatch', 'Bank reconciliation has '.$mismatches.' items needing review.', 'reconciliation:'.$run);
            }

            return $run;
        });
    }
}
