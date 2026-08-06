<?php
/**
 * Plugin Name: GetPayIn for WooCommerce
 * Plugin URI: https://paylink.sa
 * Description: Accept payments via GetPayIn in your WooCommerce store.
 * Version: 1.2.0
 * Author: GetPayIn
 * Author URI: https://paylink.sa
 * Text Domain: paylink-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.6
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 8.9
 * Requires Plugins: woocommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PAYLINK_PLUGIN_FILE', __FILE__);
define('PAYLINK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PAYLINK_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PAYLINK_VERSION', '1.2.0');
define('PAYLINK_MIN_WC_VERSION', '5.0');
define('PAYLINK_MIN_PHP_VERSION', '7.2');

// Backwards compatibility with legacy constant names.
if (!defined('GETPAYIN_PLUGIN_URL')) {
    define('GETPAYIN_PLUGIN_URL', PAYLINK_PLUGIN_URL);
}
if (!defined('GETPAYIN_PLUGIN_PATH')) {
    define('GETPAYIN_PLUGIN_PATH', PAYLINK_PLUGIN_PATH);
}
if (!defined('GETPAYIN_VERSION')) {
    define('GETPAYIN_VERSION', PAYLINK_VERSION);
}

/**
 * Load translation files. Called on `init` to satisfy WP 6.7+ ordering rules.
 */
function paylink_load_textdomain() {
    load_plugin_textdomain(
        'paylink-woocommerce',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('init', 'paylink_load_textdomain');

/**
 * Normalise the WooCommerce API query parameter when the provider appends extra
 * query data directly to the wc-api value (e.g. `?wc-api=paylink_return?status=...`).
 */
function paylink_normalize_wc_api_param() {
    if (empty($_GET['wc-api'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    $raw_value = wp_unslash($_GET['wc-api']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if (!is_string($raw_value) || strpos($raw_value, 'paylink_return?') !== 0) {
        return;
    }

    $parts       = explode('?', $raw_value, 2);
    $normalized  = $parts[0];
    $extra_query = isset($parts[1]) ? $parts[1] : '';

    $_GET['wc-api']     = $normalized;
    $_REQUEST['wc-api'] = $normalized;

    if ($extra_query !== '') {
        $extra_params = array();
        parse_str($extra_query, $extra_params);

        if (is_array($extra_params)) {
            foreach ($extra_params as $key => $value) {
                if (is_array($value)) {
                    continue;
                }
                $_GET[$key]     = $value;
                $_REQUEST[$key] = $value;
            }
        }
    }
}
add_action('init', 'paylink_normalize_wc_api_param', 0);

/**
 * Display an admin notice when WooCommerce is missing.
 */
function paylink_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p><strong>'
        . esc_html__('GetPayIn for WooCommerce', 'paylink-woocommerce') . '</strong> '
        . esc_html__('requires WooCommerce to be installed and active.', 'paylink-woocommerce')
        . '</p></div>';
}

/**
 * Display an admin notice when WooCommerce is below the minimum supported version.
 */
function paylink_wc_outdated_notice() {
    echo '<div class="notice notice-error"><p><strong>'
        . esc_html__('GetPayIn for WooCommerce', 'paylink-woocommerce') . '</strong> '
        . sprintf(
            /* translators: %s: minimum WooCommerce version */
            esc_html__('requires WooCommerce %s or newer.', 'paylink-woocommerce'),
            esc_html(PAYLINK_MIN_WC_VERSION)
        )
        . '</p></div>';
}

/**
 * One-time migration: split legacy single-pair credentials into test/live pairs.
 *
 * Pre-1.0.5 the plugin saved a single (public_token, hash_token) pair and used the
 * `testmode` flag to decide which network to call. Now we keep separate pairs for
 * each environment. If the merchant was running in live mode (`testmode = no`),
 * their existing tokens are moved to the new `live_*` keys so the live tab keeps
 * working after upgrade.
 */
function paylink_maybe_migrate_credentials() {
    if ('1' === get_option('paylink_credentials_migrated_v103', '0')) {
        return;
    }

    $settings = get_option('woocommerce_paylink_settings', array());
    if (!is_array($settings)) {
        update_option('paylink_credentials_migrated_v103', '1');
        return;
    }

    $testmode = isset($settings['testmode']) ? $settings['testmode'] : 'yes';

    if ('no' === $testmode
        && !empty($settings['public_token'])
        && empty($settings['live_public_token'])) {
        $settings['live_public_token'] = $settings['public_token'];
        $settings['live_hash_token']   = isset($settings['hash_token']) ? $settings['hash_token'] : '';
        $settings['public_token']      = '';
        $settings['hash_token']        = '';
        update_option('woocommerce_paylink_settings', $settings);
    }

    update_option('paylink_credentials_migrated_v103', '1');
}

/**
 * Initialize the GetPayIn integration.
 */
function paylink_init_gateway() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'paylink_woocommerce_missing_notice');
        return;
    }

    if (defined('WC_VERSION') && version_compare(WC_VERSION, PAYLINK_MIN_WC_VERSION, '<')) {
        add_action('admin_notices', 'paylink_wc_outdated_notice');
        return;
    }

    paylink_maybe_migrate_credentials();

    require_once PAYLINK_PLUGIN_PATH . 'includes/class-getpayin-api.php';
    require_once PAYLINK_PLUGIN_PATH . 'includes/class-getpayin-gateway.php';
    require_once PAYLINK_PLUGIN_PATH . 'includes/class-getpayin-subscriptions.php';
    require_once PAYLINK_PLUGIN_PATH . 'includes/blocks/class-getpayin-blocks-integration.php';
    require_once PAYLINK_PLUGIN_PATH . 'includes/class-getpayin-updater.php';
    require_once PAYLINK_PLUGIN_PATH . 'includes/class-getpayin-license.php';
    require_once PAYLINK_PLUGIN_PATH . 'includes/class-getpayin-telemetry.php';

    // Bridge to WooCommerce Subscriptions when it is active (self-guards internally).
    Paylink_Subscriptions::init();

    // Register the self-hosted updater.
    new Paylink_Updater(PAYLINK_PLUGIN_FILE, PAYLINK_VERSION);

    // Wire up opt-in telemetry (weekly cron, fire-and-forget POST).
    Paylink_Telemetry::bootstrap();

    // Surface license-state notices at the top of every admin page so merchants
    // see them whether or not they're on the gateway settings screen.
    add_action('admin_notices', array('Paylink_License', 'maybe_render_admin_notice'));
}
add_action('plugins_loaded', 'paylink_init_gateway');

/**
 * Register the GetPayIn gateway with WooCommerce.
 *
 * @param array $gateways Existing gateways.
 * @return array
 */
function paylink_add_gateway($gateways) {
    $gateways[] = 'WC_Gateway_Paylink';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'paylink_add_gateway');

/**
 * Enqueue plugin checkout styles on the front-end checkout page.
 */
function paylink_enqueue_styles() {
    if (function_exists('is_checkout') && is_checkout()) {
        wp_enqueue_style(
            'paylink-styles',
            PAYLINK_PLUGIN_URL . 'assets/css/getpayin.css',
            array(),
            PAYLINK_VERSION
        );
    }
}
add_action('wp_enqueue_scripts', 'paylink_enqueue_styles');

/**
 * Enqueue plugin admin styles on the gateway settings screen and order edit screen.
 *
 * @param string $hook_suffix Current admin screen hook suffix.
 */
function paylink_enqueue_admin_styles($hook_suffix) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
    $is_wc_settings = ('wc-settings' === $page && 'checkout' === $tab);
    $is_order_screen = $screen && in_array(
        $screen->id,
        array('shop_order', 'woocommerce_page_wc-orders'),
        true
    );

    if (!$is_wc_settings && !$is_order_screen) {
        return;
    }

    wp_enqueue_style(
        'paylink-admin',
        PAYLINK_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        PAYLINK_VERSION
    );

    if ($is_wc_settings) {
        wp_enqueue_script(
            'paylink-admin-settings',
            PAYLINK_PLUGIN_URL . 'assets/js/admin-settings.js',
            array(),
            PAYLINK_VERSION,
            true
        );
    }
}
add_action('admin_enqueue_scripts', 'paylink_enqueue_admin_styles');

/**
 * Add a Settings link to the plugin row.
 *
 * @param array $links Existing action links.
 * @return array
 */
function paylink_plugin_action_links($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=paylink')),
        esc_html__('Settings', 'paylink-woocommerce')
    );

    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'paylink_plugin_action_links');

