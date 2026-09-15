# 09 — Shared-Hosting (cPanel) Deployment Architecture

**Deliverable:** spec §102.9 — *"A shared-hosting deployment architecture."*
Covers §3 (deployment constraints), §69–§70 (queues, scheduler), §96 (19-point cPanel guide),
§97 (backups), §98 (migration path).

Design rule: **everything must work with nothing but PHP, MySQL, a filesystem and cron.**
Redis, Node daemons, Docker, Kubernetes, long-running supervisors and `exec()` are all
optional upgrades, never requirements.

---

## 1. Target host profile

| Requirement | Minimum | Recommended | Why |
|-------------|---------|-------------|-----|
| PHP | **8.3** | **8.4** | Laravel 13 requires `^8.3`; 8.4 is recommended because Laravel 13.3+ pulls Symfony 8 components. cPanel "MultiPHP Manager" must offer 8.3+ — **verify before signing up** |
| MySQL / MariaDB | MySQL 8.0 / MariaDB 10.6 | MySQL 8.0+ | `utf8mb4`, JSON columns, `CHECK` constraints, FULLTEXT |
| Memory | 512 MB | 1 GB+ | dompdf certificate rendering is the peak |
| `max_execution_time` | 30 s | 120 s | CSV imports, report generation (long work is queued regardless) |
| `upload_max_filesize` / `post_max_size` | 16 M / 20 M | 64 M / 72 M | CVs, assignment uploads, digital products (chunked for anything larger) |
| `memory_limit` | 256 M | 512 M | — |
| Cron jobs | ≥ 2 entries, **1-minute granularity** | 1-minute | Scheduler + queue worker (§6, §7) |
| SSH access | optional | **strongly recommended** | Composer, artisan, git deploy |
| `exec()` | often disabled | — | Not required: dompdf + bacon-qr are pure PHP |
| Composer | 2.x | 2.7+ | On-server install **or** vendor uploaded from CI |
| SSL | Let's Encrypt (AutoSSL) | + HSTS | Required for OAuth callbacks and Paystack webhooks |
| Disk | 20 GB | 50 GB+ | Database + uploads + logs; offload to S3 later |

**Required PHP extensions** (§96.2) — all standard on cPanel:

```
Required:   openssl, pdo, pdo_mysql, mbstring, tokenizer, xml, ctype, json,
            bcmath, fileinfo, curl, dom, filter, hash, session, zlib
Strongly:   gd (avatars/thumbnails), intl (formatting), zip (exports), exif (photo orientation)
Optional:   imagick (better image handling), redis (only if the host offers it)
```

Verify with `php -m` or a `phpinfo()` page (deleted immediately afterwards), or:

```bash
php artisan platform:doctor --check=extensions
```

---

## 2. Repository & build strategy

Because the host may have **no Node.js**, assets are compiled in CI (or locally) and the
result is **committed**:

```
CI (GitHub Actions)
  ├─ composer install --no-dev --optimize-autoloader
  ├─ npm ci && npm run build          → public/build/{manifest.json, assets/*}
  ├─ php artisan test                 → must be green
  ├─ php artisan pint --test
  └─ artifacts: public/build/** committed to the release branch
```

`public/build/**` is therefore **not** git-ignored (an explicit exception to the usual rule,
documented in `.gitignore` with the reason). Production never runs `npm`.

`.gitignore` still excludes: `.env`, `vendor/` (unless the "upload vendor" strategy below is
chosen), `storage/*` (except `.gitignore` stubs), `node_modules/`, backups, and any
`*.sqlite`.

**Two vendor strategies** — pick per host capability:

| Strategy | How | When |
|----------|-----|------|
| **A. Composer on the server** (preferred) | SSH in, `composer install --no-dev --optimize-autoloader --classmap-authoritative` | Host has SSH + Composer + enough memory |
| **B. Vendor uploaded** | CI produces `vendor/` as a build artifact; deploy uploads it | No SSH, or Composer installs are blocked/timed out |

---

## 3. Directory layout on cPanel (§96.6 document root)

