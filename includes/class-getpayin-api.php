<?php
/**
 * GetPayIn API client.
 *
 * Thin wrapper around the GetPayIn v2 integration endpoints. Encapsulates the
 * one place the request-signing contract lives so the one-off gateway and the
 * recurring/subscriptions layer can never drift apart.
 *
 * Signing contract (must mirror the server):
 *   signature = base64( hmac_sha256( implode('', $signed_values), hash_token ) )
 * where $signed_values are the SIGNED fields only, in the exact order the server
 * validates them, concatenated with no separator. Unsigned fields (token,
 * payment_mode, installments_enabled, installments) travel in the body but are
 * excluded from the signature.
 *
 * @package GetPayIn_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GetPayIn v2 integration API client.
 */
class Paylink_Api {

    const INIT_ENDPOINT            = '/api/v2/integration/init';
    const RECURRING_INIT_ENDPOINT  = '/api/v2/integration/recurring/init';
    const RECURRING_BASE_ENDPOINT  = '/api/v2/integration/recurring/';

    /**
     * Resolved API base URL (no trailing slash).
     *
     * @var string
     */
    private $base_url;

    /**
     * Public (authentication) token sent as `token`.
     *
     * @var string
     */
    private $public_token;

    /**
     * Hash token — the HMAC secret shared with the server.
     *
     * @var string
     */
    private $hash_token;

    /**
     * Optional logger: callable( string $message, string $level ).
     *
     * @var callable|null
     */
    private $logger;

    /**
     * @param string        $base_url     API base URL, e.g. https://pay.getpayin.com
     * @param string        $public_token Public/authentication token.
     * @param string        $hash_token   Hash token (HMAC secret).
     * @param callable|null $logger       Optional logger callback.
     */
    public function __construct($base_url, $public_token, $hash_token, $logger = null) {
        $this->base_url     = rtrim((string) $base_url, '/');
        $this->public_token = (string) $public_token;
        $this->hash_token   = (string) $hash_token;
        $this->logger       = is_callable($logger) ? $logger : null;
    }

    /**
     * Sign an ordered list of already-stringified field values.
     *
     * @param array $ordered_values Signed values in server-validation order.
     * @return string Base64 HMAC-SHA256 signature.
     */
    public function sign(array $ordered_values) {
        $concatenated = implode('', $ordered_values);

        return base64_encode(hash_hmac('sha256', $concatenated, $this->hash_token, true));
    }

    /**
     * Create a one-off checkout invoice (`/init`).
     *
     * @param array       $signed          Ordered map of SIGNED fields (server order).
     * @param array       $unsigned        Map of unsigned passthrough fields (payment_mode, installments_*).
     * @param string|null $idempotency_key Optional Idempotency-Key header value.
     * @return array {
     *     @type bool        $ok
     *     @type string      $checkout_url
     *     @type string      $invoice_id
     *     @type string      $expires_at
     *     @type string      $error   Present when ok is false.
     * }
     */
    public function create_checkout(array $signed, array $unsigned = array(), $idempotency_key = null) {
        return $this->post_checkout(self::INIT_ENDPOINT, $signed, $unsigned, $idempotency_key, 'invoice_id');
    }

    /**
     * Create a recurring mandate + setup checkout (`/recurring/init`).
     *
     * @param array       $signed          Ordered map of SIGNED fields (server order).
     * @param string|null $idempotency_key Optional Idempotency-Key header value.
     * @return array Same shape as create_checkout(), plus `mandate_id` when ok.
     */
    public function create_recurring(array $signed, $idempotency_key = null) {
        return $this->post_checkout(self::RECURRING_INIT_ENDPOINT, $signed, array(), $idempotency_key, 'mandate_id');
    }

