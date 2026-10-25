<?php

namespace App\Domain\Webhooks;

use App\Models\OrderEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\PaymentProof;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

final class WebhookService
{
    public function deliver(OrderEvent $event, WebhookEndpoint $endpoint): WebhookDelivery
    {
        abort_unless($event->order->merchant_id === $endpoint->merchant_id, 403);
        $timestamp = now()->timestamp;
        $body = json_encode([
            'event_id' => $event->id,
            'order_id' => $event->order->public_id,
            'event_type' => $event->event_type,
            'sequence_number' => $event->sequence_number,
            'verification_hash' => PaymentProof::hash([
                'id' => $event->order->public_id, 'payable_amount_minor' => $event->payload['amount_minor'] ?? $event->order->payable_amount_minor,
                'currency' => $event->payload['currency'] ?? $event->order->currency, 'payment_reference_hash' => $event->payload['reference_number_hash'] ?? null,
                'event_sequence' => $event->sequence_number, 'status' => $event->payload['status'], 'confirmation_method' => $event->payload['confirmation_method'] ?? null,
            ]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret_encrypted);
        $delivery = WebhookDelivery::create([
            'merchant_id' => $event->order->merchant_id,
            'order_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'attempt_number' => ((int) WebhookDelivery::query()
                ->where('order_event_id', $event->id)
                ->where('webhook_endpoint_id', $endpoint->id)
                ->max('attempt_number')) + 1,
            'request_timestamp' => now(),
        ]);

        try {
            $resolvedIp = $this->assertSafeUrl($endpoint->url);
            $host = parse_url($endpoint->url, PHP_URL_HOST);
            $pinned = str_contains($resolvedIp, ':') ? '['.$resolvedIp.']' : $resolvedIp;
            $response = Http::withoutRedirecting()->withOptions(['proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$pinned]]])->connectTimeout(3)->timeout(5)->withHeaders([
                'Content-Type' => 'application/json',
                'X-UPI-Event-Timestamp' => (string) $timestamp,
                'X-UPI-Event-Signature' => 'sha256='.$signature,
                'X-UPI-Event-ID' => (string) $event->id,
            ])->withBody($body, 'application/json')->post($endpoint->url);

            $delivery->update([
                'response_status' => $response->status(),
                'response_body_hash' => hash('sha256', $response->body()),
                'source_ip' => $resolvedIp,
                'delivered_at' => $response->status() === 200 ? now() : null,
                'failed_at' => $response->status() === 200 ? null : now(),
                'next_retry_at' => $response->status() === 200 ? null : now()->addMinutes(min(60, 2 ** min(6, $delivery->attempt_number))),
            ]);
        } catch (\Throwable $exception) {
            $delivery->update([
                'failed_at' => now(),
                'next_retry_at' => now()->addMinutes(min(60, 2 ** min(6, $delivery->attempt_number))),
                'response_body_hash' => hash('sha256', $exception->getMessage()),
            ]);
        }

        return $delivery;
    }

    public function assertSafeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) {
            throw new RuntimeException('Webhook URL must be HTTPS and must not contain credentials.');
        }

        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolveAddresses($host);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || IpUtils::checkIp($ip, ['100.64.0.0/10', '192.0.0.0/24', '192.88.99.0/24', '198.18.0.0/15', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '2001::/23', '2002::/16', '3fff::/20'])
                || (str_contains($ip, ':') && ! IpUtils::checkIp($ip, '2000::/3'))) {
                throw new RuntimeException('Webhook URL resolves to a private or reserved address.');
            }
        }

        if ($ips === []) {
            throw new RuntimeException('Webhook hostname has no usable address.');
        }

        return $ips[0];
    }

    /**
     * @return list<string>
     */
    private function resolveAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new RuntimeException('Webhook hostname could not be resolved.');
        }

        return array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
