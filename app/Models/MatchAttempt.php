<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchAttempt extends Model
{
    protected $fillable = ['order_id', 'payment_id', 'signal_id', 'result', 'reason_code', 'details', 'evaluated_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'evaluated_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(PaymentSignal::class, 'signal_id');
    }
}