    /**
     * Cancel / pause / resume a mandate. Signature is HMAC over the mandate uid.
     *
     * @param string $uid    Mandate uid.
     * @param string $action One of `cancel`, `pause`, `resume`.
     * @return array { @type bool $ok, @type array $data, @type string $error }
     */
    public function mandate_action($uid, $action) {
        $uid    = (string) $uid;
        $action = (string) $action;

        if (!in_array($action, array('cancel', 'pause', 'resume'), true)) {
            return array('ok' => false, 'error' => 'Unsupported mandate action.');
        }

        $url  = $this->base_url . self::RECURRING_BASE_ENDPOINT . rawurlencode($uid) . '/' . $action;
        $body = array(
            'token'     => $this->public_token,
            'signature' => $this->sign_uid($uid),
        );

        $response = wp_remote_post($url, array(
            'method'  => 'POST',
            'headers' => array('Accept' => 'application/json'),
            'body'    => $body,
            'timeout' => 30,
        ));

        return $this->normalize_json_response($response, $action);
    }

    /**
     * Fetch a mandate's current status (GET, uid-signed).
     *
     * @param string $uid Mandate uid.
     * @return array { @type bool $ok, @type array $data, @type string $error }
     */
    public function mandate_status($uid) {
        $uid = (string) $uid;
        $url = add_query_arg(
            array(
                'token'     => $this->public_token,
                'signature' => $this->sign_uid($uid),
            ),
            $this->base_url . self::RECURRING_BASE_ENDPOINT . rawurlencode($uid)
        );

        $response = wp_remote_get($url, array(
            'headers' => array('Accept' => 'application/json'),
            'timeout' => 30,
        ));

        return $this->normalize_json_response($response, 'status');
    }

    /**
     * Signature for the mandate lifecycle endpoints: HMAC over the uid itself.
     *
     * @param string $uid Mandate uid.
     * @return string
     */
    private function sign_uid($uid) {
        return base64_encode(hash_hmac('sha256', (string) $uid, $this->hash_token, true));
    }

    /**
     * POST a checkout-creating request and normalise the checkout payload.
     *
     * @param string      $endpoint        API path.
     * @param array       $signed          Ordered signed fields.
     * @param array       $unsigned        Unsigned passthrough fields.
     * @param string|null $idempotency_key Optional Idempotency-Key header.
     * @param string      $id_key          Which id field to surface (invoice_id|mandate_id).
     * @return array
     */
    private function post_checkout($endpoint, array $signed, array $unsigned, $idempotency_key, $id_key) {
        $signature = $this->sign(array_values($signed));

        $payload = array();
        foreach ($signed as $key => $value) {
            $payload[$key] = (string) $value;
        }
        foreach ($unsigned as $key => $value) {
            $payload[$key] = (string) $value;
        }
        $payload['token']     = $this->public_token;
        $payload['signature'] = $signature;

        $url      = $this->base_url . $endpoint;
        $boundary = wp_generate_password(24, false, false);
        $headers  = array(
            'Accept'       => 'application/json',
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        );

        if (is_string($idempotency_key) && $idempotency_key !== '') {
            $headers['Idempotency-Key'] = $idempotency_key;
        }

        $this->log(sprintf('POST %s payload: %s', $url, $this->redact_for_log($payload)));

        $response = wp_remote_post($url, array(
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => $this->build_multipart_body($payload, $boundary),
            'timeout' => 30,
        ));

        return $this->normalize_checkout_response($response, $id_key);
    }

    /**
     * Turn a checkout HTTP response into the normalised checkout array.
     *
     * @param array|WP_Error $response Raw wp_remote_post response.
     * @param string         $id_key   invoice_id|mandate_id.
     * @return array
     */
    private function normalize_checkout_response($response, $id_key) {
        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $body   = is_string($body) ? $body : '';

        $this->log(sprintf('Checkout response (%d): %s', $status, substr($body, 0, 4000)));

        $decoded = $body !== '' ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            $decoded = null;
        }

        if ($status < 200 || $status >= 300) {
            return array(
                'ok'    => false,
                'error' => $this->extract_error_message($decoded, $body, $status),
            );
        }

        $data = (isset($decoded['data']) && is_array($decoded['data'])) ? $decoded['data'] : $decoded;

        $checkout_url = $this->first_scalar($data, array('checkout_url', 'url'));
        $invoice_id   = $this->first_scalar($data, array('invoice_id', 'invoiceId'));
        $expires_at   = $this->first_scalar($data, array('expires_at', 'expires'));
        $entity_id    = $id_key === 'mandate_id'
            ? $this->first_scalar($data, array('mandate_id', 'mandateId'))
            : $invoice_id;

