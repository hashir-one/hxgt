<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    protected $fillable = ['type', 'aggregate_id', 'payload', 'available_at', 'processed_at', 'attempts', 'last_error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'processed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
