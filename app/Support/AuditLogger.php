<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;

final class AuditLogger
{
    public function record(
        string $action,
        ?int $merchantId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $reason = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_type' => auth()->check() ? 'user' : 'system',
            'actor_id' => auth()->id(),
            'merchant_id' => $merchantId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'ip_address' => $request?->ip() ?? request()->ip(),
            'user_agent' => $request?->userAgent() ?? request()->userAgent(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
