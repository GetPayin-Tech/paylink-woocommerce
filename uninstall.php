<?php
/**
 * Uninstall script for GetPayIn for WooCommerce.
 *
 * Runs only when the plugin is deleted from the WordPress admin.
 * Order metadata is intentionally preserved for historical record keeping.
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Gateway settings live under the gateway id ('paylink'). Also delete the legacy key
// from earlier builds in case it was written there.
delete_option('woocommerce_paylink_settings');
delete_option('woocommerce_getpayin_settings');

// Clear any scheduled events that earlier versions may have registered.
wp_clear_scheduled_hook('paylink_cleanup_logs');
wp_clear_scheduled_hook('getpayin_cleanup_logs');
