<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventAcknowledgement;
use App\Models\OrderEvent;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $merchant = $request->attributes->get('merchant');
        $after = max(0, (int) $request->integer('after', 0));

        $events = OrderEvent::query()
            ->where('id', '>', $after)
            ->whereHas('order', fn ($query) => $query->where('merchant_id', $merchant->id))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (OrderEvent $event): array => [
                'id' => $event->id,
                'order_id' => $event->order->public_id,
                'sequence_number' => $event->sequence_number,
                'event_type' => $event->event_type,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $events, 'next_after' => $events->last()['id'] ?? $after]);
    }

    public function acknowledge(Request $request)
    {
        $payload = $request->validate(['event_id' => ['required', 'integer', 'min:1']]);
        $merchant = $request->attributes->get('merchant');
        $event = OrderEvent::query()
            ->whereKey($payload['event_id'])
            ->whereHas('order', fn ($query) => $query->where('merchant_id', $merchant->id))
            ->firstOrFail();

        EventAcknowledgement::updateOrCreate(
            ['merchant_id' => $merchant->id, 'order_event_id' => $event->id],
            ['api_credential_id' => $request->attributes->get('apiCredential')->id, 'acknowledged_at' => now()],
        );

        return response()->json(['acknowledged' => (int) $event->id]);
    }
}
