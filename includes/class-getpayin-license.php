<?php
/**
 * GetPayIn — license / subscription enforcement.
 *
 * Periodically calls a verification endpoint hosted by GetPayIn (default:
 * `https://pay.getpayin.com/plugins/license/verify`) and uses the response to
 * decide whether the gateway is allowed to operate at checkout.
 *
 * Design rules (fail-safe):
 *   - **Cache** every successful response for `TRANSIENT_TTL` so we don't hit
 *     the endpoint on every page load.
 *   - **Trust-on-first-use**: if the endpoint has never returned a valid
 *     response (e.g. brand-new install, server outage, no internet), the
 *     gateway stays available. We never deactivate paying merchants because
 *     of an outage on our side.
 *   - **Grace period**: after a successful "valid" response, if subsequent
 *     verifications fail or come back "expired", the merchant gets
 *     `GRACE_PERIOD_DAYS` more days of full operation with a prominent
 *     admin notice before the gateway is hidden at checkout.
 *
 * Expected verify-endpoint shape:
 *   POST  https://pay.getpayin.com/plugins/license/verify
 *   body: {
 *     "site_url":     "https://example.com",
 *     "auth_token":   "<plugin Authentication Token>",
 *     "version":      "1.0.4",
 *     "wp_version":   "6.6",
 *     "wc_version":   "8.9"
 *   }
 *   response: {
 *     "valid":            true|false,
 *     "state":            "active" | "grace" | "expired" | "suspended" | "unknown",
 *     "expires_at":       "2026-05-01T00:00:00Z",
 *     "renewal_url":      "https://pay.getpayin.com/billing/...",
 *     "message":          "Your subscription expires in 14 days."
 *   }
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Paylink_License {

    const DEFAULT_VERIFY_URL = 'https://pay.getpayin.com/plugins/license/verify';
    const TRANSIENT_KEY      = 'paylink_license_status';
    const LAST_VALID_OPTION  = 'paylink_license_last_valid_at';
    const TRANSIENT_TTL      = 12 * HOUR_IN_SECONDS;
    const GRACE_PERIOD_DAYS  = 7;

    /**
     * Endpoint used to verify the license. Filterable.
     *
     * @return string
     */
    public static function get_verify_url() {
        return (string) apply_filters('paylink_license_verify_url', self::DEFAULT_VERIFY_URL);
    }

    /**
     * Get the last cached/fetched license status. Triggers a fetch if missing.
     *
     * @param bool $force Bypass the cache.
     * @return array
     */
    public static function get_status($force = false) {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }
        return self::fetch_status();
    }

    /**
     * Hit the verification endpoint and cache the result.
     *
     * @return array Status payload (state, expires_at, message, last_checked).
     */
    public static function fetch_status() {
        $token = self::resolve_auth_token();

        $body = array(
            'site_url'   => home_url('/'),
            'auth_token' => $token,
            'version'    => defined('PAYLINK_VERSION') ? PAYLINK_VERSION : '0.0.0',
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : '',
        );

        $response = wp_remote_post(self::get_verify_url(), array(
            'timeout' => 8,
            'headers' => array(
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode($body),
        ));

        $status = array(
            'state'        => 'unknown',
            'expires_at'   => '',
            'renewal_url'  => '',
            'message'      => '',
            'last_checked' => time(),
            'reachable'    => false,
        );

        if (is_wp_error($response)) {
            $status['message'] = $response->get_error_message();
            set_transient(self::TRANSIENT_KEY, $status, HOUR_IN_SECONDS);
            return $status;
        }

        $code     = (int) wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);

        $status['reachable'] = true;

        if ($code < 200 || $code >= 300 || empty($raw_body)) {
            $status['message'] = sprintf('License endpoint returned HTTP %d.', $code);
            set_transient(self::TRANSIENT_KEY, $status, HOUR_IN_SECONDS);
            return $status;
        }

        $decoded = json_decode($raw_body, true);
        if (!is_array($decoded)) {
            $status['message'] = 'Malformed license response.';
            set_transient(self::TRANSIENT_KEY, $status, HOUR_IN_SECONDS);
            return $status;
        }

        $state = isset($decoded['state']) ? sanitize_key($decoded['state']) : 'unknown';
        if (!in_array($state, array('active', 'grace', 'expired', 'suspended'), true)) {
            $state = !empty($decoded['valid']) ? 'active' : 'expired';
        }

        $status['state']       = $state;
        $status['expires_at']  = isset($decoded['expires_at']) ? (string) $decoded['expires_at'] : '';
        $status['renewal_url'] = isset($decoded['renewal_url']) ? esc_url_raw($decoded['renewal_url']) : '';
        $status['message']     = isset($decoded['message']) ? wp_strip_all_tags((string) $decoded['message']) : '';

        // Record the last time we got a positive answer so we can run a grace period.
        if ('active' === $state) {
            update_option(self::LAST_VALID_OPTION, time(), false);
        }

        set_transient(self::TRANSIENT_KEY, $status, self::TRANSIENT_TTL);
        return $status;
    }

    /**
     * Whether the gateway is currently allowed to process payments.
     *
     * Returns true when:
     *   - the license is active, OR
     *   - the verify endpoint has never returned a valid answer (trust-on-first-use), OR
     *   - the license recently expired and we're still inside the grace period.
     *
     * @return bool
     */
    public static function is_allowed() {
        $status = self::get_status();
        $state  = isset($status['state']) ? $status['state'] : 'unknown';

        if ('active' === $state) {
            return true;
        }

        $last_valid = (int) get_option(self::LAST_VALID_OPTION, 0);

        // Trust-on-first-use: never blocked anyone before, don't block now either.
        if ('unknown' === $state && 0 === $last_valid) {
            return true;
        }

        // Endpoint says expired/suspended but we're inside the grace window since the last
        // successful verification — keep the gateway alive and warn the merchant.
        if ($last_valid > 0 && 'active' !== $state) {
            $grace_until = $last_valid + (self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS);
            if (time() <= $grace_until) {
                return true;
            }
        }

        // Endpoint reachable, says we're not active, and grace expired → block.
        if (in_array($state, array('expired', 'suspended'), true)) {
            return false;
        }

        // Endpoint unreachable / unknown but we DO have a previous successful verification.
        // Grace already enforced above; here we still allow rather than break payments.
        return true;
    }

    /**
     * Convenience helper used by the admin UI.
     *
     * @return string One of: active, grace, expired, suspended, unknown.
     */
    public static function get_state() {
        $status = self::get_status();
        $state  = isset($status['state']) ? $status['state'] : 'unknown';

        // Compute "grace" client-side when the endpoint hasn't returned that explicitly.
        if (in_array($state, array('expired', 'suspended'), true)) {
            $last_valid = (int) get_option(self::LAST_VALID_OPTION, 0);
            if ($last_valid > 0) {
                $grace_until = $last_valid + (self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS);
                if (time() <= $grace_until) {
                    return 'grace';
                }
            }
        }
        return $state;
    }

    /**
     * Renewal URL surfaced by the verify endpoint, if any.
     *
     * @return string
     */
    public static function get_renewal_url() {
        $status = self::get_status();
        return isset($status['renewal_url']) ? (string) $status['renewal_url'] : '';
    }

    /**
     * Human-readable message returned by the verify endpoint.
     *
     * @return string
     */
    public static function get_message() {
        $status = self::get_status();
        return isset($status['message']) ? (string) $status['message'] : '';
    }

    /**
     * Last time we successfully checked.
     *
     * @return int Unix timestamp, or 0 if never.
     */
    public static function get_last_checked() {
        $status = self::get_status();
        return isset($status['last_checked']) ? (int) $status['last_checked'] : 0;
    }

    /**
     * Drop the cached status so the next access re-fetches.
     */
    public static function clear_cache() {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Resolve the auth token to send with the verification request.
     *
     * Uses the active environment's Authentication Token (Live in production, Test
     * during sandbox). If neither is set, returns an empty string and the endpoint
     * will treat this as an unauthenticated probe.
     *
     * @return string
     */
    private static function resolve_auth_token() {
        $settings = get_option('woocommerce_paylink_settings', array());
        if (!is_array($settings)) {
            return '';
        }
        $testmode = isset($settings['testmode']) && 'yes' === $settings['testmode'];
        $key      = $testmode ? 'public_token' : 'live_public_token';
        return isset($settings[$key]) ? (string) $settings[$key] : '';
    }

    /**
     * Render an admin notice when the license is in grace, expired, or suspended.
     */
    public static function maybe_render_admin_notice() {
        $state = self::get_state();
        if ('active' === $state || 'unknown' === $state) {
            return;
        }

        $renewal_url = self::get_renewal_url();
        $message     = self::get_message();

        $type_class = 'notice-error';
        $headline   = '';

        switch ($state) {
            case 'grace':
                $type_class = 'notice-warning';
                $headline   = __('GetPayIn subscription needs renewal', 'paylink-woocommerce');
                break;
            case 'expired':
                $headline   = __('GetPayIn subscription expired', 'paylink-woocommerce');
                break;
            case 'suspended':
                $headline   = __('GetPayIn integration suspended', 'paylink-woocommerce');
                break;
        }

        echo '<div class="notice ' . esc_attr($type_class) . '"><p><strong>'
            . esc_html($headline) . '</strong> '
            . ($message !== '' ? esc_html($message) : esc_html__('Please renew to keep accepting payments.', 'paylink-woocommerce'))
            . ($renewal_url !== ''
                ? ' <a class="button button-primary" style="margin-left:8px;" href="' . esc_url($renewal_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Renew now', 'paylink-woocommerce') . '</a>'
                : ' <a class="button button-secondary" style="margin-left:8px;" href="mailto:support@getpayin.com">' . esc_html__('Contact support', 'paylink-woocommerce') . '</a>')
            . '</p></div>';
    }
}
