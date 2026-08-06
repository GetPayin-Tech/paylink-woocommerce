# GetPayIn for WooCommerce

![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-96588a)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

Official WooCommerce payment gateway for **GetPayIn**. It sends shoppers to
GetPayIn's hosted, PCI-compliant checkout (Apple Pay, Google Pay, Visa,
Mastercard), then confirms the order from a signed webhook — so card data never
touches your store. Built on the GetPayIn **v2** integration API, it computes the
order-sensitive HMAC-SHA256 signatures for you and keeps them in lockstep with
the server.

- Hosted checkout redirect (`process_payment` → GetPayIn) with idempotent invoice creation
- **Embedded checkout (iframe)** — optionally keep shoppers on your store, with the hosted checkout embedded on the order-pay page (one-off payments only)
- Capture now, or **authorize** and capture later from the dashboard
- Fixed **installments** (2–24) on the hosted checkout
- **Subscriptions** via WooCommerce Subscriptions — GetPayIn owns the schedule and dunning; renewals are recorded from webhooks
- Per-request **return & webhook URLs** — no dashboard round-trip
- Signed **webhook & return** verification (fail-closed)
- HPOS and Cart/Checkout **Blocks** compatible

> **Card data never reaches your server.** Payment is completed on GetPayIn's
> hosted checkout. Your `hash_token` is a signing secret — keep it on the server
> and out of logs.

## Requirements

- WordPress **5.6+**
- WooCommerce **5.0+**
- PHP **7.2+**
- An SSL certificate (required for live payments and for automatic callback URLs)
- **WooCommerce Subscriptions** — optional, only for recurring products

## Install

1. Download or clone this repository into `wp-content/plugins/paylink-woocommerce`.
2. Activate **GetPayIn for WooCommerce** under **Plugins**.
3. Configure it under **WooCommerce → Settings → Payments → GetPayIn**.

The gateway stays hidden at checkout until both an **Authentication Token** and a
**Hash Token** are saved.

## Quick start

1. In the GetPayIn dashboard, go to **Settings → Payment Integrations** and create
   an integration. Set its **Origin** to your store domain.
2. Copy the integration's **Authentication Token** and **Hash Token**.
3. In WooCommerce, open **Settings → Payments → GetPayIn**, paste both tokens into
   the matching (Test or Live) fields, tick **Enable GetPayIn**, and save.
4. Place a test order — you'll be redirected to the hosted checkout and returned
   to your store once payment completes.

- **Authentication Token** identifies the integration and is sent on every request.
- **Hash Token** is the secret used to sign requests and verify webhooks — it never leaves your server.

## Configuration

All options live on the gateway settings screen.

| Option | What it does |
| --- | --- |
| **Payment action** | `Capture` charges immediately; `Authorize` places a hold and marks the order **On hold** — capture later from the GetPayIn dashboard. Authorize requires authorize mode enabled on your account. |
| **Installments** | Offer a fixed number of installments (2–24) on the hosted checkout. Requires installments enabled on your account. |
| **Embedded checkout** | Off by default. When on, the hosted checkout is embedded in an iframe on the order-pay page instead of redirecting, so shoppers stay on your store. One-off payments only — subscriptions always redirect. Requires the integration **Origin** to exactly match your store URL. |
| **Callback URLs** | On by default. Over HTTPS, the plugin sends its own return and webhook URLs with every request, so you don't register them in the dashboard. They must resolve to your integration's registered domain. Turn off to use dashboard-configured URLs instead. |
| **Test Mode** | Route to test credentials and surface the sandbox card reference. |
| **Debug Log** | Log requests/responses to **WooCommerce → Status → Logs** (source `paylink`), with tokens and signatures redacted. |

Tipping and multi-currency conversion are configured on your GetPayIn account and
appear automatically on the hosted checkout — no plugin setting needed.

### Callback URLs

With **Callback URLs** enabled the plugin sends these on each request:

| Purpose | URL |
| --- | --- |
| Webhook (server-to-server) | `https://yourdomain.com/?wc-api=paylink_webhook` |
| Customer return | `https://yourdomain.com/?wc-api=paylink_return` |

The server accepts callback URLs only over **HTTPS** and on the integration's
registered domain, so make sure the integration's **Origin** matches your store.
Prefer to manage them yourself? Turn the option off and register both URLs in the
dashboard.

