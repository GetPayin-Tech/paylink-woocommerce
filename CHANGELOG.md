# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0]

### Added

- **Embedded checkout (iframe)** — a new setting loads the hosted GetPayIn checkout inside an iframe on the order-pay page instead of redirecting away from the store. One-off payments only; the flag (`iframe=1`) is sent in the init request body so the server bakes it into the signed checkout URL. On completion the checkout posts a signed `paylink_payment` message that moves the shopper to the order-received (success) or pay (retry) page. Subscriptions always redirect, and the integration Origin must exactly match the store URL.

## [1.1.0]

### Added

- Migrated to the GetPayIn **v2** integration API.
- **Payment action** setting: capture immediately, or authorize (hold) and capture later from the dashboard.
- Fixed **installments** (2–24) on the hosted checkout.
- **WooCommerce Subscriptions** support — recurring billing driven by GetPayIn's schedule, with webhook-recorded renewals and two-way cancel / pause / resume sync between the subscription and the mandate.
- Per-request **return & webhook URLs** (new **Callback URLs** setting), removing the dashboard-configuration requirement.
- An **Idempotency-Key** on checkout creation, so a retried order never duplicates an invoice.
- The order screen now shows the subscription **mandate id** and status.

### Fixed

- **Callback signature verification** now matches the server contract exactly — the ordered field subset, with the signature read from the payload body rather than a (non-existent) header. Previously, valid webhooks and returns could be rejected.

### Changed

- Checkout requests now carry `payment_mode` and installment fields; the signed field order tracks the v2 endpoint.

## [1.0.1]

### Fixed

- Customer return handler now fails closed when the signature is missing or invalid.
- Webhook handler refuses requests when the Hash Token is unset, and rejects non-POST methods.
- Text-domain inconsistencies corrected and output escaping added.

### Changed

- HPOS-friendly order lookup via `meta_query`.

### Removed

- Publicly accessible diagnostic scripts.
- The non-functional refund stub.

### Security

- Sensitive fields redacted from debug logs.

## [1.0.0]

### Added

- Initial release.
