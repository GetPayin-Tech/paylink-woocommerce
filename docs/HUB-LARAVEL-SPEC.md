# GetPayIn Plugin Hub — Laravel + Inertia spec

> Paste this whole file into a Claude Code session inside your **separate** Laravel + Inertia + Vue/React project (e.g. `pay.getpayin.com`). It is the contract the WordPress plugin already speaks; build the server side to match.

---

## 0. What you're building

A small admin tool plus four public endpoints that:

1. Tell every installed copy of the **GetPayIn for WooCommerce** plugin which version is current (so the WP "Plugins" screen offers a one-click update).
2. Serve signed plugin zip downloads for those updates.
3. Verify each merchant's annual subscription and tell the plugin whether it's allowed to operate.
4. Receive HMAC-signed refund requests from WordPress stores and proxy them to the GetPayIn payment processor.

You also need an **Inertia admin panel** for GetPayIn staff to manage plugin releases, merchant licenses, and refund requests.

---

## 1. Tech stack

- **PHP** 8.2+
- **Laravel** 11
- **Inertia** with **Vue 3** (or React; pick one and stay consistent)
- **Tailwind CSS** for styling
- **Spatie Laravel Permission** for staff RBAC
- **Laravel Sanctum** is **not** used by these endpoints (the plugin authenticates via shared HMAC + token). Sanctum *is* used for the staff admin UI.
- **MySQL 8** or **PostgreSQL 15+**
- **Redis** for caching + queues

---

## 2. Public endpoints (consumed by the WP plugin)

All endpoints live under `pay.getpayin.com`. CORS must allow any origin (merchants are on arbitrary domains). Rate-limit each route at `60 requests / minute / IP`.

### 2.1 `GET /plugins/woocommerce/manifest.json`

Returned to the plugin's update checker every 12 hours. Cache with `Cache-Control: public, max-age=300, s-maxage=600` so CDN absorbs traffic.

```json
{
  "name": "GetPayIn for WooCommerce",
  "slug": "getpayin-woocommerce",
  "version": "1.1.0",
  "tested": "6.6",
  "requires": "5.6",
  "requires_php": "7.2",
  "wc_requires": "5.0",
  "wc_tested": "8.9",
  "homepage": "https://paylink.sa",
  "author": "GetPayIn",
  "last_updated": "2025-05-01 12:00:00",
  "download_url": "https://pay.getpayin.com/plugins/woocommerce/download/1.1.0",
  "signature_sha256": "a1b2c3...64-hex-chars",
  "icons": {
    "1x": "https://pay.getpayin.com/plugins/woocommerce/assets/icon-128.png",
    "2x": "https://pay.getpayin.com/plugins/woocommerce/assets/icon-256.png"
  },
  "banners": {
    "low":  "https://pay.getpayin.com/plugins/woocommerce/assets/banner-772.png",
    "high": "https://pay.getpayin.com/plugins/woocommerce/assets/banner-1544.png"
  },
  "sections": {
    "description": "<p>Accept payments via GetPayIn in WooCommerce.</p>",
    "changelog":   "<h4>1.1.0</h4><ul><li>v2 API, capture/authorize, installments, WooCommerce Subscriptions.</li></ul>",
    "installation": "<p>Upload the zip via Plugins → Add New, then configure in WooCommerce → Settings → Payments.</p>"
  }
}
```

### 2.2 `GET /plugins/woocommerce/download/{version}`

Returns a **`200`** with `Content-Type: application/zip` containing the plugin zip for that version. Should `302` to a signed S3 URL if you store releases in S3. The plugin verifies `signature_sha256` from the manifest against the downloaded file before unpacking; if it doesn't match, the install aborts.

