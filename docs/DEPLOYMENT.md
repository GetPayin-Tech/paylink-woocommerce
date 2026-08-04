# Deployment guide

This document describes how to ship the GetPayIn for WooCommerce plugin from GitHub through to merchants' WordPress sites, plus how to host the companion Hub backend on DigitalOcean.

```
            ┌─────────────────┐    git push tag v1.0.x      ┌──────────────────────┐
 Developer ─┤  GitHub repo    │──────────────────────────▶ │  GitHub Actions      │
            └─────────────────┘                            │  (release workflow)  │
                                                           └─────────┬────────────┘
                                                                     │ uploads zip
                                                                     ▼
                          ┌──────────────────────────────────────────────────────────┐
                          │  DigitalOcean Spaces  ──────  s3://getpayin-plugins/...   │
                          └──────────────────────────────────────────────────────────┘
                                                                     ▲
                                                                     │ signed URLs
                          ┌──────────────────────────────────────────────────────────┐
                          │  pay.getpayin.com  (Laravel Hub on a DO Droplet / App)   │
                          │   • /plugins/woocommerce/manifest.json                   │
                          │   • /plugins/woocommerce/download/{version}              │
                          │   • /plugins/license/verify                              │
                          │   • /plugins/usage   (telemetry, opt-in)                 │
                          └──────────────────────────────────────────────────────────┘
                                                                     ▲
                                                                     │ HTTPS
                          ┌──────────────────────────────────────────────────────────┐
                          │  Merchant WordPress sites (the plugin)                   │
                          └──────────────────────────────────────────────────────────┘
```

---

## 1. GitHub repository setup

### One-time

1. Create a new GitHub repo: `getpayin/getpayin-woocommerce`.
2. Push this folder as the initial commit on the `main` branch.
3. Protect `main`:
   - Require pull-request reviews (1 approval)
   - Require status checks: **Lint / PHP** (the matrix), **Lint / Front-end lint**
   - Disallow force pushes
4. Set repository **Settings → Actions → General → Workflow permissions** = *Read and write*.
5. Add the secrets below under **Settings → Secrets and variables → Actions**:

| Secret                | Used by              | Notes                                                 |
|-----------------------|----------------------|-------------------------------------------------------|
| `DO_SPACES_KEY`       | release workflow     | Spaces access key                                     |
| `DO_SPACES_SECRET`    | release workflow     | Spaces secret key                                     |
| `DO_SPACES_BUCKET`    | release workflow     | e.g. `getpayin-plugins`                               |
| `DO_SPACES_REGION`    | release workflow     | e.g. `fra1`                                           |
| `HUB_RELEASE_HOOK`    | release workflow     | (optional) `https://pay.getpayin.com/internal/releases` to auto-publish manifest |
| `HUB_RELEASE_TOKEN`   | release workflow     | (optional) Bearer token for the hook above            |

### Branching model

- `main` — production. Tagged releases (`vX.Y.Z`) come from here.
- `develop` — integration branch. Feature branches merge here first.
- Feature branches: `feat/...`, `fix/...`, `chore/...`, `docs/...`.

### Cutting a release

```bash
# from main, with all PRs merged
git switch main && git pull
# bump version in getpayin-woocommerce.php (header + PAYLINK_VERSION constant)
sed -i 's/Version: 1\.0\.5/Version: 1.0.6/' getpayin-woocommerce.php
sed -i "s/PAYLINK_VERSION', '1\.0\.5'/PAYLINK_VERSION', '1.0.6'/" getpayin-woocommerce.php
git add getpayin-woocommerce.php
git commit -m "chore: release 1.0.6"
git tag -s v1.0.6 -m "Release 1.0.6"
git push origin main --tags
```

The **Release** workflow then:

1. Verifies the tag matches the plugin header
2. Builds a clean zip (`getpayin-woocommerce-1.0.6.zip`)
3. Computes SHA-256
4. Uploads to `s3://$DO_SPACES_BUCKET/woocommerce/1.0.6/getpayin-woocommerce.zip`
5. Creates a GitHub Release with the zip attached
6. POSTs the metadata to the Hub (if `HUB_RELEASE_HOOK` is set), which then updates `manifest.json` automatically

---

## 2. DigitalOcean infrastructure

Two pieces:

### 2.1 DigitalOcean Spaces — release artifact storage

A single CDN-fronted bucket for plugin zips and brand assets.

```bash
# Provision once via doctl (or use the DO control panel)
doctl spaces create getpayin-plugins --region fra1
doctl spaces cdn create --space getpayin-plugins
```

Bucket layout:

```
getpayin-plugins/
├─ woocommerce/
│  ├─ 1.1.0/getpayin-woocommerce.zip
│  ├─ 1.0.6/getpayin-woocommerce.zip
│  └─ ...
└─ assets/
   ├─ icon-128.png
   ├─ icon-256.png
   ├─ banner-772x250.png
   └─ banner-1544x500.png
```

**Lifecycle**: keep all published versions forever (cheap). Pre-release / draft zips can be set to auto-expire after 30 days via a bucket lifecycle rule.