cPanel gives you `/home/{user}/public_html` as the web root. Laravel's document root is
`public/`. **Two supported layouts:**

### Layout 1 — app outside the web root (recommended)

```
/home/{cpaneluser}/
├── eduplatform/                 ← the whole Laravel app, NOT web-accessible
│   ├── app/  bootstrap/  config/  database/  resources/  routes/  tests/
│   ├── storage/                 ← private/restricted files live here, unreachable by URL
│   ├── vendor/
│   ├── artisan  composer.json  .env
│   └── public/                  ← still present, but the docroot points INTO it
└── public_html/                 ← document root
    ├── index.php                ← copied from eduplatform/public/index.php, paths adjusted
    ├── .htaccess                ← copied from eduplatform/public/.htaccess
    ├── build/                   ← compiled assets (symlink or copy)
    ├── storage -> ../eduplatform/storage/app/public
    └── (nothing else — no .env, no vendor, no app code)
```

`public_html/index.php` differs from the repo copy in exactly two paths:

```php
require __DIR__.'/../eduplatform/vendor/autoload.php';
$app = require_once __DIR__.'/../eduplatform/bootstrap/app.php';
```

Keep this override as a **documented, tracked patch** (`deploy/cpanel/index.php`) so deploys
are reproducible rather than hand-edited on the server. Add a `deploy:cpanel` artisan command
that writes it, so no human edits PHP on a production box.

### Layout 2 — everything in `public_html` (only if the host forbids Layout 1)

```
/home/{cpaneluser}/public_html/          ← app root AND docroot
├── app/ bootstrap/ config/ database/ resources/ routes/ storage/ vendor/
├── .env                                 ← MUST be blocked by .htaccess (see below)
└── public/  →  docroot re-pointed here via cPanel "Domains → Document Root"
```

If the docroot cannot be re-pointed, the fallback is the classic
`public_html` = `public/` contents with the app one level up (Layout 1). **Never** leave
`.env`, `composer.json`, `storage/` or `vendor/` reachable by URL.

### Non-negotiable `.htaccess` protections

```apache
# In the app root (Layout 2) — deny everything, then allow public/
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

# In public_html/.htaccess (both layouts)
Options -Indexes
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
<FilesMatch "\.(env|json|lock|yml|yaml|log|md|sqlite|sql|sh|ini|dist)$">
    Require all denied
</FilesMatch>
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
</IfModule>
```

The `SecurityHeaders` middleware sets the same headers for dynamic responses; `.htaccess`
covers static files and misrouted requests. Belt and braces (§57).

---

## 4. File permissions (§96.8)

| Path | Owner | Mode | Notes |
|------|-------|------|-------|
| App root & code | cPanel user | `755` dirs / `644` files | Never `777` |
| `storage/` (all) | cPanel user | `775` dirs / `664` files | Writable by PHP |
| `storage/app/private`, `storage/app/restricted` | cPanel user | `770` / `660` | Tighter; not web-served |
| `bootstrap/cache/` | cPanel user | `775` | Must be writable for config/route caches |
| `.env` | cPanel user | **`600`** | Readable by the owner only |
| `public/` | cPanel user | `755` | Served |
| `public/storage` (symlink) | cPanel user | symlink | Created by `storage:link` |

```bash
find . -type d -not -path "./vendor/*" -not -path "./.git/*" -exec chmod 755 {} \;
find . -type f -not -path "./vendor/*" -not -path "./.git/*" -exec chmod 644 {} \;
chmod -R 775 storage bootstrap/cache
chmod 600 .env
```

`platform:doctor` reports any path PHP cannot write, any world-writable file, and any `.env`
with mode > `600`.

---

## 5. Environment configuration (§96.5)

Complete `.env` for production (all 60+ variables, grouped, commented — shipped as
`.env.example` in the repo):

