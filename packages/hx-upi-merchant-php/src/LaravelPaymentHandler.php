<?php

namespace HxDev\UpiMerchant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class LaravelPaymentHandler
{
    /**
     * $expected must come from the merchant's stored checkout record.
     * $enqueueFulfillment writes to the merchant outbox in the SAME DB transaction;
     * do not perform external side effects or commit the transaction in this callback.
     */
    public function handle(Request $request, array $expected, callable $enqueueFulfillment): bool
    {
        $verifier = new TwoOfTwoVerifier((string) config('hx-upi.webhook_secret'));
        abort_unless(config('hx-upi.webhook_secret') && $verifier->verifyWebhook($request->getContent(), (string) $request->header('X-UPI-Event-Timestamp'), (string) $request->header('X-UPI-Event-Signature')), 401);
        $event = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_int($event['event_id'] ?? null) && $event['event_id'] > 0 && is_string($event['order_id'] ?? null), 422);
        if (! in_array($event['event_type'] ?? null, ['order.paid', 'order.manual_confirmed'], true)) {
            return false;
        }
        $base = rtrim((string) config('hx-upi.api_url'), '/');
        $parts = parse_url($base);
        abort_unless(($parts['scheme'] ?? null) === 'https' && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) && in_array($parts['path'] ?? '', ['', '/'], true), 503);
        $path = '/api/v1/orders/'.rawurlencode($event['order_id']).'?event_id='.$event['event_id'];
        $token = (string) config('hx-upi.read_key');
        $order = Http::withoutRedirecting()->timeout(5)->withHeaders(RequestSigner::headers($token, 'GET', $path))->get($base.$path)->throw()->json();
        $result = $verifier->verifyFulfillment($event, $order, $expected + ['reference_number_hash' => $order['payment_reference_hash'] ?? null]);
        if (! $result->verified) {
            Log::critical('HX payment verification mismatch', ['event' => $event, 'pull' => $order, 'source_ip' => $request->ip()]);
            event('hx-upi.verification_mismatch', [$event, $order, $request->ip()]);
            abort(409, 'Payment verification mismatch');
        }
        $committed = DB::transaction(function () use ($event, $order, $enqueueFulfillment): bool {
            $inserted = DB::table('hx_verified_payments')->insertOrIgnore([
                'gateway_order_id' => $order['id'], 'event_id' => $event['event_id'], 'sequence' => $order['event_sequence'], 'verified_at' => now(),
                'amount_minor' => $order['payable_amount_minor'], 'currency' => $order['currency'], 'reference_hash' => $order['payment_reference_hash'],
            ]);
            if (! $inserted) {
                return false;
            }
            $enqueueFulfillment($order);

            return true;
        });
        $path = '/api/v1/events/ack';
        $body = json_encode(['event_id' => $event['event_id']], JSON_THROW_ON_ERROR);
        Http::withoutRedirecting()->timeout(5)->withHeaders(RequestSigner::headers($token, 'POST', $path, $body))->withBody($body, 'application/json')->post($base.$path)->throw();

        return $committed;
    }

    public function reconcile(): int
    {
        $mismatches = 0;
        $base = rtrim((string) config('hx-upi.api_url'), '/');
        $parts = parse_url($base);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \RuntimeException('Configure an HTTPS gateway API origin');
        }
        DB::table('hx_verified_payments')->where('verified_at', '>=', now()->subDay())->orderBy('id')->chunkById(100, function ($records) use ($base, &$mismatches): void {
            foreach ($records as $record) {
                $path = '/api/v1/orders/'.rawurlencode($record->gateway_order_id);
                try {
                    $order = Http::withoutRedirecting()->timeout(5)->withHeaders(RequestSigner::headers((string) config('hx-upi.read_key'), 'GET', $path))->get($base.$path)->throw()->json();
                    $valid = ($order['status'] ?? null) === 'paid' && ($order['id'] ?? null) === $record->gateway_order_id
                        && ($order['payable_amount_minor'] ?? null) === (int) $record->amount_minor
                        && ($order['currency'] ?? null) === $record->currency && ($order['payment_reference_hash'] ?? null) === $record->reference_hash;
                } catch (\Throwable) {
                    $order = ['error' => 'gateway_unavailable'];
                    $valid = false;
                }
                if (! $valid) {
                    $mismatches++;
                    Log::critical('HX daily payment integrity mismatch', ['verified' => (array) $record, 'pull' => $order]);
                    event('hx-upi.verification_mismatch', [(array) $record, $order, null]);
                }
            }
        });

        return $mismatches;
    }
}
