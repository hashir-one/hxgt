<?php

namespace App\Jobs;

use App\Domain\Webhooks\WebhookService;
use App\Models\OrderEvent;
use App\Models\OutboxMessage;
use App\Support\AlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class DeliverOrderEventWebhook implements ShouldQueue
{
    use Dispatchable, \Illuminate\Bus\Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $outboxMessageId) {}

    public function handle(WebhookService $webhooks): void
    {
        $lock = Cache::lock('webhook-outbox:'.$this->outboxMessageId, 60);
        if (! $lock->get()) {
            return;
        }
        try {
            $this->deliver($webhooks);
        } finally {
            $lock->release();
        }
    }

    private function deliver(WebhookService $webhooks): void
    {
        $outbox = OutboxMessage::find($this->outboxMessageId);
        if ($outbox === null || $outbox->processed_at !== null) {
            return;
        }

        $event = OrderEvent::with('order.merchant')->find($outbox->aggregate_id);
        if ($event === null) {
            $outbox->update(['processed_at' => now()]);

            return;
        }

        $endpoints = $event->order->merchant->webhookEndpoints()->where('status', 'active')->get();
        $failed = false;
        foreach ($endpoints as $endpoint) {
            $delivery = $webhooks->deliver($event, $endpoint);
            $failed = $failed || $delivery->delivered_at === null;
        }

        if ($failed && $outbox->attempts >= 4) {
            app(AlertService::class)->raise($event->order->merchant_id, 'webhook.failed', 'Repeated webhook delivery failures for order '.$event->order->public_id, 'webhook:'.$event->id);
        }
        $outbox->update([
            'processed_at' => $failed ? null : now(),
            'available_at' => $failed ? now()->addMinutes(min(60, 2 ** min(6, $outbox->attempts + 1))) : $outbox->available_at,
            'attempts' => $outbox->attempts + 1,
            'last_error' => $failed ? 'One or more webhook deliveries failed.' : null,
        ]);
    }
}