```dotenv
# ── Application ────────────────────────────────────────────────────────────
APP_NAME="Eduplatform"
APP_ENV=production
APP_KEY=                        # php artisan key:generate --force  (NEVER commit)
APP_DEBUG=false                 # MUST be false in production (§76)
APP_TIMEZONE=UTC                # storage is always UTC (§62)
APP_URL=https://yourdomain.com
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_NG

# ── Logging & observability (§77) ──────────────────────────────────────────
LOG_CHANNEL=daily
LOG_LEVEL=warning               # 'debug' only while investigating
LOG_RETENTION_DAYS=90
PAYMENT_LOG_RETENTION_DAYS=730

# ── Database (§96.3) ───────────────────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1               # usually localhost on cPanel
DB_PORT=3306
DB_DATABASE=cpuser_eduplatform
DB_USERNAME=cpuser_edu
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
DB_STRICT=true
DB_SOCKET=                      # set if the host uses a socket instead of TCP

# ── Drivers: shared-hosting defaults (§69) ─────────────────────────────────
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax           # 'lax' so OAuth callbacks work; portals re-check auth
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log

# ── Mail (§51) ─────────────────────────────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=465
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

# ── Paystack (§41) — secrets NEVER committed ───────────────────────────────
PAYSTACK_MODE=live              # test | live — independent of APP_ENV (§75)
PAYSTACK_TEST_PUBLIC_KEY=pk_test_...
PAYSTACK_TEST_SECRET_KEY=sk_test_...
PAYSTACK_LIVE_PUBLIC_KEY=pk_live_...
PAYSTACK_LIVE_SECRET_KEY=sk_live_...
PAYSTACK_WEBHOOK_URL="${APP_URL}/webhooks/paystack"
PAYSTACK_WEBHOOK_IP_ALLOWLIST=  # optional (§08 1.5 caveat)

# ── Google (§36) ───────────────────────────────────────────────────────────
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
GOOGLE_MEET_REDIRECT_URI="${APP_URL}/integrations/google/callback"
GOOGLE_MEET_ENABLED=true
GOOGLE_ORG_CALENDAR_ID=

# ── Microsoft (§3) ─────────────────────────────────────────────────────────
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
MICROSOFT_REDIRECT_URI="${APP_URL}/auth/microsoft/callback"
MICROSOFT_TENANT=common

# ── Zoom (§37) ─────────────────────────────────────────────────────────────
ZOOM_ENABLED=true
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_REDIRECT_URI="${APP_URL}/integrations/zoom/callback"
ZOOM_S2S_ENABLED=false
ZOOM_ACCOUNT_ID=
ZOOM_S2S_CLIENT_ID=
ZOOM_S2S_CLIENT_SECRET=

# ── Platform behaviour (non-secret; DB settings override where noted) ──────
PLATFORM_MAJOR_VERSION=1
PLATFORM_DEMO_ACCOUNTS=false    # MUST be false in production (§74)
PLATFORM_DEFAULT_VIDEO_PROVIDER=manual
PLATFORM_SUPPORT_EMAIL="support@yourdomain.com"
```

**§96.5 checklist:**
1. Upload `.env`, `chmod 600`.
2. `php artisan key:generate --force` → `APP_KEY` written.
3. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (https).
4. Create the DB user in cPanel → MySQL Databases, grant **all privileges on that database
   only**, add the user to `.env`.
5. Fill Paystack / Google / Microsoft / Zoom credentials **only for what you will use**;
   leave the rest empty — the platform detects `not_configured` and hides those features.
6. `php artisan config:cache route:cache view:cache event:cache`.

---

## 6. Cron: the scheduler (§70, §96.11)

**One** cron entry drives the entire schedule. In cPanel → Cron Jobs:

```
* * * * * /usr/local/bin/php /home/CPUSER/eduplatform/artisan schedule:run >> /home/CPUSER/eduplatform/storage/logs/cron.log 2>&1
```

Use the **absolute** PHP path (`which php` / cPanel's MultiPHP path — often
`/usr/local/bin/php`, `/opt/cpanel/ea-php84/root/usr/bin/php` or
`/usr/bin/php84`). Relative paths and `php` on `PATH` are the #1 cause of "cron does
nothing" on shared hosting.

### `routes/console.php` — the full schedule

