# 00 — Environment, Toolchain & Constraints

Verified on **2026-09-07**. Everything in §1–§2 was checked against live package
registries, not assumed. §3 records hard limits of the current build sandbox and the
mitigation strategy — this is disclosed up front because spec §93 forbids pretending a
capability exists when it does not.

---

## 1. Target stack (pinned)

| Layer | Choice | Version | Verified |
|-------|--------|---------|----------|
| Language | PHP | **8.3 minimum**, 8.4 recommended | Laravel 13 requires `^8.3`; supports 8.3–8.5. Laravel 13.3+ pulls Symfony 8 components that are happiest on 8.4 |
| Framework | Laravel | **^13.0** | Released 2026-03-17 (v13.0.0). Bug fixes → Q3 2027, security → Q1 2028. Zero breaking changes from Laravel 12 |
| Database | MySQL | **8.0+** (8.4 LTS preferred) | Also tested against MariaDB 10.11+ |
| UI reactivity | Livewire | **^4.2** | v4.2.0 added Laravel 13 support + 7 security hardening fixes (X-Livewire header + JSON content-type now required on update requests) |
| Styling | Tailwind CSS | **^4.3** (4.3.3 current) | CSS-first config via `@theme`; no `tailwind.config.js` needed |
| Micro-interactions | Alpine.js | **^3** | Bundled with Livewire 4 |
| Templating | Blade | Laravel 13 built-in | — |
| Build tool | Vite | **^7** (Laravel 13 default) | Build step is **dev/CI only** — compiled assets are committed or built in CI so production shared hosting needs no Node |
| RBAC | spatie/laravel-permission | **^7.0** | v7 requires `php: ^8.3` and `illuminate/*: ^12.0\|^13.0` — confirmed on Packagist |
| 2FA / auth scaffolding | laravel/fortify | **^1** (Laravel 13 compatible) | Headless 2FA, email verification, password reset — pairs with custom Blade/Livewire UI |
| OAuth login | laravel/socialite | **^5** | Google + Microsoft (`azure`/Graph) providers |
| HTTP client | Laravel `Http` facade | built-in | Used for Paystack / Zoom / Google with retry + logging middleware |
| PDF | barryvdh/laravel-dompdf | **^3** | **Pure PHP** — no `wkhtmltopdf` binary, so it works on cPanel where `exec()` is usually disabled |
| QR codes | bacon/bacon-qr-code | **^3** | Pure PHP, renders PNG/SVG without Imagick |
| CSV | league/csv | **^9** | Streaming reads for large imports (§80) |
| Testing | Pest | **^4** | Laravel 13 ecosystem default; PHPUnit 12 compatible |
| Static analysis | larastan/larastan | **^3** | Level 6 target by Phase 11 |
| Style | laravel/pint | **^1** | PSR-12 + Laravel preset, enforced in CI |

### Composer manifest (Phase 0 will write this)

```jsonc
{
  "require": {
    "php": "^8.3",
    "laravel/framework": "^13.0",
    "laravel/fortify": "^1.25",
    "laravel/sanctum": "^4.0",
    "laravel/socialite": "^5.16",
    "laravel/tinker": "^2.10",
    "livewire/livewire": "^4.2",
    "spatie/laravel-permission": "^7.0",
    "barryvdh/laravel-dompdf": "^3.1",
    "bacon/bacon-qr-code": "^3.0",
    "league/csv": "^9.16",
    "guzzlehttp/guzzle": "^7.9"
  },
  "require-dev": {
    "pestphp/pest": "^4.0",
    "pestphp/pest-plugin-laravel": "^4.1",
    "laravel/pint": "^1.18",
    "larastan/larastan": "^3.9",
    "fakerphp/faker": "^1.24",
    "mockery/mockery": "^1.6"
  },
  "config": { "optimize-autoloader": true, "preferred-install": "dist", "sort-packages": true }
}
```

**Deliberately excluded** and why:

| Package | Reason for exclusion |
|---------|----------------------|
| `nwidart/laravel-modules` | Spec §4 asks for a modular monolith but §4 also says "do not create unnecessary abstractions". PSR-4 namespacing under `app/Domain/*` gives the same boundaries with zero framework magic and no extra autoloading risk on shared hosting (ADR-01) |
| `laravel/cashier` / `cashier-paystack` | Cashier is Stripe/Paddle-shaped. Paystack's plan/subscription semantics differ enough that a thin first-party `PaystackClient` is clearer, testable and avoids a dependency that can lag Laravel majors (§41) |
| `owenvoke/blade-fontawesome`, UI kits | Design system is hand-built Blade components (§65) to avoid the "generic admin template" look (§64) |
| `spatie/laravel-medialibrary` | Its conversions pipeline assumes ImageMagick/GD availability and a mutable public disk. A lean `files` registry + streamed authorized downloads is a better fit for §40/§59 (ADR-10) |
| `predis/predis`, `laravel/horizon`, `laravel/reverb` | Redis/Horizon violate the shared-hosting constraint (§3, §69). Database driver first, config-only swap later |
| `spatie/laravel-scout` + Meilisearch | Requires a persistent search daemon. MySQL FULLTEXT + authorized query scopes first, Scout-shaped interface so it can be swapped (§54) |

