<?php

namespace App\Domain\Orders;

use App\Domain\Devices\GatewayHealthService;
use App\Models\AmountReservation;
use App\Models\IdempotencyKey;
use App\Models\Merchant;
use App\Models\Order;
use App\Support\Money;
use App\Support\PaymentProof;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class OrderService
{
    public function __construct(
        private readonly GatewayHealthService $health,
        private readonly OrderEventService $events,
    ) {}

    public function create(Merchant $merchant, array $payload, ?string $idempotencyKey = null): array
    {
        $requestHash = Money::requestHash($payload);

        $baseAmount = (int) ($payload['base_amount_minor'] ?? 0);
        if ($baseAmount < 1 || $baseAmount > 999999999900) {
            throw new RuntimeException('INVALID_AMOUNT');
        }

        try {
            return DB::transaction(function () use ($merchant, $payload, $idempotencyKey, $requestHash, $baseAmount): array {
                $lockedMerchant = Merchant::query()->lockForUpdate()->findOrFail($merchant->id);

                if ($idempotencyKey !== null) {
                    $existing = IdempotencyKey::query()
                        ->where('merchant_id', $lockedMerchant->id)
                        ->where('key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        if (! hash_equals($existing->request_hash, $requestHash)) {
                            throw new RuntimeException('IDEMPOTENCY_KEY_REUSED');
                        }

                        return $existing->response_payload;
                    }
                }

                if (! $this->health->canAcceptOrders($lockedMerchant)) {
                    throw new RuntimeException('GATEWAY_UNAVAILABLE');
                }

                $settings = $this->health->settings($lockedMerchant);
                $now = now();
                $heldUntil = $now->copy()->addMinutes($settings->order_expiry_minutes)->addHours(config('gateway.late_payment_quarantine_hours'));

                AmountReservation::query()
                    ->where('merchant_id', $lockedMerchant->id)
                    ->where('held_until', '<=', $now)
                    ->where('state', '!=', 'released')
                    ->update(['state' => 'released']);

                $slot = null;
                for ($candidate = config('gateway.amount_slot_min'); $candidate <= config('gateway.amount_slot_max'); $candidate++) {
                    $taken = AmountReservation::query()
                        ->where('merchant_id', $lockedMerchant->id)
                        ->where('payable_amount_minor', $baseAmount + $candidate)
                        ->where('state', '!=', 'released')
                        ->where('held_until', '>', $now)
                        ->exists();

                    if (! $taken) {
                        $slot = $candidate;
                        break;
                    }
                }

                if ($slot === null) {
                    throw new RuntimeException('BUSY');
                }

                $token = Str::random(64);
                $order = Order::create([
                    'merchant_id' => $lockedMerchant->id,
                    'merchant_order_id' => $payload['merchant_order_id'],
                    'requested_amount_minor' => $baseAmount,
                    'payable_amount_minor' => $baseAmount + $slot,
                    'slot_paise' => $slot,
                    'currency' => $payload['currency'] ?? 'INR',
                    'status' => 'pending',
                    'checkout_token_hash' => hash('sha256', $token),
                    'return_url' => $payload['return_url'] ?? null,
                    'metadata' => $payload['metadata'] ?? null,
                    'expires_at' => $now->copy()->addMinutes($settings->order_expiry_minutes),
                ]);

                AmountReservation::create([
                    'merchant_id' => $lockedMerchant->id,
                    'order_id' => $order->id,
                    'base_amount_minor' => $baseAmount,
                    'slot_paise' => $slot,
                    'payable_amount_minor' => $order->payable_amount_minor,
                    'state' => 'held',
                    'held_until' => $heldUntil,
                ]);

                $breaker = $this->health->breaker($lockedMerchant);
                if ($breaker->state === 'half_open') {
                    $breaker->update(['probe_order_id' => $order->id]);
                }

                $this->events->append($order, 'order.created', [
                    'payable_amount_minor' => $order->payable_amount_minor,
                    'currency' => $order->currency,
                ]);

                $response = $this->serialize($order, $token);

                if ($idempotencyKey !== null) {
                    IdempotencyKey::create([
                        'merchant_id' => $lockedMerchant->id,
                        'key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'response_type' => Order::class,
                        'response_id' => $order->id,
                        'response_payload' => $response,
                    ]);
                }

                return $response;
            });
        } catch (UniqueConstraintViolationException) {
            if ($idempotencyKey !== null) {
                $existing = IdempotencyKey::query()
                    ->where('merchant_id', $merchant->id)
                    ->where('key', $idempotencyKey)
                    ->first();

                if ($existing !== null && hash_equals($existing->request_hash, $requestHash)) {
                    return $existing->response_payload;
                }
            }

            throw new RuntimeException('ORDER_COULD_NOT_BE_CREATED');
        }
    }

    public function serialize(Order $order, ?string $token = null): array
    {
        $token ??= 'redacted';

        $data = [
            'id' => $order->public_id,
            'merchant_order_id' => $order->merchant_order_id,
            'requested_amount_minor' => $order->requested_amount_minor,
            'payable_amount_minor' => $order->payable_amount_minor,
            'currency' => $order->currency,
            'status' => $order->status,
            'confirmation_method' => $order->confirmation_method,
            'checkout_url' => $token === 'redacted' ? null : rtrim(config('gateway.checkout_domain'), '/').route('checkout.show', ['token' => $token], false),
            'expires_at' => $order->expires_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'payment_reference_hash' => $order->payment?->reference_number_hash,
            'event_sequence' => $order->events()->max('sequence_number'),
        ];
        $data['verification_hash'] = PaymentProof::hash($data);

        return $data;
    }
}
