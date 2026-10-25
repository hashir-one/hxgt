<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SandboxTestOrder extends Model
{
    protected $fillable = [
        'public_id', 'admin_user_id', 'merchant_id', 'merchant_order_id',
        'requested_amount_minor', 'payable_amount_minor', 'currency',
        'reference_number_normalized', 'status', 'confirmation_method',
        'expires_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount_minor' => 'integer',
            'payable_amount_minor' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->public_id ??= (string) Str::uuid();
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(SandboxTestSignal::class);
    }
}
