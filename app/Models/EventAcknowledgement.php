<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAcknowledgement extends Model
{
    protected $fillable = ['merchant_id', 'order_event_id', 'api_credential_id', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}
