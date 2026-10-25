<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Device extends Model
{
    protected $fillable = [
        'public_id', 'merchant_id', 'slot_number', 'label', 'public_key',
        'attestation_status', 'attestation_digest', 'status', 'last_heartbeat_at',
        'sms_listener_healthy', 'notification_listener_healthy',
        'consecutive_good_heartbeats', 'revoked_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'slot_number' => 'integer', 'last_heartbeat_at' => 'datetime',
            'sms_listener_healthy' => 'boolean', 'notification_listener_healthy' => 'boolean',
            'consecutive_good_heartbeats' => 'integer', 'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $device): void {
            $device->public_id ??= (string) Str::uuid();
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(PaymentSignal::class);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }
}
