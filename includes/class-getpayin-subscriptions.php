<?php
/**
 * GetPayIn ↔ WooCommerce Subscriptions bridge.
 *
 * GetPayIn owns the billing schedule: once the customer authorizes the mandate at
 * the hosted checkout (first charge, 3DS), GetPayIn charges the vaulted card on the
 * configured cadence and notifies us with signed `subscription.*` webhooks. This
 * bridge therefore treats the WEBHOOK as the source of truth for renewals rather
 * than charging on WooCommerce's own Action Scheduler clock:
 *
 *   - Initial order  → POST /recurring/init → redirect to the hosted checkout.
 *   - subscription.activated  → complete the parent order (activates the subscription).
 *   - subscription.charged    → create + complete a renewal order (deduped by the
 *                               GetPayIn invoice id; reuses any pending renewal the
 *                               WC scheduler may have pre-created, so the two never
 *                               double up).
 *   - payment_failed / suspended / cancelled / completed → mirror onto the subscription.
 *   - WC cancel / pause / resume → mirror back onto the mandate via the lifecycle API.
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bridges GetPayIn recurring mandates and WooCommerce Subscriptions.
 */
class Paylink_Subscriptions {

    /**
     * Singleton instance.
     *
     * @var Paylink_Subscriptions|null
     */
    private static $instance = null;

    /**
     * Lazily-built gateway used when a hook does not hand one in.
     *
     * @var WC_Gateway_Paylink|null
     */
    private $gateway = null;

    /**
     * True while applying a webhook-driven status change, so the WC→GetPayIn
     * lifecycle sync does not echo the change straight back to the server.
     *
     * @var bool
     */
    private $applying_webhook = false;

    /**
     * Whether WooCommerce Subscriptions is present.
     *
     * @return bool
     */
    public static function is_available() {
        return class_exists('WC_Subscriptions') || class_exists('WC_Subscriptions_Core_Plugin');
    }

    /**
     * Register hooks exactly once.
     */
    public static function init() {
        if (self::$instance === null && self::is_available()) {
            self::$instance = new self();
        }
    }

    /**
     * Constructor — wires all bridge hooks.
     */
    private function __construct() {
        add_filter('paylink_process_subscription_payment', array($this, 'maybe_process_subscription'), 10, 3);
        add_filter('paylink_handle_subscription_webhook', array($this, 'handle_subscription_webhook'), 10, 4);
        add_action('woocommerce_scheduled_subscription_payment_' . WC_Gateway_Paylink::GATEWAY_ID, array($this, 'on_scheduled_payment'), 10, 2);
        add_action('woocommerce_subscription_status_updated', array($this, 'on_subscription_status_updated'), 10, 3);
    }

    /**
     * The feature flags to advertise on the gateway when subscriptions are active.
     *
     * @return string[]
     */
    public static function gateway_supports() {
        return array(
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        );
    }

