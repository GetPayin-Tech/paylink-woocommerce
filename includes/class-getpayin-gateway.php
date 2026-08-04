<?php
/**
 * GetPayIn Gateway Class
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GetPayIn checkout integration for WooCommerce.
 */
class WC_Gateway_Paylink extends WC_Payment_Gateway {

    const GATEWAY_ID            = 'paylink';
    const TEXT_DOMAIN           = 'paylink-woocommerce';
    const META_INVOICE_ID       = '_paylink_invoice_id';
    const META_CHECKOUT_URL     = '_paylink_checkout_url';
    const META_CHECKOUT_EXPIRES = '_paylink_checkout_expires_at';
    const META_MANDATE_ID       = '_paylink_mandate_id';
    const META_MANDATE_STATUS   = '_paylink_mandate_status';
    const API_ENDPOINT_LIVE     = 'https://pay.getpayin.com';
    const API_ENDPOINT_TEST     = 'https://pay.getpayin.com';
    const LOG_SOURCE            = 'paylink';
    const PROCESS_LOCK_TTL      = 30; // seconds — prevents double-clicks creating two invoices.
    const SUPPORT_EMAIL         = 'support@getpayin.com';
    const REFUND_EMAIL          = 'click2shop@aaib.com';
    const LOGIN_URL             = 'https://pay.getpayin.com/login';
    const RETURN_ROUTE          = 'paylink_return';
    const WEBHOOK_ROUTE         = 'paylink_webhook';

    /**
     * Currencies supported by the GetPayIn `/api/integration/init` endpoint.
     * Per the official OpenAPI spec.
     */
    const SUPPORTED_CURRENCIES = array('EGP', 'EUR', 'USD');

    /**
     * Resolved API endpoint.
     *
     * @var string
     */
    private $api_endpoint;

    /**
     * Cached test-mode flag.
     *
     * @var bool
     */
    public $testmode;

    /**
     * Public token (sent to gateway).
     *
     * @var string
     */
    public $public_token;

    /**
     * Hash token (HMAC secret).
     *
     * @var string
     */
    public $hash_token;

    /**
     * Payment action for one-off checkouts: 'capture' or 'authorize'.
     *
     * @var string
     */
    public $payment_mode;

    /**
     * Whether merchant-fixed installments are offered on the hosted checkout.
     *
     * @var bool
     */
    public $installments_enabled;

    /**
     * Fixed number of installments (2–24) when installments are enabled.
     *
     * @var int
     */
    public $installments;

    /**
     * Whether to send this site's return/webhook URLs with each request (V2),
     * removing the need to configure them in the GetPayIn dashboard.
     *
     * @var bool
     */
    public $send_own_urls;

    /**
     * Lazily-built API client.
     *
     * @var Paylink_Api|null
     */
    private $api = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = self::GATEWAY_ID;
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __('GetPayIn', 'paylink-woocommerce');
        $this->method_description = __('Accept payments via GetPayIn in your WooCommerce store.', 'paylink-woocommerce');

        $this->supports = array(
            'products',
        );

        if (class_exists('Paylink_Subscriptions') && Paylink_Subscriptions::is_available()) {
            $this->supports = array_merge($this->supports, Paylink_Subscriptions::gateway_supports());
        }

        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        $this->testmode     = $this->get_option('testmode') === 'yes';

        if ($this->testmode) {
            $this->public_token = $this->get_option('public_token');
            $this->hash_token   = $this->get_option('hash_token');
        } else {
            $this->public_token = $this->get_option('live_public_token');
            $this->hash_token   = $this->get_option('live_hash_token');
        }

        $this->api_endpoint = $this->testmode ? self::API_ENDPOINT_TEST : self::API_ENDPOINT_LIVE;

