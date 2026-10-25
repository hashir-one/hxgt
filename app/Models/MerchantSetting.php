<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantSetting extends Model
{
    protected $fillable = [
        'merchant_id', 'order_expiry_minutes', 'heartbeat_timeout_seconds',
        'healthy_heartbeats_to_resume', 'breaker_consecutive_threshold',
        'breaker_rolling_threshold', 'breaker_window_minutes',
        'breaker_cooldown_minutes', 'dispute_escalation_hours',
        'auto_confirm_order_cap_minor', 'auto_confirm_day_cap_minor', 'regional_locale',
    ];

    protected function casts(): array
    {
        return [
            'order_expiry_minutes' => 'integer', 'heartbeat_timeout_seconds' => 'integer',
            'healthy_heartbeats_to_resume' => 'integer', 'breaker_consecutive_threshold' => 'integer',
            'breaker_rolling_threshold' => 'integer', 'breaker_window_minutes' => 'integer',
            'breaker_cooldown_minutes' => 'integer', 'dispute_escalation_hours' => 'integer',
            'auto_confirm_order_cap_minor' => 'integer', 'auto_confirm_day_cap_minor' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
