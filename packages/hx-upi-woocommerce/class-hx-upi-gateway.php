<?php

use HxDev\UpiMerchant\RequestSigner;
use HxDev\UpiMerchant\TwoOfTwoVerifier;

if (! defined('ABSPATH')) {
    exit;
}

class HX_UPI_Gateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'hx_upi';
        $this->method_title = 'HX UPI';
        $this->method_description = 'Payment is completed only after a signed webhook agrees with a signed API pull. Configure the webhook URL: '.home_url('/?wc-api=hx_upi');
        $this->has_fields = false;
        $this->supports = ['products'];
        $this->form_fields = [
            'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'default' => 'no'],
            'title' => ['title' => 'Checkout title', 'type' => 'text', 'default' => 'UPI'],
            'description' => ['title' => 'Description', 'type' => 'textarea', 'default' => 'Pay directly to the merchant. An exact-paise verification adjustment of ₹0.01–₹0.99 is shown before payment.'],
            'api_url' => ['title' => 'Gateway API origin', 'type' => 'text', 'description' => 'HTTPS origin only, for example https://api.example.com'],
            'checkout_origin' => ['title' => 'Checkout origin', 'type' => 'text', 'description' => 'HTTPS origin of the hosted payment page.'],
            'create_key' => ['title' => 'Create API key', 'type' => 'password'],
            'read_key' => ['title' => 'Read API key', 'type' => 'password'],
            'webhook_secret' => ['title' => 'Webhook secret', 'type' => 'password'],
            'accept_manual' => ['title' => 'Accept owner-confirmed payments', 'type' => 'checkbox', 'default' => 'no'],
        ];
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_hx_upi', [$this, 'webhook']);
    }

    public function is_available(): bool
    {
        return get_woocommerce_currency() === 'INR' && parent::is_available();
    }

    public function process_payment($order_id): array
    {
        global $wpdb;
        $lock = $this->lock((int) $order_id);
        if (! $lock) {
            wc_add_notice('A payment request is already being processed. Please retry shortly.', 'error');

            return ['result' => 'failure'];
        }
        try {
            $order = wc_get_order($order_id);
            if (! $order || $order->get_currency() !== 'INR' || $order->is_paid()) {
                throw new RuntimeException('Order is not payable.');
            }
            $table = $wpdb->prefix.'hx_upi_orders';
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE wc_order_id = %d", $order_id), ARRAY_A);
            if ($existing) {
                if (self::minor((string) $order->get_total()) !== (int) $existing['amount_minor']) {
                    throw new RuntimeException('Stored order total changed');
                }

                return ['result' => 'success', 'redirect' => $existing['checkout_url']];
            }
            $base = (int) $order->get_meta('_hx_base_minor');
            if (! $base) {
                $base = self::minor((string) $order->get_total());
                $order->update_meta_data('_hx_base_minor', $base);
                $order->save();
            }
            $reference = 'wc-'.$order_id.'-'.substr(hash('sha256', $order->get_order_key()), 0, 16);
            $payload = ['merchant_order_id' => $reference, 'base_amount_minor' => $base, 'currency' => 'INR', 'return_url' => $this->get_return_url($order)];
            $created = $this->api('POST', '/api/v1/orders', $payload, 'create_key', ['Idempotency-Key' => $reference]);
            if (! is_string($created['id'] ?? null) || ! is_int($created['payable_amount_minor'] ?? null) || $created['payable_amount_minor'] <= $base || $created['payable_amount_minor'] > $base + 99 || ($created['currency'] ?? null) !== 'INR') {
                throw new RuntimeException('Invalid checkout response.');
            }
            $checkout = (string) ($created['checkout_url'] ?? '');
            $origin = $this->origin((string) $this->get_option('checkout_origin'));
            if (! str_starts_with($checkout, $origin.'/checkout/') || ! preg_match('~^'.preg_quote($origin, '~').'/checkout/[A-Za-z0-9]{64}$~D', $checkout)) {
                throw new RuntimeException('Unexpected checkout address.');
            }
            $hasAdjustment = false;
            foreach ($order->get_items('fee') as $fee) {
                $hasAdjustment = $hasAdjustment || (bool) $fee->get_meta('_hx_adjustment');
            }
            if (! $hasAdjustment) {
                $fee = new WC_Order_Item_Fee;
                $fee->set_name('UPI verification adjustment');
                $fee->set_tax_status('none');
                $fee->set_amount(number_format(($created['payable_amount_minor'] - $base) / 100, 2, '.', ''));
                $fee->set_total($fee->get_amount());
                $fee->add_meta_data('_hx_adjustment', true);
                $order->add_item($fee);
                $order->calculate_totals(false);
                $order->save();
            }
            if (self::minor((string) $order->get_total()) !== $created['payable_amount_minor']) {
                throw new RuntimeException('The order total changed. Please contact the merchant.');
            }
            $inserted = $wpdb->insert($table, ['wc_order_id' => $order_id, 'gateway_order_id' => $created['id'], 'amount_minor' => $created['payable_amount_minor'], 'checkout_url' => $checkout, 'expires_at' => $created['expires_at']]);
            if (! $inserted) {
                throw new RuntimeException('Could not persist checkout. Retry with the same order.');
            }

            return ['result' => 'success', 'redirect' => $checkout];
        } catch (Throwable $exception) {
            wc_get_logger()->error($exception->getMessage(), ['source' => 'hx-upi', 'order_id' => $order_id]);
            wc_add_notice('UPI checkout is unavailable. Please retry shortly or contact the merchant.', 'error');

            return ['result' => 'failure'];
        } finally {
            $this->unlock($lock);
        }
    }

    public function webhook(): void
    {
        global $wpdb;
        $lock = null;
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('POST required', 405);
            }
            $body = file_get_contents('php://input', false, null, 0, 16385);
            $this->handleWebhook((string) $body, (string) ($_SERVER['HTTP_X_UPI_EVENT_TIMESTAMP'] ?? ''), (string) ($_SERVER['HTTP_X_UPI_EVENT_SIGNATURE'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            status_header(200);
        } catch (Throwable $exception) {
            wc_get_logger()->error($exception->getMessage(), ['source' => 'hx-upi']);
            $status = in_array($exception->getCode(), [401, 405, 409, 422, 503], true) ? $exception->getCode() : 503;
            status_header($status);
        } finally {
            if ($lock) {
                $this->unlock($lock);
            }
        }
        exit;
    }

    public function handleWebhook(string $body, string $timestamp, string $signature, string $sourceIp): void
    {
        global $wpdb;
        $lock = null;
        try {
            $verifier = new TwoOfTwoVerifier((string) $this->get_option('webhook_secret'));
            if (! is_string($body) || strlen($body) > 16384 || ! $verifier->verifyWebhook($body, $timestamp, $signature)) {
                throw new RuntimeException('Invalid webhook', 401);
            }
            $event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            if (! is_int($event['event_id'] ?? null) || $event['event_id'] < 1 || ! is_string($event['order_id'] ?? null)) {
                throw new RuntimeException('Invalid event', 422);
            }
            if (! in_array($event['event_type'] ?? null, ['order.paid', 'order.manual_confirmed'], true)) {
                return;
            }
            $table = $wpdb->prefix.'hx_upi_orders';
            $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway_order_id = %s", $event['order_id']), ARRAY_A);
            if (! $record || ! ($lock = $this->lock((int) $record['wc_order_id']))) {
                throw new RuntimeException('Order unavailable; retry', 503);
            }
            $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE gateway_order_id = %s", $event['order_id']), ARRAY_A);
            $pulled = $this->api('GET', '/api/v1/orders/'.rawurlencode($event['order_id']).'?event_id='.$event['event_id']);
            $expected = ['order_id' => $record['gateway_order_id'], 'amount_minor' => (int) $record['amount_minor'], 'currency' => 'INR', 'reference_number_hash' => $pulled['payment_reference_hash'] ?? null, 'accept_manual_confirmation' => $this->get_option('accept_manual') === 'yes'];
            if (! $verifier->verifyFulfillment($event, $pulled, $expected)->verified) {
                $this->alert('Two-channel payment mismatch', ['event' => $event, 'pull' => $pulled, 'source_ip' => $sourceIp], $event['order_id']);
                throw new RuntimeException('Verification mismatch', 409);
            }
            $order = wc_get_order($record['wc_order_id']);
            if (! $order || $order->get_payment_method() !== $this->id || $order->get_currency() !== 'INR' || self::minor((string) $order->get_total()) !== (int) $record['amount_minor']) {
                throw new RuntimeException('Stored order mismatch', 409);
            }
            if (! $record['fulfilled_at']) {
                if ($order->is_paid() && $order->get_transaction_id() !== $event['order_id']) {
                    throw new RuntimeException('Order paid with a different transaction', 409);
                }
                if (! $order->is_paid()) {
                    if (! $order->has_status(['pending', 'on-hold', 'failed'])) {
                        throw new RuntimeException('Order requires manual review', 409);
                    }
                    $order->payment_complete($event['order_id']);
                    $order = wc_get_order($record['wc_order_id']);
                    if (! $order->is_paid()) {
                        throw new RuntimeException('Order completion failed', 503);
                    }
                }
                if ($wpdb->update($table, ['fulfilled_event_id' => $event['event_id'], 'reference_hash' => $pulled['payment_reference_hash'], 'fulfilled_at' => gmdate('Y-m-d H:i:s')], ['wc_order_id' => $record['wc_order_id']]) === false) {
                    throw new RuntimeException('Could not persist acknowledgement', 503);
                }
            }
            $this->api('POST', '/api/v1/events/ack', ['event_id' => $event['event_id']]);
        } finally {
            if ($lock) {
                $this->unlock($lock);
            }
        }
    }

    public static function minor(string $value): int
    {
        if (! preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
            throw new RuntimeException('Invalid INR amount');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public function reconcile(): void
    {
        global $wpdb;
        $table = $wpdb->prefix.'hx_upi_orders';
        $after = 0;
        do {
            $records = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE fulfilled_at >= %s AND wc_order_id > %d ORDER BY wc_order_id LIMIT 100", gmdate('Y-m-d H:i:s', time() - 86400), $after), ARRAY_A);
            foreach ($records as $record) {
                $after = (int) $record['wc_order_id'];
                try {
                    $order = $this->api('GET', '/api/v1/orders/'.rawurlencode($record['gateway_order_id']));
                    if (($order['status'] ?? null) !== 'paid' || ($order['id'] ?? null) !== $record['gateway_order_id'] || ($order['payable_amount_minor'] ?? null) !== (int) $record['amount_minor'] || ($order['currency'] ?? null) !== 'INR' || ($order['payment_reference_hash'] ?? null) !== $record['reference_hash']) {
                        throw new RuntimeException('Gateway no longer agrees with fulfilled payment');
                    }
                } catch (Throwable $exception) {
                    $this->alert('Daily payment integrity check needs review', ['gateway_order_id' => $record['gateway_order_id'], 'reason' => $exception->getMessage()], $record['gateway_order_id']);
                }
            }
        } while (count($records) === 100);
    }

    private function alert(string $message, array $context, string $orderId): void
    {
        wc_get_logger()->critical($message, ['source' => 'hx-upi'] + $context);
        $key = 'hx_upi_alert_'.hash('sha256', $message.$orderId);
        if (! get_transient($key) && wp_mail(get_option('admin_email'), 'HX UPI: '.$message, 'Order '.$orderId.' requires review. See WooCommerce > Status > Logs > hx-upi. No additional fulfillment was authorized.')) {
            set_transient($key, true, 86400);
        }
    }

    private function api(string $method, string $path, ?array $payload = null, string $key = 'read_key', array $extra = []): array
    {
        $origin = $this->origin((string) $this->get_option('api_url'));
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = RequestSigner::headers((string) $this->get_option($key), $method, $path, $body) + $extra;
        $response = wp_safe_remote_request($origin.$path, ['method' => $method, 'headers' => $headers, 'body' => $body, 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536]);
        if (is_wp_error($response) || ! in_array(wp_remote_retrieve_response_code($response), [200, 201], true)) {
            throw new RuntimeException('Gateway request failed');
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid gateway response');
        }

        return $decoded;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('HTTPS origin required');
        }

        return rtrim($url, '/');
    }

    private function lock(int $orderId): ?string
    {
        global $wpdb;
        $key = 'hx-upi:'.substr(hash('sha256', $wpdb->prefix.':'.$orderId), 0, 48);

        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key)) === 1 ? $key : null;
    }

    private function unlock(string $key): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    }
}
