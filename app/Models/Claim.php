<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Claim extends Model
{
    protected $fillable = [
        'order_id', 'reference_number_hash', 'status', 'attempt_count',
        'captcha_passed_at', 'opened_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer', 'captcha_passed_at' => 'datetime',
            'opened_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