```php
use Illuminate\Support\Facades\Schedule;

// ── Money ────────────────────────────────────────────────────────────────
Schedule::command('payments:reconcile')->everyFiveMinutes()
        ->withoutOverlapping(10)->runInBackground(false);
Schedule::command('subscriptions:check')->hourly()->withoutOverlapping(30);
Schedule::command('entitlements:expire')->hourlyAt(5)->withoutOverlapping(30);
Schedule::command('invoices:mark-overdue')->dailyAt('02:10');

// ── Sessions & reminders ─────────────────────────────────────────────────
Schedule::command('sessions:generate --days=30')->dailyAt('01:00')->withoutOverlapping(60);
Schedule::command('sessions:provision-meetings')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('sessions:remind --offset=24h')->hourlyAt(15);
Schedule::command('sessions:remind --offset=1h')->everyFifteenMinutes();
Schedule::command('sessions:mark-no-show')->hourlyAt(45);

// ── Academics ────────────────────────────────────────────────────────────
Schedule::command('assessments:open')->everyFifteenMinutes();
Schedule::command('assessments:close')->everyFifteenMinutes();
Schedule::command('assessments:remind-due')->dailyAt('08:00');
Schedule::command('progress:recalculate')->dailyAt('03:00')->withoutOverlapping(120);
Schedule::command('reports:build-scheduled')->dailyAt('04:00');

// ── Bookings ─────────────────────────────────────────────────────────────
Schedule::command('bookings:release-expired-holds')->everyFiveMinutes();
Schedule::command('reviews:open-window')->hourlyAt(20);

// ── Maintenance & hygiene ────────────────────────────────────────────────
Schedule::command('integrations:refresh-tokens')->everyThirtyMinutes();
Schedule::command('integration-logs:prune')->dailyAt('02:30');
Schedule::command('audit:prune')->dailyAt('02:40');
Schedule::command('imports:prune')->weeklyOn(0, '03:10');
Schedule::command('carts:abandon --after=72h')->dailyAt('03:20');
Schedule::command('queue:retry-failed --max=3')->dailyAt('03:30');
Schedule::command('platform:health-check')->everyThirtyMinutes();
Schedule::command('platform:backup-database')->dailyAt('00:30')->withoutOverlapping(120);
Schedule::command('platform:backup-files')->weeklyOn(6, '01:30')->withoutOverlapping(240);
```

`withoutOverlapping()` matters on shared hosting: a slow 1-minute cron can otherwise stack
duplicate runs. Because `runInBackground()` is not available without a proper process manager,
long commands are written to be **resumable and idempotent** (they process a bounded batch per
run and pick up where they left off).

---

## 7. Queue worker without a daemon (§69, §96.12)

Shared hosts kill long-running processes, so the worker is **cron-driven**:

```
* * * * * /usr/local/bin/php /home/CPUSER/eduplatform/artisan queue:work database --stop-when-empty --max-time=50 --max-jobs=100 --tries=3 --sleep=1 --queue=default,payments,emails,reports >> /home/CPUSER/eduplatform/storage/logs/queue.log 2>&1
```

| Flag | Why |
|------|-----|
| `--stop-when-empty` | exits when drained, so cron can start a fresh worker next minute |
| `--max-time=50` | finishes inside the 60 s cron window; no overlapping workers |
| `--max-jobs=100` | bounds memory on small hosts (mitigates leaks) |
| `--tries=3` | matches `tries` on job classes |
| `--queue=…` | priority ordering: `payments` before `emails` before `reports` |

**Design consequences** (already reflected in the code plan):
- Every job is **idempotent and retry-safe** — a worker killed at second 50 must not corrupt
  state on retry.
- `ShouldBeUnique` is used for certificate rendering, payment verification, reminders and
  report builds, with `uniqueFor` bounds so a lost lock self-heals.
- Latency-sensitive notifications (password reset, 2FA, security alerts) are sent
  **synchronously**, not queued — a user must never be locked out by queue lag.
- Job payload sizes are kept small (ids, not models) to respect MySQL row limits in `jobs`.

