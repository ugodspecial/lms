# Setup & integration guide

Everything here is written for a deployment that has **no SSH access, no Node
runtime and no root privileges** — conventional cPanel shared hosting. Where a
step needs something the host does not provide, it says so and gives the
workaround rather than assuming a VPS.

Run `php artisan platform:doctor` after any change on this page. It reads the same
configuration the application reads, so it cannot tell you something is
configured when it is not.

---

## Contents

- [1. Requirements](#1-requirements)
- [2. Local development](#2-local-development)
- [3. Deploying to cPanel shared hosting](#3-deploying-to-cpanel-shared-hosting)
- [4. Cron & queues](#4-cron--queues)
- [5. Email](#email)
- [6. Paystack](#paystack)
- [7. Google sign-in, Calendar & Meet](#google)
  - [Google Meet provisioning](#google-meet)
- [8. Microsoft sign-in](#microsoft)
- [9. Zoom](#zoom)
- [10. Storage & file visibility](#10-storage--file-visibility)
- [11. Backups](#11-backups)
- [12. Troubleshooting](#12-troubleshooting)

---

## 1. Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 8.3 | 8.4 |
| MySQL | 8.0 | 8.4 |
| MariaDB | 10.6 | 11.x |
| Composer | 2.x | 2.x |
| Node (build machine only) | 22 | 22 |

**SQLite is not supported.** The schema uses FULLTEXT indexes and enforced
`CHECK` constraints — the "exactly one entitlement target" rule is a `CHECK`
constraint, and SQLite ignores it silently. A SQLite deployment would appear to
work while enforcing none of its integrity rules.

Required PHP extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json`,
`ctype`, `tokenizer`, `xml`, `curl`, `fileinfo`, `bcmath`, `zip`, `gd`.

`fileinfo` is a security control, not a convenience: it is what stops a file
renamed from `shell.php` to `notes.jpg` from being accepted as a course upload.
`bcmath` is what makes money arithmetic exact.

On cPanel: **Select PHP Version** → tick the extensions → **Apply**. The CLI and
Apache PHP binaries are configured separately on many hosts, so enable them for
both, and confirm with:

```bash
php -m | grep -E 'bcmath|fileinfo|gd|zip|intl'
```

Recommended `php.ini` values (`256M` / `60` / `20M` / `25M` for memory limit,
max execution time, upload and post size). `platform:doctor` warns if a value is
too low and explains which feature needs it.

---

## 2. Local development

```bash
composer install
cp .env.example .env
php artisan key:generate

mysql -u root -e "CREATE DATABASE eduplatform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE eduplatform_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate --seed
npm install
npm run dev          # or: npm run build
php artisan serve
```

In a second terminal, drain the queue — without a worker, anything dispatched
(enrollment emails, meeting provisioning, webhook processing) is stored and never
executed:

```bash
php artisan queue:work --tries=3 --timeout=90
```

### Running the tests

```bash
php artisan test                     # all four suites
vendor/bin/phpunit --testsuite Unit  # domain rules, no database needed
```

Tests use the **separate** `eduplatform_test` database and run
`migrate:fresh` — they drop every table in whatever database
`.env.testing` names. Never point that file at a database you care about.

`.env.testing` also carries a throwaway `APP_KEY`, so a fresh clone can run the
suite with no setup step. It is not a secret: it protects cookies and encrypted
columns for a database that is wiped on every run and holds no real person's
data. It must decode to exactly 32 bytes or the encrypter throws *Unsupported
cipher or incorrect key length* the first time a test touches a session. **Never
copy it into `.env`** — production keys come from `php artisan key:generate` and
stay out of git. `phpunit.xml` deliberately does not declare an `APP_KEY`, so
there is exactly one source for it.

No test may use a real payment credential. `phpunit.xml` sets fake Paystack keys
and every external call is made through `Http::fake()` or a manual gateway
double.

---

## 3. Deploying to cPanel shared hosting

### 3.1 Document-root layout

This is the part that is most often done wrong, and getting it wrong exposes
`.env` — which holds the Paystack secret key and the database password — to
anyone who guesses a URL.

```
/home/<user>/
├── eduplatform/            ← Laravel root. NOT web-accessible.
│   ├── app/  config/  routes/  storage/  vendor/
│   ├── .env
│   ├── artisan
│   └── public/             ← the *contents* of this become the document root
│
└── public_html/            ← document root: contents of eduplatform/public/
    ├── index.php           ← edited, see below
    ├── .htaccess
    ├── build/
    └── storage -> ../eduplatform/storage/app/public
```

Upload the Laravel root **outside** `public_html` (File Manager, or
`git clone` over SSH if your host allows it), then either:

**(a)** Point the domain's document root at `eduplatform/public` in
cPanel → Domains, **or**

**(b)** Copy the *contents* of `eduplatform/public` into `public_html` and edit
`public_html/index.php` so both paths point one level deeper:

```php
require __DIR__.'/../eduplatform/vendor/autoload.php';
$app = require_once __DIR__.'/../eduplatform/bootstrap/app.php';
```

Option (a) is cleaner and survives a `git pull` without re-copying. Use (b) only
when the host will not let you move the document root.

### 3.2 Deploy steps

```bash
cd /home/<user>/eduplatform
git pull
composer install --no-dev --optimize-autoloader
cp .env.example .env          # first deploy only — then edit it
php artisan key:generate      # first deploy only
php artisan migrate --force
php artisan storage:link
php artisan config:cache route:cache view:cache
php artisan platform:doctor
```

**Front-end assets are already built and committed** (`public/build`). There is no
`npm install` step on the server, and there does not need to be a Node runtime.
CI rebuilds them on every push and fails if the committed copy is stale, so a
`git pull` always ships assets that match the source.

### 3.3 `.env` for production

```ini
APP_ENV=production
APP_DEBUG=false          # critical: true renders stack traces and credentials
APP_URL=https://your-real-domain.test
SESSION_SECURE_COOKIE=true
PLATFORM_DEMO_ACCOUNTS=false
LOG_LEVEL=warning
```

`APP_DEBUG=true` in production renders exception details, file paths, database
credentials and student data to anyone who can trigger an error. `platform:doctor`
fails the deployment on it.

`APP_URL` must be the real public URL: OAuth redirect URIs, password-reset links,
certificate URLs and Paystack callbacks are all built from it.

---

## 4. Cron & queues

There is no supervisor on shared hosting. Two cron entries replace it — see
[`deploy/cpanel/crontab.txt`](deploy/cpanel/crontab.txt) for the exact lines with
commentary.

```
* * * * *   cd /home/<user>/eduplatform && php artisan schedule:run  >> /dev/null 2>&1
* * * * * * cd /home/<user>/eduplatform && php artisan queue:work --stop-when-empty --tries=3 --timeout=90 --sleep=3 >> /dev/null 2>&1
```

Both are required:

- Without `schedule:run`, nothing in `routes/console.php` runs — no session
  pruning, no failed-job cleanup, no daily health check.
- Without `queue:work`, queued work accumulates forever and is never executed.
  The visible symptom is "the platform accepted it but nothing happened": an
  order stays pending after payment, a verification email never arrives, a
  meeting link is never created.

`queue:work --stop-when-empty` exits when the queue drains, which is what makes
it safe from cron on a host that kills long-lived processes.

The PHP binary cron uses is **independent** of the one Apache uses. Find it with
`which php`, and if the host offers several versions point cron at the 8.3+
binary explicitly (e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`). A mismatch here
produces errors that cannot be reproduced in the browser.

Verify:

```bash
php artisan schedule:list
tail -f storage/logs/laravel.log
```

---

## Email

Transactional email carries email verification, password resets, enrolment
confirmations, receipts and meeting reminders. Marketing email is a separate
consent and a separate concern — the platform never auto-subscribes anyone, and
a transactional message is never used to carry marketing content.

### Local

```ini
MAIL_MAILER=log
```

Messages are written to `storage/logs/laravel.log`. Nothing is delivered.

### cPanel SMTP

```ini
MAIL_MAILER=smtp
MAIL_HOST=mail.your-domain.test
MAIL_PORT=465
MAIL_USERNAME=no-reply@your-domain.test
MAIL_PASSWORD="the mailbox password"
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="no-reply@your-domain.test"
MAIL_FROM_NAME="EduPlatform"
```

Create the mailbox in cPanel → Email Accounts first. Port 465 is SMTPS (implicit
TLS); port 587 uses `MAIL_ENCRYPTION=tls`. Many shared hosts block outbound port
25 — do not use it.

`MAIL_FROM_ADDRESS` must be a real mailbox on the sending domain. A from-address
that does not match the authenticated user is the most common cause of messages
landing in spam, and for a password-reset email that is indistinguishable from
"the platform is broken".

### Managed providers

Postmark, Resend and SES are supported by setting `MAIL_MAILER` and the matching
keys in `.env` (`POSTMARK_API_KEY`, `RESEND_API_KEY`, `AWS_*`). For anything
above a few hundred messages a day, a managed provider is worth it: shared-hosting
SMTP is rate-limited, and a throttled password reset at 9pm before an exam is a
support ticket you cannot fix from the application.

### Verifying

```bash
php artisan platform:doctor --only=runtime    # reports MAIL_MAILER and from-address
```

`MAIL_MAILER=log` in production is reported as a **failure**: it means no email
is ever delivered, so verification and password reset silently do nothing.

---

## Paystack

Payments are the highest-risk integration in the platform. Read this whole
section before enabling it.

### The two keys

| Key | Prefix | Where it may appear |
|---|---|---|
| Public | `pk_test_…` / `pk_live_…` | Browser, JS, checkout form |
| Secret | `sk_test_…` / `sk_live_…` | **Server only. Never a view, bundle, log or commit.** |

`tests/Payment/PaystackKeyHandlingTest.php` enforces this on every build: it
scans the compiled assets, the source tree and every unauthenticated response for
a key-shaped string, and fails if the secret appears anywhere it should not.

### Mode is independent of APP_ENV

```ini
PAYSTACK_MODE=test        # or: live
PAYSTACK_TEST_PUBLIC_KEY=pk_test_...
PAYSTACK_TEST_SECRET_KEY=sk_test_...
PAYSTACK_LIVE_PUBLIC_KEY=
PAYSTACK_LIVE_SECRET_KEY=
```

A staging server may run live keys against a real merchant account while
`APP_ENV=staging`; a production server may be pointed at test while a migration
is rehearsed. `config('services.paystack.secret')` always resolves to the correct
key for the configured mode, and no service anywhere branches on the mode itself.

### Setup

1. Create an account at [dashboard.paystack.com](https://dashboard.paystack.com).
   Test and live keys are **separate** — switching mode in the dashboard does not
   change the keys in `.env`.
2. Copy the keys into `.env` for the mode you are configuring.
3. Set `PAYSTACK_ENABLED=true`.
4. Set the callback URL:
   ```ini
   PAYSTACK_CALLBACK_URL="https://your-domain.test/payments/paystack/callback"
   ```
5. In **Settings → Developer → Callback URL**, enter the same address. Paystack
   redirects the browser here after checkout.
6. In **Settings → Developer → Webhook URL**, enter:
   ```
   https://your-domain.test/webhooks/paystack
   ```
7. Verify:
   ```bash
   php artisan platform:doctor --only=integrations
   ```

### How the platform confirms a payment

The browser redirect is **never** trusted to mark an order paid. A redirect can be
forged, replayed, or simply never arrive (the customer closes the tab). The flow
is:

1. Server creates the transaction with Paystack and stores the reference.
2. Customer pays on Paystack's hosted page.
3. Paystack redirects the browser to the callback URL.
4. **Server calls `GET /transaction/verify/{reference}`** and records what
   Paystack says — amount, currency, status — not what the URL says.
5. The webhook provides the same confirmation independently, for customers who
   never come back.
6. Only then is an entitlement granted.

Payment records are never coupled directly to course access: a payment creates an
order, an order creates entitlements, and access is checked against entitlements.
That indirection is what makes a refund, a partial refund, a transferred purchase
and a "parent buys, child receives" all expressible without rewriting access
logic.

### Webhooks

Paystack signs each delivery with **HMAC-SHA512 of the raw request body** using
your **secret key**, sent in the `x-paystack-signature` header. There is no
separate webhook secret.

Two consequences that are easy to get wrong:

- The signature must be computed over `$request->getContent()` — the exact bytes
  received. Re-serialising a decoded array produces a different string and every
  payload containing a nested object will fail verification.
- Webhook routes are exempt from CSRF (they cannot obtain a token) and are
  rate-limited instead. The exemption is scoped to `webhooks/*` and a test asserts
  that CSRF protection is still enforced everywhere else.

Deliveries are **idempotent**: `payment_events` carries a unique
`(provider, provider_event_id)` constraint, so a re-delivery — which Paystack will
do, repeatedly, whenever your endpoint is slow — is recorded once and processed
once. Duplicate orders, entitlements and subscriptions are prevented by unique
constraints, not by application-level checks that a race could defeat.

Amounts are **integer minor units** (kobo). Do not assume two decimal places:
see `App\Domain\Commerce\ValueObjects\Currency`, which reads the exponent from
configuration because some currencies in the region have zero.

### Going live

Before switching `PAYSTACK_MODE=live`:

- [ ] Rehearse the full flow on test keys: success, failure, abandoned checkout,
      duplicate webhook delivery, refund.
- [ ] Confirm the webhook is reaching you (Paystack dashboard → Settings →
      Developer shows delivery history and response codes).
- [ ] Confirm `platform:doctor` reports Paystack as configured.
- [ ] Confirm reconciliation: every paid transaction in Paystack has a matching
      order, and every order marked paid has a verified transaction.
- [ ] Set `PAYSTACK_LIVE_*` keys, switch the mode, and re-run `platform:doctor`.

---

## Google

One Google Cloud project provides sign-in, Calendar and Meet. Sign-in and Meet
are gated separately (`GOOGLE_ENABLED` and `GOOGLE_MEET_ENABLED`), because an
organisation may want Google login without exposing Google Meet.

### Create credentials

1. Go to [console.cloud.google.com](https://console.cloud.google.com) → create or
   select a project.
2. **APIs & Services → OAuth consent screen**
   - User type: **External** (unless every user is in your Google Workspace).
   - Fill in the app name, support email and developer email.
   - Add your own account under **Audience → Test users** while the app is in
     "Testing" status. Without this, sign-in fails with
     `Error 403: access_denied` for everyone but you.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**
   - Application type: **Web application**
   - Authorized JavaScript origins: `https://your-domain.test`
   - Authorized redirect URIs — **exact match, including scheme and trailing
     slash**. Add one per environment:
     ```
     https://your-domain.test/auth/google/callback
     https://staging.your-domain.test/auth/google/callback
     http://localhost:8000/auth/google/callback
     ```
4. Copy the client ID and secret into `.env`:
   ```ini
   GOOGLE_ENABLED=true
   GOOGLE_CLIENT_ID="....apps.googleusercontent.com"
   GOOGLE_CLIENT_SECRET="..."
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
   ```
5. Enable the APIs you need under **APIs & Services → Library**.
6. `php artisan platform:doctor --only=integrations`

`redirect_uri_mismatch` is almost always a trailing-slash or http/https mismatch
between `.env` and the console. The value must match character for character.

Scopes are requested **incrementally**: sign-in asks for `openid`, `email`,
`profile` only. Calendar scopes are requested when a user connects a calendar,
not at first login — a parent who only ever signs in must not be shown a consent
screen asking to manage their calendar.

### Google Meet

Meet links are created through the **Calendar API**, not a Meet REST endpoint.
Creating an event with `conferenceDataVersion=1` and a
`conferenceData.createRequest` (carrying a unique `requestId` and
`hangoutsMeet`) is the supported path; the Meet REST API alone cannot reliably
create a meeting.

Additional setup:

1. Enable the **Google Calendar API** in the same project.
2. Add the scope:
   ```
   https://www.googleapis.com/auth/calendar.events
   ```
   to the OAuth consent screen's scope list.
3. Set:
   ```ini
   GOOGLE_MEET_ENABLED=true
   GOOGLE_MEET_REDIRECT_URI="${APP_URL}/integrations/google/callback"
   ```
   and add that redirect URI to the OAuth client as well.
4. Each host (tutor or instructor) connects their own Google account from the
   integrations page. Meetings are then created **on their calendar**, so the
   meeting genuinely belongs to the person hosting it and appears in their own
   Google Calendar.

For an organisation-wide calendar instead, set `GOOGLE_ORG_CALENDAR_ID` and
`GOOGLE_ORG_REFRESH_TOKEN`. Use this only when meetings should not belong to an
individual — it concentrates a great deal of access in one credential.

The platform does not assume Google Meet. Meetings go through a provider
abstraction, and `manual` — where a host pastes their own join link — is always
available. When provisioning fails, the lesson falls back to manual and says so,
rather than leaving a student with a button that produces an error.

---

## Microsoft

1. [entra.microsoft.com](https://entra.microsoft.com) → **App registrations →
   New registration**.
2. Supported account types: choose based on your users.
   - **Personal, work and school accounts** → `MICROSOFT_TENANT=common`
   - Your organisation only → the tenant ID
3. Redirect URI (Web): `https://your-domain.test/auth/microsoft/callback`
4. **Certificates & secrets → New client secret**. Copy the value immediately —
   it is shown once.
5. **API permissions**: `Microsoft Graph` → Delegated → `openid`, `email`,
   `profile`, `User.Read`. These are default permissions and need no admin
   consent.
6. `.env`:
   ```ini
   MICROSOFT_ENABLED=true
   MICROSOFT_CLIENT_ID="..."
   MICROSOFT_CLIENT_SECRET="..."
   MICROSOFT_REDIRECT_URI="${APP_URL}/auth/microsoft/callback"
   MICROSOFT_TENANT=common
   ```

A client secret expires (default 24 months). Put the expiry in a calendar: an
expired secret breaks Microsoft sign-in for every user at once, with an error
message that points at the user rather than at the credential.

---

## Zoom

**Zoom JWT apps are retired.** Two real options remain, and the platform supports
both:

| Type | Best for | Credential |
|---|---|---|
| User-managed OAuth | Each tutor hosts from their own Zoom account | `ZOOM_CLIENT_ID` / `ZOOM_CLIENT_SECRET` |
| Server-to-Server OAuth | One organisation-level host account | `ZOOM_S2S_*` + `ZOOM_ACCOUNT_ID` |

User-managed is the better default: the meeting belongs to the person hosting it,
their own Zoom plan's limits apply, and a tutor leaving the platform takes their
meeting history with them rather than orphaning it in an org account.

### User-managed OAuth

1. [marketplace.zoom.us](https://marketplace.zoom.us) → **Develop → Build app** →
   **OAuth** (not "Server-to-Server").
2. Redirect URL: `https://your-domain.test/integrations/zoom/callback`
3. Scopes: `meeting:write`, `meeting:read`, `user:read`.
4. Copy the Client ID and Secret into `.env`:
   ```ini
   ZOOM_ENABLED=true
   ZOOM_CLIENT_ID="..."
   ZOOM_CLIENT_SECRET="..."
   ZOOM_REDIRECT_URI="${APP_URL}/integrations/zoom/callback"
   ```

> **Important.** For a user-managed app, an **account administrator** must
> install the app in the Zoom account before other users can authorize it.
> Otherwise authorization fails with `Invalid scope` — an error that reads like a
> configuration mistake in your code and is actually an uninstalled app. This is
> the single most common Zoom integration failure.

5. Each tutor connects their own account from the integrations page.

### Server-to-Server OAuth

1. Marketplace → **Server-to-Server OAuth** app.
2. Scopes: `meeting:write:admin`, `meeting:read:admin`, `user:read:admin`.
3. Account ID, Client ID and Client Secret into `.env`:
   ```ini
   ZOOM_ENABLED=true
   ZOOM_S2S_ENABLED=true
   ZOOM_ACCOUNT_ID="..."
   ZOOM_S2S_CLIENT_ID="..."
   ZOOM_S2S_CLIENT_SECRET="..."
   ```

When `ZOOM_S2S_ENABLED=true`, `config('services.zoom.client_id')` resolves to the
S2S credential automatically, and the `:admin` scopes are requested instead of the
user scopes. No calling code branches on the app type.

### Zoom is never assumed

Scheduling is not coupled to Zoom or Google. Meetings go through a provider
abstraction; the host picks a provider from those an administrator has actually
connected; and `manual` is always available. A platform that cannot create a Zoom
meeting must still be able to run a lesson.

---

## 10. Storage & file visibility

Four tiers, four physical disks (`config/filesystems.php`), declared in
`config('platform.files.tiers')`:

| Tier | Root | Served by the web server? |
|---|---|---|
| `public` | `storage/app/public` | Yes, via `php artisan storage:link` |
| `authenticated` | `storage/app/authenticated` | **No** |
| `private` | `storage/app/private` | **No** |
| `restricted` | `storage/app/restricted` | **No** |

The three non-public tiers have `serve => false`, so **no URL returns their
bytes**. Access is only through a controller that checks authorization → purchase
→ payment → entitlement, and records the download. Guessable URLs are not merely
discouraged; there is nothing to guess.

Two things must be true on the host:

1. `storage/` must be **outside** the document root (see §3.1). If it is inside,
   every protection above is moot — Apache serves the file directly regardless of
   what PHP decides.
2. Only `public` is symlinked. `config('filesystems.links')` has exactly one
   entry, and a test fails if a second one is added.

The tier is stored **per file row**, not inferred from the path, so a file can be
reclassified — a draft made public, a public asset withdrawn — without moving it
and without a window where it is reachable under both rules.

Uploads are validated by MIME type detected from content (`fileinfo`), not by
extension. `config('platform.files.blocked_extensions')` rejects `svg` (which can
carry script), `php`, `phar`, `js`, `html` and executables outright.

---

## 11. Backups

Backups must never be committed (`/backups`, `*.sql`, `*.sql.gz` are gitignored)
and must never sit inside the document root — a downloadable database dump
containing student records is the worst outcome available here.

Take them with cPanel → Backup, or over SSH:

```bash
mysqldump --single-transaction --routines --triggers \
  -u <dbuser> -p <dbname> | gzip > /home/<user>/backups/db-$(date +%F).sql.gz
tar czf /home/<user>/backups/storage-$(date +%F).tar.gz \
  -C /home/<user>/eduplatform storage/app
```

`--single-transaction` matters: without it, InnoDB takes a lock and a backup can
block a live checkout.

Test a restore. An untested backup is a hypothesis.

---

## 12. Troubleshooting

**`platform:doctor` is the first step for every problem below.** It reports the
actual observed state, not a checklist someone wrote once.

| Symptom | Likely cause |
|---|---|
| Page renders with no styling | `public/build` missing or stale. It is committed — restore from git, or rebuild locally and commit. |
| 404 on every route except `/` | `public/.htaccess` missing, or `AllowOverride` is not `All`. |
| `.env` is downloadable | The Laravel root is inside the document root. See §3.1 — this is an emergency. |
| Queued work never runs | No `queue:work` cron entry. See §4. |
| Scheduled tasks never run | No `schedule:run` cron entry. See §4. |
| Webhooks rejected with 419 | The route is not inside the `webhooks` middleware group. |
| Every Paystack webhook fails verification | Wrong key: the signature uses the **secret** key for the **active mode**. |
| Google sign-in `redirect_uri_mismatch` | The redirect URI in `.env` and the Google console do not match exactly. |
| Google sign-in `403 access_denied` | The consent screen is in "Testing" and the user is not a test user. |
| Zoom `Invalid scope` | A user-managed app that no account administrator has installed. |
| Emails not arriving | `MAIL_MAILER=log` in production, or a from-address that is not a real mailbox on the sending domain. |
| Uploads fail silently | `upload_max_filesize` / `post_max_size` too low, or the storage directory is not writable by the PHP process. |
| Times are wrong by an hour | Something is converting before storing. Instants are stored in UTC and converted only at the edge (ADR-08). |
| `500` with no useful message | Correct behaviour in production. Find the reference id shown on the page and grep `storage/logs`. |

To see the real exception during development, set `APP_DEBUG=true` and
`APP_ENV=local` — never in production.
