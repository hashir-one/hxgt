<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CircuitBreaker extends Model
{
    protected $fillable = [
        'merchant_id', 'state', 'fault_count', 'consecutive_faults', 'opened_at',
        'cooldown_until', 'trip_count_24h', 'last_fault_at',
        'trip_window_started_at', 'probe_order_id',
    ];

    protected function casts(): array
    {
        return [
            'fault_count' => 'integer', 'consecutive_faults' => 'integer',
            'opened_at' => 'datetime', 'cooldown_until' => 'datetime',
            'trip_count_24h' => 'integer', 'last_fault_at' => 'datetime',
            'trip_window_started_at' => 'datetime',
            'probe_order_id' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