## Embedded checkout (iframe)

With **Embedded checkout** enabled, one-off payments no longer redirect away from
your store: after the order is placed the shopper lands on the WooCommerce
order-pay page, which loads the hosted GetPayIn checkout inside an iframe.

1. `process_payment` sends `iframe=1` in the init request body, so the server bakes
   iframe mode into the (signed) checkout URL. The flag is never appended to the
   returned URL — that would break the signature.
2. The customer is redirected to the order-pay page, which embeds the checkout.
3. On completion the hosted checkout posts a signed `paylink_payment` message; a
   small listener moves the top window to the order-received (success) or pay
   (retry) page. The message is accepted only from the GetPayIn checkout origin.

Because the checkout posts its completion message only to a parent on the
integration's registered Origin, the integration **Origin must exactly match your
store URL** for the embedded flow to work. Subscriptions always redirect (the
recurring init path does not sign the iframe flag).

## Subscriptions

With **WooCommerce Subscriptions** installed and **recurring payments enabled** on
your GetPayIn account, subscription products are billed automatically:

1. The customer authorizes the subscription once — first charge plus 3-D Secure —
   on the hosted checkout.
2. GetPayIn then charges the saved card on your subscription's schedule and sends
   signed `subscription.*` webhooks.
3. The plugin records each renewal as a WooCommerce renewal order and keeps the
   subscription status in sync.

Cancelling, pausing, or resuming the subscription in WooCommerce is mirrored to
GetPayIn's mandate.

> GetPayIn owns the billing schedule, not WooCommerce, so the **webhook is the
> source of truth** for renewals. Free trials and sign-up fees are not supported
> in this release.

## Webhooks and return verification

Every webhook and customer-return is verified against your **Hash Token** with
HMAC-SHA256. The signature travels **in the payload body** (there is no signature
header). The plugin recomputes it over the concatenated field values, in order,
with no separator:

```
success, invoice_id, invoice_status, message
```

plus, for `subscription.*` events only:

```
mandate_id, external_reference, subscription_status
```

`event`, `event_triggered_at`, `timezone`, `auth_code`, and `refund_*` are
excluded from the signature. A webhook without a valid signature is rejected
(HTTP 401); with no Hash Token configured, the webhook handler returns 503.

The customer-return handler is **fail-closed**: if the signature does not verify,
the order is not updated by the redirect and the webhook remains authoritative.

> Signatures carry no timestamp, so verification proves authenticity, not
> freshness. The plugin de-duplicates on the GetPayIn invoice id; renewal cycles
> each bill a new invoice id and are correlated by `mandate_id`.

## Test mode

Enable **Test Mode**, use the credentials GetPayIn provides, and pay with a
sandbox card (the settings screen lists them — e.g. Visa `4111 1111 1111 1111`,
any future expiry, any CVN). Watch the trace under **WooCommerce → Status → Logs**
with source `paylink`.

## Troubleshooting

**Gateway missing at checkout** — confirm WooCommerce is active, the gateway is
enabled, and both tokens are saved (it's hidden until then).

**"could not verify the payment confirmation" on return** — the redirect signature
did not match; the webhook is authoritative and will confirm the order.

**`ERR_NAME_NOT_RESOLVED` after paying** — the return URL points at a host that
doesn't resolve. With **Callback URLs** on, make sure the integration's **Origin**
matches your store domain; otherwise fix the dashboard URLs.

**"must be on the integration's registered domain"** — your store domain doesn't
match the integration's Origin/URL host. Align the Origin, or turn off **Callback
URLs** and register the URLs in the dashboard.

## Refunds

Automatic refunds are not processed inside WooCommerce. Issue refunds from the
GetPayIn dashboard; the order screen shows the invoice id and a pre-filled refund
email.

## API reference

The full HTTP API — endpoints, fields, signing, and test cards — is documented in
the PayLink API reference:
<https://pay.getpayin.com/docs/payment_integration/index.html>

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) — in particular the note on signed-field
ordering, which must stay in lockstep with the server.

Security issues: see [SECURITY.md](SECURITY.md). Please do not open a public issue
for a vulnerability.

## License

GPL-2.0-or-later