    /**
     * Take over checkout for an order that sets up a subscription.
     *
     * @param array|null        $result  Passthrough result (null = not handled).
     * @param WC_Order          $order   Order being paid.
     * @param WC_Gateway_Paylink $gateway Gateway instance.
     * @return array|null
     */
    public function maybe_process_subscription($result, $order, $gateway) {
        if (!$this->order_contains_subscription($order)) {
            return $result;
        }

        $this->gateway = $gateway;

        $subscriptions = wcs_get_subscriptions_for_order($order, array('order_type' => array('parent')));
        $subscription  = !empty($subscriptions) ? reset($subscriptions) : null;

        if (!$subscription instanceof WC_Subscription) {
            $gateway->log('Subscription order without a linked subscription; aborting.', 'error');
            return $this->failure(__('Could not initialise the subscription. Please contact the store.', 'paylink-woocommerce'));
        }

        if (!$gateway->is_currency_supported($subscription->get_currency())) {
            return $this->failure(sprintf(
                /* translators: %s: currency code */
                __('GetPayIn does not support recurring payments in %s.', 'paylink-woocommerce'),
                $subscription->get_currency()
            ));
        }

        $fields = $this->build_recurring_fields($order, $subscription);

        $result = $gateway->api()->create_recurring($fields, 'wc_sub_' . $subscription->get_id());

        if (empty($result['ok'])) {
            $gateway->log('Recurring init failed: ' . (isset($result['error']) ? $result['error'] : 'unknown'), 'error');
            return $this->failure(sprintf(
                /* translators: %s: error detail */
                __('Subscription error: %s', 'paylink-woocommerce'),
                isset($result['error']) ? wp_strip_all_tags($result['error']) : __('unknown error', 'paylink-woocommerce')
            ));
        }

        $mandate_id  = $result['mandate_id'];
        $invoice_id  = $result['invoice_id'];
        $checkout_url = $result['checkout_url'];

        $subscription->update_meta_data(WC_Gateway_Paylink::META_MANDATE_ID, $mandate_id);
        $subscription->save();

        $order->update_meta_data(WC_Gateway_Paylink::META_MANDATE_ID, $mandate_id);
        $order->update_meta_data(WC_Gateway_Paylink::META_INVOICE_ID, $invoice_id);
        $order->update_meta_data(WC_Gateway_Paylink::META_CHECKOUT_URL, $checkout_url);
        $order->update_meta_data(WC_Gateway_Paylink::META_CHECKOUT_EXPIRES, $result['expires_at']);
        $order->save();

        $order->add_order_note(sprintf(
            /* translators: 1: mandate id, 2: setup invoice id */
            __('GetPayIn subscription mandate %1$s created (setup invoice %2$s).', 'paylink-woocommerce'),
            $mandate_id,
            $invoice_id
        ));

        return array(
            'result'   => 'success',
            'redirect' => $checkout_url,
        );
    }

    /**
     * Handle a verified `subscription.*` webhook.
     *
     * @param bool               $handled    Whether already handled.
     * @param array              $payload    Verified webhook payload.
     * @param array              $normalized Normalised invoice_id/status view.
     * @param WC_Gateway_Paylink $gateway    Gateway instance.
     * @return bool
     */
    public function handle_subscription_webhook($handled, $payload, $normalized, $gateway) {
        $this->gateway = $gateway;

        $mandate_id = isset($payload['mandate_id']) ? (string) $payload['mandate_id'] : '';
        $event      = isset($payload['event']) ? (string) $payload['event'] : '';

        if ($mandate_id === '') {
            return $handled;
        }

        $subscription = $this->find_subscription_by_mandate($mandate_id);

        if (!$subscription instanceof WC_Subscription) {
            $gateway->log(sprintf('Subscription webhook for unknown mandate %s (event: %s).', $mandate_id, $event), 'warning');
            return true;
        }

        $subscription->update_meta_data(WC_Gateway_Paylink::META_MANDATE_STATUS, isset($payload['subscription_status']) ? (string) $payload['subscription_status'] : '');
        $subscription->save();

        $this->applying_webhook = true;

        try {
            switch ($event) {
                case 'subscription.activated':
                    $this->activate_subscription($subscription, $normalized['invoice_id']);
                    break;

                case 'subscription.charged':
                    $this->record_renewal($subscription, $normalized['invoice_id']);
                    break;

                case 'subscription.payment_failed':
                    $subscription->payment_failed();
                    $subscription->add_order_note(__('GetPayIn reported a failed recurring charge; a retry may follow.', 'paylink-woocommerce'));
                    break;

                case 'subscription.suspended':
                    if (!$subscription->has_status(array('cancelled', 'expired'))) {
                        $subscription->update_status('on-hold', __('GetPayIn suspended the subscription after exhausting retries.', 'paylink-woocommerce'));
                    }
                    break;

                case 'subscription.cancelled':
                    if (!$subscription->has_status(array('cancelled', 'expired'))) {
                        $subscription->update_status('cancelled', __('Subscription cancelled at GetPayIn.', 'paylink-woocommerce'));
                    }
                    break;

                case 'subscription.completed':
                    if (!$subscription->has_status(array('cancelled', 'expired'))) {
                        $subscription->update_status('expired', __('Subscription reached the end of its schedule at GetPayIn.', 'paylink-woocommerce'));
                    }
                    break;

                default:
                    $gateway->log('Unhandled subscription webhook event: ' . $event, 'warning');
                    break;
            }
        } catch (Exception $e) {
            $gateway->log('Error handling subscription webhook: ' . $e->getMessage(), 'error');
        }

        $this->applying_webhook = false;

        return true;
    }

