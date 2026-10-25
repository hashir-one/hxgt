<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvent extends Model
{
    protected $fillable = ['order_id', 'sequence_number', 'event_type', 'payload'];

    protected function casts(): array
    {
        return ['sequence_number' => 'integer', 'payload' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
