# Contributing

## Setup

```bash
composer install
composer run lint      # phpcs against phpcs.xml (WordPress + PHP 7.2 compatibility)
```

Before opening a PR, run the linter and a syntax check across the PHP sources:

| Command | What it does |
| --- | --- |
| `composer run lint` | `phpcs --standard=phpcs.xml .` |
| `composer run lint:fix` | `phpcbf` — auto-fix what it can |
| `php -l <file>` | Syntax-check a changed file |

The plugin targets **PHP 7.2**. Do not use syntax or functions newer than that
(`match`, enums, `str_contains`, named args, arrow functions, `readonly`, …).

## The one thing to get right: signature parity

The plugin's core job is reproducing a byte-exact, order-sensitive HMAC that the
server rebuilds independently. The server signs
`base64(hmac_sha256(implode('', Arr::except($request->validated(), [...]))))`, and
Laravel's `validated()` returns fields in **`rules()` order**, skipping absent
ones.

That means **the order of keys in `build_checkout_fields()` (and
`build_recurring_fields()` in the subscriptions bridge) must match the order of
keys in the endpoint's FormRequest `rules()`**, minus the excluded fields
(`token`, `signature`, and the unsigned passthroughs — `payment_mode`,
`installments_enabled`, `installments`).

When you touch a request:

1. Open the matching FormRequest under
   `app/Http/Requests/Application/ExternalPaymentIntegration/` in the PayLink repo
   and read `rules()` top to bottom.
2. Mirror that order in the `$signed` array. `Paylink_Api::sign()` hashes
   `array_values($signed)`, so the array's key order **is** the signature — the
   linter cannot catch a reordering.
3. Send unsigned passthroughs in the `$unsigned` array; they travel in the body
   but must stay out of the signature.

The canonical orders are documented at the top of `class-getpayin-api.php`.

## Webhooks sign by opt-out — the mirror-image trap

Requests sign by opt-**in**: the `$signed` array lists exactly what gets signed.
Webhooks are the opposite. `PaymentIntegrationWebhookJob` copies the whole payload
and `unset()`s a fixed exclusion list before hashing:

```php
$signatureData = $data;
unset($signatureData['event'], …['event_triggered_at'], …['timezone'], …['auth_code'], …['refund_amount'], …['refund_currency']);
$data['signature'] = $integration->buildSignatureString($signatureData);
```

So **whether a new webhook field is signed depends on where in that job it is
added** — before the `unset()` it is signed; after `buildSignatureString()` it is
not. `auth_code` is the existing example of the second case: sent in the payload,
deliberately unsigned.

`verify_signed_payload()` in `class-getpayin-gateway.php` rebuilds the signature
from an explicit ordered list rather than iterating the payload, precisely so a
stray field can't shift a position. When the server adds a signed webhook field,
add it to that list in the correct position — and only if the server signs it.

| Server behaviour | `verify_signed_payload()` |
| --- | --- |
| Signs the field (added before `unset`) | **must** append it in order |
| Sends it unsigned (added after) | **must not** include it |

## Renewals are webhook-driven

GetPayIn owns the subscription schedule, so the bridge records renewals from
`subscription.charged` webhooks rather than charging on WooCommerce's clock. The
`woocommerce_scheduled_subscription_payment_paylink` handler intentionally defers
to the webhook, and renewal orders are de-duplicated on the GetPayIn invoice id.
Keep webhook-driven changes inside the `applying_webhook` guard so a status change
is not echoed straight back to the mandate API.

## Style

`composer run lint` (phpcs / WPCS) is the source of truth. Beyond that:

- Explicit types where the codebase already uses them; match sibling files.
- Prefer a doc comment explaining _why_ over an inline comment restating _what_.
- Keep monetary values at full precision; never round.

## Releasing

1. Bump the version in **two** places — they must agree:
   - the `Version:` header in `getpayin-woocommerce.php`, and
   - the `PAYLINK_VERSION` constant.
2. Update `CHANGELOG.md`.
3. If the update server is in play, publish the matching manifest (see
   `docs/HUB-LARAVEL-SPEC.md`).
4. Tag the release `v<version>`.
