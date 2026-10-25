<?php

namespace App\Domain\Orders;

use App\Jobs\DeliverOrderEventWebhook;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OutboxMessage;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class OrderEventService
{
    public function append(Order $order, string $type, array $payload = []): OrderEvent
    {
        return DB::transaction(function () use ($order, $type, $payload): OrderEvent {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $sequence = ((int) $lockedOrder->events()->max('sequence_number')) + 1;

            $event = $lockedOrder->events()->create([
                'sequence_number' => $sequence,
                'event_type' => $type,
                'payload' => array_merge([
                    'order_id' => $lockedOrder->public_id,
                    'status' => $lockedOrder->status,
                    'amount_minor' => $lockedOrder->payable_amount_minor,
                    'currency' => $lockedOrder->currency,
                    'reference_number_hash' => $lockedOrder->payment?->reference_number_hash,
                    'confirmation_method' => $lockedOrder->confirmation_method,
                ], $payload),
            ]);

            app(AuditLogger::class)->record($type, $lockedOrder->merchant_id, Order::class, $lockedOrder->id, metadata: ['event_id' => $event->id, 'sequence' => $sequence]);

            $outbox = OutboxMessage::create([
                'type' => 'order.event',
                'aggregate_id' => $event->id,
                'payload' => ['event_id' => $event->id, 'order_id' => $lockedOrder->id],
                'available_at' => now(),
                'attempts' => 0,
            ]);

            DeliverOrderEventWebhook::dispatch($outbox->id)->onQueue('events')->afterCommit();

            return $event;
        });
    }
}