**Upgrade path:** on a VPS, replace both cron lines with Supervisor running
`queue:work --daemon` (+ Horizon if Redis is added). No application code changes (§98).

---

## 8. Deployment procedure (§96.4, §96.9, §96.10)

### First deployment

```bash
# 1. Get the code (SSH) — or upload a release zip via cPanel File Manager
cd /home/CPUSER
git clone git@github.com:ugodspecial/lms.git eduplatform
cd eduplatform && git checkout <release-tag>

# 2. Dependencies (§96.4)
composer install --no-dev --optimize-autoloader --classmap-authoritative
#    no composer on the server? → upload the CI-built vendor/ artifact

# 3. Environment (§96.5)
cp .env.example .env && nano .env && chmod 600 .env
php artisan key:generate --force

# 4. Database (§96.3) — create DB + user in cPanel first
php artisan migrate --force                       # §96.9
php artisan db:seed --class=ProductionSeeder --force   # §96.10 (roles, permissions,
                                                      # settings, grading schemes,
                                                      # admin account — NO demo data)

# 5. Storage symlink (§96.7)
php artisan storage:link
php artisan platform:fix-permissions              # chmod pass from §4

# 6. Caches
php artisan config:cache && php artisan route:cache
php artisan view:cache   && php artisan event:cache

# 7. Document root (§96.6)
php artisan deploy:cpanel                         # writes public_html/index.php + .htaccess

# 8. Cron (§96.11, §96.12) — add the two entries from §6 and §7

# 9. SSL (§96.13) — cPanel → SSL/TLS Status → Run AutoSSL, then force HTTPS

# 10. Verify
php artisan platform:doctor
php artisan paystack:ping
php artisan paystack:verify-webhook
```

`ProductionSeeder` is **separate** from `DemoDataSeeder`. It creates: the permission
registry, roles, default grading schemes, essential settings, one Super Admin (email from
`--email`, password prompted interactively or from `PLATFORM_FIRST_ADMIN_PASSWORD`, forced to
change on first login), and the 16 message templates. **It never creates demo students,
parents, tutors or orders** (§73, §74).

`DemoDataSeeder` is invoked only by `php artisan platform:seed-demo`, which **refuses to run
when `APP_ENV=production`** unless `--i-know-this-is-production` is passed, and which stamps
every created record with `meta.demo = true` so it can be purged (§73).

### Subsequent deployments (low-risk, no SSH required if necessary)

```bash
php artisan down --retry=60 --refresh=60        # optional maintenance page
git fetch && git checkout <release-tag>
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Rules that keep deploys safe on a host you cannot easily debug:
- **Migrations are additive and reversible** wherever possible; destructive changes ship as
  expand → migrate data → contract across two releases.
- `migrate --force` is preceded by an automatic backup (§11).
- Route/config caches are **always** rebuilt — a stale cache is the classic "it broke after
  deploy and nothing changed" cause.
- `php artisan about` output is captured to the deploy log for post-mortems.

---

## 9. External service configuration (§96.14–§96.18)

| # | Item | Value to configure | Where |
|---|------|--------------------|-------|
| §96.14 | **Paystack webhook URL** | `https://yourdomain.com/webhooks/paystack` | Paystack Dashboard → Settings → Preferences → Webhook URL. Verify with `php artisan paystack:verify-webhook`. Must be **HTTPS**; the route is CSRF-exempt and HMAC-verified |
| §96.15 | **Google OAuth redirect** | `https://yourdomain.com/auth/google/callback` (login) and `https://yourdomain.com/integrations/google/callback` (Meet) | Google Cloud Console → APIs & Services → Credentials → OAuth client → Authorized redirect URIs (exact match). Enable the **Calendar API**. Complete the OAuth consent screen (app name, logo, privacy policy, terms) and submit for verification before production |
| §96.16 | **Microsoft OAuth redirect** | `https://yourdomain.com/auth/microsoft/callback` | Entra ID → App registrations → Authentication → Redirect URI (Web). Note the tenant id; `common` for multi-tenant |
| §96.17 | **Zoom OAuth configuration** | Redirect `https://yourdomain.com/integrations/zoom/callback` | Zoom App Marketplace → Develop → build a **General (User-managed OAuth)** app for tutor connections, and/or a **Server-to-Server OAuth** app for the org fallback. Request granular scopes `meeting:read`, `meeting:write` (+ `:admin` variants for S2S). ⚠ For user-managed apps, an **account admin must install the app** or meeting scopes fail with "Invalid scope" |
| §96.18 | **Google API configuration** | Calendar API enabled; OAuth consent screen verified; scopes limited to `calendar.events` | Google Cloud Console. Domain verification is required if you later use org-wide delegation |
| — | Mail | SMTP host/port/user/pass from cPanel → Email Accounts; SPF + DKIM + DMARC DNS records | cPanel → Email Deliverability |
| — | SSL | AutoSSL / Let's Encrypt, then force HTTPS | cPanel → SSL/TLS Status |