/**
 * Declare HPOS and Cart/Checkout Blocks compatibility.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Plugin activation hook — verifies host requirements before allowing activation.
 */
function paylink_activate() {
    if (version_compare(PHP_VERSION, PAYLINK_MIN_PHP_VERSION, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                /* translators: %s: minimum PHP version */
                esc_html__('GetPayIn for WooCommerce requires PHP %s or newer.', 'paylink-woocommerce'),
                esc_html(PAYLINK_MIN_PHP_VERSION)
            ),
            esc_html__('Plugin dependency check', 'paylink-woocommerce'),
            array('back_link' => true)
        );
    }

    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('GetPayIn for WooCommerce requires WooCommerce to be installed and active.', 'paylink-woocommerce'),
            esc_html__('Plugin dependency check', 'paylink-woocommerce'),
            array('back_link' => true)
        );
    }

    if (defined('WC_VERSION') && version_compare(WC_VERSION, PAYLINK_MIN_WC_VERSION, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                /* translators: %s: minimum WooCommerce version */
                esc_html__('GetPayIn for WooCommerce requires WooCommerce %s or newer.', 'paylink-woocommerce'),
                esc_html(PAYLINK_MIN_WC_VERSION)
            ),
            esc_html__('Plugin dependency check', 'paylink-woocommerce'),
            array('back_link' => true)
        );
    }
}
register_activation_hook(__FILE__, 'paylink_activate');

/**
 * Plugin deactivation hook — clean up scheduled events.
 */
function paylink_deactivate() {
    if (class_exists('Paylink_Telemetry')) {
        Paylink_Telemetry::deactivate();
    }
}
register_deactivation_hook(__FILE__, 'paylink_deactivate');
