<?php
/**
 * GetPayIn — self-hosted plugin updater.
 *
 * Polls a JSON manifest hosted by GetPayIn (default:
 * `https://pay.getpayin.com/plugins/woocommerce/manifest.json`) and feeds the
 * result into WordPress's standard update plumbing so admins see the new
 * version directly on the **Plugins** screen and can update with one click.
 *
 * Expected manifest shape (fields marked * are required):
 *   {
 *     "name": "GetPayIn for WooCommerce",
 *     "slug": "getpayin-woocommerce",        *   // must match the plugin directory
 *     "version": "1.0.5",                    *   // semantic version
 *     "download_url": "https://...zip",      *   // direct download URL
 *     "tested": "6.6",
 *     "requires": "5.6",
 *     "requires_php": "7.2",
 *     "homepage": "https://paylink.sa",
 *     "author": "GetPayIn",
 *     "last_updated": "2025-05-01 12:00:00",
 *     "sections": {
 *         "description": "<p>HTML…</p>",
 *         "changelog":   "<h4>1.0.5</h4><ul>…</ul>"
 *     }
 *   }
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Paylink_Updater {

    const DEFAULT_MANIFEST_URL = 'https://pay.getpayin.com/plugins/woocommerce/manifest.json';
    const TRANSIENT_KEY        = 'paylink_update_manifest';
    const TRANSIENT_TTL        = 12 * HOUR_IN_SECONDS;
    const FORCE_CHECK_ACTION   = 'paylink_force_update_check';

    /**
     * Absolute path to the main plugin file.
     *
     * @var string
     */
    private $plugin_file;

    /**
     * Plugin basename (e.g. `getpayin-woocommerce/getpayin-woocommerce.php`).
     *
     * @var string
     */
    private $plugin_basename;

    /**
     * Plugin slug (directory name).
     *
     * @var string
     */
    private $plugin_slug;

    /**
     * Currently installed version.
     *
     * @var string
     */
    private $current_version;

    /**
     * Constructor.
     *
     * @param string $plugin_file Absolute path to the main plugin file (use __FILE__).
     * @param string $version     Currently installed version.
     */
    public function __construct($plugin_file, $version) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug     = dirname($this->plugin_basename);
        $this->current_version = $version;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'rename_source'), 10, 4);
        add_filter('upgrader_pre_download', array($this, 'verify_download'), 10, 4);

        add_action('admin_init', array($this, 'handle_force_check'));
    }

    /**
     * URL of the JSON update manifest. Filterable.
     *
     * @return string
     */
    public static function get_manifest_url() {
        return (string) apply_filters('paylink_update_manifest_url', self::DEFAULT_MANIFEST_URL);
    }

    /**
     * Fetch the manifest, with a 12-hour transient cache.
     *
     * @param bool $force Bypass the cache.
     * @return array|null Decoded manifest or null on failure.
     */
    public static function get_manifest($force = false) {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = wp_remote_get(self::get_manifest_url(), array(
            'timeout' => 8,
            'headers' => array('Accept' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            // Cache the failure for 1 hour to avoid hammering the endpoint.
            set_transient(self::TRANSIENT_KEY, array('__error' => $response->get_error_message()), HOUR_IN_SECONDS);
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300 || empty($body)) {
            set_transient(self::TRANSIENT_KEY, array('__error' => 'HTTP ' . $code), HOUR_IN_SECONDS);
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['version']) || empty($decoded['download_url'])) {
            set_transient(self::TRANSIENT_KEY, array('__error' => 'malformed manifest'), HOUR_IN_SECONDS);
            return null;
        }

        set_transient(self::TRANSIENT_KEY, $decoded, self::TRANSIENT_TTL);
        return $decoded;
    }

    /**
     * Clear the manifest cache so the next access re-fetches.
     */
    public static function clear_cache() {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * Whether a newer version is available than the one installed.
     *
     * @param string $current_version Installed version.
     * @return array|null Manifest if a newer version exists, null otherwise.
     */
    public static function maybe_get_newer_manifest($current_version) {
        $manifest = self::get_manifest();
        if (!is_array($manifest) || isset($manifest['__error'])) {
            return null;
        }
        if (version_compare($manifest['version'], $current_version, '>')) {
            return $manifest;
        }
        return null;
    }

    /**
     * Hook: inject our plugin's update info into the global plugins-update transient.
     *
     * @param object|mixed $transient The transient object (or false on first call).
     * @return mixed
     */
    public function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $manifest = self::maybe_get_newer_manifest($this->current_version);
        if (!$manifest) {
            return $transient;
        }

        $update = (object) array(
            'id'             => $this->plugin_basename,
            'slug'           => $this->plugin_slug,
            'plugin'         => $this->plugin_basename,
            'new_version'    => (string) $manifest['version'],
            'url'            => isset($manifest['homepage']) ? esc_url_raw($manifest['homepage']) : '',
            'package'        => esc_url_raw($manifest['download_url']),
            'tested'         => isset($manifest['tested']) ? (string) $manifest['tested'] : '',
            'requires_php'   => isset($manifest['requires_php']) ? (string) $manifest['requires_php'] : '',
            'icons'          => isset($manifest['icons']) && is_array($manifest['icons']) ? $manifest['icons'] : array(),
            'banners'        => isset($manifest['banners']) && is_array($manifest['banners']) ? $manifest['banners'] : array(),
        );

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }
        $transient->response[$this->plugin_basename] = $update;

        // Remove from "no_update" list if it's there.
        if (isset($transient->no_update[$this->plugin_basename])) {
            unset($transient->no_update[$this->plugin_basename]);
        }

        return $transient;
    }

    /**
     * Hook: provide plugin details for the "View version X.Y.Z details" popup.
     *
     * @param false|object|array $result Default result.
     * @param string             $action The type of information being requested.
     * @param object             $args   Plugin API arguments.
     * @return mixed
     */
    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action) {
            return $result;
        }
        if (empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $manifest = self::get_manifest();
        if (!is_array($manifest) || isset($manifest['__error'])) {
            return $result;
        }

        $info = (object) array(
            'name'              => isset($manifest['name']) ? $manifest['name'] : 'GetPayIn for WooCommerce',
            'slug'              => $this->plugin_slug,
            'version'           => $manifest['version'],
            'author'            => isset($manifest['author']) ? $manifest['author'] : 'GetPayIn',
            'homepage'          => isset($manifest['homepage']) ? $manifest['homepage'] : '',
            'requires'          => isset($manifest['requires']) ? $manifest['requires'] : '',
            'tested'            => isset($manifest['tested']) ? $manifest['tested'] : '',
            'requires_php'      => isset($manifest['requires_php']) ? $manifest['requires_php'] : '',
            'last_updated'      => isset($manifest['last_updated']) ? $manifest['last_updated'] : '',
            'download_link'     => $manifest['download_url'],
            'sections'          => isset($manifest['sections']) && is_array($manifest['sections']) ? $manifest['sections'] : array(),
            'banners'           => isset($manifest['banners']) && is_array($manifest['banners']) ? $manifest['banners'] : array(),
        );

        return $info;
    }

    /**
     * Hook: download the update zip ourselves so we can verify its SHA-256
     * digest against the value in the manifest before WordPress unpacks it.
     *
     * If the manifest doesn't include a `signature_sha256`, this falls back to
     * WordPress's default download behaviour (no integrity check beyond TLS).
     *
     * @param mixed       $reply    Default reply (false to use core's downloader).
     * @param string      $package  The package URL.
     * @param WP_Upgrader $upgrader Upgrader instance.
     * @param array       $hook_extra Extra args. Contains 'plugin' for plugin upgrades.
     * @return string|WP_Error|mixed Local zip path on success, WP_Error on failure, or pass-through.
     */
    public function verify_download($reply, $package, $upgrader, $hook_extra = array()) {
        // Only intervene for OUR plugin upgrades.
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $reply;
        }
        if (!is_string($package) || $package === '') {
            return $reply;
        }

        $manifest = self::get_manifest();
        if (!is_array($manifest) || empty($manifest['signature_sha256'])) {
            // No checksum published — let core download the file as usual.
            return $reply;
        }

        $expected = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $manifest['signature_sha256']));
        if (strlen($expected) !== 64) {
            return new WP_Error(
                'paylink_invalid_checksum_field',
                __('GetPayIn update aborted: manifest signature_sha256 is malformed.', 'paylink-woocommerce')
            );
        }

        // Stream the file to a temp path.
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = download_url($package, 60);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $actual = @hash_file('sha256', $tmp);
        if (false === $actual) {
            @unlink($tmp);
            return new WP_Error('paylink_hash_failed', __('GetPayIn update aborted: failed to hash downloaded zip.', 'paylink-woocommerce'));
        }

        if (!hash_equals($expected, strtolower($actual))) {
            @unlink($tmp);
            return new WP_Error(
                'paylink_checksum_mismatch',
                sprintf(
                    /* translators: 1: expected sha256, 2: actual sha256 */
                    __('GetPayIn update aborted: zip checksum mismatch (expected %1$s, got %2$s). The download may be corrupted or tampered with.', 'paylink-woocommerce'),
                    $expected,
                    $actual
                )
            );
        }

        return $tmp;
    }

    /**
     * Hook: rename the unzipped source directory if it doesn't match the
     * installed plugin slug. Without this, an upgrade that ships the plugin
     * inside a differently-named folder would deactivate the plugin.
     *
     * @param string      $source        Path of the unzipped folder.
     * @param string      $remote_source Path of the original zip.
     * @param WP_Upgrader $upgrader      The upgrader object.
     * @param array       $hook_extra    Extra args.
     * @return string|WP_Error
     */
    public function rename_source($source, $remote_source, $upgrader, $hook_extra = array()) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }
        if (!is_dir($source)) {
            return $source;
        }

        $expected = trailingslashit(dirname($source)) . $this->plugin_slug;
        if ($source === $expected) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem || !$wp_filesystem->move($source, $expected, true)) {
            return new WP_Error('paylink_rename_failed', __('Could not rename the update source directory.', 'paylink-woocommerce'));
        }

        return $expected;
    }

    /**
     * Handle the "Check for updates now" link from the gateway settings page.
     *
     * Hits this URL with `?paylink_force_update_check=1&_wpnonce=...`,
     * we clear the transient, then redirect back without the param.
     */
    public function handle_force_check() {
        if (empty($_GET['paylink_force_update_check'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::FORCE_CHECK_ACTION)) {
            return;
        }

        self::clear_cache();
        // Force WP's core plugins-update transient to be rebuilt next page load.
        delete_site_transient('update_plugins');

        $redirect = remove_query_arg(array('paylink_force_update_check', '_wpnonce'));
        wp_safe_redirect(add_query_arg('paylink_update_checked', '1', $redirect));
        exit;
    }
}
