<?php

namespace App\Http\Controllers\Api;

use App\Domain\Orders\OrderService;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Support\PaymentProof;
use Illuminate\Http\Request;
use RuntimeException;

class OrderController extends Controller
{
    public function store(Request $request, OrderService $orders)
    {
        $merchant = $request->attributes->get('merchant');
        $payload = $request->validate([
            'merchant_order_id' => ['required', 'string', 'max:128'],
            'base_amount_minor' => ['required', 'integer', 'min:1', 'max:999999999900'],
            'currency' => ['required', 'in:INR'],
            'return_url' => ['nullable', 'url', 'max:2048'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (! $this->allowedReturnUrl($merchant, $payload['return_url'] ?? null)) {
            return response()->json(['error' => 'RETURN_URL_NOT_ALLOWED'], 422);
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return response()->json(['error' => 'IDEMPOTENCY_KEY_REQUIRED'], 422);
        }

        try {
            return response()->json($orders->create($merchant, $payload, $idempotencyKey), 201);
        } catch (RuntimeException $exception) {
            $status = match ($exception->getMessage()) {
                'GATEWAY_UNAVAILABLE' => 503,
                'BUSY' => 409,
                'IDEMPOTENCY_KEY_REUSED', 'INVALID_AMOUNT' => 422,
                default => 422,
            };

            return response()->json(['error' => $exception->getMessage()], $status);
        }
    }

    public function show(Request $request, string $id, OrderService $orders)
    {
        $merchant = $request->attributes->get('merchant');
        $order = Order::query()->where('merchant_id', $merchant->id)->where('public_id', $id)->firstOrFail();
        $payload = $request->validate(['event_id' => ['sometimes', 'integer', 'min:1']]);
        $data = $orders->serialize($order->load('payment', 'events'));
        if (isset($payload['event_id'])) {
            $event = $order->events()->whereKey($payload['event_id'])->firstOrFail();
            // A delayed paid webhook remains verifiable after a separate refund event.
            // Values still come from the current order; changes fail the webhook proof.
            $data['event_sequence'] = $event->sequence_number;
            $data['verification_hash'] = PaymentProof::hash($data);
        }

        return response()->json($data);
    }

    private function allowedReturnUrl(Merchant $merchant, ?string $returnUrl): bool
    {
        if ($returnUrl === null) {
            return true;
        }

        $parts = parse_url($returnUrl);
        $host = $parts['host'] ?? null;
        $allowed = $merchant->return_url_allowlist ?? [];

        if ($host === null || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && ! app()->environment(['local', 'testing'])) {
            return false;
        }

        return in_array(strtolower($host), array_map('strtolower', $allowed), true);
    }
}
