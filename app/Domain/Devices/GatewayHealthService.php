<?php

namespace App\Domain\Devices;

use App\Models\CircuitBreaker;
use App\Models\Device;
use App\Models\FaultEvent;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Support\AlertService;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class GatewayHealthService
{
    public function settings(Merchant $merchant): MerchantSetting
    {
        return $merchant->settings()->firstOrCreate([], [
            'order_expiry_minutes' => config('gateway.order_expiry_minutes'),
            'heartbeat_timeout_seconds' => config('gateway.device.heartbeat_timeout_seconds'),
            'healthy_heartbeats_to_resume' => config('gateway.device.healthy_heartbeats_to_resume'),
            'breaker_consecutive_threshold' => config('gateway.breaker.consecutive_threshold'),
            'breaker_rolling_threshold' => config('gateway.breaker.rolling_threshold'),
            'breaker_window_minutes' => config('gateway.breaker.rolling_window_minutes'),
            'breaker_cooldown_minutes' => config('gateway.breaker.cooldown_minutes'),
        ]);
    }

    public function breaker(Merchant $merchant): CircuitBreaker
    {
        return $merchant->circuitBreaker()->firstOrCreate([], ['state' => 'closed']);
    }

    public function canAcceptOrders(Merchant $merchant): bool
    {
        return DB::transaction(function () use ($merchant): bool {
            $merchant = Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            if ($merchant->status !== 'active') {
                return false;
            }

            $this->refreshDeviceStates($merchant);
            $breaker = $this->breaker($merchant);

            if ($breaker->state === 'open') {
                if ($breaker->cooldown_until?->isPast()) {
                    if ($breaker->trip_count_24h >= 2 && $breaker->trip_window_started_at?->addDay()->isFuture()) {
                        return false;
                    }

                    $breaker->update(['state' => 'half_open']);
                } else {
                    return false;
                }
            }

            if ($breaker->state === 'half_open' && $breaker->probe_order_id !== null) {
                return false;
            }

            return $merchant->devices()
                ->whereNull('revoked_at')
                ->where('attestation_status', 'verified')
                ->where('status', 'online')
                ->where('sms_listener_healthy', true)
                ->where('notification_listener_healthy', true)
                ->exists();
        });
    }

    public function refreshDeviceStates(Merchant $merchant): void
    {
        DB::transaction(function () use ($merchant): void {
            Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            $settings = $this->settings($merchant);
            $timeout = $settings->heartbeat_timeout_seconds;
            $cutoff = now()->subSeconds($timeout);

            $merchant->devices()->whereNull('revoked_at')->each(function (Device $device) use ($cutoff, $settings): void {
                if ($device->attestation_status !== 'verified') {
                    $device->update(['status' => 'pending_review']);

                    return;
                }

                if ($device->last_heartbeat_at === null || $device->last_heartbeat_at->lt($cutoff)) {
                    $device->update(['status' => 'offline', 'consecutive_good_heartbeats' => 0]);

                    return;
                }

                $healthy = $device->sms_listener_healthy && $device->notification_listener_healthy;
                if (! $healthy) {
                    $device->update(['status' => 'degraded']);

                    return;
                }

                if ($device->status !== 'online' && $device->consecutive_good_heartbeats < $settings->healthy_heartbeats_to_resume) {
                    return;
                }

                $device->update(['status' => 'online']);
            });
        });
    }

    public function recordFault(Merchant $merchant, string $type, array $details = []): void
    {
        DB::transaction(function () use ($merchant, $type, $details): void {
            Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            FaultEvent::create([
                'merchant_id' => $merchant->id,
                'type' => $type,
                'severity' => 'system',
                'details' => $details,
                'created_at' => now(),
            ]);

            $breaker = CircuitBreaker::query()->where('merchant_id', $merchant->id)->lockForUpdate()->first();
            if ($breaker === null) {
                $breaker = CircuitBreaker::create(['merchant_id' => $merchant->id, 'state' => 'closed']);
                $breaker = CircuitBreaker::query()->lockForUpdate()->findOrFail($breaker->id);
            }

            $settings = $this->settings($merchant);
            $now = now();
            $tripWindowStartedAt = $breaker->trip_window_started_at;
            $tripCount = $breaker->trip_count_24h;
            if ($tripWindowStartedAt === null || $tripWindowStartedAt->addDay()->isPast()) {
                $tripWindowStartedAt = $now;
                $tripCount = 0;
            }

            $windowStart = $now->copy()->subMinutes($settings->breaker_window_minutes);
            $recentFaults = $merchant->faultEvents()->where('created_at', '>=', $windowStart)->count();
            $consecutive = $breaker->consecutive_faults + 1;
            $shouldOpen = $breaker->state === 'half_open' || ($breaker->state !== 'open'
                && ($consecutive >= $settings->breaker_consecutive_threshold || $recentFaults >= $settings->breaker_rolling_threshold));
            $openedAt = $shouldOpen ? $now : $breaker->opened_at;

            if ($shouldOpen) {
                app(AlertService::class)->raise($merchant->id, 'breaker.opened', 'Payments paused after repeated system faults.', 'breaker:'.$breaker->id.':'.$now->timestamp);
                app(AuditLogger::class)->record('breaker.opened', $merchant->id, CircuitBreaker::class, $breaker->id, metadata: ['fault_type' => $type]);
            }
            $breaker->update([
                'fault_count' => $breaker->fault_count + 1,
                'consecutive_faults' => $consecutive,
                'last_fault_at' => $now,
                'state' => $shouldOpen ? 'open' : $breaker->state,
                'opened_at' => $openedAt,
                'cooldown_until' => $shouldOpen ? $now->copy()->addMinutes($settings->breaker_cooldown_minutes) : $breaker->cooldown_until,
                'trip_count_24h' => $shouldOpen ? $tripCount + 1 : $tripCount,
                'trip_window_started_at' => $tripWindowStartedAt,
                'probe_order_id' => $shouldOpen ? null : $breaker->probe_order_id,
            ]);
        });
    }

    public function recordHealthyConfirmation(Merchant $merchant, int $orderId): void
    {
        DB::transaction(function () use ($merchant, $orderId): void {
            Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            $breaker = $this->breaker($merchant);
            if ($breaker->state === 'half_open' && $breaker->probe_order_id === $orderId) {
                $breaker->update(['state' => 'closed', 'consecutive_faults' => 0, 'fault_count' => 0, 'cooldown_until' => null, 'probe_order_id' => null]);
            } elseif ($breaker->state === 'closed') {
                $breaker->update(['consecutive_faults' => 0]);
            }
        });
    }
}