**Environment separation:** create **separate** OAuth apps and **separate** Paystack keys for
staging and production. Never share credentials across environments — a staging test would
otherwise create real meetings in the production Zoom account and real webhooks against the
live Paystack account.

---

## 10. Production hardening checklist (§57, §76, §77)

Run `php artisan platform:doctor --check=all`; it asserts every line below.

**Application**
- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` set, `.env` mode `600`, not committed
- [ ] `PLATFORM_DEMO_ACCOUNTS=false`; `/demo-login` route not registered
- [ ] Config/route/view/event caches built
- [ ] `DemoDataSeeder` never run; no `meta.demo = true` rows exist
- [ ] First Super Admin password changed from the seeded value
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`
- [ ] HSTS + security headers present on a real response (curl -I)
- [ ] Error pages 403/404/419/429/500/503 render without stack traces

**Database**
- [ ] Dedicated DB user with privileges on **one** database only
- [ ] `sql_mode` includes `STRICT_TRANS_TABLES`
- [ ] All migrations applied; `migrations` table matches the release
- [ ] Backup job has run successfully in the last 24 h

**Files**
- [ ] `.env`, `composer.json`, `storage/`, `vendor/` unreachable by URL (verified with curl)
- [ ] `public/storage` symlink exists and points at `storage/app/public`
- [ ] No sensitive file has `visibility=public`
- [ ] `storage/app/restricted` is mode `770`

**Payments**
- [ ] `PAYSTACK_MODE` matches intent; `paystack:ping` returns success
- [ ] Webhook signature verified end-to-end with a real Paystack test event
- [ ] `payments:reconcile` cron has run (check `integration_logs`)
- [ ] A ₦100 test order completes: order → payment → entitlement → access

**Integrations**
- [ ] Each configured provider reports `ready`; each unconfigured one reports
      `not_configured` **and its UI is hidden** (§93)
- [ ] A test meeting is created and the join URL resolves (`provisioning → ready`)
- [ ] Token refresh works (connected account older than 1 h)

**Operations**
- [ ] Both cron entries present and firing (check `storage/logs/cron.log`, `queue.log`)
- [ ] `failed_jobs` is empty or triaged
- [ ] Log retention configured; disk usage < 70%
- [ ] Uptime + error monitoring connected (external service, optional)

---

## 11. Backups (§97)

**Rule: backups are never stored inside a publicly accessible directory.**

```
/home/CPUSER/backups/                    ← OUTSIDE public_html, mode 750
├── db/eduplatform-2026-09-07-0030.sql.gz
├── files/private-2026-09-07.tar.gz
├── files/restricted-2026-09-07.tar.gz
├── config/env-2026-09-07.tar.gz.enc     ← .env + settings dump, encrypted with APP_KEY
└── manifest.json                        ← checksums + sizes for restore verification
```

### What is backed up (§97)

