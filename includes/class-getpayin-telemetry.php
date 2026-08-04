<?php
/**
 * GetPayIn — opt-in anonymous telemetry.
 *
 * Once a week (WP Cron) and only when the merchant has explicitly opted in
 * via the "Anonymous usage data" setting, the plugin POSTs a minimal payload
 * to GetPayIn so the team can see install health.
 *
 * Payload (no PII, no tokens, no order data):
 *   {
 *     "site_hash":  "<sha256 of home_url(), truncated to 16 chars>",
 *     "plugin":     "1.0.5",
 *     "wp":         "6.6.1",
 *     "wc":         "8.9.2",
 *     "php":        "8.2.10",
 *     "currency":   "EGP",
 *     "mode":       "test",
 *     "errors_24h": 0
 *   }
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Paylink_Telemetry {

    const DEFAULT_ENDPOINT = 'https://pay.getpayin.com/plugins/usage';
    const CRON_HOOK        = 'paylink_telemetry_weekly';

    public static function bootstrap() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'send'));

        // Schedule / unschedule based on the setting.
        add_action('update_option_woocommerce_paylink_settings', array(__CLASS__, 'reschedule'), 10, 2);

        // First load after activation: if telemetry is on but no cron, schedule one.
        if (self::is_enabled() && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK);
        }
    }

    public static function endpoint() {
        return (string) apply_filters('paylink_telemetry_endpoint', self::DEFAULT_ENDPOINT);
    }

    public static function is_enabled() {
        $settings = get_option('woocommerce_paylink_settings', array());
        return is_array($settings) && isset($settings['telemetry']) && 'yes' === $settings['telemetry'];
    }

    public static function reschedule($old_value, $new_value) {
        $was_on = is_array($old_value) && !empty($old_value['telemetry']) && 'yes' === $old_value['telemetry'];
        $is_on  = is_array($new_value) && !empty($new_value['telemetry']) && 'yes' === $new_value['telemetry'];

        $next = wp_next_scheduled(self::CRON_HOOK);

        if ($is_on && !$next) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK);
        } elseif (!$is_on && $next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
    }

    public static function send() {
        if (!self::is_enabled()) {
            return;
        }

        $settings = get_option('woocommerce_paylink_settings', array());
        $testmode = is_array($settings) && isset($settings['testmode']) && 'yes' === $settings['testmode'];

        $payload = array(
            'site_hash'  => substr(hash('sha256', home_url('/')), 0, 16),
            'plugin'     => defined('PAYLINK_VERSION') ? PAYLINK_VERSION : '0.0.0',
            'wp'         => get_bloginfo('version'),
            'wc'         => defined('WC_VERSION') ? WC_VERSION : '',
            'php'        => PHP_VERSION,
            'currency'   => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'mode'       => $testmode ? 'test' : 'live',
            'errors_24h' => 0, // Reserved — populate from a counter once we add one.
        );

        wp_remote_post(self::endpoint(), array(
            'timeout'  => 5,
            'blocking' => false, // fire-and-forget so the cron run is fast
            'headers'  => array(
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ),
            'body'     => wp_json_encode($payload),
        ));
    }

    public static function deactivate() {
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
    }
}