        $this->payment_mode         = $this->get_option('payment_mode') === 'authorize' ? 'authorize' : 'capture';
        $this->installments_enabled = $this->get_option('installments_enabled') === 'yes';
        $this->installments         = max(2, min(24, (int) $this->get_option('installments', 3)));
        $this->send_own_urls        = $this->get_option('send_own_urls', 'yes') === 'yes';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_paylink_webhook', array($this, 'handle_webhook'));
        add_action('woocommerce_api_paylink_return', array($this, 'handle_payment_return'));
        add_action('woocommerce_api_paylink_health', array($this, 'handle_health_check'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'render_admin_order_meta'));
    }

    /**
     * Lazily build the shared API client bound to the active environment's tokens.
     *
     * @return Paylink_Api
     */
    public function api() {
        if ($this->api === null) {
            $logger     = array($this, 'log');
            $this->api  = new Paylink_Api($this->api_endpoint, $this->public_token, $this->hash_token, $logger);
        }

        return $this->api;
    }

    /**
     * Initialize Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'hero' => array(
                'type' => 'paylink_hero',
            ),
            'hub' => array(
                'type' => 'paylink_hub',
            ),
            'urls_notice' => array(
                'title' => __('GetPayIn integration setup', 'paylink-woocommerce'),
                'type'  => 'paylink_urls',
            ),
            'enabled' => array(
                'title'       => __('Enable/Disable', 'paylink-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Enable GetPayIn', 'paylink-woocommerce'),
                'default'     => 'no',
                'description' => __('Enable or disable GetPayIn at checkout.', 'paylink-woocommerce'),
            ),
            'title' => array(
                'title'       => __('Title', 'paylink-woocommerce'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'paylink-woocommerce'),
                'default'     => __('Apple Pay / Google Pay / Visa / Mastercard', 'paylink-woocommerce'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'paylink-woocommerce'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'paylink-woocommerce'),
                'default'     => __('Pay securely using your card or wallet via GetPayIn.', 'paylink-woocommerce'),
            ),
            'mode_panel' => array(
                'title' => __('Mode & credentials', 'paylink-woocommerce'),
                'type'  => 'paylink_mode_panel',
            ),
            'testmode' => array(
                'type'    => 'paylink_internal',
                'default' => 'yes',
            ),
            'public_token' => array(
                'type'    => 'paylink_internal',
                'default' => '',
            ),
            'hash_token' => array(
                'type'    => 'paylink_internal',
                'default' => '',
            ),
            'live_public_token' => array(
                'type'    => 'paylink_internal',
                'default' => '',
            ),
            'live_hash_token' => array(
                'type'    => 'paylink_internal',
                'default' => '',
            ),
            'payment_options_title' => array(
                'title'       => __('Payment options', 'paylink-woocommerce'),
                'type'        => 'title',
                'description' => __('Fine-tune how GetPayIn charges your customers.', 'paylink-woocommerce'),
            ),
            'payment_mode' => array(
                'title'       => __('Payment action', 'paylink-woocommerce'),
                'type'        => 'select',
                'default'     => 'capture',
                'options'     => array(
                    'capture'   => __('Capture — charge the card immediately', 'paylink-woocommerce'),
                    'authorize' => __('Authorize — hold funds, capture later', 'paylink-woocommerce'),
                ),
                'description' => __('Authorize places a hold and marks the order “On hold”; capture the funds later from your GetPayIn dashboard. Requires authorize mode enabled on your account.', 'paylink-woocommerce'),
                'desc_tip'    => true,
            ),
            'installments_enabled' => array(
                'title'       => __('Installments', 'paylink-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Offer fixed installments on the GetPayIn checkout', 'paylink-woocommerce'),
                'default'     => 'no',
                'description' => __('Requires installments to be enabled on your GetPayIn account.', 'paylink-woocommerce'),
            ),
            'installments' => array(
                'title'             => __('Number of installments', 'paylink-woocommerce'),
                'type'              => 'number',
                'default'           => '3',
                'custom_attributes' => array('min' => '2', 'max' => '24', 'step' => '1'),
                'description'       => __('Between 2 and 24. Used only when installments are enabled.', 'paylink-woocommerce'),
                'desc_tip'          => true,
            ),
            'send_own_urls' => array(
                'title'       => __('Callback URLs', 'paylink-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Send this store’s return & webhook URLs automatically', 'paylink-woocommerce'),
                'default'     => 'yes',
                'description' => __('When enabled (and the store uses HTTPS), the plugin sends its own return and webhook URLs with every request, so you don’t have to register them in the GetPayIn dashboard. They must resolve to your integration’s registered domain. Disable to use the URLs configured in your dashboard instead.', 'paylink-woocommerce'),
            ),
            'hosted_features_note' => array(
                'title'       => __('More options on the hosted checkout', 'paylink-woocommerce'),
                'type'        => 'title',
                'description' => __('Tipping and multi-currency conversion are configured on your GetPayIn account and appear automatically on the hosted checkout — no plugin setting is needed. Subscriptions require the WooCommerce Subscriptions plugin plus recurring payments enabled on your GetPayIn account.', 'paylink-woocommerce'),
            ),
            'test_cards' => array(
                'title' => __('Test cards', 'paylink-woocommerce'),
                'type'  => 'paylink_test_cards',
            ),
            'support_notice' => array(
                'title' => __('Support', 'paylink-woocommerce'),
                'type'  => 'paylink_support',
            ),
            'refund_notice' => array(
                'title' => __('Refunds', 'paylink-woocommerce'),
                'type'  => 'paylink_refund',
            ),
            'footer' => array(
                'type' => 'paylink_footer',
            ),
            'debug' => array(
                'title'       => __('Debug Log', 'paylink-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'paylink-woocommerce'),
                'default'     => 'no',
                'description' => sprintf(
                    /* translators: %s: full path to the log file */
                    __('Log GetPayIn events, such as API requests, inside %s', 'paylink-woocommerce'),
                    '<code>' . esc_html(wc_get_log_file_path(self::LOG_SOURCE)) . '</code>'
                ),
            ),
            'telemetry' => array(
                'title'       => __('Anonymous usage data', 'paylink-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Help improve the plugin by sharing anonymous usage data', 'paylink-woocommerce'),
                'default'     => 'no',
                'description' => __('Sends only WordPress + WooCommerce versions, plugin version, currency, and aggregate error counts to GetPayIn once a week. No personal data, no tokens, no order details.', 'paylink-woocommerce'),
            ),
        );
    }

    /**
     * Render an empty row for fields whose value is saved by WC but whose UI is
     * rendered inside the `paylink_mode_panel` custom field above.
     *
     * `WC_Settings_API::get_field_value()` falls back to `validate_text_field` when
     * a type-specific validator is not defined, which sanitises the posted value
     * the same way as a plain text field — exactly what we want for the tokens
     * and the yes/no testmode value.
     *
     * @param string $key  Field key.
     * @param array  $data Field config (unused).
     * @return string
     */
    public function generate_paylink_internal_html($key, $data) {
        return '';
    }

    /**
     * Stricter sanitiser for `paylink_internal` fields. Strips tags entirely
     * (vs. WC's default text validator which allows post-safe HTML) — appropriate
     * for tokens and the yes/no testmode flag.
     *
     * @param string $key   Field key.
     * @param mixed  $value Posted value.
     * @return string
     */
    public function validate_paylink_internal_field($key, $value) {
        if (is_null($value)) {
            return '';
        }
        return wp_strip_all_tags(trim((string) wp_unslash($value)));
    }

    /**
     * Render the hero / status banner at the very top of the settings page.
     *
     * Spans both columns of the form-table for a banner effect.
     *
     * @param string $key  Field key.
     * @param array  $data Field config.
     * @return string
     */
    public function generate_paylink_hero_html($key, $data) {
        $saved_mode = $this->get_option('testmode') === 'yes' ? 'test' : 'live';
        $test_pub   = (string) $this->get_option('public_token');
        $test_hash  = (string) $this->get_option('hash_token');
        $live_pub   = (string) $this->get_option('live_public_token');
        $live_hash  = (string) $this->get_option('live_hash_token');
        $enabled    = $this->get_option('enabled') === 'yes';

        if ($saved_mode === 'test') {
            $configured = ($test_pub !== '' && $test_hash !== '');
        } else {
            $configured = ($live_pub !== '' && $live_hash !== '');
        }

        $is_ready = ($enabled && $configured);

        if ($is_ready) {
            $status_class = 'is-ready';
            $status_label = $saved_mode === 'test'
                ? __('Connected · Test mode', 'paylink-woocommerce')
                : __('Connected · Live mode', 'paylink-woocommerce');
            $status_icon  = 'yes-alt';
        } elseif ($enabled && !$configured) {
            $status_class = 'is-warning';
            $status_label = __('Enabled · Credentials missing', 'paylink-woocommerce');
            $status_icon  = 'warning';
        } else {
            $status_class = 'is-idle';
            $status_label = __('Not yet enabled', 'paylink-woocommerce');
            $status_icon  = 'marker';
        }

        // Setup checklist — three high-signal markers.
        $checks = array(
            array(
                'done'  => $configured,
                'label' => $saved_mode === 'test'
                    ? __('Test credentials saved', 'paylink-woocommerce')
                    : __('Live credentials saved', 'paylink-woocommerce'),
            ),
            array(
                'done'  => ($test_pub !== '' && $test_hash !== '') || ($live_pub !== '' && $live_hash !== ''),
                'label' => __('At least one environment configured', 'paylink-woocommerce'),
            ),
            array(
                'done'  => $enabled,
                'label' => __('Gateway enabled at checkout', 'paylink-woocommerce'),
            ),
        );

        ob_start();
        ?>
        <tr valign="top" class="paylink-hero-row">
            <td colspan="2" class="paylink-hero-cell">
                <div class="paylink-hero <?php echo esc_attr($status_class); ?>">
                    <div class="paylink-hero-main">
                        <div class="paylink-hero-brand">
                            <div class="paylink-hero-text">
                                <h2><?php esc_html_e('GetPayIn for WooCommerce', 'paylink-woocommerce'); ?></h2>
                                <p>
                                    <?php
                                    printf(
                                        /* translators: %s: plugin version */
                                        esc_html__('Version %s', 'paylink-woocommerce'),
                                        esc_html(PAYLINK_VERSION)
                                    );
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="paylink-hero-status">
                            <span class="paylink-status-pill paylink-status-pill--<?php echo esc_attr($status_class); ?>">
                                <span class="dashicons dashicons-<?php echo esc_attr($status_icon); ?>" aria-hidden="true"></span>
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </div>
                    </div>
                    <ul class="paylink-hero-checklist">
                        <?php foreach ($checks as $item) : ?>
                            <li class="<?php echo $item['done'] ? 'is-done' : 'is-pending'; ?>">
                                <span class="dashicons dashicons-<?php echo $item['done'] ? 'yes-alt' : 'marker'; ?>" aria-hidden="true"></span>
                                <?php echo esc_html($item['label']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the Hub panel — update + license status with one-click "check now"
     * actions for both the manifest and license endpoints.
     *
     * @param string $key  Field key.
     * @param array  $data Field config.
     * @return string
     */
    public function generate_paylink_hub_html($key, $data) {
        $current_version = PAYLINK_VERSION;

        // Update info.
        $manifest        = class_exists('Paylink_Updater') ? Paylink_Updater::get_manifest() : null;
        $latest_version  = (is_array($manifest) && !isset($manifest['__error']) && !empty($manifest['version']))
            ? (string) $manifest['version']
            : '';
        $update_state    = '';
        if ($latest_version !== '') {
            $update_state = version_compare($latest_version, $current_version, '>') ? 'available' : 'current';
        } elseif (is_array($manifest) && isset($manifest['__error'])) {
            $update_state = 'unreachable';
        } else {
            $update_state = 'unknown';
        }

        $force_check_url = '';
        if (class_exists('Paylink_Updater')) {
            $force_check_url = wp_nonce_url(
                add_query_arg('paylink_force_update_check', '1'),
                Paylink_Updater::FORCE_CHECK_ACTION
            );
        }
        $plugins_screen_url = admin_url('plugins.php');

        // License info.
        $license_state   = class_exists('Paylink_License') ? Paylink_License::get_state() : 'unknown';
        $license_message = class_exists('Paylink_License') ? Paylink_License::get_message() : '';
        $renewal_url     = class_exists('Paylink_License') ? Paylink_License::get_renewal_url() : '';
        $last_checked    = class_exists('Paylink_License') ? Paylink_License::get_last_checked() : 0;

        $license_label = '';
        $license_class = '';
        switch ($license_state) {
            case 'active':
                $license_label = __('Active', 'paylink-woocommerce');
                $license_class = 'is-active';
                break;
            case 'grace':
                $license_label = __('Grace period · renew soon', 'paylink-woocommerce');
                $license_class = 'is-warning';
                break;
            case 'expired':
                $license_label = __('Expired', 'paylink-woocommerce');
                $license_class = 'is-danger';
                break;
            case 'suspended':
                $license_label = __('Suspended', 'paylink-woocommerce');
                $license_class = 'is-danger';
                break;
            default:
                $license_label = __('Not yet verified', 'paylink-woocommerce');
                $license_class = 'is-idle';
                break;
        }

        $update_label = '';
        $update_class = '';
        switch ($update_state) {
            case 'available':
                /* translators: %s: latest version */
                $update_label = sprintf(__('Update available · v%s', 'paylink-woocommerce'), $latest_version);
                $update_class = 'is-warning';
                break;
            case 'current':
                $update_label = __('Up to date', 'paylink-woocommerce');
                $update_class = 'is-active';
                break;
            case 'unreachable':
                $update_label = __('Update server unreachable', 'paylink-woocommerce');
                $update_class = 'is-idle';
                break;
            default:
                $update_label = __('Checking…', 'paylink-woocommerce');
                $update_class = 'is-idle';
                break;
        }

        ob_start();
        ?>
        <tr valign="top" class="paylink-hub-row">
            <td colspan="2" class="paylink-fullwidth-cell">
                <div class="paylink-hub-card">
                    <div class="paylink-section-head">
                        <span class="dashicons dashicons-cloud" aria-hidden="true"></span>
                        <div>
                            <h3><?php esc_html_e('GetPayIn Hub', 'paylink-woocommerce'); ?></h3>
                            <p><?php esc_html_e('Plugin updates and subscription status for your GetPayIn integration.', 'paylink-woocommerce'); ?></p>
                        </div>
                    </div>

                    <div class="paylink-hub-grid">
                        <div class="paylink-hub-tile">
                            <div class="paylink-hub-tile-head">
                                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                <span class="paylink-hub-tile-title"><?php esc_html_e('Plugin updates', 'paylink-woocommerce'); ?></span>
                                <span class="paylink-hub-status-pill <?php echo esc_attr($update_class); ?>"><?php echo esc_html($update_label); ?></span>
                            </div>
                            <div class="paylink-hub-tile-body">
                                <p>
                                    <?php
                                    printf(
                                        /* translators: 1: installed version, 2: latest available version (or em dash) */
                                        esc_html__('Installed: %1$s · Latest: %2$s', 'paylink-woocommerce'),
                                        '<code>' . esc_html($current_version) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        '<code>' . esc_html($latest_version !== '' ? $latest_version : '—') . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                    );
                                    ?>
                                </p>
                                <div class="paylink-hub-tile-actions">
                                    <?php if ($force_check_url !== '') : ?>
                                        <a href="<?php echo esc_url($force_check_url); ?>" class="button">
                                            <span class="dashicons dashicons-update-alt" aria-hidden="true"></span>
                                            <?php esc_html_e('Check for updates', 'paylink-woocommerce'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($update_state === 'available') : ?>
                                        <a href="<?php echo esc_url($plugins_screen_url); ?>" class="button button-primary">
                                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                            <?php esc_html_e('Update now', 'paylink-woocommerce'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php
                        $health_url = '';
                        $hash_token = (string) $this->get_option(
                            $this->testmode ? 'hash_token' : 'live_hash_token'
                        );
                        if ($hash_token !== '') {
                            $health_url = add_query_arg(array(
                                'wc-api' => 'paylink_health',
                                'secret' => $this->compute_health_secret($hash_token),
                            ), home_url('/'));
                        }
                        ?>
                        <div class="paylink-hub-tile">
                            <div class="paylink-hub-tile-head">
                                <span class="dashicons dashicons-id" aria-hidden="true"></span>
                                <span class="paylink-hub-tile-title"><?php esc_html_e('Subscription', 'paylink-woocommerce'); ?></span>
                                <span class="paylink-hub-status-pill <?php echo esc_attr($license_class); ?>"><?php echo esc_html($license_label); ?></span>
                            </div>
                            <div class="paylink-hub-tile-body">
                                <p>
                                    <?php
                                    if ($license_message !== '') {
                                        echo esc_html($license_message);
                                    } elseif ($last_checked > 0) {
                                        printf(
                                            /* translators: %s: relative time, e.g. "2 hours ago" */
                                            esc_html__('Last verified %s.', 'paylink-woocommerce'),
                                            esc_html(human_time_diff($last_checked, time()) . ' ' . __('ago', 'paylink-woocommerce'))
                                        );
                                    } else {
                                        esc_html_e('Not yet checked. Verification runs automatically once the integration is configured.', 'paylink-woocommerce');
                                    }
                                    ?>
                                </p>
                                <div class="paylink-hub-tile-actions">
                                    <?php if ($renewal_url !== '') : ?>
                                        <a href="<?php echo esc_url($renewal_url); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
                                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                                            <?php esc_html_e('Renew subscription', 'paylink-woocommerce'); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="mailto:<?php echo esc_attr(self::SUPPORT_EMAIL); ?>" class="button">
                                            <span class="dashicons dashicons-email" aria-hidden="true"></span>
                                            <?php esc_html_e('Contact billing', 'paylink-woocommerce'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($health_url !== '') : ?>
                                        <button type="button" class="button paylink-copy-btn" data-paylink-copy="<?php echo esc_attr($health_url); ?>" title="<?php echo esc_attr__('Diagnostic URL — share with GetPayIn support if asked', 'paylink-woocommerce'); ?>">
                                            <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                            <span class="paylink-copy-label"><?php esc_html_e('Copy diagnostic URL', 'paylink-woocommerce'); ?></span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the footer card with quick links.
     *
     * @param string $key  Field key.
     * @param array  $data Field config.
     * @return string
     */
    public function generate_paylink_footer_html($key, $data) {
        ob_start();
        ?>
        <tr valign="top" class="paylink-footer-row">
            <td colspan="2" class="paylink-footer-cell">
                <div class="paylink-footer">
                    <div class="paylink-footer-item">
                        <span class="dashicons dashicons-book" aria-hidden="true"></span>
                        <a href="https://pay.getpayin.com/docs/payment_integration/index.html" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('API documentation', 'paylink-woocommerce'); ?>
                        </a>
                    </div>
                    <div class="paylink-footer-item">
                        <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                        <a href="<?php echo esc_url(self::LOGIN_URL); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e('Open GetPayIn dashboard', 'paylink-woocommerce'); ?>
                        </a>
                    </div>
                    <div class="paylink-footer-item">
                        <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                        <a href="mailto:<?php echo esc_attr(self::SUPPORT_EMAIL); ?>">
                            <?php echo esc_html(self::SUPPORT_EMAIL); ?>
                        </a>
                    </div>
                    <div class="paylink-footer-item paylink-footer-item--brand">
                        <?php
                        printf(
                            /* translators: %s: plugin version */
                            esc_html__('GetPayIn for WooCommerce · v%s', 'paylink-woocommerce'),
                            esc_html(PAYLINK_VERSION)
                        );
                        ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the test/live tabbed credentials panel.
     *
     * Both tab panels are rendered server-side; a small vanilla-JS file
     * (`assets/js/admin-settings.js`) handles tab clicks and toggles a hidden
     * input that holds the active mode (yes/no) which WC saves under `testmode`.
     *
     * @param string $key  Field key.
     * @param array  $data Field config.
     * @return string
     */
    public function generate_paylink_mode_panel_html($key, $data) {
        $data = wp_parse_args($data, array(
            'title' => __('Mode & credentials', 'paylink-woocommerce'),
            'class' => '',
        ));

        $saved_mode      = $this->get_option('testmode') === 'yes' ? 'test' : 'live';
        $field_testmode  = $this->get_field_key('testmode');
        $field_test_pub  = $this->get_field_key('public_token');
        $field_test_hash = $this->get_field_key('hash_token');
        $field_live_pub  = $this->get_field_key('live_public_token');
        $field_live_hash = $this->get_field_key('live_hash_token');

        $test_pub  = (string) $this->get_option('public_token');
        $test_hash = (string) $this->get_option('hash_token');
        $live_pub  = (string) $this->get_option('live_public_token');
        $live_hash = (string) $this->get_option('live_hash_token');

        $test_filled = ($test_pub !== '' && $test_hash !== '');
        $live_filled = ($live_pub !== '' && $live_hash !== '');

        ob_start();
        ?>
        <tr valign="top" class="paylink-mode-row">
            <td colspan="2" class="paylink-fullwidth-cell">
                <div class="paylink-section-head">
                    <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                    <div>
                        <h3><?php echo esc_html($data['title']); ?></h3>
                        <p><?php esc_html_e('Choose which environment is active and paste the matching tokens.', 'paylink-woocommerce'); ?></p>
                    </div>
                </div>
                <div class="paylink-mode-panel" data-active-mode="<?php echo esc_attr($saved_mode); ?>">
                    <input type="hidden"
                        id="<?php echo esc_attr($field_testmode); ?>"
                        name="<?php echo esc_attr($field_testmode); ?>"
                        value="<?php echo $saved_mode === 'test' ? 'yes' : 'no'; ?>" />

                    <div class="paylink-active-toggle">
                        <div class="paylink-active-toggle-text">
                            <span class="paylink-active-toggle-title"><?php esc_html_e('Active on the website', 'paylink-woocommerce'); ?></span>
                            <span class="paylink-active-toggle-help"><?php esc_html_e('The highlighted option is the one customers will pay through after you click Save.', 'paylink-woocommerce'); ?></span>
                        </div>
                        <div class="paylink-toggle" role="radiogroup" aria-label="<?php echo esc_attr__('Active environment', 'paylink-woocommerce'); ?>">
                            <button type="button"
                                class="paylink-toggle-option<?php echo $saved_mode === 'test' ? ' is-active' : ''; ?>"
                                data-mode="test"
                                role="radio"
                                aria-checked="<?php echo $saved_mode === 'test' ? 'true' : 'false'; ?>">
                                <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
                                <?php esc_html_e('Test', 'paylink-woocommerce'); ?>
                            </button>
                            <button type="button"
                                class="paylink-toggle-option<?php echo $saved_mode === 'live' ? ' is-active' : ''; ?>"
                                data-mode="live"
                                role="radio"
                                aria-checked="<?php echo $saved_mode === 'live' ? 'true' : 'false'; ?>">
                                <span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
                                <?php esc_html_e('Live', 'paylink-woocommerce'); ?>
                            </button>
                        </div>
                        <p class="paylink-toggle-hint">
                            <span class="dashicons dashicons-info" aria-hidden="true"></span>
                            <span><?php
                                echo wp_kses(
                                    __('To switch to live: click <strong>Live</strong> above, make sure the live tokens below are filled, then scroll down and click <strong>Save changes</strong>.', 'paylink-woocommerce'),
                                    array('strong' => array())
                                );
                            ?></span>
                        </p>
                    </div>

                    <div class="paylink-creds-stack">
                        <section class="paylink-creds-card paylink-creds-card--test<?php echo $saved_mode === 'test' ? ' is-active' : ''; ?>" data-mode="test">
                            <header class="paylink-creds-head">
                                <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
                                <h4><?php esc_html_e('Test credentials', 'paylink-woocommerce'); ?></h4>
                                <span class="paylink-creds-active-pill"><?php esc_html_e('Active', 'paylink-woocommerce'); ?></span>
                                <span class="paylink-mode-creds-state <?php echo $test_filled ? 'is-ready' : 'is-pending'; ?>">
                                    <span class="dashicons dashicons-<?php echo $test_filled ? 'yes-alt' : 'marker'; ?>" aria-hidden="true"></span>
                                    <?php echo $test_filled
                                        ? esc_html__('Configured', 'paylink-woocommerce')
                                        : esc_html__('Incomplete', 'paylink-woocommerce'); ?>
                                </span>
                            </header>
                            <p class="paylink-creds-summary"><?php esc_html_e('Sandbox credentials used to verify the integration before going live.', 'paylink-woocommerce'); ?></p>
                            <p class="paylink-mode-field">
                                <label for="<?php echo esc_attr($field_test_pub); ?>">
                                    <?php esc_html_e('Authentication Token', 'paylink-woocommerce'); ?>
                                    <span class="paylink-status-dot <?php echo $test_pub !== '' ? 'is-filled' : 'is-empty'; ?>" aria-hidden="true"></span>
                                </label>
                                <input type="text"
                                    id="<?php echo esc_attr($field_test_pub); ?>"
                                    name="<?php echo esc_attr($field_test_pub); ?>"
                                    value="<?php echo esc_attr($test_pub); ?>"
                                    placeholder="<?php echo esc_attr__('Paste your Test Authentication Token', 'paylink-woocommerce'); ?>"
                                    autocomplete="off"
                                    spellcheck="false" />
                            </p>
                            <p class="paylink-mode-field">
                                <label for="<?php echo esc_attr($field_test_hash); ?>">
                                    <?php esc_html_e('Hash Token', 'paylink-woocommerce'); ?>
                                    <span class="paylink-status-dot <?php echo $test_hash !== '' ? 'is-filled' : 'is-empty'; ?>" aria-hidden="true"></span>
                                </label>
                                <input type="password"
                                    id="<?php echo esc_attr($field_test_hash); ?>"
                                    name="<?php echo esc_attr($field_test_hash); ?>"
                                    value="<?php echo esc_attr($test_hash); ?>"
                                    placeholder="<?php echo esc_attr__('Paste your Test Hash Token', 'paylink-woocommerce'); ?>"
                                    autocomplete="off"
                                    spellcheck="false" />
                            </p>
                            <?php if (!$test_filled) : ?>
                                <p class="paylink-mode-empty"><?php esc_html_e('Both tokens are required to use the test environment.', 'paylink-woocommerce'); ?></p>
                            <?php endif; ?>
                        </section>

                        <section class="paylink-creds-card paylink-creds-card--live<?php echo $saved_mode === 'live' ? ' is-active' : ''; ?>" data-mode="live">
                            <header class="paylink-creds-head">
                                <span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
                                <h4><?php esc_html_e('Live credentials', 'paylink-woocommerce'); ?></h4>
                                <span class="paylink-creds-active-pill"><?php esc_html_e('Active', 'paylink-woocommerce'); ?></span>
                                <span class="paylink-mode-creds-state <?php echo $live_filled ? 'is-ready' : 'is-pending'; ?>">
                                    <span class="dashicons dashicons-<?php echo $live_filled ? 'yes-alt' : 'marker'; ?>" aria-hidden="true"></span>
                                    <?php echo $live_filled
                                        ? esc_html__('Configured', 'paylink-woocommerce')
                                        : esc_html__('Incomplete', 'paylink-woocommerce'); ?>
                                </span>
                            </header>
                            <div class="paylink-creds-summary paylink-creds-summary--live">
                                <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                                <span><?php
                                    printf(
                                        /* translators: %s: support email rendered as a mailto link */
                                        wp_kses(
                                            __('After a successful test transaction, email <a href="mailto:%1$s">%1$s</a> to request a live account, then paste the live tokens here.', 'paylink-woocommerce'),
                                            array('a' => array('href' => array()))
                                        ),
                                        esc_html(self::SUPPORT_EMAIL)
                                    );
                                ?></span>
                            </div>
                            <p class="paylink-mode-field">
                                <label for="<?php echo esc_attr($field_live_pub); ?>">
                                    <?php esc_html_e('Authentication Token', 'paylink-woocommerce'); ?>
                                    <span class="paylink-status-dot <?php echo $live_pub !== '' ? 'is-filled' : 'is-empty'; ?>" aria-hidden="true"></span>
                                </label>
                                <input type="text"
                                    id="<?php echo esc_attr($field_live_pub); ?>"
                                    name="<?php echo esc_attr($field_live_pub); ?>"
                                    value="<?php echo esc_attr($live_pub); ?>"
                                    placeholder="<?php echo esc_attr__('Paste your Live Authentication Token', 'paylink-woocommerce'); ?>"
                                    autocomplete="off"
                                    spellcheck="false" />
                            </p>
                            <p class="paylink-mode-field">
                                <label for="<?php echo esc_attr($field_live_hash); ?>">
                                    <?php esc_html_e('Hash Token', 'paylink-woocommerce'); ?>
                                    <span class="paylink-status-dot <?php echo $live_hash !== '' ? 'is-filled' : 'is-empty'; ?>" aria-hidden="true"></span>
                                </label>
                                <input type="password"
                                    id="<?php echo esc_attr($field_live_hash); ?>"
                                    name="<?php echo esc_attr($field_live_hash); ?>"
                                    value="<?php echo esc_attr($live_hash); ?>"
                                    placeholder="<?php echo esc_attr__('Paste your Live Hash Token', 'paylink-woocommerce'); ?>"
                                    autocomplete="off"
                                    spellcheck="false" />
                            </p>
                            <?php if (!$live_filled) : ?>
                                <p class="paylink-mode-empty"><?php esc_html_e('Both tokens are required to use the live environment.', 'paylink-woocommerce'); ?></p>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the "GetPayIn integration setup" panel on the gateway settings screen.
     *
     * Shows the Origin / Redirection / Webhook URLs as readonly inputs the merchant
     * can copy into the GetPayIn dashboard, plus the step-by-step instructions.
     *
     * @param string $key  Field key.
     * @param array  $data Field config.
     * @return string
     */
    public function generate_paylink_urls_html($key, $data) {
        $data = wp_parse_args($data, array(
            'title' => __('GetPayIn integration setup', 'paylink-woocommerce'),
            'class' => '',
        ));

        $origin_url  = home_url('/');
        $return_url  = home_url('/?wc-api=paylink_return');
        $webhook_url = home_url('/?wc-api=paylink_webhook');

        $allowed_strong = array('strong' => array());
        $allowed_link   = array('a' => array('href' => array(), 'target' => array(), 'rel' => array()));

        ob_start();
        ?>
        <tr valign="top" class="paylink-urls-row">
            <td colspan="2" class="paylink-fullwidth-cell">
                <div class="paylink-setup-box">
                    <div class="paylink-section-head">
                        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                        <div>
                            <h3><?php esc_html_e('Setup steps', 'paylink-woocommerce'); ?></h3>
                            <p><?php esc_html_e('Follow these steps in your GetPayIn dashboard, then return here to paste the tokens.', 'paylink-woocommerce'); ?></p>
                        </div>
                    </div>
                        <ol class="paylink-step-list">
                            <li>
                                <span class="paylink-step-num">1</span>
                                <div class="paylink-step-body">
                                    <?php
                                    printf(
                                        /* translators: %s: GetPayIn login URL */
                                        wp_kses(__('Log in to your <a href="%s" target="_blank" rel="noopener noreferrer">GetPayIn account</a>.', 'paylink-woocommerce'), $allowed_link),
                                        esc_url(self::LOGIN_URL)
                                    );
                                    ?>
                                </div>
                            </li>
                            <li>
                                <span class="paylink-step-num">2</span>
                                <div class="paylink-step-body">
                                    <?php echo wp_kses(__('Go to <strong>Settings → Payment Integrations</strong> and click <strong>Add new integration</strong>.', 'paylink-woocommerce'), $allowed_strong); ?>
                                </div>
                            </li>
                            <li>
                                <span class="paylink-step-num">3</span>
                                <div class="paylink-step-body">
                                    <p class="paylink-step-text"><?php esc_html_e('Enter an integration name, then copy the URLs below into the matching fields:', 'paylink-woocommerce'); ?></p>
                                    <div class="paylink-url-list">
                                        <?php
                                        $url_rows = array(
                                            array('label' => __('Origin domain', 'paylink-woocommerce'),    'value' => $origin_url),
                                            array('label' => __('Redirection URL', 'paylink-woocommerce'), 'value' => $return_url),
                                            array('label' => __('Webhook URL', 'paylink-woocommerce'),     'value' => $webhook_url),
                                        );
                                        foreach ($url_rows as $row) :
                                            ?>
                                            <div class="paylink-url-row">
                                                <span class="paylink-url-label"><?php echo esc_html($row['label']); ?></span>
                                                <span class="paylink-copy-group">
                                                    <input type="text" readonly value="<?php echo esc_attr($row['value']); ?>" data-paylink-copy-source />
                                                    <button type="button" class="button paylink-copy-btn" data-paylink-copy="<?php echo esc_attr($row['value']); ?>">
                                                        <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                                        <span class="paylink-copy-label"><?php esc_html_e('Copy', 'paylink-woocommerce'); ?></span>
                                                    </button>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </li>
                            <li>
                                <span class="paylink-step-num">4</span>
                                <div class="paylink-step-body">
                                    <?php esc_html_e('Save the new integration.', 'paylink-woocommerce'); ?>
                                </div>
                            </li>
                            <li>
                                <span class="paylink-step-num">5</span>
                                <div class="paylink-step-body">
                                    <?php echo wp_kses(__('Open the integration in <strong>Edit</strong> mode and copy the <strong>Authentication Token</strong> and <strong>Hash Token</strong>.', 'paylink-woocommerce'), $allowed_strong); ?>
                                </div>
                            </li>
                            <li>
                                <span class="paylink-step-num">6</span>
                                <div class="paylink-step-body">
                                    <?php echo wp_kses(__('Paste them into the <strong>Authentication Token</strong> and <strong>Hash Token</strong> fields in the Mode &amp; credentials panel below, then save these settings.', 'paylink-woocommerce'), $allowed_strong); ?>
                                </div>
                            </li>
                        </ol>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the test-cards reference panel on the gateway settings screen.
     *
     * Only rendered while Test Mode is enabled — they have no effect in production
     * and would only confuse merchants on a live site.
     *
     * @param string $key  Field key.
     * @param array  $data Field config.
     * @return string
     */
    public function generate_paylink_test_cards_html($key, $data) {
        if ($this->get_option('testmode') !== 'yes') {
            return '';
        }

        $data = wp_parse_args($data, array(
            'title' => __('Test cards', 'paylink-woocommerce'),
            'class' => '',
        ));

        // Use any future expiry date and any matching-length CVN/CVV.
        // outcome_kind: 'approved' | 'declined' (for badge colour).
        $cards = array(
            array('brand' => __('Visa', 'paylink-woocommerce'),                 'number' => '4111 1111 1111 1111', 'cvn' => '123',  'outcome' => __('Approved', 'paylink-woocommerce'),                       'outcome_kind' => 'approved'),
            array('brand' => __('Visa', 'paylink-woocommerce'),                 'number' => '4000 0000 0000 0002', 'cvn' => '123',  'outcome' => __('Declined (do not honor)', 'paylink-woocommerce'),         'outcome_kind' => 'declined'),
            array('brand' => __('Visa', 'paylink-woocommerce'),                 'number' => '4000 0000 0000 9995', 'cvn' => '123',  'outcome' => __('Declined (insufficient funds)', 'paylink-woocommerce'),   'outcome_kind' => 'declined'),
            array('brand' => __('Visa', 'paylink-woocommerce'),                 'number' => '4000 0000 0000 0069', 'cvn' => '123',  'outcome' => __('Declined (expired card)', 'paylink-woocommerce'),         'outcome_kind' => 'declined'),
            array('brand' => __('Mastercard', 'paylink-woocommerce'),           'number' => '5555 5555 5555 4444', 'cvn' => '123',  'outcome' => __('Approved', 'paylink-woocommerce'),                       'outcome_kind' => 'approved'),
            array('brand' => __('Mastercard (2-series)', 'paylink-woocommerce'),'number' => '2222 4000 7000 0005', 'cvn' => '123',  'outcome' => __('Approved', 'paylink-woocommerce'),                       'outcome_kind' => 'approved'),
        );

        ob_start();
        ?>
        <tr valign="top" class="paylink-test-cards-row">
            <td colspan="2" class="paylink-fullwidth-cell">
                <div class="paylink-test-cards-box">
                    <div class="paylink-section-head">
                        <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
                        <div>
                            <h3><?php esc_html_e('Sandbox card numbers', 'paylink-woocommerce'); ?></h3>
                            <p><?php esc_html_e('Use any future expiry date and any matching-length CVN. Click a card number to copy it.', 'paylink-woocommerce'); ?></p>
                        </div>
                    </div>
                        <table class="widefat striped paylink-test-cards-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Brand', 'paylink-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Card number', 'paylink-woocommerce'); ?></th>
                                    <th><?php esc_html_e('CVN', 'paylink-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Expected outcome', 'paylink-woocommerce'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cards as $card) : ?>
                                    <tr>
                                        <td><?php echo esc_html($card['brand']); ?></td>
                                        <td>
                                            <button type="button" class="paylink-test-card-number" data-paylink-copy="<?php echo esc_attr(str_replace(' ', '', $card['number'])); ?>" title="<?php echo esc_attr__('Click to copy', 'paylink-woocommerce'); ?>">
                                                <?php echo esc_html($card['number']); ?>
                                            </button>
                                        </td>
                                        <td><code><?php echo esc_html($card['cvn']); ?></code></td>
                                        <td>
                                            <span class="paylink-outcome paylink-outcome--<?php echo esc_attr($card['outcome_kind']); ?>">
                                                <span class="dashicons dashicons-<?php echo $card['outcome_kind'] === 'approved' ? 'yes-alt' : 'dismiss'; ?>" aria-hidden="true"></span>
                                                <?php echo esc_html($card['outcome']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the support contact panel on the gateway settings screen.
     *
     * @param string $key  Field key.
     * @param array  $data Field config.
     * @return string
     */
    public function generate_paylink_support_html($key, $data) {
        $data = wp_parse_args($data, array(
            'title' => __('Support', 'paylink-woocommerce'),
            'class' => '',
        ));

        ob_start();
        ?>
        <tr valign="top" class="paylink-support-row">
            <td colspan="2" class="paylink-fullwidth-cell">
                <div class="paylink-support-box">
                        <div class="paylink-support-icon" aria-hidden="true">
                            <span class="dashicons dashicons-email-alt"></span>
                        </div>
                        <div class="paylink-support-body">
                            <h3 class="paylink-support-title"><?php esc_html_e('Need a hand?', 'paylink-woocommerce'); ?></h3>
                            <p class="paylink-support-text">
                                <?php esc_html_e('Reach the GetPayIn team for onboarding, live credentials, or any integration questions:', 'paylink-woocommerce'); ?>
                            </p>
                            <div class="paylink-support-actions">
                                <a href="mailto:<?php echo esc_attr(self::SUPPORT_EMAIL); ?>" class="button button-primary">
                                    <span class="dashicons dashicons-email" aria-hidden="true"></span>
                                    <?php
                                    printf(
                                        /* translators: %s: support email address */
                                        esc_html__('Email %s', 'paylink-woocommerce'),
                                        esc_html(self::SUPPORT_EMAIL)
                                    );
                                    ?>
                                </a>
                                <button type="button" class="button paylink-copy-btn" data-paylink-copy="<?php echo esc_attr(self::SUPPORT_EMAIL); ?>">
                                    <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                    <span class="paylink-copy-label"><?php esc_html_e('Copy email', 'paylink-woocommerce'); ?></span>
                                </button>
                            </div>
                        </div>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the refund-info panel: refunds are not handled inside WooCommerce
     * for this gateway — admins must email a dedicated address.
     *
     * @param string $key  Field key.
     * @param array  $data Field config.
     * @return string
     */
    public function generate_paylink_refund_html($key, $data) {
        $data = wp_parse_args($data, array(
            'title' => __('Refunds', 'paylink-woocommerce'),
            'class' => '',
        ));

        ob_start();
        ?>
        <tr valign="top" class="paylink-refund-row">
            <td colspan="2" class="paylink-fullwidth-cell">
                <div class="paylink-refund-box">
                    <div class="paylink-refund-icon" aria-hidden="true">
                        <span class="dashicons dashicons-undo"></span>
                    </div>
                    <div class="paylink-refund-body">
                        <h3 class="paylink-refund-title"><?php esc_html_e('How to issue a refund', 'paylink-woocommerce'); ?></h3>
                        <p class="paylink-refund-text">
                            <?php
                            printf(
                                /* translators: %s: refund email address (rendered as a mailto link) */
                                wp_kses(
                                    __('Refunds are processed manually by AAIB. To request a refund, email <a href="mailto:%1$s">%1$s</a> with the order number and the GetPayIn invoice ID (visible on the order edit screen).', 'paylink-woocommerce'),
                                    array('a' => array('href' => array()))
                                ),
                                esc_html(self::REFUND_EMAIL)
                            );
                            ?>
                        </p>
                        <div class="paylink-refund-actions">
                            <a href="mailto:<?php echo esc_attr(self::REFUND_EMAIL); ?>" class="button button-primary">
                                <span class="dashicons dashicons-email" aria-hidden="true"></span>
                                <?php
                                printf(
                                    /* translators: %s: refund email */
                                    esc_html__('Email %s', 'paylink-woocommerce'),
                                    esc_html(self::REFUND_EMAIL)
                                );
                                ?>
                            </a>
                            <button type="button" class="button paylink-copy-btn" data-paylink-copy="<?php echo esc_attr(self::REFUND_EMAIL); ?>">
                                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                                <span class="paylink-copy-label"><?php esc_html_e('Copy email', 'paylink-woocommerce'); ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Payment form on checkout page.
     */
    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }

        ?>
        <div id="paylink-payment-form">
            <p><?php echo esc_html__('You will be redirected to GetPayIn to complete your payment.', 'paylink-woocommerce'); ?></p>
            <div class="getpayin-payment-logos" role="list" aria-label="<?php echo esc_attr__('Supported payment methods', 'paylink-woocommerce'); ?>">
                <img src="<?php echo esc_url(PAYLINK_PLUGIN_URL . 'assets/images/apple-pay.svg'); ?>" alt="<?php echo esc_attr__('Apple Pay', 'paylink-woocommerce'); ?>" role="listitem" />
                <img src="<?php echo esc_url(PAYLINK_PLUGIN_URL . 'assets/images/google-pay.svg'); ?>" alt="<?php echo esc_attr__('Google Pay', 'paylink-woocommerce'); ?>" role="listitem" />
                <img src="<?php echo esc_url(PAYLINK_PLUGIN_URL . 'assets/images/visa.svg'); ?>" alt="<?php echo esc_attr__('Visa', 'paylink-woocommerce'); ?>" role="listitem" />
                <img src="<?php echo esc_url(PAYLINK_PLUGIN_URL . 'assets/images/mastercard.svg'); ?>" alt="<?php echo esc_attr__('Mastercard', 'paylink-woocommerce'); ?>" class="getpayin-logo-mastercard" role="listitem" />
            </div>
        </div>
        <?php
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return $this->payment_failure(__('Order not found.', 'paylink-woocommerce'));
        }

        if (empty($this->public_token) || empty($this->hash_token)) {
            $this->log('Payment aborted: gateway credentials are not configured.', 'error');
            return $this->payment_failure(__('GetPayIn is not configured. Please contact the store administrator.', 'paylink-woocommerce'));
        }

        if (!$this->is_currency_supported($order->get_currency())) {
            $this->log(sprintf('Payment aborted: order currency %s is not supported by GetPayIn.', $order->get_currency()), 'warning');
            return $this->payment_failure(sprintf(
                /* translators: 1: order currency code, 2: comma-separated supported currencies */
                __('GetPayIn does not support %1$s. Supported currencies: %2$s.', 'paylink-woocommerce'),
                $order->get_currency(),
                implode(', ', $this->get_supported_currencies())
            ));
        }

        /**
         * Let the subscriptions layer take over when the order sets up a
         * subscription. It returns a WC process_payment array, or null to let the
         * one-off flow below handle an ordinary order.
         *
         * @param array|null $result Subscription result, or null to passthrough.
         * @param WC_Order   $order  Order instance.
         * @param self       $gateway Gateway instance.
         */
        $subscription_result = apply_filters('paylink_process_subscription_payment', null, $order, $this);
        if (is_array($subscription_result)) {
            return $subscription_result;
        }

        $existing_checkout_url = $this->get_reusable_checkout_url($order);
        if ($existing_checkout_url) {
            $this->log(sprintf('Reusing existing GetPayIn checkout for order #%d', $order->get_id()));
            return array(
                'result'   => 'success',
                'redirect' => $existing_checkout_url,
            );
        }

        // Short-lived lock prevents a fast double-click from creating two GetPayIn invoices.
        $lock_key = 'paylink_lock_' . (int) $order_id;
        if (get_transient($lock_key)) {
            $this->log(sprintf('Concurrent process_payment ignored for order #%d (lock held).', $order_id), 'warning');
            return $this->payment_failure(__('A payment is already being processed for this order. Please wait a moment.', 'paylink-woocommerce'));
        }
        set_transient($lock_key, time(), self::PROCESS_LOCK_TTL);

        try {
            $fields = $this->build_checkout_fields($order);

            /**
             * Filter the checkout fields before they are signed and sent to GetPayIn.
             *
             * Shape: array( 'signed' => array<string,string>, 'unsigned' => array<string,string> ).
             * The `signed` map MUST stay in the server's canonical field order — reordering
             * it will invalidate the HMAC signature.
             *
             * @param array    $fields  Signed + unsigned field maps.
             * @param WC_Order $order   Order instance.
             * @param self     $gateway Gateway instance.
             */
            $fields = apply_filters('paylink_checkout_fields', $fields, $order, $this);

            $result = $this->api()->create_checkout(
                isset($fields['signed']) && is_array($fields['signed']) ? $fields['signed'] : array(),
                isset($fields['unsigned']) && is_array($fields['unsigned']) ? $fields['unsigned'] : array(),
                $this->build_idempotency_key($order)
            );

            if (empty($result['ok'])) {
                throw new Exception(isset($result['error']) ? $result['error'] : __('Unknown error from GetPayIn.', 'paylink-woocommerce'));
            }

            $checkout_url = $result['checkout_url'];
            $invoice_id   = $result['invoice_id'];

            $order->update_meta_data(self::META_INVOICE_ID, $invoice_id);
            $order->update_meta_data(self::META_CHECKOUT_URL, $checkout_url);
            $order->update_meta_data(self::META_CHECKOUT_EXPIRES, $result['expires_at']);
            $order->save();

            $order->add_order_note(sprintf(
                /* translators: %s: GetPayIn invoice ID */
                __('GetPayIn checkout link issued (invoice %s).', 'paylink-woocommerce'),
                $invoice_id
            ));

            /**
             * Fires after a GetPayIn checkout has been issued for an order.
             *
             * @param WC_Order $order        Order instance.
             * @param string   $invoice_id   GetPayIn invoice identifier.
             * @param string   $checkout_url GetPayIn checkout URL.
             */
            do_action('paylink_checkout_initiated', $order, $invoice_id, $checkout_url);

            return array(
                'result'   => 'success',
                'redirect' => $checkout_url,
            );

        } catch (Exception $e) {
            $message = $e->getMessage();
            $this->log('Error processing payment: ' . $message, 'error');
            return $this->payment_failure(sprintf(
                /* translators: %s: error detail from the gateway */
                __('Payment error: %s', 'paylink-woocommerce'),
                wp_strip_all_tags($message)
            ));
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Build a checkout failure response that works for both classic and blocks checkout.
     *
     * Classic checkout reads notices added via wc_add_notice. WC Blocks reads the `messages`
     * key in the returned array, so we set both.
     *
     * @param string $message Customer-facing message.
     * @return array
     */
    private function payment_failure($message) {
        wc_add_notice($message, 'error');

        return array(
            'result'   => 'failure',
            'messages' => $message,
        );
    }

    /**
     * Build the signed + unsigned checkout fields for the GetPayIn v2 init call.
     *
     * The `signed` map is ordered to match the server's signature contract exactly:
     * first_name, last_name, email, order_title, order_amount, address, city,
     * country, currency, [redirection_url], [webhook_url]. Unsigned fields
     * (payment_mode, installments) travel in the body but are excluded from the HMAC.
     *
     * @param WC_Order $order Order instance.
     * @return array{signed: array<string,string>, unsigned: array<string,string>}
     */
    private function build_checkout_fields($order) {
        $address_line_1 = $this->normalize_field($order->get_billing_address_1());
        $address_line_2 = $this->normalize_field($order->get_billing_address_2());

        $signed = array(
            'first_name'   => $this->normalize_field($order->get_billing_first_name()),
            'last_name'    => $this->normalize_field($order->get_billing_last_name()),
            'email'        => $this->normalize_field($order->get_billing_email()),
            'order_title'  => $this->normalize_field(sprintf(
                /* translators: %s: order number */
                __('Order #%s', 'paylink-woocommerce'),
                $order->get_order_number()
            )),
            'order_amount' => $this->normalize_amount($order->get_total(), $order->get_currency()),
            'address'      => $this->normalize_field(trim($address_line_1 . ' ' . $address_line_2)),
            'city'         => $this->normalize_field($order->get_billing_city()),
            'country'      => $this->normalize_field($order->get_billing_country()),
            'currency'     => $this->normalize_field($order->get_currency()),
        );

        $own_urls = $this->maybe_own_callback_urls();
        if ($own_urls !== null) {
            $signed['redirection_url'] = $own_urls['redirection_url'];
            $signed['webhook_url']     = $own_urls['webhook_url'];
        }

        $unsigned = array();
        if ($this->payment_mode === 'authorize') {
            $unsigned['payment_mode'] = 'authorize';
        }
        if ($this->installments_enabled) {
            $unsigned['installments_enabled'] = '1';
            $unsigned['installments']         = (string) $this->installments;
        }

        return array('signed' => $signed, 'unsigned' => $unsigned);
    }

    /**
     * This site's return + webhook URLs, sent per-request so the merchant does not
     * have to register them in the dashboard. Returns null when disabled or when the
     * site is not served over HTTPS (the server rejects non-HTTPS callback URLs).
     *
     * @return array{redirection_url: string, webhook_url: string}|null
     */
    private function maybe_own_callback_urls() {
        if (!$this->send_own_urls) {
            return null;
        }

        $return_url  = home_url('/?wc-api=' . self::RETURN_ROUTE);
        $webhook_url = home_url('/?wc-api=' . self::WEBHOOK_ROUTE);

        if (strtolower((string) wp_parse_url($return_url, PHP_URL_SCHEME)) !== 'https') {
            $this->log('Per-request callback URLs skipped: the store is not served over HTTPS.', 'warning');
            return null;
        }

        return array(
            'redirection_url' => $return_url,
            'webhook_url'     => $webhook_url,
        );
    }

    /**
     * Build a stable Idempotency-Key so a retried checkout for the same order never
     * creates a duplicate GetPayIn invoice. The order key is unique and stable per
     * order; capped at the server's 64-character limit.
     *
     * @param WC_Order $order Order instance.
     * @return string
     */
    private function build_idempotency_key($order) {
        return substr('wc_' . $order->get_order_key(), 0, 64);
    }

    /**
     * Handle webhook from GetPayIn.
     */
    public function handle_webhook() {
        if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            $this->log('Webhook rejected: invalid HTTP method', 'warning');
            status_header(405);
            exit;
        }

        if (empty($this->hash_token)) {
            $this->log('Webhook rejected: hash_token is not configured', 'error');
            status_header(503);
            exit;
        }

        $raw_body = file_get_contents('php://input');
        $payload  = $this->parse_webhook_payload($raw_body);

        if (empty($payload)) {
            $this->log('Invalid webhook payload', 'warning');
            status_header(400);
            exit;
        }

        $this->log('Webhook received raw payload: ' . $this->redact_payload_for_log($payload));

        if (!$this->verify_signed_payload($payload)) {
            $this->log('Invalid webhook signature — request rejected', 'error');
            status_header(401);
            exit;
        }

        $normalized = $this->normalize_gateway_payload($payload);

        if (empty($normalized['invoice_id']) || empty($normalized['status'])) {
            $this->log('Webhook missing required fields after normalisation', 'warning');
            status_header(400);
            exit;
        }

        // Subscription lifecycle events bill a fresh invoice id per cycle that maps to
        // no local order; hand them to the subscriptions layer, which correlates by
        // mandate id instead. Returns true when it has handled the event.
        if (isset($payload['mandate_id']) && (string) $payload['mandate_id'] !== '') {
            /**
             * @param bool  $handled    Whether the subscriptions layer handled the event.
             * @param array $payload    Raw verified webhook payload.
             * @param array $normalized Normalised invoice_id/status view.
             * @param self  $gateway    Gateway instance.
             */
            $handled = apply_filters('paylink_handle_subscription_webhook', false, $payload, $normalized, $this);
            if ($handled) {
                status_header(200);
                exit;
            }
        }

        $this->process_webhook_payment($normalized);

        status_header(200);
        exit;
    }

    /**
     * Diagnostic health endpoint — returns a redacted JSON snapshot of the
     * integration state so support can triage merchant issues with one URL.
     *
     * Authenticated via a shared secret derived from the merchant's Hash Token:
     *   secret = first 16 chars of base64( hmac_sha256( 'paylink-health', hash_token ) )
     *
     * Hit with: `?wc-api=paylink_health&secret=<value>`. Returns 401 if missing
     * or wrong, 503 if no Hash Token is configured (no shared secret to compare).
     */
    public function handle_health_check() {
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');

        if (empty($this->hash_token)) {
            status_header(503);
            echo wp_json_encode(array('ok' => false, 'reason' => 'hash_token_not_configured'));
            exit;
        }

        $expected = $this->compute_health_secret($this->hash_token);
        $provided = isset($_GET['secret']) ? sanitize_text_field(wp_unslash($_GET['secret'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($provided === '' || !hash_equals($expected, $provided)) {
            status_header(401);
            echo wp_json_encode(array('ok' => false, 'reason' => 'unauthorized'));
            exit;
        }

        $test_pub  = (string) $this->get_option('public_token');
        $test_hash = (string) $this->get_option('hash_token');
        $live_pub  = (string) $this->get_option('live_public_token');
        $live_hash = (string) $this->get_option('live_hash_token');

        $data = array(
            'ok'              => true,
            'plugin_version'  => PAYLINK_VERSION,
            'wp_version'      => get_bloginfo('version'),
            'wc_version'      => defined('WC_VERSION') ? WC_VERSION : '',
            'php_version'     => PHP_VERSION,
            'site_url'        => home_url('/'),
            'mode'            => $this->testmode ? 'test' : 'live',
            'enabled'         => ($this->enabled === 'yes'),
            'currency'        => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'currency_supported' => function_exists('get_woocommerce_currency')
                ? $this->is_currency_supported(get_woocommerce_currency())
                : null,
            'tokens' => array(
                'test_public' => $test_pub  !== '' ? $this->redact($test_pub)  : null,
                'test_hash'   => $test_hash !== '' ? $this->redact($test_hash) : null,
                'live_public' => $live_pub  !== '' ? $this->redact($live_pub)  : null,
                'live_hash'   => $live_hash !== '' ? $this->redact($live_hash) : null,
            ),
            'license' => class_exists('Paylink_License') ? array(
                'state'        => Paylink_License::get_state(),
                'last_checked' => Paylink_License::get_last_checked(),
            ) : null,
            'updates' => class_exists('Paylink_Updater') ? (function () {
                $manifest = Paylink_Updater::get_manifest();
                return is_array($manifest) && empty($manifest['__error']) ? array(
                    'latest_version' => isset($manifest['version']) ? $manifest['version'] : null,
                    'reachable'      => true,
                ) : array(
                    'latest_version' => null,
                    'reachable'      => false,
                );
            })() : null,
            'webhook_url'  => home_url('/?wc-api=paylink_webhook'),
            'return_url'   => home_url('/?wc-api=paylink_return'),
            'generated_at' => gmdate('c'),
        );

        echo wp_json_encode($data);
        exit;
    }

    /**
     * Compute the shared secret used by the health endpoint.
     *
     * @param string $hash_token Merchant's Hash Token.
     * @return string 16-character secret.
     */
    private function compute_health_secret($hash_token) {
        return substr(base64_encode(hash_hmac('sha256', 'paylink-health', (string) $hash_token, true)), 0, 16);
    }

    /**
     * Handle customer redirect after GetPayIn checkout.
     */
    public function handle_payment_return() {
        $params     = $this->collect_return_params();
        $normalized = $this->normalize_gateway_payload($params);
        $signature  = $normalized['signature'];
        $invoice_id = $normalized['invoice_id'];
        $status     = $normalized['status'];

        $this->log('Processing payment return payload: ' . $this->redact_payload_for_log($normalized));

        if (!$invoice_id) {
            $this->log('Missing invoice ID in payment return.', 'warning');
            $this->redirect_after_return(false);
            return;
        }

        if (!$status) {
            $this->log('Missing status in payment return.', 'warning');
            $this->redirect_after_return(false);
            return;
        }

        $order = $this->find_order_by_invoice_id($invoice_id);

        if (!$order) {
            $this->log('Order not found for return invoice ID: ' . $invoice_id, 'warning');
            $this->redirect_after_return(false);
            return;
        }

        // Fail closed: if a hash token is configured we MUST be able to verify the signature.
        if (!empty($this->hash_token)) {
            if (empty($signature) || !$this->verify_signed_payload($params)) {
                $this->log(sprintf('Return signature verification failed for invoice ID %s — refusing to update order.', $invoice_id), 'error');
                wc_add_notice(__('We could not verify the payment confirmation. If your payment was completed it will be confirmed by webhook shortly.', 'paylink-woocommerce'), 'notice');
                $this->redirect_after_return($order, true);
                return;
            }
        }

        $result = $this->update_order_status_from_gateway($order, $status, 'redirect', $invoice_id);

        switch ($result) {
            case 'success':
                wc_add_notice(__('Payment confirmed successfully.', 'paylink-woocommerce'), 'success');
                $this->redirect_after_return($order, true);
                return;

            case 'authorized':
                wc_add_notice(__('Payment authorized. Your order will be processed once the payment is captured.', 'paylink-woocommerce'), 'success');
                $this->redirect_after_return($order, true);
                return;

            case 'failed':
                wc_add_notice(__('Payment failed. Please try again or choose another payment method.', 'paylink-woocommerce'), 'error');
                $this->redirect_after_return($order, false, true);
                return;

            case 'pending':
                wc_add_notice(__('Payment is still pending. We will update your order once GetPayIn confirms the result.', 'paylink-woocommerce'), 'notice');
                $this->redirect_after_return($order, true);
                return;

            case 'retry':
                wc_add_notice(__('Payment was not completed. Please try again to finish your order.', 'paylink-woocommerce'), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;

            default:
                wc_add_notice(__('We received an unexpected payment status. Please contact support if this persists.', 'paylink-woocommerce'), 'notice');
                $this->redirect_after_return($order, true);
                return;
        }
    }

    /**
     * Verify a GetPayIn callback signature (redirect return OR server webhook).
     *
     * The server signs the concatenated VALUES of an ordered subset of the payload,
     * with no separator, and delivers the signature IN the payload (there is no
     * signature header). The signed fields are, in order:
     *
     *   success, invoice_id, invoice_status, message
     *
     * plus, for subscription.* events only (detected by a present `mandate_id`):
     *
     *   mandate_id, external_reference, subscription_status
     *
     * `event`, `event_triggered_at`, `timezone`, `auth_code`, `refund_amount`,
     * `refund_currency` and `signature` itself are excluded from the signature.
     *
     * @param array $params Decoded callback payload.
     * @return bool
     */
    private function verify_signed_payload(array $params) {
        if (empty($this->hash_token)) {
            return false;
        }

        $signature = isset($params['signature']) ? (string) $params['signature'] : '';
        if ($signature === '') {
            return false;
        }

        $ordered = array(
            $this->param_string($params, 'success'),
            $this->param_string($params, 'invoice_id'),
            $this->param_string($params, 'invoice_status'),
            $this->param_string($params, 'message'),
        );

        if (array_key_exists('mandate_id', $params)) {
            $ordered[] = $this->param_string($params, 'mandate_id');
            $ordered[] = $this->param_string($params, 'external_reference');
            $ordered[] = $this->param_string($params, 'subscription_status');
        }

        $expected = base64_encode(hash_hmac('sha256', implode('', $ordered), $this->hash_token, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Stringify a single payload value for signature reconstruction, matching PHP's
     * `implode` coercion on the server (missing/null → '', bool → '1'/'0').
     *
     * @param array  $params Payload.
     * @param string $key    Field name.
     * @return string
     */
    private function param_string(array $params, $key) {
        if (!array_key_exists($key, $params)) {
            return '';
        }

        $value = $params[$key];

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Redirect customer after processing the GetPayIn return payload.
     *
     * @param WC_Order|false $order             Order instance or false if not available.
     * @param bool           $to_thank_you      Whether to send user to the order received page.
     * @param bool           $force_payment_url Whether to send user back to the payment page.
     */
    private function redirect_after_return($order, $to_thank_you = true, $force_payment_url = false) {
        if ($order instanceof WC_Order) {
            if ($force_payment_url) {
                $redirect_url = $order->get_checkout_payment_url(true);
            } elseif ($to_thank_you) {
                $redirect_url = $order->get_checkout_order_received_url();
            } else {
                $redirect_url = wc_get_checkout_url();
            }
        } else {
            $redirect_url = wc_get_checkout_url();
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Process webhook payment status update.
     *
     * @param array $payload Normalised payload.
     */
    private function process_webhook_payment($payload) {
        $invoice_id = $payload['invoice_id'];
        $status     = $payload['status'];

        $order = $this->find_order_by_invoice_id($invoice_id);

        if (!$order) {
            $this->log('Order not found for invoice ID: ' . $invoice_id, 'warning');
            return;
        }

        $this->update_order_status_from_gateway($order, $status, 'webhook', $invoice_id);
    }

    /**
     * Enqueue payment scripts on the checkout page.
     */
    public function payment_scripts() {
        if (!is_checkout() || $this->enabled !== 'yes') {
            return;
        }

        wp_enqueue_script(
            'paylink-checkout',
            PAYLINK_PLUGIN_URL . 'assets/js/checkout.js',
            array(),
            PAYLINK_VERSION,
            true
        );
    }

    /**
     * Log a message via the WC logger when debug mode is enabled.
     *
     * @param string $message Message to log.
     * @param string $level   Logger level (info, warning, error).
     */
    public function log($message, $level = 'info') {
        if ($this->get_option('debug') !== 'yes') {
            return;
        }

        $logger = wc_get_logger();
        $context = array('source' => self::LOG_SOURCE);

        switch ($level) {
            case 'warning':
                $logger->warning($message, $context);
                break;
            case 'error':
                $logger->error($message, $context);
                break;
            default:
                $logger->info($message, $context);
        }
    }

    /**
     * Locate an order using the stored GetPayIn invoice ID.
     *
     * @param string $invoice_id GetPayIn invoice identifier.
     * @return WC_Order|false
     */
    private function find_order_by_invoice_id($invoice_id) {
        if (empty($invoice_id)) {
            return false;
        }

        $orders = wc_get_orders(array(
            'limit'      => 1,
            'meta_query' => array(
                array(
                    'key'     => self::META_INVOICE_ID,
                    'value'   => $invoice_id,
                    'compare' => '=',
                ),
            ),
        ));

        return !empty($orders) ? $orders[0] : false;
    }

    /**
     * Sync WooCommerce order status with GetPayIn status notifications.
     *
     * @param WC_Order $order      Order instance.
     * @param string   $status     Status string from GetPayIn.
     * @param string   $source     Notification source (webhook|redirect).
     * @param string   $invoice_id Invoice identifier from GetPayIn.
     * @return string Normalised result (success|authorized|failed|pending|retry|refunded|unknown).
     */
    private function update_order_status_from_gateway($order, $status, $source, $invoice_id = '') {
        if (!$order instanceof WC_Order) {
            return 'unknown';
        }

        $normalized_status = strtolower(trim((string) $status));
        /* translators: %s: notification source */
        $context = sprintf(__('GetPayIn %s notification', 'paylink-woocommerce'), $source);

        switch ($normalized_status) {
            case 'completed':
            case 'success':
            case 'paid':
            case 'processing':
                if (!$order->is_paid()) {
                    $order->payment_complete($invoice_id);
                    $order->add_order_note(sprintf(
                        /* translators: %s: notification context */
                        __('Payment completed via GetPayIn (%s).', 'paylink-woocommerce'),
                        $context
                    ));
                    $this->log(sprintf('Payment completed for order #%d (status: %s, source: %s)', $order->get_id(), $normalized_status, $source));

                    /**
                     * Fires after an order has been marked paid via GetPayIn.
                     *
                     * @param WC_Order $order      Order instance.
                     * @param string   $invoice_id GetPayIn invoice identifier.
                     * @param string   $source     Notification source (webhook|redirect).
                     */
                    do_action('paylink_payment_completed', $order, $invoice_id, $source);
                } else {
                    $this->log(sprintf('Order #%d already marked paid (source: %s, status: %s)', $order->get_id(), $source, $normalized_status));
                }
                return 'success';

            case 'failed':
            case 'failure':
            case 'declined':
            case 'canceled':
            case 'cancelled':
                if (!$order->is_paid()) {
                    $order->delete_meta_data(self::META_CHECKOUT_URL);
                    $order->delete_meta_data(self::META_CHECKOUT_EXPIRES);
                    $order->update_status('failed', sprintf(
                        /* translators: %s: notification context */
                        __('Payment failed via GetPayIn (%s).', 'paylink-woocommerce'),
                        $context
                    ));
                    $this->log(sprintf('Payment failed for order #%d (status: %s, source: %s)', $order->get_id(), $normalized_status, $source), 'warning');

                    /**
                     * Fires after an order has been marked failed via GetPayIn.
                     *
                     * @param WC_Order $order      Order instance.
                     * @param string   $invoice_id GetPayIn invoice identifier.
                     * @param string   $source     Notification source (webhook|redirect).
                     * @param string   $status     Raw status string from GetPayIn.
                     */
                    do_action('paylink_payment_failed', $order, $invoice_id, $source, $normalized_status);
                } else {
                    $this->log(sprintf('Ignored failure status for already paid order #%d (source: %s)', $order->get_id(), $source));
                }
                return 'failed';

            case 'unpaid':
                $order->update_status('pending', sprintf(
                    /* translators: %s: notification context */
                    __('Payment marked unpaid via GetPayIn (%s).', 'paylink-woocommerce'),
                    $context
                ));
                $this->log(sprintf('Payment marked unpaid for order #%d (status: %s, source: %s)', $order->get_id(), $normalized_status, $source));
                return 'retry';

            case 'pending':
            case 'initiated':
            case 'in_progress':
                $order->update_status('pending', sprintf(
                    /* translators: %s: notification context */
                    __('Payment pending via GetPayIn (%s).', 'paylink-woocommerce'),
                    $context
                ));
                $this->log(sprintf('Payment pending for order #%d (status: %s, source: %s)', $order->get_id(), $normalized_status, $source));
                return 'pending';

            case 'authorized':
                if (!$order->is_paid()) {
                    $order->update_status('on-hold', sprintf(
                        /* translators: %s: notification context */
                        __('Payment authorized via GetPayIn — awaiting capture (%s).', 'paylink-woocommerce'),
                        $context
                    ));
                    $this->log(sprintf('Payment authorized for order #%d (source: %s)', $order->get_id(), $source));

                    /**
                     * Fires after an order has been authorized (funds held, not captured).
                     *
                     * @param WC_Order $order      Order instance.
                     * @param string   $invoice_id GetPayIn invoice identifier.
                     * @param string   $source     Notification source (webhook|redirect).
                     */
                    do_action('paylink_payment_authorized', $order, $invoice_id, $source);
                } else {
                    $this->log(sprintf('Ignored authorized status for already paid order #%d (source: %s)', $order->get_id(), $source));
                }
                return 'authorized';

            case 'voided':
            case 'reversed':
                if (!$order->is_paid()) {
                    $order->update_status('cancelled', sprintf(
                        /* translators: %s: notification context */
                        __('Authorization voided/reversed via GetPayIn (%s).', 'paylink-woocommerce'),
                        $context
                    ));
                    $this->log(sprintf('Authorization voided/reversed for order #%d (status: %s, source: %s)', $order->get_id(), $normalized_status, $source), 'warning');
                }
                return 'failed';

            case 'refunded':
            case 'partially_refunded':
                $order->add_order_note(sprintf(
                    /* translators: 1: status string, 2: notification context */
                    __('GetPayIn reported a %1$s status (%2$s). Reconcile the refund in WooCommerce if required.', 'paylink-woocommerce'),
                    $normalized_status,
                    $context
                ));
                $this->log(sprintf('Refund status "%s" received for order #%d (source: %s)', $normalized_status, $order->get_id(), $source));
                return 'refunded';

            default:
                $order->add_order_note(sprintf(
                    /* translators: 1: status string, 2: notification context */
                    __('Received unhandled GetPayIn status "%1$s" (%2$s).', 'paylink-woocommerce'),
                    $normalized_status,
                    $context
                ));
                $this->log(sprintf('Unhandled GetPayIn status "%s" for order #%d (source: %s)', $normalized_status, $order->get_id(), $source), 'warning');
                return 'unknown';
        }
    }

    /**
     * Normalise scalar fields so signature and request payload stay in sync.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function normalize_field($value) {
        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * Normalise monetary value for signature generation.
     *
     * @param mixed  $amount   Order amount.
     * @param string $currency Currency code.
     * @return string
     */
    private function normalize_amount($amount, $currency) {
        if (!function_exists('wc_format_decimal')) {
            return $this->normalize_field($amount);
        }

        $decimals  = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
        $formatted = wc_format_decimal($amount, $decimals);

        return $this->normalize_field($formatted);
    }

    /**
     * Collect return parameters from GET/POST/JSON payloads.
     *
     * @return array
     */
    private function collect_return_params() {
        $params = array();

        $sources = array(
            wp_unslash($_GET),  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_unslash($_POST), // phpcs:ignore WordPress.Security.NonceVerification.Missing
        );

        foreach ($sources as $source) {
            if (empty($source) || !is_array($source)) {
                continue;
            }

            foreach ($source as $key => $value) {
                if (is_array($value)) {
                    continue;
                }
                $params[(string) $key] = (string) $value;
            }
        }

        if (!empty($params)) {
            return $params;
        }

        $raw_body = file_get_contents('php://input');

        if ($raw_body) {
            $decoded = json_decode($raw_body, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_scalar($value)) {
                        $params[(string) $key] = (string) $value;
                    }
                }
            } else {
                $fallback = array();
                parse_str($raw_body, $fallback);

                if (!empty($fallback) && is_array($fallback)) {
                    foreach ($fallback as $key => $value) {
                        if (is_scalar($value)) {
                            $params[(string) $key] = (string) $value;
                        }
                    }
                }
            }
        }

        return $params;
    }

    /**
     * Normalise raw payload data from GetPayIn into a consistent structure.
     *
     * @param array $data Raw payload.
     * @return array
     */
    private function normalize_gateway_payload(array $data) {
        $invoice_id = $this->get_array_value($data, array('invoiceId', 'invoice_id', 'invoice'));
        $status     = $this->get_array_value($data, array('status', 'invoice_status', 'payment_status'));

        if (!$status && array_key_exists('success', $data)) {
            $status = $this->bool_from_value($data['success']) ? 'success' : 'failed';
        }

        $signature = $this->get_array_value($data, array('signature', 'signatureValue', 'sign'));
        $message   = $this->get_array_value($data, array('message', 'status_message', 'message_text'));

        return array(
            'invoice_id' => $invoice_id,
            'status'     => $status,
            'signature'  => $signature,
            'message'    => $message,
            'success'    => array_key_exists('success', $data) ? $this->bool_from_value($data['success']) : null,
            'raw'        => $data,
        );
    }

    /**
     * Retrieve first matching value from an array using multiple keys.
     *
     * @param array $array Source array.
     * @param array $keys  Keys to inspect.
     * @return string
     */
    private function get_array_value(array $array, array $keys) {
        foreach ($keys as $key) {
            if (isset($array[$key]) && $array[$key] !== '') {
                return (string) $array[$key];
            }
        }

        return '';
    }

    /**
     * Convert various scalar values into boolean context.
     *
     * @param mixed $value Raw value.
     * @return bool
     */
    private function bool_from_value($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, array('1', 'true', 'yes', 'y', 'success', 'paid', 'completed'), true);
    }

    /**
     * Parse webhook payload supporting JSON and form-encoded bodies.
     *
     * @param string $raw_body Raw request body.
     * @return array
     */
    private function parse_webhook_payload($raw_body) {
        if (empty($raw_body)) {
            return array();
        }

        $decoded = json_decode($raw_body, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $parsed = array();
        parse_str($raw_body, $parsed);

        if (!empty($parsed) && is_array($parsed)) {
            $clean = array();
            foreach ($parsed as $key => $value) {
                if (is_scalar($value)) {
                    $clean[(string) $key] = (string) $value;
                }
            }
            return $clean;
        }

        return array();
    }

    /**
     * Check if this gateway is available for use.
     *
     * @return bool
     */
    public function is_available() {
        if (!parent::is_available()) {
            return false;
        }

        if (empty($this->public_token) || empty($this->hash_token)) {
            return false;
        }

        if (!$this->is_currency_supported(get_woocommerce_currency())) {
            return false;
        }

        if (class_exists('Paylink_License') && !Paylink_License::is_allowed()) {
            return false;
        }

        return true;
    }

    /**
     * Currencies the GetPayIn `/api/integration/init` endpoint accepts.
     *
     * Filterable so other plugins (or merchants whose accounts have been provisioned for
     * additional currencies) can extend the list.
     *
     * @return string[]
     */
    public function get_supported_currencies() {
        $currencies = array_map('strtoupper', array_filter(
            (array) apply_filters('paylink_supported_currencies', self::SUPPORTED_CURRENCIES, $this),
            'is_string'
        ));

        return array_values(array_unique($currencies));
    }

    /**
     * Whether the given currency code is in the supported list.
     *
     * @param string $currency Currency code (3-letter ISO 4217).
     * @return bool
     */
    public function is_currency_supported($currency) {
        return in_array(strtoupper((string) $currency), $this->get_supported_currencies(), true);
    }

    /**
     * Get payment method data for block-based checkout.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'icon'        => $this->icon,
            'supports'    => $this->supports,
        );
    }

    /**
     * Get payment method script handles for block-based checkout.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        return array('paylink-checkout');
    }


    /**
     * Return a still-usable GetPayIn checkout URL for the given order, or empty string.
     *
     * @param WC_Order $order Order instance.
     * @return string
     */
    private function get_reusable_checkout_url($order) {
        if (!$order instanceof WC_Order) {
            return '';
        }

        $invoice_id   = (string) $order->get_meta(self::META_INVOICE_ID);
        $checkout_url = (string) $order->get_meta(self::META_CHECKOUT_URL);

        if ($invoice_id === '' || $checkout_url === '') {
            return '';
        }

        if ($order->is_paid()) {
            return '';
        }

        if ($order->has_status(array('failed', 'cancelled', 'refunded'))) {
            return '';
        }

        if ($this->is_checkout_expired((string) $order->get_meta(self::META_CHECKOUT_EXPIRES))) {
            return '';
        }

        if (!wp_http_validate_url($checkout_url)) {
            return '';
        }

        return $checkout_url;
    }

    /**
     * Decide whether a stored expires_at value is in the past.
     *
     * Accepts unix seconds, unix milliseconds, or any strtotime()-parsable string.
     * An empty / unparsable value is treated as "no expiry" (not expired).
     *
     * @param string $expires_at Stored expiry value.
     * @return bool
     */
    private function is_checkout_expired($expires_at) {
        $expires_at = trim((string) $expires_at);
        if ($expires_at === '') {
            return false;
        }

        if (is_numeric($expires_at)) {
            $ts = (float) $expires_at;
            if ($ts > 1e11) {
                $ts = $ts / 1000;
            }
            return (int) $ts <= time();
        }

        $ts = strtotime($expires_at);
        if (false === $ts) {
            return false;
        }

        return $ts <= time();
    }

    /**
     * Format an expires_at value for human display in the admin.
     *
     * @param string $expires_at Stored expiry value.
     * @return string
     */
    private function format_expiry_for_display($expires_at) {
        $expires_at = trim((string) $expires_at);
        if ($expires_at === '') {
            return '';
        }

        $ts = is_numeric($expires_at) ? (float) $expires_at : strtotime($expires_at);
        if (!$ts) {
            return $expires_at;
        }
        if ($ts > 1e11) {
            $ts = $ts / 1000;
        }

        $format = trim(get_option('date_format') . ' ' . get_option('time_format'));
        return wp_date($format, (int) $ts);
    }

    /**
     * Render the GetPayIn payment panel on the order edit screen.
     *
     * Hooked to `woocommerce_admin_order_data_after_billing_address`, which is
     * fired by both the legacy posts-based screen and the HPOS order screen.
     *
     * @param WC_Order $order Order instance.
     */
    public function render_admin_order_meta($order) {
        if (!$order instanceof WC_Order) {
            return;
        }

        if ($order->get_payment_method() !== self::GATEWAY_ID) {
            return;
        }

        $invoice_id = (string) $order->get_meta(self::META_INVOICE_ID);
        if ($invoice_id === '') {
            return;
        }

        $checkout_url = (string) $order->get_meta(self::META_CHECKOUT_URL);
        $expires_at   = (string) $order->get_meta(self::META_CHECKOUT_EXPIRES);
        $expired      = $this->is_checkout_expired($expires_at);
        $is_paid      = $order->is_paid();

        /**
         * Filter the URL used to deep-link from an order to the matching GetPayIn invoice.
         *
         * Default: empty string (no link). Return a full URL to render the invoice ID as a link.
         *
         * @param string   $url        Default empty.
         * @param string   $invoice_id GetPayIn invoice identifier.
         * @param WC_Order $order      Order instance.
         */
        $default_dashboard_url = $invoice_id !== ''
            ? 'https://pay.getpayin.com/invoices/' . rawurlencode($invoice_id)
            : '';
        $dashboard_url = (string) apply_filters('paylink_admin_invoice_url', $default_dashboard_url, $invoice_id, $order);

        ?>
        <div class="paylink-order-meta">
            <h4><?php esc_html_e('GetPayIn Payment', 'paylink-woocommerce'); ?></h4>
            <p>
                <strong><?php esc_html_e('Invoice ID:', 'paylink-woocommerce'); ?></strong>
                <?php if ($dashboard_url !== '') : ?>
                    <a href="<?php echo esc_url($dashboard_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($invoice_id); ?></a>
                <?php else : ?>
                    <code><?php echo esc_html($invoice_id); ?></code>
                <?php endif; ?>
            </p>
            <?php
            $mandate_id     = (string) $order->get_meta(self::META_MANDATE_ID);
            $mandate_status = (string) $order->get_meta(self::META_MANDATE_STATUS);
            if ($mandate_id !== '') :
                ?>
                <p>
                    <strong><?php esc_html_e('Subscription mandate:', 'paylink-woocommerce'); ?></strong>
                    <code><?php echo esc_html($mandate_id); ?></code>
                    <?php if ($mandate_status !== '') : ?>
                        <span class="paylink-mandate-status">(<?php echo esc_html($mandate_status); ?>)</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($expires_at !== '') : ?>
                <p>
                    <strong><?php esc_html_e('Checkout expires:', 'paylink-woocommerce'); ?></strong>
                    <?php echo esc_html($this->format_expiry_for_display($expires_at)); ?>
                    <?php if ($expired) : ?>
                        <span class="paylink-expired">(<?php esc_html_e('expired', 'paylink-woocommerce'); ?>)</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($checkout_url !== '' && !$is_paid && !$expired) : ?>
                <p>
                    <a href="<?php echo esc_url($checkout_url); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary"><?php esc_html_e('Open GetPayIn checkout', 'paylink-woocommerce'); ?></a>
                </p>
            <?php endif; ?>
            <?php if ($is_paid) :
                $subject = sprintf(
                    /* translators: 1: order number, 2: invoice id */
                    __('Refund request — Order #%1$s (invoice %2$s)', 'paylink-woocommerce'),
                    $order->get_order_number(),
                    $invoice_id
                );
                $body = sprintf(
                    /* translators: 1: site URL, 2: order number, 3: invoice id, 4: amount, 5: currency */
                    __("Hi,\n\nPlease refund the following order:\n\nSite: %1\$s\nOrder #: %2\$s\nInvoice ID: %3\$s\nAmount: %4\$s %5\$s\n\nReason: ", 'paylink-woocommerce'),
                    home_url('/'),
                    $order->get_order_number(),
                    $invoice_id,
                    $order->get_total(),
                    $order->get_currency()
                );
                $mailto = 'mailto:' . self::REFUND_EMAIL
                    . '?subject=' . rawurlencode($subject)
                    . '&body='    . rawurlencode($body);
                ?>
                <p>
                    <a href="<?php echo esc_url($mailto); ?>" class="button"><?php
                        printf(
                            /* translators: %s: refund email */
                            esc_html__('Request refund via %s', 'paylink-woocommerce'),
                            esc_html(self::REFUND_EMAIL)
                        );
                    ?></a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Redact a likely-sensitive string for logging.
     *
     * @param string $value Value to redact.
     * @return string
     */
    private function redact($value) {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4) . str_repeat('*', max(0, $length - 8)) . substr($value, -4);
    }

    /**
     * JSON-encode a payload for log output, redacting sensitive keys.
     *
     * @param array $payload Payload data.
     * @return string
     */
    private function redact_payload_for_log($payload) {
        if (!is_array($payload)) {
            return '';
        }

        $sensitive = array('signature', 'signatureValue', 'sign', 'token', 'hash_token', 'public_token', 'authorization');
        $copy      = array();

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $copy[$key] = $this->redact_payload_for_log($value);
                continue;
            }
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $copy[$key] = $this->redact((string) $value);
            } else {
                $copy[$key] = $value;
            }
        }

        $encoded = wp_json_encode($copy);
        if (!is_string($encoded)) {
            return '';
        }

        if (strlen($encoded) > 4000) {
            $encoded = substr($encoded, 0, 4000) . '...';
        }

        return $encoded;
    }
}
