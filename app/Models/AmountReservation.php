<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmountReservation extends Model
{
    protected $fillable = [
        'merchant_id', 'order_id', 'base_amount_minor', 'slot_paise',
        'payable_amount_minor', 'state', 'held_until',
    ];

    protected function casts(): array
    {
        return [
            'base_amount_minor' => 'integer', 'slot_paise' => 'integer',
            'payable_amount_minor' => 'integer', 'held_until' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
