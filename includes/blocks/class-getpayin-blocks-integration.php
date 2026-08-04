<?php
/**
 * GetPayIn Blocks Integration
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * GetPayIn Payment Method Block Integration.
 */
final class WC_Paylink_Blocks_Support extends AbstractPaymentMethodType {

    const SETTINGS_OPTION = 'woocommerce_paylink_settings';

    /**
     * Payment method name (matches gateway id).
     *
     * @var string
     */
    protected $name = 'paylink';

    /**
     * Initialize the payment method type.
     */
    public function initialize() {
        $this->settings = get_option(self::SETTINGS_OPTION, array());
    }

    /**
     * Whether this payment method should be active for blocks checkout.
     *
     * @return bool
     */
    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    /**
     * Returns an array of script handles to enqueue for this payment method.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        $asset_path   = PAYLINK_PLUGIN_PATH . 'build/blocks-integration.asset.php';
        $version      = PAYLINK_VERSION;
        $dependencies = array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n');

        if (file_exists($asset_path)) {
            $asset = require $asset_path;
            if (is_array($asset)) {
                if (isset($asset['version'])) {
                    $version = $asset['version'];
                }
                if (isset($asset['dependencies']) && is_array($asset['dependencies'])) {
                    $dependencies = $asset['dependencies'];
                }
            }
        }

        wp_register_script(
            'wc-paylink-blocks-integration',
            PAYLINK_PLUGIN_URL . 'build/blocks-integration.js',
            $dependencies,
            $version,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-paylink-blocks-integration', 'paylink-woocommerce');
        }

        return array('wc-paylink-blocks-integration');
    }

    /**
     * Returns an array of supported features.
     *
     * @return string[]
     */
    public function get_supported_features() {
        $features = array('products');

        if (class_exists('Paylink_Subscriptions') && Paylink_Subscriptions::is_available()) {
            $features = array_merge($features, Paylink_Subscriptions::gateway_supports());
        }

        return $features;
    }

    /**
     * Returns payment method data for the frontend.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->get_setting('title', __('Apple Pay / Google Pay / Visa / Mastercard', 'paylink-woocommerce')),
            'description' => $this->get_setting('description', __('Pay securely using your card or wallet via GetPayIn.', 'paylink-woocommerce')),
            'testmode'    => $this->get_setting('testmode') === 'yes',
            'supports'    => $this->get_supported_features(),
        );
    }
}

/**
 * Register the blocks integration once WC Blocks is ready.
 */
add_action('woocommerce_blocks_payment_method_type_registration', function ($payment_method_registry) {
    $payment_method_registry->register(new WC_Paylink_Blocks_Support());
});
