<?php

// Contract harness for plugin behavior; a real WooCommerce staging test is still required.
const ABSPATH = __DIR__.'/';
const ARRAY_A = 'ARRAY_A';
class WC_Payment_Gateway
{
    public $id;

    public $method_title;

    public $method_description;

    public $has_fields;

    public $supports;

    public $form_fields;

    public $title;

    public $description;

    public $enabled;

    public function init_settings(): void {}

    public function get_option($key): mixed
    {
        return $GLOBALS['settings'][$key] ?? '';
    }

    public function is_available(): bool
    {
        return true;
    }
}
function get_transient($key): bool
{
    return false;
}
function set_transient(...$args): bool
{
    return true;
}
function get_option($key): string
{
    return 'admin@example.com';
}
function wp_mail(...$args): bool
{
    return true;
}
function home_url($path): string
{
    return 'https://shop.example'.$path;
}
function add_action(...$args): void {}
function wc_get_order($id): object
{
    return $GLOBALS['order'];
}
function wc_get_logger(): object
{
    return new class
    {
        public function critical(...$args): void {}

        public function error(...$args): void {}
    };
}
function is_wp_error($response): bool
{
    return false;
}
function wp_remote_retrieve_response_code($response): int
{
    return $response['code'];
}
function wp_remote_retrieve_body($response): string
{
    return json_encode($response['body']);
}
function wp_safe_remote_request($url, $args): array
{
    $GLOBALS['requests'][] = [$url, $args];
    if (str_contains($url, '/events/ack')) {
        return ['code' => $GLOBALS['ack_status'], 'body' => ['acknowledged' => 7]];
    }

    return ['code' => 200, 'body' => $GLOBALS['pull']];
}
foreach (['VerificationResult', 'TwoOfTwoVerifier', 'RequestSigner'] as $class) {
    require_once __DIR__.'/../../packages/hx-upi-merchant-php/src/'.$class.'.php';
}
require_once __DIR__.'/../../packages/hx-upi-woocommerce/class-hx-upi-gateway.php';
$GLOBALS['settings'] = ['api_url' => 'https://api.example', 'read_key' => 'upi_live_read_secret', 'webhook_secret' => 'hook-secret'];
$GLOBALS['requests'] = [];
$GLOBALS['ack_status'] = 503;
$GLOBALS['record'] = ['wc_order_id' => 10, 'gateway_order_id' => 'gateway-order', 'amount_minor' => 10001, 'fulfilled_at' => null];
$GLOBALS['order'] = new class
{
    public int $completions = 0;

    public function get_payment_method(): string
    {
        return 'hx_upi';
    }

    public function get_currency(): string
    {
        return 'INR';
    }

    public function get_total(): string
    {
        return '100.01';
    }

    public function is_paid(): bool
    {
        return $this->completions > 0;
    }

    public function has_status($statuses): bool
    {
        return true;
    }

    public function get_transaction_id(): string
    {
        return 'gateway-order';
    }

    public function payment_complete($id): void
    {
        $this->completions++;
    }
};
$GLOBALS['wpdb'] = new class
{
    public string $prefix = 'wp_';

    public function prepare($query, ...$args): string
    {
        return $query;
    }

    public function get_row(...$args): array
    {
        return $GLOBALS['record'];
    }

    public function get_var($query): int
    {
        return 1;
    }

    public function update($table, $data, $where): int
    {
        $GLOBALS['record'] = array_merge($GLOBALS['record'], $data);

        return 1;
    }
};
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
$reference = str_repeat('a', 64);
$GLOBALS['pull'] = ['id' => 'gateway-order', 'status' => 'paid', 'confirmation_method' => 'automatic', 'payable_amount_minor' => 10001, 'currency' => 'INR', 'payment_reference_hash' => $reference, 'event_sequence' => 2];
$body = json_encode(['event_id' => 7, 'order_id' => 'gateway-order', 'event_type' => 'order.paid', 'sequence_number' => 2, 'verification_hash' => hash('sha256', json_encode(['gateway-order', 10001, 'INR', $reference, 2, 'paid', 'automatic']))]);
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp.'.'.$body, 'hook-secret');
$gateway = new HX_UPI_Gateway;
try {
    $gateway->handleWebhook($body, $timestamp, str_repeat('0', 64), '192.0.2.1');
    throw new RuntimeException('Forged webhook was accepted');
} catch (RuntimeException $exception) {
    check($exception->getCode() === 401, 'Forged webhook must be 401');
}
check(count($GLOBALS['requests']) === 0, 'Forged webhook contacted API');
$GLOBALS['pull']['payable_amount_minor'] = 10002;
try {
    $gateway->handleWebhook($body, $timestamp, $signature, '192.0.2.1');
    throw new RuntimeException('Mismatch was accepted');
} catch (RuntimeException $exception) {
    check($exception->getCode() === 409, 'Mismatch must be 409');
}
check($GLOBALS['order']->completions === 0, 'Mismatch fulfilled order');
$GLOBALS['pull']['payable_amount_minor'] = 10001;
try {
    $gateway->handleWebhook($body, $timestamp, $signature, '192.0.2.1');
    throw new RuntimeException('Failed acknowledgement did not request retry');
} catch (RuntimeException $exception) {
    check($exception->getMessage() === 'Gateway request failed', 'Unexpected failure');
}
check($GLOBALS['order']->completions === 1, 'Valid payment did not complete');
$GLOBALS['ack_status'] = 200;
$gateway->handleWebhook($body, $timestamp, $signature, '192.0.2.1');
check($GLOBALS['order']->completions === 1, 'Duplicate webhook completed twice');
check(HX_UPI_Gateway::minor('0.01') === 1 && HX_UPI_Gateway::minor('100.1') === 10010, 'INR conversion failed');
check(str_contains($GLOBALS['requests'][0][0], '?event_id=7'), 'Paid event sequence was not pinned');
echo "WooCommerce contract checks passed: forged signature, mismatch, ACK retry, duplicate fulfillment, paise conversion.\n";