The directory inside the zip **must** be named `getpayin-woocommerce/` (WordPress's update flow re-uses the directory name from the zip).

### 2.3 `POST /plugins/license/verify`

Called by every install every 12 hours.

**Request:**

```json
{
  "site_url":   "https://merchant.example.com",
  "auth_token": "8UE8BCGY...",
  "version":    "1.1.0",
  "wp_version": "6.6.1",
  "wc_version": "8.9.2"
}
```

**Response:**

```json
{
  "valid": true,
  "state": "active",
  "expires_at": "2026-05-01T00:00:00Z",
  "renewal_url": "https://pay.getpayin.com/billing/renew/abc123",
  "message": ""
}
```

`state` is one of: `active`, `grace`, `expired`, `suspended`. If you don't recognize the merchant by `auth_token`, return `state: "expired"` with a `message` like *"Token not associated with an active GetPayIn account."* — the plugin's grace-period logic protects merchants who have a working history from a temporary backend issue.

If a merchant is `expired` or `suspended` past the grace window, the plugin removes itself from the WC checkout entirely until the merchant renews.

Always respond within 5 seconds. The plugin times out at 8.

### 2.4 `POST /api/integration/refund`

Called by the plugin when a WC admin issues a refund.

**Request** (multipart/form-data, same shape as `/api/integration/init`):

| field        | description                                                        |
|--------------|--------------------------------------------------------------------|
| `token`      | Merchant's Authentication Token                                    |
| `invoice_id` | GetPayIn invoice ID returned at checkout time                      |
| `amount`     | Refund amount as decimal string, e.g. `"12.50"`                    |
| `currency`   | `EGP` / `EUR` / `USD`                                              |
| `reason`     | Free-text reason (may be empty)                                    |
| `signature`  | base64( HMAC-SHA256( `invoice_id + amount + currency`, hash_token ) ) |

Verify the signature with the merchant's stored `hash_token`; reject with `401` on mismatch.

**Response:**

```json
{
  "success": true,
  "refund_id": "ref_42a91…",
  "invoice_id": "INV-368106",
  "amount": "12.50",
  "currency": "EGP"
}
```

Failure responses should set HTTP 4xx with:

```json
{ "success": false, "message": "Refund window has expired." }
```

If you haven't shipped this endpoint yet, return **HTTP 501** with a JSON body — the plugin treats that as "not yet available" and shows a friendly *"refund manually in the GetPayIn dashboard"* notice instead of a hard error.

---

## 3. Database schema

```sql
-- 3.1 Plugins (you may eventually publish more than one)
CREATE TABLE plugins (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    slug VARCHAR(100) UNIQUE NOT NULL,            -- 'getpayin-woocommerce'
    name VARCHAR(255) NOT NULL,
    homepage VARCHAR(500),
    author VARCHAR(255),
    created_at TIMESTAMP, updated_at TIMESTAMP
);

-- 3.2 Releases
CREATE TABLE plugin_releases (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    plugin_id BIGINT UNSIGNED NOT NULL REFERENCES plugins(id),
    version VARCHAR(20) NOT NULL,
    requires_wp VARCHAR(10),
    tested_wp VARCHAR(10),
    requires_php VARCHAR(10),
    wc_requires VARCHAR(10),
    wc_tested VARCHAR(10),
    zip_path VARCHAR(500) NOT NULL,               -- S3 key or local path
    zip_sha256 CHAR(64) NOT NULL,
    is_published BOOLEAN DEFAULT FALSE,
    description_html TEXT,
    changelog_html TEXT,
    installation_html TEXT,
    published_at TIMESTAMP NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP,
    UNIQUE (plugin_id, version)
);

-- 3.3 Merchants (already exists if you have a billing system — adapt to it)
CREATE TABLE merchants (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    contact_email VARCHAR(255) NOT NULL,
    auth_token_hash CHAR(64) NOT NULL,            -- sha256 of plugin auth_token
    hash_token_hash CHAR(64) NOT NULL,            -- sha256 of plugin hash_token (for refund signature verification we need the actual token; see note)
    created_at TIMESTAMP, updated_at TIMESTAMP,
    INDEX (auth_token_hash)
);
-- NOTE: For HMAC verification on /api/integration/refund you need the
-- *plaintext* hash_token. Store it encrypted at rest with Laravel's
-- encrypter (Crypt::encryptString) — do NOT store as a one-way hash.
-- Migrate the column to: hash_token_encrypted TEXT NOT NULL.

-- 3.4 Subscriptions
CREATE TABLE subscriptions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL REFERENCES merchants(id),
    state ENUM('active','grace','expired','suspended') NOT NULL DEFAULT 'active',
    expires_at TIMESTAMP NOT NULL,
    renewal_url VARCHAR(500),
    last_renewed_at TIMESTAMP,
    notes TEXT,
    created_at TIMESTAMP, updated_at TIMESTAMP,
    INDEX (state, expires_at)
);

-- 3.5 Verification log (for audit + debugging)
CREATE TABLE license_verifications (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NULL REFERENCES merchants(id),
    site_url VARCHAR(500),
    plugin_version VARCHAR(20),
    wp_version VARCHAR(20),
    wc_version VARCHAR(20),
    response_state VARCHAR(20),
    ip_address VARCHAR(45),
    created_at TIMESTAMP,
    INDEX (merchant_id, created_at),
    INDEX (created_at)
);

-- 3.6 Refund requests (audit log)
CREATE TABLE refund_requests (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    merchant_id BIGINT UNSIGNED NOT NULL REFERENCES merchants(id),
    invoice_id VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    reason TEXT,
    state ENUM('pending','succeeded','rejected','failed') NOT NULL DEFAULT 'pending',
    upstream_refund_id VARCHAR(100),
    upstream_response JSON,
    created_at TIMESTAMP, updated_at TIMESTAMP,
    INDEX (merchant_id, created_at),
    INDEX (invoice_id)
);
```

---

## 4. Routes (`routes/api.php`)

```php
Route::prefix('plugins/woocommerce')->group(function () {
    Route::get('manifest.json', ManifestController::class)
        ->name('plugins.manifest');
    Route::get('download/{version}', DownloadController::class)
        ->name('plugins.download');
});

Route::prefix('plugins/license')->group(function () {
    Route::post('verify', LicenseVerifyController::class)
        ->middleware('throttle:60,1')
        ->name('license.verify');
});

Route::prefix('api/integration')->group(function () {
    Route::post('refund', RefundController::class)
        ->middleware('throttle:30,1')
        ->name('integration.refund');
});
```

Apply CORS via `config/cors.php` so `*` is allowed for these paths only.

---

## 5. Controllers — implementation notes

### `ManifestController`

```php
public function __invoke(Request $req)
{
    $plugin = Plugin::where('slug', 'getpayin-woocommerce')->firstOrFail();
    $latest = $plugin->releases()
        ->where('is_published', true)
        ->orderByDesc('published_at')
        ->firstOrFail();

    return response()->json([
        'name'            => $plugin->name,
        'slug'            => $plugin->slug,
        'version'         => $latest->version,
        'tested'          => $latest->tested_wp,
        'requires'        => $latest->requires_wp,
        'requires_php'    => $latest->requires_php,
        'wc_tested'       => $latest->wc_tested,
        'wc_requires'     => $latest->wc_requires,
        'homepage'        => $plugin->homepage,
        'author'          => $plugin->author,
        'last_updated'    => $latest->published_at?->format('Y-m-d H:i:s'),
        'download_url'    => route('plugins.download', $latest->version),
        'signature_sha256'=> $latest->zip_sha256,
        'sections'        => array_filter([
            'description'  => $latest->description_html,
            'changelog'    => $latest->changelog_html,
            'installation' => $latest->installation_html,
        ]),
    ])->setPublic()->setMaxAge(300)->setSharedMaxAge(600);
}
```

### `DownloadController`

```php
public function __invoke(string $version)
{
    $release = PluginRelease::where('version', $version)
        ->where('is_published', true)
        ->firstOrFail();

    // S3 example: redirect to a 5-minute signed URL.
    $url = Storage::disk('releases')->temporaryUrl(
        $release->zip_path, now()->addMinutes(5)
    );
    return redirect()->away($url);
}
```

### `LicenseVerifyController`

```php
public function __invoke(VerifyLicenseRequest $req): JsonResponse
{
    $tokenHash = hash('sha256', $req->string('auth_token'));

    $merchant = Merchant::where('auth_token_hash', $tokenHash)->first();
    LicenseVerification::create([
        'merchant_id'    => $merchant?->id,
        'site_url'       => $req->string('site_url'),
        'plugin_version' => $req->string('version'),
        'wp_version'     => $req->string('wp_version'),
        'wc_version'     => $req->string('wc_version'),
        'ip_address'     => $req->ip(),
    ]);

    if (!$merchant) {
        return response()->json([
            'valid' => false, 'state' => 'expired',
            'message' => 'No GetPayIn account is linked to this Authentication Token.',
        ]);
    }

    $sub = $merchant->activeSubscription;
    if (!$sub) {
        return response()->json([
            'valid' => false, 'state' => 'expired',
            'renewal_url' => route('billing.renew', ['merchant' => $merchant]),
            'message' => 'No active subscription found.',
        ]);
    }

    LicenseVerification::where('id', last_inserted_id())
        ->update(['response_state' => $sub->state]);

    return response()->json([
        'valid'       => $sub->state === 'active',
        'state'       => $sub->state,
        'expires_at'  => $sub->expires_at?->toIso8601String(),
        'renewal_url' => $sub->renewal_url,
        'message'     => match ($sub->state) {
            'grace'     => "Your subscription expired on {$sub->expires_at->format('Y-m-d')}. Please renew to continue accepting payments.",
            'expired'   => 'Your subscription has expired. Customers cannot complete payments until you renew.',
            'suspended' => 'Your account is suspended. Contact support@getpayin.com.',
            default     => "Subscription active until {$sub->expires_at->format('Y-m-d')}.",
        },
    ]);
}
```

### `RefundController`

```php
public function __invoke(RefundRequest $req): JsonResponse
{
    $token = $req->string('token');
    $merchant = Merchant::where('auth_token_hash', hash('sha256', $token))->firstOrFail();
    $hashToken = decrypt($merchant->hash_token_encrypted);

    $expected = base64_encode(hash_hmac(
        'sha256',
        $req->string('invoice_id') . $req->string('amount') . $req->string('currency'),
        $hashToken,
        true
    ));
    abort_unless(hash_equals($expected, $req->string('signature')), 401, 'Invalid signature');

    $log = RefundRequest::create([
        'merchant_id' => $merchant->id,
        'invoice_id'  => $req->string('invoice_id'),
        'amount'      => $req->string('amount'),
        'currency'    => $req->string('currency'),
        'reason'      => $req->string('reason'),
        'state'       => 'pending',
    ]);

    // Proxy to your real refund service (or queue it):
    $result = app(GetPayInProcessor::class)->refund($log);

    $log->update([
        'state'              => $result->success ? 'succeeded' : 'failed',
        'upstream_refund_id' => $result->refund_id,
        'upstream_response'  => $result->raw,
    ]);

    if (!$result->success) {
        return response()->json([
            'success' => false,
            'message' => $result->message,
        ], 422);
    }

    return response()->json([
        'success'    => true,
        'refund_id'  => $result->refund_id,
        'invoice_id' => $log->invoice_id,
        'amount'     => $log->amount,
        'currency'   => $log->currency,
    ]);
}
```

---

## 6. Inertia admin panel

Routes mounted under `/admin` (gated by `auth + role:staff`):

- `GET /admin/plugins/getpayin-woocommerce/releases` → list of releases
- `GET /admin/plugins/getpayin-woocommerce/releases/create` → upload form
- `POST /admin/plugins/getpayin-woocommerce/releases` → handle upload
- `POST /admin/plugins/getpayin-woocommerce/releases/{release}/publish` → flip `is_published`
- `GET /admin/merchants` → searchable list
- `GET /admin/merchants/{merchant}` → merchant detail (subscription, recent verifications, refunds)
- `POST /admin/merchants/{merchant}/subscriptions` → extend / renew / suspend
- `GET /admin/refunds` → searchable refund log

### Inertia pages to build

1. `Admin/Releases/Index.vue` — table with version, published toggle, last updated, **Download zip**, **Edit changelog**.
2. `Admin/Releases/Create.vue` — drag-and-drop zip upload, auto-detects version from header, computes SHA-256 client-side AND server-side and shows them side-by-side, fields for changelog HTML.
3. `Admin/Merchants/Index.vue` — searchable table; columns: name, plan, state pill, expires, last verified.
4. `Admin/Merchants/Show.vue` — header with merchant info + state pill, three tabs: **Subscription**, **Verifications** (last 30 days, paginated), **Refunds**.
5. `Admin/Refunds/Index.vue` — global refunds log with filters (state, merchant, currency, date range).

### Suggested component shapes

- `<StatusPill :state="...">` — maps `active|grace|expired|suspended` to teal / amber / red.
- `<MetricTile title icon value note>` — for top-of-dashboard stats.
- `<CodeBlock copyable>` — for displaying tokens/invoice IDs.

### Tailwind theme

Match the plugin's brand teal:

```js
// tailwind.config.js
theme: {
  extend: {
    colors: {
      brand: {
        50:  '#E6F7F6',
        100: '#CCEEEC',
        500: '#009C95',
        600: '#00736D',
        700: '#004C48',
      },
    },
  },
},
```

---

## 7. Release pipeline (zip publishing)

A `php artisan plugin:release` command (or admin upload):

1. Reads the uploaded plugin zip.
2. Validates the directory inside is named exactly `getpayin-woocommerce`.
3. Reads the plugin header (`getpayin-woocommerce/getpayin-woocommerce.php`) to extract `Version`, `Tested up to`, `Requires at least`, `Requires PHP`, `WC tested up to`, `WC requires at least`.
4. Computes SHA-256 of the file.
5. Stores it on S3 (or `storage/app/releases/`) and creates a `plugin_releases` row with `is_published = false`.
6. Staff reviews + publishes via the admin UI.

A simple PHPUnit feature test should:

- POST a known fixture zip to the release endpoint.
- Assert the record exists with the right SHA-256.
- Assert the manifest now points at the new version.
- Download the manifest and assert all required keys are present.

---

## 8. Cron jobs (`app/Console/Kernel.php`)

```php
$schedule->call(fn () => Subscription::query()
    ->where('state', 'active')
    ->where('expires_at', '<', now())
    ->update(['state' => 'grace']))
    ->dailyAt('00:05');

$schedule->call(fn () => Subscription::query()
    ->where('state', 'grace')
    ->where('expires_at', '<', now()->subDays(7))
    ->update(['state' => 'expired']))
    ->dailyAt('00:10');

$schedule->job(new SendRenewalReminderEmails)->dailyAt('07:00');

// Old verification rows are noisy; trim after 90 days.
$schedule->call(fn () => LicenseVerification::where('created_at', '<', now()->subDays(90))->delete())
    ->weekly();
```

---

## 9. Security checklist

- [ ] All four public endpoints behind HTTPS only (force via `URL::forceScheme`).
- [ ] CORS allows `*` for the four public routes; locked to admin domain for everything else.
- [ ] Rate-limited: manifest 60/min, download 30/min, license verify 60/min, refund 30/min — per IP.
- [ ] Manifest cached at the edge (`max-age=300, s-maxage=600`).
- [ ] Refund endpoint verifies HMAC against the merchant's stored `hash_token` and rejects with `401` on mismatch.
- [ ] `merchants.hash_token_encrypted` stored encrypted at rest using Laravel's `Crypt`. Never log it; never return it from any endpoint.
- [ ] Audit log every refund + license verification with `ip_address`.
- [ ] Admin actions (publish release, suspend merchant) write to `activity_log` (Spatie).
- [ ] Release zip downloads served via short-lived signed S3 URLs (5 min).

---

## 10. Acceptance criteria

You can call the spec done when:

1. `curl -i https://pay.getpayin.com/plugins/woocommerce/manifest.json` returns 200 with the JSON shape in §2.1.
2. A WP install of the plugin shows "Update available" within 12 hours of you publishing a new release row, **and** one-click update succeeds (with the SHA-256 matching).
3. Suspending a merchant in the admin UI causes their next license check (within 12 hours) to return `state: "suspended"`, and the gateway disappears from the merchant's WC checkout 7 days later (after the grace period) — with the prominent admin notice firing immediately.
4. The merchant's WP "Order edit" screen "Refund" button successfully refunds an order and the `refund_requests` table records `state: "succeeded"` with the upstream refund ID.
5. The plugin's `?wc-api=paylink_health&secret=...` endpoint can be opened by support to confirm the install's state without exposing tokens.

---

## 11. What to ask Claude when building this

> Read `HUB-LARAVEL-SPEC.md` and implement it module-by-module:
> 1. Migrations + models
> 2. The four public endpoints with feature tests
> 3. The Inertia admin pages, starting with Releases
> 4. The `plugin:release` artisan command
> 5. Cron jobs + observability
>
> Use the brand teal `#009C95` and shades, match the StatusPill / MetricTile component shapes from §6, and follow the Security checklist in §9.

---

## Plugin-side reference (already built)

If you need to look at how the plugin sends/expects each request, the relevant files are in the WordPress plugin repo:

- `includes/class-getpayin-updater.php` — manifest fetch + zip verify
- `includes/class-getpayin-license.php` — license verify call
- `includes/class-getpayin-gateway.php` `process_payment()` — `/api/integration/init`
- `includes/class-getpayin-gateway.php` `process_refund()` — `/api/integration/refund`
- `includes/class-getpayin-gateway.php` `handle_webhook()` — webhook receiver

Plugin signs everything with HMAC-SHA256 over a deterministic concatenation of fields, base64-encoded. Match the same scheme on both sides.
