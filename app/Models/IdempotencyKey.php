<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'merchant_id', 'key', 'request_hash', 'response_type', 'response_id', 'response_payload',
    ];

    protected function casts(): array
    {
        return ['response_payload' => 'array'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
