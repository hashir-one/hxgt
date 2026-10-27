<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PairingRecovery extends Model
{
    protected $fillable = [
        'public_id', 'merchant_id', 'requested_by', 'public_key', 'spki_sha256',
        'origin', 'nonce', 'expires_at', 'consumed_at', 'outcome',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
