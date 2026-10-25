<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'merchant_id', 'order_event_id', 'webhook_endpoint_id', 'attempt_number', 'request_timestamp',
        'response_status', 'response_body_hash', 'source_ip', 'next_retry_at',
        'delivered_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer', 'request_timestamp' => 'datetime',
            'next_retry_at' => 'datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrderEvent::class, 'order_event_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