**Access**: the Hub generates short-lived signed URLs (5 min) when serving `/plugins/woocommerce/download/{version}` so the bucket itself stays private. Public-read on the assets sub-prefix is fine for icons/banners.

### 2.2 DigitalOcean App Platform — the Hub backend

Recommended over a plain Droplet because App Platform handles TLS, autoscaling, and zero-downtime deploys for Laravel out of the box.

```yaml
# .do/app.yaml — paste into the Hub repo (NOT this repo)
name: getpayin-hub
region: fra
services:
  - name: web
    github:
      repo: getpayin/hub
      branch: main
      deploy_on_push: true
    build_command: |
      composer install --no-dev --optimize-autoloader
      php artisan config:cache
      php artisan route:cache
      php artisan event:cache
      npm ci && npm run build
    run_command: |
      php artisan migrate --force
      php-fpm
    instance_size_slug: basic-s
    instance_count: 2
    http_port: 8080
    health_check:
      http_path: /up
    routes:
      - path: /
    envs:
      - { key: APP_ENV,   value: production }
      - { key: APP_KEY,   type: SECRET }
      - { key: APP_URL,   value: https://pay.getpayin.com }
      - { key: DB_CONNECTION, value: mysql }
      - { key: DB_HOST,       type: SECRET }
      - { key: DB_DATABASE,   value: hub }
      - { key: DB_USERNAME,   type: SECRET }
      - { key: DB_PASSWORD,   type: SECRET }
      - { key: REDIS_HOST,    type: SECRET }
      - { key: SPACES_KEY,    type: SECRET }
      - { key: SPACES_SECRET, type: SECRET }
      - { key: SPACES_BUCKET, value: getpayin-plugins }
      - { key: SPACES_REGION, value: fra1 }
databases:
  - name: hub-db
    engine: MYSQL
    version: "8"
    size: db-s-1vcpu-1gb
    cluster_name: hub-db
```

Bring up:

```bash
doctl apps create --spec .do/app.yaml
```

CDN + custom domain:

1. Buy/transfer `pay.getpayin.com` to DO Domains (or set NS records to DO).
2. App Platform → **Domains** → add `pay.getpayin.com` → automatic Let's Encrypt cert.
3. Add a **CDN** in front of `pay.getpayin.com` (origin is the App's hostname).
4. The Spaces CDN serves zips and brand assets directly.

### 2.3 Cron / scheduled tasks

App Platform supports **Workers** for background tasks. Add one entry to the same `app.yaml`:

```yaml
workers:
  - name: scheduler
    github: { repo: getpayin/hub, branch: main }
    build_command: composer install --no-dev --optimize-autoloader
    run_command: |
      while true; do
        php artisan schedule:run --verbose --no-interaction
        sleep 60
      done
    instance_size_slug: basic-xs
    instance_count: 1
```

### 2.4 Backups

- **DB**: enable automated daily backups on the managed MySQL cluster (DO does this via the control panel for a small fee).
- **Spaces**: cross-region replicate to a second region for disaster recovery (`doctl spaces ...`).

---

## 3. Pre-flight checklist before publishing 1.0.6+

- [ ] Bump version in `getpayin-woocommerce.php` (header **and** `PAYLINK_VERSION`).
- [ ] Update changelog section in the README.
- [ ] All PRs squash-merged into `main`.
- [ ] Lint workflow green on the merge commit.
- [ ] Sandbox merchant test transaction passes through the **Test** environment.
- [ ] At least one live merchant test (you can use a $1 EGP order against a sandboxed live integration).
- [ ] Tag pushed; release workflow uploaded the zip; SHA-256 in the GitHub Release notes matches the manifest's `signature_sha256`.
- [ ] `manifest.json` updated and reachable at the public URL.
- [ ] One staging merchant successfully one-click-updated to the new version.

---

## 4. Repo / package size budget

Target: < 600 KB unzipped, < 200 KB zipped.

To audit what ends up in the released zip:

```bash
git archive HEAD --format=zip -o /tmp/inspect.zip
unzip -l /tmp/inspect.zip | sort -k4
```

If a release ever crosses 1 MB without a clear reason, investigate: WP plugin reviews flag bloat.

---

## 5. Rollback

If a release breaks merchant sites:

1. **Mark the bad release as `is_published = false`** in the Hub admin → manifest immediately starts pointing at the previous version.
2. Affected merchants stay on the bad version (one-click updates only flow forward), so push a fixed `1.0.6.1` patch within the same hour and bump the manifest to it.
3. Open an incident issue; postmortem within 48 hours.

WordPress doesn't support automatic downgrades — making the next version include a fix is the only path forward.

---

## 6. Required DO secrets / env vars summary

| Env var          | Where used        |
|------------------|-------------------|
| `APP_KEY`        | Hub Laravel       |
| `DB_*`           | Hub Laravel       |
| `REDIS_HOST`     | Hub Laravel       |
| `SPACES_KEY`     | Hub + GH Actions  |
| `SPACES_SECRET`  | Hub + GH Actions  |
| `SPACES_BUCKET`  | Hub + GH Actions  |
| `SPACES_REGION`  | Hub + GH Actions  |
