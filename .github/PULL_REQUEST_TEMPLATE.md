<!--
Thanks for contributing to GetPayIn for WooCommerce. Keep the description focused
on what changes and why. Delete any section that does not apply.
-->

## What & why

<!-- What does this PR change, and what problem does it solve? Link issues with "Closes #123". -->

## Type of change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that changes existing behavior)
- [ ] Chore / tooling / docs (no runtime behavior change)

## Checklist

- [ ] `composer run lint` passes (phpcs)
- [ ] `php -l` is clean on changed files; no PHP > 7.2 syntax introduced
- [ ] Public behavior changes are reflected in the README and CHANGELOG
- [ ] No secret (`hash_token`) or card data is logged or committed

## Signature parity

<!--
Only if this PR touches a signed request field, the webhook verification list,
or the field order. The plugin must reproduce the server's byte-exact HMAC.
-->

- [ ] Not applicable — this PR does not touch signed fields
- [ ] The `$signed` field order matches the endpoint's FormRequest `rules()` order
- [ ] Webhook verification (`verify_signed_payload`) mirrors the server's signed/unsigned decision
