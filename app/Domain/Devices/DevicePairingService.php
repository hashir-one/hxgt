<?php

namespace App\Domain\Devices;

use App\Models\Approval;
use App\Models\DevicePairing;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DevicePairingService
{
    public function create(Merchant $merchant, User $user, int $slot, ?int $replaces = null): array
    {
        return DB::transaction(function () use ($merchant, $user, $slot, $replaces): array {
            $merchant = Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            if ($merchant->status !== 'active' || $user->merchant_id !== $merchant->id || $user->role !== 'owner') {
                throw new \RuntimeException('MERCHANT_UNAVAILABLE');
            }
            if ($slot < 1 || $slot > config('gateway.max_devices')) {
                throw new \RuntimeException('INVALID_DEVICE_SLOT');
            }
            $device = $merchant->devices()->where('slot_number', $slot)->whereNull('revoked_at')->first();
            if ($device && $device->id !== $replaces) {
                throw new \RuntimeException('DEVICE_SLOT_IN_USE');
            }
            if ($replaces && (! $device || $device->id !== $replaces)) {
                throw new \RuntimeException('MIGRATION_DEVICE_MISMATCH');
            }
            if ($slot > 1 || $replaces) {
                $approval = Approval::where('merchant_id', $merchant->id)->where('type', $replaces ? 'migration' : 'additional_device')
                    ->where('target_id', $replaces ?? $slot)->where('status', 'approved')->lockForUpdate()->first();
                if (! $approval) {
                    throw new \RuntimeException('ADMIN_APPROVAL_REQUIRED');
                }
                $approval->update(['status' => 'executed']);
            }
            DevicePairing::where('merchant_id', $merchant->id)->where('slot_number', $slot)->whereNull('consumed_at')->get()->each->update(['approval_status' => 'rejected']);
            $token = Str::random(48);
            $pairing = DevicePairing::create([
                'merchant_id' => $merchant->id, 'slot_number' => $slot, 'token_hash' => hash('sha256', $token),
                'requested_by' => $user->id, 'approval_status' => 'approved', 'replaces_device_id' => $replaces,
                'expires_at' => now()->addMinutes(5),
            ]);

            return [$pairing, $token];
        });
    }
}
