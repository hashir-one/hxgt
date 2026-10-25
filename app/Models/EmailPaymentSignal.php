<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailPaymentSignal extends Model
{
    protected $fillable = [
        'merchant_id', 'message_id_hash', 'sender_email', 'subject_hash',
        'amount_minor', 'reference_number_normalized', 'reference_number_hash',
        'occurred_at', 'authentication', 'parser_rule_version', 'order_id',
        'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'authentication' => 'array',
            'parser_rule_version' => 'integer',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
