<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Merchant extends Model
{
    protected $fillable = [
        'public_id', 'name', 'slug', 'status', 'upi_vpa', 'support_phone',
        'logo_path', 'default_locale', 'return_url_allowlist',
    ];

    protected function casts(): array
    {
        return ['return_url_allowlist' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $merchant): void {
            $merchant->public_id ??= (string) Str::uuid();
            $merchant->status ??= 'active';
            $merchant->default_locale ??= 'en';
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function circuitBreaker()
    {
        return $this->hasOne(CircuitBreaker::class);
    }

    public function settings()
    {
        return $this->hasOne(MerchantSetting::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function faultEvents(): HasMany
    {
        return $this->hasMany(FaultEvent::class);
    }
}
