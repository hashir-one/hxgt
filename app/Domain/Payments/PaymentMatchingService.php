<?php

namespace App\Domain\Payments;

use App\Domain\Devices\GatewayHealthService;
use App\Domain\Orders\OrderEventService;
use App\Models\Claim;
use App\Models\Device;
use App\Models\Dispute;
use App\Models\MatchAttempt;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentSignal;
use App\Support\AlertService;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class PaymentMatchingService
{
    public function __construct(
        private readonly OrderEventService $events,
        private readonly GatewayHealthService $health,
    ) {}

    public function ingest(Device $device, array $data): array
    {
        return DB::transaction(function () use ($device, $data): array {
            Merchant::whereKey($device->merchant_id)->lockForUpdate()->firstOrFail();
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($device->attestation_status !== 'verified' || $device->revoked_at !== null) {
                return ['accepted' => false, 'status' => 'rejected', 'reason' => 'device_attestation_required'];
            }

            $reference = Money::normalizeReference($data['reference_number'] ?? null);
            $referenceHash = $reference === null ? null : Money::referenceHash($reference);

            try {
                $signal = DB::transaction(fn () => PaymentSignal::create([
                    'device_id' => $device->id,
                    'source' => $data['source'],
                    'client_signal_id' => $data['client_signal_id'],
                    'amount_minor' => (int) $data['amount_minor'],
                    'reference_number_normalized' => $reference,
                    'reference_number_hash' => $referenceHash,
                    'occurred_at' => $data['occurred_at'],
                    'captured_at' => $data['captured_at'] ?? now(),
                    'parser_rule_version' => $data['parser_rule_version'] ?? null,
                    'source_metadata' => $data['source_metadata'] ?? null,
                    'processing_status' => 'received',
                ]));
            } catch (UniqueConstraintViolationException) {
                $signal = PaymentSignal::query()
                    ->where('device_id', $device->id)
                    ->where('client_signal_id', $data['client_signal_id'])
                    ->firstOrFail();

                return ['accepted' => true, 'duplicate' => true, 'signal_id' => $signal->id];
            }

            if ($reference === null) {
                $signal->update(['processing_status' => 'needs_review', 'failure_reason' => 'missing_reference']);
                $this->health->recordFault($device->merchant, 'unparseable_credit', ['signal_id' => $signal->id]);

                return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'missing_reference', 'signal_id' => $signal->id];
            }

            $this->lockReference($reference);
            $payment = Payment::query()
                ->where('reference_number_normalized', $reference)
                ->lockForUpdate()
                ->first();

            if ($payment !== null && $payment->merchant_id !== $device->merchant_id) {
                $signal->update(['processing_status' => 'needs_review', 'failure_reason' => 'reference_collision']);
                $this->health->recordFault($device->merchant, 'reference_collision', ['reference_hash' => $referenceHash]);

                return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'reference_collision', 'signal_id' => $signal->id];
            }

            if ($payment !== null && $payment->amount_minor !== (int) $data['amount_minor']) {
                if ($payment->order?->status === 'paid') {
                    app(AlertService::class)->raise($device->merchant_id, 'payment.conflicting_evidence', 'Conflicting bank evidence arrived for paid order '.$payment->order->public_id.'. The paid result has been preserved.', 'paid-conflict:'.$payment->id);
                }
                $signal->update(['processing_status' => 'needs_review', 'failure_reason' => 'reference_amount_conflict']);
                $this->health->recordFault($device->merchant, 'amount_mismatch', ['reference_hash' => $referenceHash]);

                return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'reference_amount_conflict', 'signal_id' => $signal->id];
            }

            $payment ??= Payment::create([
                'reference_number_normalized' => $reference,
                'merchant_id' => $device->merchant_id,
                'reference_number_hash' => $referenceHash,
                'amount_minor' => (int) $data['amount_minor'],
                'currency' => 'INR',
                'first_seen_at' => $data['occurred_at'],
                'status' => 'unmatched',
            ]);

            $order = $payment->order;
            if ($order === null) {
                $claimed = Claim::where('reference_number_hash', $referenceHash)->where('status', 'open')->whereHas('order', fn ($q) => $q->where('merchant_id', $device->merchant_id))->first();
                $order = $claimed?->order ?? $this->findCandidateOrder($device, (int) $data['amount_minor'], $data['occurred_at']);
                if ($order !== null) {
                    $payment->update(['order_id' => $order->id]);
                }
            }

            if ($order === null) {
                $signal->update(['processing_status' => 'unmatched', 'failure_reason' => 'no_candidate_order']);
                $this->health->recordFault($device->merchant, 'credit_without_order', ['signal_id' => $signal->id, 'reference_hash' => $referenceHash]);
                $this->attempt(null, $payment, $signal, 'rejected', 'no_candidate');

                return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'no_candidate', 'signal_id' => $signal->id];
            }

            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->merchant_id !== $device->merchant_id || $payment->merchant_id !== $device->merchant_id) {
                $signal->update(['processing_status' => 'needs_review', 'failure_reason' => 'merchant_mismatch']);
                $this->attempt($order, $payment, $signal, 'rejected', 'merchant_mismatch');

                return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'merchant_mismatch', 'signal_id' => $signal->id];
            }

            if ($order->status === 'paid') {
                $signal->update(['processing_status' => 'duplicate_payment']);
                $this->attempt($order, $payment, $signal, 'rejected', 'reference_already_used');

                return ['accepted' => true, 'status' => 'duplicate', 'reason' => 'reference_already_used', 'signal_id' => $signal->id];
            }

            $isExact = $order->payable_amount_minor === (int) $data['amount_minor'];
            $isAfterCreation = $order->created_at->lte($signal->occurred_at);
            $isBeforeExpiry = $order->expires_at->gte($signal->occurred_at);

            if (! $isExact) {
                $order->update(['status' => 'needs_review']);
                if ($order->wasChanged('status')) {
                    $this->events->append($order, 'order.needs_review', ['reason' => 'amount_mismatch']);
                }
                $signal->update(['processing_status' => 'needs_review', 'failure_reason' => 'amount_mismatch']);
                Dispute::firstOrCreate(['order_id' => $order->id, 'claimed_reference_hash' => $referenceHash], [
                    'failure_reasons' => ['amount_mismatch'], 'status' => 'open', 'created_at' => now(),
                ]);
                $this->health->recordFault($device->merchant, 'amount_mismatch', ['order_id' => $order->public_id]);
                $this->attempt($order, $payment, $signal, 'review', 'amount_mismatch');

                return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'amount_mismatch', 'signal_id' => $signal->id];
            }

            if (! $isAfterCreation) {
                $signal->update(['processing_status' => 'needs_review', 'failure_reason' => 'before_order_creation']);
                $this->attempt($order, $payment, $signal, 'review', 'before_order_creation');

                return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'before_order_creation', 'signal_id' => $signal->id];
            }

            if (! $isBeforeExpiry) {
                if ($order->status === 'late_payment') {
                    return ['accepted' => true, 'status' => 'late_payment', 'signal_id' => $signal->id];
                }
                Dispute::firstOrCreate(['order_id' => $order->id, 'status' => 'open'], ['failure_reasons' => ['late_payment'], 'created_at' => now()]);
                $order->update(['status' => 'late_payment', 'late_payment_at' => now()]);
                $payment->update(['status' => 'late']);
                $signal->update(['processing_status' => 'late_payment']);
                Claim::query()
                    ->where('reference_number_hash', $referenceHash)
                    ->where('status', 'open')
                    ->get()->each->update(['status' => 'matched', 'resolved_at' => now()]);
                $this->events->append($order, 'payment.late', ['reference_number_hash' => $referenceHash]);
                $this->failProbeIfNeeded($order);
                $this->attempt($order, $payment, $signal, 'review', 'after_expiry');

                return ['accepted' => true, 'status' => 'late_payment', 'reason' => 'after_expiry', 'signal_id' => $signal->id];
            }

            if ($order->status === 'paid' || $payment->order_id !== $order->id) {
                $signal->update(['processing_status' => 'duplicate_payment']);
                $this->attempt($order, $payment, $signal, 'rejected', 'reference_already_used');

                return ['accepted' => true, 'status' => 'duplicate', 'reason' => 'reference_already_used', 'signal_id' => $signal->id];
            }

            $counterpart = PaymentSignal::query()
                ->where('id', '!=', $signal->id)
                ->where('source', $signal->source === 'sms' ? 'notification' : 'sms')
                ->where('reference_number_normalized', $reference)
                ->where('amount_minor', $order->payable_amount_minor)
                ->whereIn('processing_status', ['awaiting_second_signal', 'matched', 'unmatched', 'late_payment'])
                ->whereHas('device', fn ($query) => $query->where('merchant_id', $device->merchant_id)->whereNull('revoked_at')->where('attestation_status', 'verified'))
                ->whereBetween('occurred_at', [
                    $signal->occurred_at->copy()->subSeconds(config('gateway.matching.signal_grace_seconds')),
                    $signal->occurred_at->copy()->addSeconds(config('gateway.matching.signal_grace_seconds')),
                ])
                ->where('occurred_at', '>=', $order->created_at)
                ->where('occurred_at', '<=', $order->expires_at)
                ->first();

            if ($counterpart === null) {
                if ($order->status === 'pending') {
                    $order->update(['status' => 'detected']);
                    $this->events->append($order, 'order.detected', ['signal_id' => $signal->id]);
                }
                $signal->update(['processing_status' => 'awaiting_second_signal']);
                $this->attempt($order, $payment, $signal, 'pending', 'missing_second_signal');

                return ['accepted' => true, 'status' => 'detected', 'reason' => 'missing_second_signal', 'signal_id' => $signal->id];
            }

            if (! $order->isOpen()) {
                $signal->update(['processing_status' => 'duplicate_payment']);
                $this->attempt($order, $payment, $signal, 'rejected', 'order_not_open');

                return ['accepted' => true, 'status' => 'duplicate', 'reason' => 'order_not_open', 'signal_id' => $signal->id];
            }

            if (! $this->withinAutoConfirmCaps($order)) {
                $signal->update(['processing_status' => 'needs_review', 'failure_reason' => 'auto_confirm_cap']);

                return ['accepted' => true, 'status' => 'needs_review', 'reason' => 'auto_confirm_cap', 'signal_id' => $signal->id];
            }

            $order->update([
                'status' => 'paid',
                'confirmation_method' => 'automatic',
                'paid_at' => now(),
            ]);
            $payment->update(['status' => 'confirmed']);
            $signal->update(['processing_status' => 'matched']);
            $counterpart->update(['processing_status' => 'matched']);
            Claim::query()
                ->where('reference_number_hash', $referenceHash)
                ->where('status', 'open')
                ->get()->each->update(['status' => 'matched', 'resolved_at' => now()]);
            $this->events->append($order, 'order.paid', [
                'confirmation_method' => 'automatic',
                'reference_number_hash' => $referenceHash,
                'amount_minor' => $order->payable_amount_minor,
            ]);
            $this->attempt($order, $payment, $signal, 'confirmed', 'confirmed');
            $this->health->recordHealthyConfirmation($device->merchant, $order->id);

            return ['accepted' => true, 'status' => 'paid', 'reason' => 'confirmed', 'signal_id' => $signal->id, 'order_id' => $order->public_id];
        });
    }

    /**
     * Resolve a customer claim using the same two-source rules as automatic matching.
     *
     * @return array{status:string,message:string}
     */
    public function resolveClaim(Order $order, string $reference, bool $retry = false): array
    {
        $reference = Money::assertReference($reference);
        $referenceHash = Money::referenceHash($reference);

        return DB::transaction(function () use ($order, $reference, $referenceHash, $retry): array {
            Merchant::whereKey($order->merchant_id)->lockForUpdate()->firstOrFail();
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status === 'paid') {
                Claim::where('order_id', $lockedOrder->id)->where('reference_number_hash', $referenceHash)->where('status', 'open')->get()->each->update(['status' => 'matched', 'resolved_at' => now()]);

                return ['status' => 'rejected', 'message' => 'This order is already resolved.'];
            }

            $this->lockReference($reference);
            $claim = Claim::where('reference_number_hash', $referenceHash)->first();
            if ($claim && (! $retry || $claim->order_id !== $lockedOrder->id || $claim->status !== 'open')) {
                return ['status' => 'rejected', 'message' => 'We could not match that reference to this order.'];
            }
            $claim ??= Claim::create([
                'order_id' => $lockedOrder->id, 'reference_number_hash' => $referenceHash,
                'status' => 'open', 'attempt_count' => 1, 'opened_at' => now(),
            ]);

            $payment = Payment::query()
                ->where('reference_number_normalized', $reference)
                ->lockForUpdate()
                ->first();
            if ($payment === null) {
                return ['status' => 'open', 'message' => 'We will keep checking for the payment.'];
            }

            if ($payment->merchant_id !== $lockedOrder->merchant_id || ($payment->order_id !== null && $payment->order_id !== $lockedOrder->id)) {
                $claim->update(['status' => 'rejected', 'resolved_at' => now()]);

                return ['status' => 'rejected', 'message' => 'We could not match that reference to this order.'];
            }

            if ($payment->first_seen_at->lt($lockedOrder->created_at)) {
                $claim->update(['status' => 'rejected', 'resolved_at' => now()]);

                return ['status' => 'rejected', 'message' => 'We could not match that reference to this order.'];
            }

            if ($payment->amount_minor !== $lockedOrder->payable_amount_minor) {
                $claim->update(['status' => 'matched', 'resolved_at' => now()]);
                $lockedOrder->update(['status' => 'needs_review']);
                if (! $lockedOrder->payment()->exists()) {
                    $payment->update(['order_id' => $lockedOrder->id, 'status' => 'needs_review']);
                }
                Dispute::create([
                    'order_id' => $lockedOrder->id,
                    'claim_id' => $claim->id,
                    'claimed_reference_hash' => $referenceHash,
                    'failure_reasons' => ['amount_mismatch'],
                    'status' => 'open',
                    'created_at' => now(),
                ]);

                $this->events->append($lockedOrder, 'order.needs_review', ['reason' => 'amount_mismatch']);

                return ['status' => 'needs_review', 'message' => 'Your payment needs a merchant review.'];
            }

            $signals = PaymentSignal::query()
                ->where('reference_number_normalized', $reference)
                ->where('amount_minor', $lockedOrder->payable_amount_minor)
                ->whereIn('processing_status', ['awaiting_second_signal', 'matched', 'unmatched', 'late_payment'])
                ->where('occurred_at', '>=', $lockedOrder->created_at)
                ->whereHas('device', fn ($query) => $query->where('merchant_id', $lockedOrder->merchant_id)->whereNull('revoked_at')->where('attestation_status', 'verified'))
                ->get();
            $pair = null;
            foreach ($signals->where('source', 'sms') as $sms) {
                foreach ($signals->where('source', 'notification') as $notification) {
                    if (abs($sms->occurred_at->timestamp - $notification->occurred_at->timestamp) <= config('gateway.matching.signal_grace_seconds')) {
                        $pair = collect([$sms, $notification]);
                        break 2;
                    }
                }
            }
            if ($pair === null) {
                return ['status' => 'open', 'message' => 'We will keep checking for the second bank confirmation.'];
            }

            if ($pair->contains(fn ($signal) => $signal->occurred_at->gt($lockedOrder->expires_at)) || in_array($lockedOrder->status, ['expired', 'late_payment'], true)) {
                $claim->update(['status' => 'matched', 'resolved_at' => now()]);
                if ($lockedOrder->status === 'late_payment') {
                    return ['status' => 'late_payment', 'message' => 'Your payment needs merchant review.'];
                }
                Dispute::firstOrCreate(['order_id' => $lockedOrder->id, 'status' => 'open'], ['failure_reasons' => ['late_payment'], 'created_at' => now()]);
                $lockedOrder->update(['status' => 'late_payment', 'late_payment_at' => now()]);
                $payment->update(['order_id' => $lockedOrder->id, 'status' => 'late']);
                $this->events->append($lockedOrder, 'payment.late', ['reference_number_hash' => $referenceHash]);

                return ['status' => 'late_payment', 'message' => 'Your payment arrived after the order expired.'];
            }

            if (! $this->withinAutoConfirmCaps($lockedOrder)) {
                return ['status' => 'needs_review', 'message' => 'Your payment needs a merchant review.'];
            }

            $claim->update(['status' => 'matched', 'resolved_at' => now()]);
            $payment->update(['order_id' => $lockedOrder->id, 'status' => 'confirmed']);
            $lockedOrder->update(['status' => 'paid', 'confirmation_method' => 'claim', 'paid_at' => now()]);
            $pair->each->update(['processing_status' => 'matched']);
            $this->health->recordHealthyConfirmation($lockedOrder->merchant, $lockedOrder->id);
            $this->events->append($lockedOrder, 'order.paid', [
                'confirmation_method' => 'claim',
                'reference_number_hash' => $referenceHash,
            ]);

            return ['status' => 'confirmed', 'message' => 'Payment confirmed.'];
        });
    }

    public function manualConfirm(Order $order, string $reference, string $reason): void
    {
        DB::transaction(function () use ($order, $reference, $reason): void {
            Merchant::whereKey($order->merchant_id)->lockForUpdate()->firstOrFail();
            $reference = Money::assertReference($reference);
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['pending', 'detected', 'needs_review', 'expired', 'late_payment'], true)) {
                throw new \RuntimeException('ORDER_NOT_OPEN');
            }

            $this->lockReference($reference);
            $payment = Payment::query()
                ->where('reference_number_normalized', $reference)
                ->lockForUpdate()
                ->first();
            if ($payment !== null && ($payment->merchant_id !== $order->merchant_id || ($payment->order_id !== null && $payment->order_id !== $order->id))) {
                throw new \RuntimeException('REFERENCE_ALREADY_USED');
            }

            $payment ??= Payment::create([
                'merchant_id' => $order->merchant_id,
                'reference_number_hash' => Money::referenceHash($reference),
                'reference_number_normalized' => $reference,
                'amount_minor' => $order->payable_amount_minor,
                'currency' => $order->currency,
                'first_seen_at' => now(),
                'status' => 'confirmed',
            ]);
            if ($payment->amount_minor !== $order->payable_amount_minor) {
                throw new \RuntimeException('AMOUNT_MISMATCH');
            }

            $payment->update(['merchant_id' => $order->merchant_id, 'order_id' => $order->id, 'status' => 'confirmed']);
            $order->update(['status' => 'paid', 'confirmation_method' => 'manual_confirmed', 'paid_at' => now()]);
            $this->events->append($order, 'order.manual_confirmed', [
                'reference_number_hash' => $payment->reference_number_hash,
                'reason' => $reason,
            ]);
        });
    }

    private function withinAutoConfirmCaps(Order $order): bool
    {
        // Serialize confirmations for this merchant so concurrent orders share one daily budget.
        $merchant = Merchant::query()->lockForUpdate()->findOrFail($order->merchant_id);
        $settings = $this->health->settings($merchant);
        $todayTotal = $merchant->orders()->where('status', 'paid')->where('paid_at', '>=', now()->startOfDay())->sum('payable_amount_minor');
        if ($order->payable_amount_minor <= $settings->auto_confirm_order_cap_minor
            && $todayTotal + $order->payable_amount_minor <= $settings->auto_confirm_day_cap_minor) {
            return true;
        }

        $order->update(['status' => 'needs_review']);
        Dispute::firstOrCreate(['order_id' => $order->id, 'status' => 'open'], [
            'failure_reasons' => ['auto_confirm_cap'], 'created_at' => now(),
        ]);
        if ($order->wasChanged('status')) {
            $this->events->append($order, 'order.needs_review', ['reason' => 'auto_confirm_cap']);
        }

        return false;
    }

    private function findCandidateOrder(Device $device, int $amount, mixed $occurredAt): ?Order
    {
        return Order::query()
            ->where('merchant_id', $device->merchant_id)
            ->whereIn('status', ['pending', 'detected', 'expired'])
            ->where('created_at', '<=', $occurredAt)
            ->where(function ($query) use ($amount): void {
                $query->where('payable_amount_minor', $amount)
                    ->orWhere(function ($query) use ($amount): void {
                        $query->where('requested_amount_minor', '<=', $amount)
                            ->where('requested_amount_minor', '>', $amount - 100);
                    });
            })
            ->orderByRaw('CASE WHEN payable_amount_minor = ? THEN 0 ELSE 1 END', [$amount])
            ->latest('created_at')
            ->first();
    }

    private function attempt(?Order $order, ?Payment $payment, ?PaymentSignal $signal, string $result, string $reason): void
    {
        MatchAttempt::create([
            'order_id' => $order?->id,
            'payment_id' => $payment?->id,
            'signal_id' => $signal?->id,
            'result' => $result,
            'reason_code' => $reason,
            'evaluated_at' => now(),
        ]);
    }

    private function lockReference(string $reference): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$reference]);
        }
    }

    private function failProbeIfNeeded(Order $order): void
    {
        $breaker = $order->merchant->circuitBreaker;
        if ($breaker?->probe_order_id !== $order->id) {
            return;
        }

        $breaker->update([
            'state' => 'open',
            'cooldown_until' => now()->addMinutes(config('gateway.breaker.cooldown_minutes')),
            'probe_order_id' => null,
        ]);
    }
}