---

## 2. Verified integration facts that drove the design

These were confirmed against current documentation before the architecture was written,
because each one changes the shape of a module.

| Fact | Consequence in this design |
|------|---------------------------|
| **Paystack has no separate webhook secret.** `x-paystack-signature` = `HMAC-SHA512(raw_body, secret_key)`, hex, lowercase | `VerifyPaystackWebhookSignature` must read the **raw** body before any JSON parsing. Middleware ordering matters — see ADR-06 |
| Paystack signs with the **same** secret key used for API calls, and only ever calls webhooks from 3 documented IPs | Optional IP allowlist as defence-in-depth (`PAYSTACK_WEBHOOK_IP_ALLOWLIST`), configurable because hosts can sit behind proxies that rewrite `REMOTE_ADDR` |
| Amounts are in **minor units** (NGN 100 → `10000`) and Paystack supports NGN, GHS, ZAR, KES, USD, XOF | `Money` VO stores `amount_minor` INT + ISO-4217 `currency`; per-currency exponent map (ADR-02). Matches §61 exactly |
| Visiting `callback_url` proves nothing — **verification via API is mandatory** before delivering value | Order is marked `paid` only by `VerifyPaystackTransaction` or a verified webhook. Redirect handler shows a *pending* screen and triggers verification (ADR-06) |
| The same webhook event **can arrive more than once** | `payment_events` has `UNIQUE(provider, event_id)` and every handler is written to be replay-safe (§42) |
| **Google Meet has no general "create meeting" REST API.** Meet links are produced by `POST calendar/v3/calendars/{id}/events?conferenceDataVersion=1` with `conferenceData.createRequest.conferenceSolutionKey.type = "hangoutsMeet"` and a **unique `requestId`** per link | `GoogleMeetProvider` is implemented on top of the Calendar API, requires scope `https://www.googleapis.com/auth/calendar.events`, and stores the `requestId` for idempotent retries (ADR-05) |
| Meet conference data is generated **asynchronously** — the first response may not contain `entryPoints` | `GoogleMeetProvider::createMeeting()` polls/refetches the event and the `meetings` row starts in status `provisioning`, flipping to `ready` when the join URL appears. The UI never shows an empty "Join" button |
| **Zoom JWT apps were retired** (no new JWT apps since 2023-09-08). Only Server-to-Server OAuth (account-level) or User-managed OAuth (per-user) exist | `ZoomProvider` supports **both** modes: user-connected OAuth for "tutor connects their own Zoom" (§37) and S2S OAuth as an org-level fallback. Granular scopes `meeting:write:admin`/`meeting:read:admin` for S2S, `meeting:write`/`meeting:read` for user-managed |
| Zoom OAuth access tokens live ~1 hour with a rolling 90-day refresh | `connected_accounts` stores `expires_at` + `refresh_token`; `ConnectedAccountTokenRefresher` runs before each call and on a scheduled sweep |
| Livewire 4.2 update requests require the `X-Livewire` header **and** JSON content type | Any custom Livewire route/middleware must preserve this; documented in the deployment guide so reverse proxies don't strip the header |
| Laravel 13 introduced **passkey authentication**, `Cache::touch()`, `Queue::route()`, a Reverb **database** driver, and PHP 8 attributes across ~15 framework locations | Passkeys are noted as a future hardening option for privileged accounts (§6 optional 2FA); Reverb's database driver is the shared-hosting-compatible path *if* real-time is ever needed |

---

## 3. Build-sandbox constraints (disclosed, not hidden)

The agent workspace used to produce this design has been probed. Results:

| Capability | Available? | Evidence |
|------------|-----------|----------|
| Node.js 22 / npm 10 | ✅ Yes | `node -v` → v22.22.3; `npm ping` → PONG |
| `registry.npmjs.org` reachable | ✅ Yes | HTTP 200 |
| `github.com` reachable (git/gh) | ✅ Yes | HTTP 200; `gh` auth preconfigured |
| **PHP runtime** | ❌ **No** | `php` not found; no PHP binary anywhere on disk |
| **Composer** | ❌ **No** | `composer` not found |
| `repo.packagist.org` reachable | ❌ **No** | TLS `SSL_ERROR_SYSCALL` (egress blocked) |
| `deb.debian.org` reachable | ❌ **No** | HTTP 000 — cannot `apt-get install php8.3` |
| MySQL / MariaDB server | ❌ **No** | client and server absent |
| SQLite | ❌ **No** | `sqlite3` absent |
| `sudo` | ✅ Yes | but useless without reachable package repos |
| Disk | ✅ 20 GB free | — |

### 3.1 What this means

**I can author the entire application** — every migration, model, action, policy, Livewire
component, Blade view, notification, job, seeder, config file and test — to a production
standard, and commit it to `arena/01a07c5b-lms`.

**I cannot execute PHP in this sandbox.** Therefore I cannot, from here:

