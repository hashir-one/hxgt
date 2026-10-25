<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'public_id', 'merchant_id', 'merchant_order_id', 'requested_amount_minor',
        'payable_amount_minor', 'slot_paise', 'currency', 'status',
        'confirmation_method', 'checkout_token_hash', 'return_url', 'metadata',
        'expires_at', 'paid_at', 'expired_at', 'late_payment_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount_minor' => 'integer',
            'payable_amount_minor' => 'integer',
            'slot_paise' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
            'late_payment_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->public_id ??= (string) Str::uuid();
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function amountReservation()
    {
        return $this->hasOne(AmountReservation::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'detected', 'needs_review'], true);
    }
}