    /**
     * Mirror a WooCommerce subscription status change back to the GetPayIn mandate.
     *
     * @param WC_Subscription $subscription Subscription.
     * @param string          $new_status  New status (without wc- prefix).
     * @param string          $old_status  Previous status.
     */
    public function on_subscription_status_updated($subscription, $new_status, $old_status) {
        if ($this->applying_webhook) {
            return;
        }

        if (!$subscription instanceof WC_Subscription || $subscription->get_payment_method() !== WC_Gateway_Paylink::GATEWAY_ID) {
            return;
        }

        $mandate_id = (string) $subscription->get_meta(WC_Gateway_Paylink::META_MANDATE_ID);
        if ($mandate_id === '') {
            return;
        }

        $action = $this->map_status_to_mandate_action($new_status, $old_status);
        if ($action === null) {
            return;
        }

        $result = $this->gateway()->api()->mandate_action($mandate_id, $action);

        if (empty($result['ok'])) {
            $subscription->add_order_note(sprintf(
                /* translators: 1: action, 2: error */
                __('GetPayIn %1$s request failed: %2$s', 'paylink-woocommerce'),
                $action,
                isset($result['error']) ? $result['error'] : __('unknown error', 'paylink-woocommerce')
            ));
            return;
        }

        $subscription->add_order_note(sprintf(
            /* translators: %s: action */
            __('GetPayIn mandate %s request sent.', 'paylink-woocommerce'),
            $action
        ));
    }

    /**
     * GetPayIn charges renewals itself, so the WC-scheduled payment hook only records
     * that the charge is pending; the matching `subscription.charged` webhook then
     * completes this same renewal order.
     *
     * @param float    $amount        Renewal amount (unused; GetPayIn owns the amount).
     * @param WC_Order $renewal_order Pending renewal order created by the scheduler.
     */
    public function on_scheduled_payment($amount, $renewal_order) {
        if (!$renewal_order instanceof WC_Order) {
            return;
        }

        $renewal_order->add_order_note(__('GetPayIn charges this subscription automatically; awaiting the charge webhook to confirm this renewal.', 'paylink-woocommerce'));
        $this->gateway()->log('Scheduled renewal deferred to GetPayIn webhook for order #' . $renewal_order->get_id());
    }

    /**
     * Complete the parent order (and thus activate the subscription) after the
     * customer's first, customer-present charge.
     *
     * @param WC_Subscription $subscription Subscription.
     * @param string          $invoice_id   Setup invoice id.
     */
    private function activate_subscription($subscription, $invoice_id) {
        $parent = $subscription->get_parent();

        if ($parent instanceof WC_Order && !$parent->is_paid()) {
            $parent->update_meta_data(WC_Gateway_Paylink::META_INVOICE_ID, $invoice_id);
            $parent->save();
            $parent->payment_complete($invoice_id);
        }

        if ($subscription->has_status(array('pending', 'on-hold'))) {
            $subscription->update_status('active', __('Subscription activated by GetPayIn.', 'paylink-woocommerce'));
        }
    }

    /**
     * Record a successful recurring charge as a completed renewal order, reusing any
     * pending renewal the scheduler pre-created and deduping on the GetPayIn invoice.
     *
     * @param WC_Subscription $subscription Subscription.
     * @param string          $invoice_id   Renewal invoice id (new each cycle).
     */
    private function record_renewal($subscription, $invoice_id) {
        if ($this->renewal_recorded($subscription, $invoice_id)) {
            $this->gateway()->log(sprintf('Renewal invoice %s already recorded for subscription #%d.', $invoice_id, $subscription->get_id()));
            return;
        }

        $renewal = $this->reusable_renewal_order($subscription);

        if (!$renewal instanceof WC_Order) {
            $renewal = wcs_create_renewal_order($subscription);
        }

        if (is_wp_error($renewal) || !$renewal instanceof WC_Order) {
            $this->gateway()->log('Could not create a renewal order for subscription #' . $subscription->get_id(), 'error');
            return;
        }

        $renewal->set_payment_method(WC_Gateway_Paylink::GATEWAY_ID);
        $renewal->update_meta_data(WC_Gateway_Paylink::META_INVOICE_ID, $invoice_id);
        $renewal->update_meta_data(WC_Gateway_Paylink::META_MANDATE_ID, (string) $subscription->get_meta(WC_Gateway_Paylink::META_MANDATE_ID));
        $renewal->save();

        $renewal->payment_complete($invoice_id);
        $renewal->add_order_note(sprintf(
            /* translators: %s: GetPayIn invoice id */
            __('Recurring charge captured by GetPayIn (invoice %s).', 'paylink-woocommerce'),
            $invoice_id
        ));
    }

