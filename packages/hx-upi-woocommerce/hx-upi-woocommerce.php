<?php

/**
 * Plugin Name: HX UPI Gateway
 * Description: Hosted UPI checkout with signed requests and webhook plus pull verification.
 * Version: 1.0.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 */
if (! defined('ABSPATH')) {
    exit;
}

foreach (['VerificationResult', 'TwoOfTwoVerifier', 'RequestSigner'] as $hxClass) {
    $hxSource = __DIR__.'/lib/'.$hxClass.'.php';
    require_once is_file($hxSource) ? $hxSource : __DIR__.'/../hx-upi-merchant-php/src/'.$hxClass.'.php';
}

register_activation_hook(__FILE__, function (): void {
    global $wpdb;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $table = $wpdb->prefix.'hx_upi_orders';
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        wc_order_id bigint unsigned NOT NULL,
        gateway_order_id varchar(64) NOT NULL,
        amount_minor bigint unsigned NOT NULL,
        checkout_url text NOT NULL,
        expires_at varchar(40) NOT NULL,
        fulfilled_event_id bigint unsigned DEFAULT NULL,
        reference_hash varchar(64) DEFAULT NULL,
        fulfilled_at datetime DEFAULT NULL,
        PRIMARY KEY  (wc_order_id),
        UNIQUE KEY gateway_order_id (gateway_order_id)
    ) {$charset};");
    if (! wp_next_scheduled('hx_upi_daily_integrity')) {
        wp_schedule_event(time() + 3600, 'daily', 'hx_upi_daily_integrity');
    }
});

register_deactivation_hook(__FILE__, fn () => wp_clear_scheduled_hook('hx_upi_daily_integrity'));

add_action('plugins_loaded', function (): void {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }
    require_once __DIR__.'/class-hx-upi-gateway.php';
    add_action('hx_upi_daily_integrity', fn () => (new HX_UPI_Gateway)->reconcile());
    add_filter('woocommerce_payment_gateways', function (array $gateways): array {
        $gateways[] = HX_UPI_Gateway::class;

        return $gateways;
    });
});
