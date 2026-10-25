<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSignal extends Model
{
    protected $fillable = [
        'device_id', 'source', 'client_signal_id', 'amount_minor',
        'reference_number_normalized', 'reference_number_hash', 'occurred_at',
        'captured_at', 'parser_rule_version', 'source_metadata',
        'processing_status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer', 'occurred_at' => 'datetime', 'captured_at' => 'datetime',
            'parser_rule_version' => 'integer', 'source_metadata' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
