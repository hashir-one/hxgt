<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    protected $fillable = [
        'order_id', 'claim_id', 'claimed_reference_hash', 'failure_reasons',
        'status', 'assigned_to', 'merchant_decision', 'merchant_reason',
        'admin_decision', 'created_at', 'resolved_at', 'escalated_at',
    ];

    protected function casts(): array
    {
        return ['failure_reasons' => 'array', 'created_at' => 'datetime', 'resolved_at' => 'datetime', 'escalated_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