| Target | Method | Frequency | Retention |
|--------|--------|-----------|-----------|
| **MySQL** (§97.1) | `platform:backup-database` → `mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4` piped through `gzip` (pure PHP `php -r` fallback if `exec()` is disabled, using PDO row streaming) | daily 00:30 + before every deploy | 7 daily, 4 weekly, 6 monthly |
| **Uploaded files** (§97.2) | `platform:backup-files` → tar of `storage/app/{private,restricted,authenticated,public}` | weekly + incremental nightly for `private` | 4 weekly, 3 monthly |
| **Application config** (§97.3) | `.env` + a JSON dump of the `settings` table + `config/*.php` diff, **encrypted** (`Crypt::encrypt`) | daily | 30 days |
| **Code** | Git — the repo *is* the backup | every deploy | forever |

### 3-2-1 rule

At least **3** copies, on **2** media, **1** offsite:
1. On-host `/home/CPUSER/backups/`
2. cPanel's own backup system (if the host provides it) — enable it, don't rely on it alone
3. **Offsite**: `platform:backup-push` uploads to an S3-compatible bucket (Backblaze B2,
   Wasabi, Cloudflare R2, S3) using credentials from `.env`. If no offsite target is
   configured, the doctor command reports `backup.offsite: not_configured` and warns — it does
   not silently claim you're protected.

### Restore drill

A backup you have never restored is a hypothesis. `php artisan platform:restore-test`
restores the latest dump into a scratch database, runs a row-count + checksum comparison
against production and reports divergences. Scheduled **monthly**; the result is logged.

```bash
# Manual restore
gunzip < backups/db/eduplatform-2026-09-07-0030.sql.gz | mysql -u CPUSER_edu -p cpuser_eduplatform
tar -xzf backups/files/private-2026-09-07.tar.gz -C /home/CPUSER/eduplatform/storage/app/
php artisan config:cache && php artisan platform:doctor
```

### Data classification & retention (§58)

| Class | Examples | Retention | Deletion |
|-------|----------|-----------|----------|
| Academic record | results, submissions, certificates, attendance | configurable, default **7 years** after withdrawal | anonymize, never hard-delete while financial records exist |
| Financial record | orders, payments, invoices, refunds | configurable, default **7 years** (tax) | archive |
| Sensitive personal (minors) | student documents, DOB, notes, consent scans | configurable, default **2 years** after withdrawal | purge files, tombstone the `files` row |
| Recruitment | tutor applications, CVs, evaluations | default **24 months** after rejection; permanent after approval | purge documents, keep the decision record |
| Auth/telemetry | sessions, `integration_logs`, `learning_activities`, `audit_logs` | 30–90 days (audit: configurable, default 365) | pruned by scheduled commands |

Retention values live in `settings` (`retention.*`), never hard-coded (§95).

---

## 12. Scaling path (§98)

The architecture is deliberately **host-agnostic**: every shared-hosting compromise is a
*configuration* choice, not a code path.

| Stage | Infrastructure | Config changes only | Code changes |
|-------|----------------|---------------------|--------------|
| **1. Shared hosting** (launch → ~2,000 students) | cPanel + MySQL + cron queue | as this document | none |
| **2. Managed/VPS** (~2,000–20,000) | Nginx + PHP-FPM 8.4, MySQL 8, Redis, Supervisor | `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`; Supervisor runs `queue:work --daemon` + Horizon; remove the queue cron | none |
| **3. Cloud single-region** (~20,000–100,000) | Load-balanced app tier, managed MySQL with read replicas, S3 storage, Meilisearch, Reverb | `FILESYSTEM_DISK=s3`; read-replica connection for reports; `SCOUT_DRIVER=meilisearch`; Reverb (database driver works, Redis better) | none — the `SearchIndex` and `Filesystem` contracts absorb it |
| **4. Cloud multi-service** (100,000+) | Horizontal app tier, per-module queue workers, partitioned hot tables, CDN for `public/`, optional Octane | queue names already segmented (`payments`, `emails`, `reports`); partition `learning_activities`, `audit_logs`, `attendance_records`; CDN in front of `public/build` and `public/storage` | none required; Octane needs a statefulness audit (documented as a task, not a rewrite) |

**Why this works** — the specific decisions that prevent a rewrite:

1. **Domain services depend on contracts, not adapters** (`PaymentGateway`,
   `VideoMeetingProvider`, `SearchIndex`, `DocumentRenderer`, `Storage`). Swapping a provider
   is registration in a service provider.
2. **No in-process state.** Everything lives in MySQL/S3. Any host can serve any request, so
   horizontal scaling needs no session-stickiness work.
3. **Queue names are already segmented**, so a VPS can run dedicated workers per priority
   without touching job classes.
4. **`files.disk` is per row**, so storage migration to S3 can be gradual and reversible.
5. **Money, time and identifiers are host-independent**: integer minor units, UTC instants,
   BIGINT PKs + UUID public references.
6. **No Node runtime dependency in production** — assets are pre-built, so the app tier stays
   plain PHP at every stage.
7. **API-ready domain layer** (§68) — a future mobile app consumes the same Actions/DTOs
   through `/api/v1` without duplicating business rules (§99).

---

## 13. Shared-hosting pitfalls (and the mitigation already designed in)

| Pitfall | Symptom | Mitigation |
|---------|---------|------------|
| No PHP 8.3 available | `composer install` fails on platform requirements | `platform:doctor` checks the PHP version first; `composer.json` pins `^8.3` so it fails loudly, not mysteriously. Choose the host before choosing the framework version |
| `exec()` disabled | wkhtmltopdf/Imagick-based tools crash | dompdf + bacon-qr are **pure PHP**; backups have a PDO fallback |
| Cron only every 5/15 minutes | Reminders late, queue backlog | Reminders are offset-based and idempotent; the queue drains in bounded batches; `settings` document minimum cron granularity |
| Worker killed mid-job | Half-sent emails, half-created records | All jobs idempotent; `ShouldBeUnique`; DB transactions around multi-row writes |
| `open_basedir` restrictions | Can't read paths outside the account | Everything the app touches is inside `/home/CPUSER`; backups too |
| Symlinks disabled | `storage:link` fails | `deploy:cpanel` can copy `public/storage` instead; `platform:doctor` detects a missing link and explains both options |
| Max 20 MySQL connections | Timeouts under load | Connection pooling via persistent connections off + report queries on a schedule, not per request; heavy reads cached |
| Disk quota exhausted by logs | 500s, failed writes | Per-channel log retention, daily rotation, `LOG_LEVEL=warning` in production, log-pruning cron |
| PHP `memory_limit` 128 MB | dompdf/imports crash | Chunked imports (streaming), queued PDF rendering, bounded batch sizes, `--max-jobs` on the worker |
| ModSecurity blocks webhook POSTs | Paystack webhooks 403 | Documented: whitelist the webhook path with the host; `payment_events` + `payments:reconcile` mean a blocked webhook still resolves via API verification |
| Timezone set to server-local | Wrong session times | `APP_TIMEZONE=UTC`; all instants UTC; local wall-clock stored explicitly with an IANA name (§62) |
| `.env` served as a static file | **Secret leak** | `.htaccess` `FilesMatch` deny + Layout 1 puts `.env` outside the docroot entirely + doctor check curls it and expects 403/404 |

---

## 14. Environments (§75)

| | local | staging | production |
|---|---|---|---|
| Domain | `eduplatform.test` | `staging.yourdomain.com` | `yourdomain.com` |
| `APP_ENV` / `APP_DEBUG` | local / true | staging / true | production / **false** |
| `PAYSTACK_MODE` | test | test | **live** |
| OAuth apps | local dev app | staging app | production app |
| Zoom app | dev | dev | production |
| Mail | `log` | SMTP sandbox | SMTP production |
| Queue | `sync` or `database` | `database` + cron | `database` + cron |
| Seed data | demo allowed | demo allowed | **`ProductionSeeder` only** |
| Cron | `schedule:work` | 1-min cron | 1-min cron + 1-min queue worker |
| Backups | none | weekly to scratch | daily DB + weekly files + offsite |

Promotion is `git tag` → staging → smoke test → production. The staging environment runs the
**exact** deployment script from §8, so the production deploy is never a first-time event.