        if ($checkout_url === '' || !wp_http_validate_url($checkout_url)) {
            return array(
                'ok'    => false,
                'error' => $this->extract_error_message($decoded, $body, $status),
            );
        }

        return array(
            'ok'           => true,
            'checkout_url' => $checkout_url,
            'invoice_id'   => $invoice_id,
            'mandate_id'   => $id_key === 'mandate_id' ? $entity_id : '',
            'expires_at'   => $expires_at,
        );
    }

    /**
     * Normalise a plain JSON action/status response.
     *
     * @param array|WP_Error $response Raw response.
     * @param string         $context Label for logging.
     * @return array
     */
    private function normalize_json_response($response, $context) {
        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => $response->get_error_message(), 'data' => array());
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $body    = wp_remote_retrieve_body($response);
        $body    = is_string($body) ? $body : '';
        $decoded = $body !== '' ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            $decoded = array();
        }

        $this->log(sprintf('Mandate %s response (%d): %s', $context, $status, substr($body, 0, 2000)));

        if ($status < 200 || $status >= 300) {
            return array(
                'ok'    => false,
                'error' => $this->extract_error_message($decoded, $body, $status),
                'data'  => isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array(),
            );
        }

        return array(
            'ok'   => true,
            'data' => isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded,
        );
    }

    /**
     * Convert a payload map to a multipart/form-data body.
     *
     * @param array  $fields   Field values.
     * @param string $boundary Multipart boundary.
     * @return string
     */
    private function build_multipart_body(array $fields, $boundary) {
        $eol  = "\r\n";
        $body = '';

        foreach ($fields as $name => $value) {
            $safe_name = str_replace(array("\r", "\n", '"'), '', (string) $name);
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $safe_name . '"' . $eol . $eol;
            $body .= (string) $value . $eol;
        }

        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    /**
     * First non-empty scalar among the given keys.
     *
     * @param array $data Source array.
     * @param array $keys Candidate keys.
     * @return string
     */
    private function first_scalar($data, array $keys) {
        if (!is_array($data)) {
            return '';
        }

        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return '';
    }

    /**
     * Pull a human-readable error out of a decoded response.
     *
     * @param array|null $decoded Parsed JSON, if any.
     * @param string     $body    Raw body fallback.
     * @param int        $status  HTTP status code.
     * @return string
     */
    private function extract_error_message($decoded, $body, $status) {
        if (is_array($decoded)) {
            foreach (array('message', 'error', 'error_message', 'description', 'detail', 'reason') as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key]) && (string) $decoded[$key] !== '') {
                    return (string) $decoded[$key];
                }
            }

            if (isset($decoded['errors']) && is_array($decoded['errors']) && !empty($decoded['errors'])) {
                $first = reset($decoded['errors']);
                if (is_array($first)) {
                    $first = reset($first);
                }
                if (is_scalar($first) && (string) $first !== '') {
                    return (string) $first;
                }
            }
        }

        $trimmed = is_string($body) ? trim($body) : '';
        if ($trimmed !== '') {
            return substr($trimmed, 0, 300);
        }

        return sprintf('GetPayIn returned HTTP %d.', $status);
    }

    /**
     * JSON-encode a payload for logging with sensitive keys redacted.
     *
     * @param array $payload Payload.
     * @return string
     */
    private function redact_for_log($payload) {
        if (!is_array($payload)) {
            return '';
        }

        $sensitive = array('signature', 'token', 'hash_token', 'public_token', 'authorization');
        $copy      = array();

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true) && is_string($value) && $value !== '') {
                $length     = strlen($value);
                $copy[$key] = $length <= 8
                    ? str_repeat('*', $length)
                    : substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
                continue;
            }
            $copy[$key] = $value;
        }

        $encoded = wp_json_encode($copy);

        return is_string($encoded) ? substr($encoded, 0, 4000) : '';
    }

    /**
     * Emit a log line through the injected logger, if any.
     *
     * @param string $message Message.
     * @param string $level   Level.
     */
    private function log($message, $level = 'info') {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message, $level);
        }
    }
}
