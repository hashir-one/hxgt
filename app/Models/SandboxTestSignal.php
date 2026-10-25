<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SandboxTestSignal extends Model
{
    protected $fillable = [
        'sandbox_test_order_id', 'source', 'amount_minor',
        'reference_number_normalized', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SandboxTestOrder::class, 'sandbox_test_order_id');
    }
}
