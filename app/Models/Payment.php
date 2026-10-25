<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'merchant_id', 'reference_number_normalized', 'reference_number_hash', 'amount_minor',
        'currency', 'first_seen_at', 'order_id', 'status',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'first_seen_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
