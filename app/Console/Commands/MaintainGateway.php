<?php

namespace App\Console\Commands;

use App\Domain\Devices\GatewayHealthService;
use App\Domain\Payments\PaymentMatchingService;
use App\Mail\OperationalAlertMail;
use App\Models\Claim;
use App\Models\Dispute;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentSignal;
use App\Models\User;
use App\Support\AlertService;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MaintainGateway extends Command
{
    protected $signature = 'gateway:maintain';

    protected $description = 'Recover claims, escalate disputes, monitor devices and deliver operational alerts';

    public function handle(GatewayHealthService $health, PaymentMatchingService $matching, AlertService $alerts): int
    {
        PaymentSignal::where('processing_status', 'awaiting_second_signal')->whereNull('fault_recorded_at')->where('created_at', '<=', now()->subSeconds(config('gateway.matching.signal_grace_seconds')))->orderBy('id')->chunkById(100, function ($signals) use ($health): void {
            foreach ($signals as $signal) {
                DB::transaction(function () use ($signal, $health): void {
                    Merchant::whereKey($signal->device->merchant_id)->lockForUpdate()->firstOrFail();
                    $locked = PaymentSignal::whereKey($signal->id)->lockForUpdate()->firstOrFail();
                    if ($locked->fault_recorded_at || $locked->processing_status !== 'awaiting_second_signal') {
                        return;
                    }
                    // Keep evidence eligible for delayed dual-source recovery.
                    $locked->forceFill(['fault_recorded_at' => now()])->save();
                    $health->recordFault($locked->device->merchant, 'missing_second_signal', ['signal_id' => $locked->id]);
                    $payment = Payment::where('merchant_id', $locked->device->merchant_id)->where('reference_number_hash', $locked->reference_number_hash)->first();
                    if ($payment?->order && $payment->order->status !== 'paid') {
                        Dispute::firstOrCreate(['order_id' => $payment->order_id, 'status' => 'open'], ['failure_reasons' => ['missing_second_signal'], 'created_at' => now()]);
                    }
                });
            }
        });
        Claim::with('order')->where('status', 'open')->orderBy('id')->chunkById(100, function ($claims) use ($matching): void {
            foreach ($claims as $claim) {
                if ($claim->opened_at->lt(now()->subHours(config('gateway.claims.open_hours')))) {
                    $claim->update(['status' => 'expired', 'resolved_at' => now()]);
                    Dispute::firstOrCreate(['order_id' => $claim->order_id, 'status' => 'open'], ['claim_id' => $claim->id, 'failure_reasons' => ['claim_expired'], 'created_at' => now()]);

                    continue;
                }
                $payment = Payment::where('merchant_id', $claim->order->merchant_id)->where('reference_number_hash', $claim->reference_number_hash)->first();
                if ($payment) {
                    $matching->resolveClaim($claim->order, $payment->reference_number_normalized, true);
                }
            }
        });
        Dispute::with('order.merchant.settings')->where('status', 'open')->whereNull('escalated_at')->orderBy('id')->chunkById(100, function ($disputes) use ($alerts): void {
            foreach ($disputes as $dispute) {
                if ($dispute->created_at->gt(now()->subHours($dispute->order->merchant->settings?->dispute_escalation_hours ?? 24))) {
                    continue;
                }
                $dispute->update(['escalated_at' => now()]);
                app(AuditLogger::class)->record('dispute.escalated', $dispute->order->merchant_id, Dispute::class, $dispute->id);
                $alerts->raise($dispute->order->merchant_id, 'dispute.escalated', 'Dispute '.$dispute->id.' requires administrator attention.', 'dispute:'.$dispute->id);
            }
        });
        Merchant::where('status', 'active')->orderBy('id')->chunkById(100, function ($merchants) use ($health, $alerts): void {
            foreach ($merchants as $merchant) {
                $previous = $merchant->devices()->where('status', 'online')->count();
                $health->refreshDeviceStates($merchant);
                if ($previous && ! $merchant->devices()->where('status', 'online')->whereNull('revoked_at')->exists()) {
                    $alerts->raise($merchant->id, 'device.offline', 'All capture devices are unavailable. New payments are paused.', 'offline:'.$merchant->id.':'.now()->format('YmdH'));
                }
                $this->checkAnomalies($merchant, $alerts);
            }
        });
        DB::table('operational_alerts')->whereNull('resolved_at')->where(fn ($query) => $query->whereNull('emailed_at')->orWhere('emailed_at', '<=', now()->subHours(4)))->orderBy('id')->chunkById(100, function ($records): void {
            foreach ($records as $alert) {
                $recipients = User::whereNull('disabled_at')->where(fn ($query) => $query->where('role', 'admin')->orWhere(fn ($q) => $q->where('role', 'owner')->where('merchant_id', $alert->merchant_id)))->pluck('email');
                foreach ($recipients as $email) {
                    Mail::to($email)->queue(new OperationalAlertMail($alert->type, $alert->message));
                }
                DB::table('operational_alerts')->where('id', $alert->id)->update([
                    'emailed_at' => now(),
                    'resolved_at' => in_array($alert->type, ['device.added', 'device.revoked'], true) ? now() : null,
                ]);
            }
        });
        DB::table('api_nonces')->where('expires_at', '<', now())->delete();
        DB::table('device_nonces')->where('expires_at', '<', now())->delete();
        DB::table('otp_challenges')->where('expires_at', '<', now()->subDays(7))->delete();

        return self::SUCCESS;
    }

    private function checkAnomalies(Merchant $merchant, AlertService $alerts): void
    {
        $window = max(1, (int) config('gateway.anomalies.window_minutes'));
        $query = Order::where('merchant_id', $merchant->id)->where('status', 'paid')->where('paid_at', '>=', now()->subMinutes($window));
        $count = (clone $query)->count();
        if ($count === 0) {
            return;
        }
        $key = $merchant->id.':'.intdiv(now()->timestamp, $window * 60);
        if ($count >= config('gateway.anomalies.payment_count')) {
            $alerts->raise($merchant->id, 'payment.velocity', $count.' payments confirmed within '.$window.' minutes. Review unexpected activity.', 'velocity:'.$key);
        }
        $tinyCount = (clone $query)->where('payable_amount_minor', '<=', config('gateway.anomalies.tiny_amount_minor'))->count();
        if ($tinyCount >= config('gateway.anomalies.tiny_count')) {
            $alerts->raise($merchant->id, 'payment.tiny_burst', $tinyCount.' small payments confirmed within '.$window.' minutes.', 'tiny:'.$key);
        }
        $local = now()->timezone(config('gateway.anomalies.timezone'));
        if (in_array($local->hour, config('gateway.anomalies.quiet_hours'), true)) {
            $alerts->raise($merchant->id, 'payment.quiet_hours', 'Payments were confirmed during configured quiet hours. Review if unexpected.', 'quiet:'.$merchant->id.':'.$local->format('YmdH'));
        }
    }
}
