<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEndpoint extends Model
{
    protected $fillable = ['merchant_id', 'url', 'secret_encrypted', 'status', 'last_tested_at'];

    protected function casts(): array
    {
        return ['secret_encrypted' => 'encrypted', 'last_tested_at' => 'datetime'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
