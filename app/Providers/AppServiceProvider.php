<?php

namespace App\Providers;

use App\Models\Approval;
use App\Models\CircuitBreaker;
use App\Models\Claim;
use App\Models\Device;
use App\Models\DevicePairing;
use App\Models\Dispute;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\ParserRule;
use App\Models\Payment;
use App\Models\PaymentSignal;
use App\Support\AlertService;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Merchant::class, Order::class, Device::class, DevicePairing::class, CircuitBreaker::class, Claim::class, Dispute::class, Approval::class, ParserRule::class, Payment::class, PaymentSignal::class] as $model) {
            $model::updated(function (Model $record): void {
                $changes = array_intersect_key($record->getChanges(), array_flip(['status', 'state', 'attestation_status', 'revoked_at', 'confirmation_method', 'approved_by', 'second_approved_by', 'processing_status', 'failure_reason', 'approval_status', 'consumed_at']));
                if ($changes === []) {
                    return;
                }
                $merchantId = $record instanceof Merchant ? $record->id : ($record->merchant_id ?? null);
                if ($record instanceof Claim || $record instanceof Dispute) {
                    $merchantId = $record->order->merchant_id;
                }
                if ($record instanceof PaymentSignal) {
                    $merchantId = $record->device->merchant_id;
                }
                app(AuditLogger::class)->record('state.changed', $merchantId, $record::class, $record->id, metadata: [
                    'before' => array_intersect_key($record->getRawOriginal(), $changes), 'after' => $changes,
                    'device_id' => $record instanceof Device ? $record->id : null,
                ]);
            });
        }
        Device::created(function (Device $device): void {
            app(AlertService::class)->raise($device->merchant_id, 'device.added', 'Capture device '.$device->public_id.' was paired. Payment access still requires verified attestation and healthy listeners.', 'device-added:'.$device->id);
        });
        Device::updated(function (Device $device): void {
            if ($device->wasChanged('revoked_at') && $device->revoked_at) {
                app(AlertService::class)->raise($device->merchant_id, 'device.revoked', 'Capture device '.$device->public_id.' was revoked. Its signing key can no longer upload payment evidence.', 'device-revoked:'.$device->id);
            }
        });
    }
}
