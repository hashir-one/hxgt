<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiCredential extends Model
{
    protected $hidden = ['secret_hash', 'signing_secret_encrypted'];

    protected $fillable = [
        'merchant_id', 'name', 'type', 'key_prefix', 'secret_hash', 'scopes',
        'ip_allowlist', 'expires_at', 'revoked_at', 'signing_secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'signing_secret_encrypted' => 'encrypted',
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
