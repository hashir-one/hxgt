<?php

namespace App\Domain\Devices;

use App\Models\Approval;
use App\Models\CircuitBreaker;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class ApprovalService
{
    public function request(Merchant $merchant, User $actor, string $type, int $target): Approval
    {
        abort_unless(in_array($type, ['additional_device', 'migration', 'breaker_reopen'], true), 422);
        abort_if($type === 'breaker_reopen' && ! $actor->isAdmin(), 403);

        return DB::transaction(function () use ($merchant, $actor, $type, $target): Approval {
            Merchant::whereKey($merchant->id)->lockForUpdate()->firstOrFail();
            $approval = Approval::firstOrCreate(['merchant_id' => $merchant->id, 'type' => $type, 'target_id' => $target, 'status' => 'pending'], [
                'target_type' => match ($type) {
                    'migration' => Device::class, 'breaker_reopen' => CircuitBreaker::class, default => 'device_slot'
                },
                'requested_by' => $actor->id,
                'approved_by' => $type === 'breaker_reopen' ? $actor->id : null,
            ]);
            app(AuditLogger::class)->record('approval.requested', $merchant->id, Approval::class, $approval->id);

            return $approval;
        });
    }

    public function vote(Approval $approval, User $admin, bool $accept, string $reason): void
    {
        abort_unless($admin->isAdmin(), 403);
        DB::transaction(function () use ($approval, $admin, $accept, $reason): void {
            Merchant::whereKey($approval->merchant_id)->lockForUpdate()->firstOrFail();
            $locked = Approval::whereKey($approval->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 422, 'Approval already resolved');
            abort_if((int) $locked->requested_by === $admin->id || (int) $locked->approved_by === $admin->id, 403, 'A different administrator must approve');
            if (! $accept) {
                $locked->update(['status' => 'rejected', 'metadata' => ['reason' => $reason]]);
            } elseif (in_array($locked->type, ['migration', 'breaker_reopen'], true) && ! $locked->approved_by) {
                $locked->update(['approved_by' => $admin->id]);
            } else {
                $locked->update(['status' => 'approved', $locked->approved_by ? 'second_approved_by' : 'approved_by' => $admin->id]);
                if ($locked->type === 'breaker_reopen') {
                    $breaker = CircuitBreaker::where('merchant_id', $locked->merchant_id)->lockForUpdate()->findOrFail($locked->target_id);
                    abort_unless($breaker->merchant->status === 'active' && in_array($breaker->state, ['open', 'half_open'], true), 422);
                    $breaker->update(['state' => 'half_open', 'cooldown_until' => null, 'probe_order_id' => null]);
                    $locked->update(['status' => 'executed']);
                }
            }
            app(AuditLogger::class)->record($accept ? 'approval.approved' : 'approval.rejected', $locked->merchant_id, Approval::class, $locked->id, $reason);
        });
    }
}
