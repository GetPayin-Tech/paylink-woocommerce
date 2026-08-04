# Security Policy

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Report it privately via [GitHub's private vulnerability reporting](https://github.com/GetPayin-Tech/paylink-woocommerce/security/advisories/new), or email **tech@getpayin.com**.

Please include the plugin version, your WordPress and WooCommerce versions, a description of the impact, and a reproduction if you have one. We aim to acknowledge within 3 business days.

## Supported versions

The latest release receives security fixes. Please keep the plugin, WordPress, and WooCommerce up to date.

## Handling credentials

- `hash_token` is a signing secret. It signs every request and verifies every webhook. Anyone holding it can forge requests and webhooks for your integration. It is stored in the WooCommerce gateway settings and never sent to the browser.
- `public_token` (the Authentication Token) identifies the integration and is sent on every request. It is not secret, but it is not a substitute for the signing secret either.
- Enable **Debug Log** only when troubleshooting. Tokens, signatures, and authorization values are redacted before being written, but the log still records request and response detail.

## What this plugin does not do for you

- **Webhook replay protection.** GetPayIn webhook signatures carry no timestamp or nonce, so a valid payload stays valid. Signature verification proves authenticity, not freshness — the plugin de-duplicates on the GetPayIn invoice id, and you should treat the webhook as authoritative over the browser redirect.
- **PCI scope reduction beyond the hosted checkout.** Card entry happens on GetPayIn's hosted checkout, which keeps card data off your server. Do not add flows that collect raw PAN/CVV through your store.
- **Return-URL trust.** The customer redirect is fail-closed: an invalid or missing signature does not update the order. The server-to-server webhook is the source of truth.
