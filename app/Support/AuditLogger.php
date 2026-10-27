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
        bool $includeRequestContext = true,
    ): AuditLog {
        // Pairing audit hooks must not retain attacker-controlled headers or identifiers.
        $includeRequestContext = $includeRequestContext && ! request()->is('api/device/v1/pair', 'api/device/v1/pair/*', 'panel/devices/recovery/*');

        return AuditLog::create([
            'actor_type' => auth()->check() ? 'user' : 'system',
            'actor_id' => auth()->id(),
            'merchant_id' => $merchantId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'ip_address' => $includeRequestContext ? ($request?->ip() ?? request()->ip()) : null,
            'user_agent' => $includeRequestContext ? ($request?->userAgent() ?? request()->userAgent()) : null,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