    /**
     * Whether a renewal order already carries this GetPayIn invoice id.
     *
     * @param WC_Subscription $subscription Subscription.
     * @param string          $invoice_id   Invoice id.
     * @return bool
     */
    private function renewal_recorded($subscription, $invoice_id) {
        $related = $subscription->get_related_orders('ids', 'renewal');

        foreach ($related as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && (string) $order->get_meta(WC_Gateway_Paylink::META_INVOICE_ID) === (string) $invoice_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * A pending/failed renewal order not yet tied to a GetPayIn invoice, if any.
     *
     * @param WC_Subscription $subscription Subscription.
     * @return WC_Order|null
     */
    private function reusable_renewal_order($subscription) {
        $related = $subscription->get_related_orders('ids', 'renewal');

        foreach ($related as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            if ((string) $order->get_meta(WC_Gateway_Paylink::META_INVOICE_ID) !== '') {
                continue;
            }
            if ($order->has_status(array('pending', 'failed', 'on-hold'))) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Build the signed recurring/init fields in the server's canonical order:
     * first_name, last_name, email, order_title, order_amount, currency,
     * cadence_interval, cadence_count, [end_date], consent_text, external_reference,
     * [redirection_url], [webhook_url].
     *
     * @param WC_Order        $order        Parent order (billing source).
     * @param WC_Subscription $subscription Subscription (schedule + amount source).
     * @return array<string,string>
     */
    private function build_recurring_fields($order, $subscription) {
        $currency = $subscription->get_currency();

        $fields = array(
            'first_name'       => $this->clean($order->get_billing_first_name()),
            'last_name'        => $this->clean($order->get_billing_last_name()),
            'email'            => $this->clean($order->get_billing_email()),
            'order_title'      => $this->clean(sprintf(
                /* translators: %s: order number */
                __('Subscription — Order #%s', 'paylink-woocommerce'),
                $order->get_order_number()
            )),
            'order_amount'     => $this->format_amount($subscription->get_total(), $currency),
            'currency'         => $this->clean($currency),
            'cadence_interval' => $this->clean($subscription->get_billing_period()),
            'cadence_count'    => (string) max(1, (int) $subscription->get_billing_interval()),
        );

        $end_date = $this->subscription_end_date($subscription);
        if ($end_date !== '') {
            $fields['end_date'] = $end_date;
        }

        $fields['consent_text']       = $this->consent_text($subscription, $currency);
        $fields['external_reference'] = 'wc_sub_' . $subscription->get_id();

        $own_urls = $this->own_callback_urls();
        if ($own_urls !== null) {
            $fields['redirection_url'] = $own_urls['redirection_url'];
            $fields['webhook_url']     = $own_urls['webhook_url'];
        }

        return $fields;
    }

    /**
     * The subscription's scheduled end as YYYY-MM-DD, or '' when open-ended.
     *
     * @param WC_Subscription $subscription Subscription.
     * @return string
     */
    private function subscription_end_date($subscription) {
        $end = $subscription->get_date('end');

        if (empty($end) || $end === '0') {
            return '';
        }

        $timestamp = strtotime($end);
        if ($timestamp === false || $timestamp <= time()) {
            return '';
        }

        return gmdate('Y-m-d', $timestamp);
    }

    /**
     * Human-readable consent text stored by GetPayIn for audit.
     *
     * @param WC_Subscription $subscription Subscription.
     * @param string          $currency     Currency code.
     * @return string
     */
    private function consent_text($subscription, $currency) {
        $interval = (int) $subscription->get_billing_interval();
        $period   = $subscription->get_billing_period();
        $cadence  = function_exists('wcs_get_subscription_period_strings')
            ? wcs_get_subscription_period_strings($interval, $period)
            : trim($interval . ' ' . $period);

        return sprintf(
            /* translators: 1: store name, 2: amount, 3: currency, 4: cadence, e.g. "month" */
            __('I authorize %1$s to charge my card %2$s %3$s every %4$s on a recurring basis until I cancel.', 'paylink-woocommerce'),
            get_bloginfo('name'),
            $this->format_amount($subscription->get_total(), $currency),
            $currency,
            $cadence
        );
    }

    /**
     * This store's HTTPS return + webhook URLs for the recurring request, or null.
     *
     * @return array{redirection_url: string, webhook_url: string}|null
     */
    private function own_callback_urls() {
        if ($this->gateway()->send_own_urls !== true) {
            return null;
        }

        $return_url  = home_url('/?wc-api=' . WC_Gateway_Paylink::RETURN_ROUTE);
        $webhook_url = home_url('/?wc-api=' . WC_Gateway_Paylink::WEBHOOK_ROUTE);

        if (strtolower((string) wp_parse_url($return_url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        return array(
            'redirection_url' => $return_url,
            'webhook_url'     => $webhook_url,
        );
    }

    /**
     * Map a WooCommerce subscription status transition to a mandate lifecycle action.
     *
     * @param string $new_status New status.
     * @param string $old_status Previous status.
     * @return string|null cancel|pause|resume, or null for no-op.
     */
    private function map_status_to_mandate_action($new_status, $old_status) {
        if ($new_status === 'cancelled' || $new_status === 'pending-cancel') {
            return 'cancel';
        }

        if ($new_status === 'on-hold') {
            return 'pause';
        }

        if ($new_status === 'active' && $old_status === 'on-hold') {
            return 'resume';
        }

        return null;
    }

    /**
     * Whether the order sets up a subscription (parent / resubscribe / switch).
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    private function order_contains_subscription($order) {
        return function_exists('wcs_order_contains_subscription')
            && wcs_order_contains_subscription($order, array('parent', 'resubscribe', 'switch'));
    }

    /**
     * Find the subscription carrying a given GetPayIn mandate id (HPOS-friendly).
     *
     * @param string $mandate_id Mandate id.
     * @return WC_Subscription|null
     */
    private function find_subscription_by_mandate($mandate_id) {
        $subscriptions = wc_get_orders(array(
            'type'       => 'shop_subscription',
            'limit'      => 1,
            'meta_query' => array(
                array(
                    'key'     => WC_Gateway_Paylink::META_MANDATE_ID,
                    'value'   => $mandate_id,
                    'compare' => '=',
                ),
            ),
        ));

        $subscription = !empty($subscriptions) ? reset($subscriptions) : null;

        return $subscription instanceof WC_Subscription ? $subscription : null;
    }

    /**
     * The active gateway, built on demand when a hook did not hand one in.
     *
     * @return WC_Gateway_Paylink
     */
    private function gateway() {
        if (!$this->gateway instanceof WC_Gateway_Paylink) {
            $this->gateway = new WC_Gateway_Paylink();
        }

        return $this->gateway;
    }

    /**
     * Sanitise a scalar for the signed request.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function clean($value) {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Format a monetary amount identically to the one-off checkout path.
     *
     * @param mixed  $amount   Amount.
     * @param string $currency Currency (reserved for future per-currency rules).
     * @return string
     */
    private function format_amount($amount, $currency) {
        $decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        return function_exists('wc_format_decimal')
            ? wc_format_decimal($amount, $decimals)
            : trim((string) $amount);
    }

    /**
     * Build a checkout failure result for both classic and blocks checkout.
     *
     * @param string $message Customer-facing message.
     * @return array
     */
    private function failure($message) {
        wc_add_notice($message, 'error');

        return array(
            'result'   => 'failure',
            'messages' => $message,
        );
    }
}