- run `composer install` (no Packagist egress),
- run `php artisan migrate` / `db:seed`,
- run `php artisan test`,
- prove a green test suite locally.

Spec §92 says *"do not silently skip failed tests"* and §93 says *"do not pretend a feature
works when it does not"*. Honoured by making verification an explicit, tracked part of every
phase rather than an assumed one.

### 3.2 Verification strategy — three options (your call)

| Option | How it works | Pros | Cons |
|--------|--------------|------|------|
| **A. GitHub Actions CI (recommended)** | I add `.github/workflows/ci.yml` that on every push installs PHP 8.4 + Composer, runs `composer install`, boots **MySQL 8 as a service container**, runs `php artisan migrate --force`, then `pint --test`, `larastan`, and `pest --coverage`. I read the results via `gh run list` / `gh run view` and fix failures in subsequent turns | Real execution, real database, no work for you, permanent regression gate, results are visible to me so I can iterate honestly | Each verification round costs a CI run (~4–6 min) |
| **B. You run it locally** | I ship code + a `SETUP.md`; you run `composer install && php artisan migrate && php artisan test` and paste failures back | Fastest feedback if you already have PHP 8.3+ | Manual loop; you become the test runner |
| **C. Provide a PHP-capable environment** | Point this session at a sandbox/host with PHP 8.3+, Composer and MySQL | I can iterate directly | Requires infra change on your side |

**Recommendation: A, with B as a fallback.** Option A keeps the "run tests → fix errors →
review security → review authorization → proceed" loop from §92 genuinely closed, because
CI output is readable from this session via the `gh` CLI.

### 3.3 Consequences for the code I write

Because I cannot lean on a local interpreter, the code is written to be **verifiable by
inspection and by CI on first run**:

1. **Migrations are ordered and idempotent** — numbered timestamps, `down()` methods, no
   cross-migration data assumptions, `Schema::hasTable()` guards where a later phase alters
   an earlier table.
2. **No reliance on `artisan make:*` generators** — files are written out fully, with correct
   namespaces matching the PSR-4 map in `composer.json`.
3. **Tests are written alongside features**, not after, and every test names the spec section
   it satisfies (e.g. `test('minor cannot self-purchase restricted tutoring service', ...)`
   → §10).
4. **Zero runtime dependency on Node** — Vite builds in CI/dev; compiled assets are committed
   under `public/build` so cPanel needs no Node (§96).
5. **Config is complete and self-documenting** — `.env.example` carries every variable with a
   comment, plus a `php artisan platform:doctor` command (Phase 1) that reports missing
   configuration instead of failing cryptically at runtime.

---

## 4. Open decisions requiring your confirmation

These are the only blockers to starting Phase 0. Everything else in the design is settled.

| # | Decision | Default if you say "proceed" |
|---|----------|------------------------------|
| D1 | **Verification strategy** (§3.2) | Option A — GitHub Actions CI with a MySQL 8 service container |
| D2 | **Scaffold approach.** Composer cannot run here, so the Laravel 13 skeleton cannot be generated by `composer create-project`. Either (a) I hand-write the complete, correct skeleton (`composer.json`, `artisan`, `bootstrap/app.php`, all `config/*`, `public/index.php`, `.env.example`, service providers) so the repo is a real Laravel 13 app the moment you run `composer install`; or (b) you run `composer create-project laravel/laravel:^13.0 .` and I layer the application on top | Option (a) — hand-written skeleton, pinned to `laravel/framework: ^13.0`. It keeps the repo self-contained and reviewable in one place |
| D3 | **Package set** (§1) — in particular Fortify for 2FA/verification/reset, spatie/laravel-permission v7 for RBAC, dompdf for certificates | Accept all as listed. Every one has a pure-PHP, shared-hosting-safe implementation |
| D4 | **Naming**: `parents` table (spec's "Parent/Guardian") vs `guardians`; `cohorts` vs `student_groups`; `students` vs `student_profiles` | `parents`, `cohorts`, `students` — matches the spec's own vocabulary, which reduces translation cost for future developers |
| D5 | **Currency launch set** | `NGN` primary; `Money` VO ships with exponents for NGN, USD, GHS, ZAR, KES, XOF, GBP, EUR so §61 is satisfied from day one |

---

## 5. Definition of "runnable" per phase

Spec §91 requires the application to stay runnable after every phase. Concretely, at the end
of each phase **all** of the following must be true, evidenced by a CI run:

```
✔ composer install                    → resolves with no conflicts
✔ php artisan migrate:fresh --seed    → completes with zero errors
✔ php artisan test                    → 100% pass, no skipped tests hiding failures
✔ php artisan pint --test             → clean
✔ php artisan about                   → boots, reports env + drivers
✔ vendor/bin/phpstan analyse          → no new errors at the agreed level
✔ php artisan route:list              → no duplicate/ambiguous route names
✔ Manual smoke: login → dashboard     → renders for each seeded role
✔ npm run build                       → assets compile (CI only)
```

A phase is **not** closed until that block is green. This is the operational form of §101's
definition of done.
