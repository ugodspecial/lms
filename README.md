# EduPlatform

A production-ready, **online-only** education platform built as a Laravel modular
monolith: education management, an LMS, parent and student portals, tutor
management and recruitment, an evaluator/interview system, tutoring bookings,
Google Meet and Zoom integration, Paystack commerce and subscriptions, a digital
products store, and academic administration.

It reproduces the *business concepts* of Frappe Education and Frappe Learning
with an original Laravel architecture. No Frappe code, schema, branding or UI is
copied.

---

## Current status

**Phase 0 — platform foundation. Complete.**

The repository is a real Laravel 13 application with the toolchain, design
tokens, error handling, portal gating, money and timezone primitives, deployment
health check and CI gate in place. No business module is navigable yet, and the
home page says so rather than linking to pages that do not exist.

Delivery is phased (see [`docs/10-phased-implementation-plan.md`](docs/10-phased-implementation-plan.md)).
A phase is complete when CI is green, not when the code is written.

| Phase | Scope | Status |
|---|---|---|
| 0 | Scaffold & toolchain | **done** |
| 1 | Identity, roles, permissions, settings, notifications | next |
| 2 | Education management: programmes, subjects, cohorts, enrolment, admissions | planned |
| 3 | LMS: courses, lessons, materials, progress, certificates | planned |
| 4 | Tutors: profiles, approval, availability, booking | planned |
| 5 | Assessment: evaluators, interviews, grading, attendance | planned |
| 6 | Parent & student portals | planned |
| 7 | Communication: Google Meet, Zoom, messaging, notifications | planned |
| 8 | Commerce: Paystack, orders, entitlements, subscriptions, refunds | planned |
| 9 | Digital products store | planned |
| 10 | Administration: imports, exports, reporting, audit | planned |
| 11 | Marketing site, legal, public catalogue | planned |
| 12 | Hardening: CSP, penetration test pass, backup & restore drills | planned |

---

## Stack

Laravel 13 · PHP 8.3+ · MySQL 8 / MariaDB 10.6+ · Blade · Livewire 4 · Alpine.js · Tailwind CSS 4 · Vite 8

No SPA, no Node backend, no microservices. One application, one database.

| Concern | Package |
|---|---|
| Authentication, 2FA, email verification | `laravel/fortify` |
| Token API auth | `laravel/sanctum` |
| Google & Microsoft OAuth | `laravel/socialite` |
| Reactive UI | `livewire/livewire` |
| Roles & permissions | `spatie/laravel-permission` |
| Tests | `phpunit/phpunit` 12 |
| Static analysis | `larastan/larastan` |
| Formatting | `laravel/pint` |

Deferred to the phase that needs them: `barryvdh/laravel-dompdf` (certificates),
`bacon/bacon-qr-code` (2FA), `league/csv` (imports).

### Package discovery is deferred deliberately

Fortify, Socialite, Sanctum, `spatie/laravel-permission` and Livewire are
installed but listed in `composer.json` → `extra.laravel.dont-discover`. Each
auto-registers routes, guards or assets when discovered, and in Phase 0 that
means a `/login` route rendering a view that does not exist — a 500 behind a link
that looks real, which is exactly the "dead button" the spec forbids.

Each package is removed from that list by the phase that configures it
(Fortify, Socialite, Sanctum and Permission in Phase 1; Livewire with the first
reactive screen), together with its published config, views and migrations. The
list shrinks as the platform is built and should be empty by Phase 3.

---

## Quick start

Requires PHP 8.3+ (8.4 recommended), Composer 2, MySQL 8 and Node 22.

```bash
composer install
cp .env.example .env
php artisan key:generate
# Create the database first:
#   CREATE DATABASE eduplatform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate --seed

npm install
npm run build          # or: npm run dev

php artisan serve
```

Then verify the deployment:

```bash
php artisan platform:doctor
```

`platform:doctor` is not decoration. It reports PHP version and extensions,
environment sanity, storage writability **by actually writing**, database
connectivity and migration state, queue and mail configuration, committed asset
integrity, and every integration that is enabled but not configured. It exits
non-zero on any failure, so it doubles as a deploy gate.

> **`composer.lock` is not committed yet.** The Phase 0 skeleton was written in an
> environment without a PHP or Composer runtime, so dependency versions could be
> verified against each package's published constraints but no lock file could be
> generated. CI detects this, warns, and resolves from `composer.json` — which
> means a build is not yet bit-reproducible. The first person with PHP available
> should run `composer update`, commit the resulting `composer.lock`, and CI will
> switch itself to `composer install` automatically. `package-lock.json` *is*
> committed, because Node was available.

---

## Shared hosting deployment

The platform is designed to run on conventional cPanel shared hosting: MySQL,
database-backed queues, cache and sessions, the Laravel scheduler driven by cron,
and filesystem storage. **Redis, Docker, Kubernetes, supervisor and a Node
runtime are never required.**

Two consequences shape the codebase:

- **Compiled front-end assets are committed** to `public/build` (ADR-13). CI
  rebuilds them and fails if the committed copy is stale, so a `git pull` deploy
  is sufficient and the shipped bundle is reviewable.
- **No background process may be assumed.** Queued work is drained by
  `queue:work --stop-when-empty` from cron, and `runInBackground()` is never used
  because `proc_open()` is on the disabled-functions list of most shared hosts.

Full instructions, including the document-root layout that keeps `.env` and
`storage/` outside the webroot, are in [`SETUP.md`](SETUP.md) and
[`deploy/cpanel/`](deploy/cpanel/).

---

## Repository layout

```
app/
  Domain/                 Business logic, grouped by bounded context
    Commerce/             Money value objects (Phase 8 extends this)
    Identity/             Users, roles, parent–student links      (Phase 1)
    Education/            Programmes, cohorts, enrolment          (Phase 2)
    LMS/                  Courses, lessons, progress              (Phase 3)
    Tutoring/             Profiles, availability, booking         (Phase 4)
    Assessment/           Interviews, grading, attendance         (Phase 5)
    Communication/        Messaging, announcements                (Phase 7)
    Integration/          Meet/Zoom/Paystack provider contracts   (Phase 7)
    Administration/       Settings, imports, reporting, audit     (Phase 10)
  Exceptions/             PlatformException — expected business refusals
  Http/Middleware/        Area gating, timezone, security headers,
                          integration readiness
  Support/                Presentation helpers: money formatting, timezone
config/platform.php       Areas, currencies, feature flags, integrations,
                          file tiers, log redaction, module map
deploy/cpanel/            Cron entries, .htaccess, document-root layout
docs/                     13 design documents — the source of truth
resources/css/app.css     Tailwind 4 @theme design tokens
routes/web/               One file per portal area
tests/
  Unit/                   Domain rules: no database, no HTTP
  Feature/                HTTP + database
  Authorization/          Who may see whose data — named as a suite on purpose
  Payment/                Paystack lifecycle, key handling, webhook abuse
```

Business rules live in **services, actions, policies, form requests, DTOs,
events, jobs and notifications** — never in controllers and never in Blade. A
rule such as "a minor cannot purchase tutoring" is expressed once, in a domain
service, and is reached identically from the web UI, the API and the admin panel
(ADR-14).

---

## Testing

```bash
php artisan test                       # all four suites
vendor/bin/phpunit --testsuite Unit    # domain rules only, no database
vendor/bin/phpstan analyse             # static analysis
vendor/bin/pint --test                 # formatting
```

Tests run against **MySQL, never SQLite**. The schema depends on FULLTEXT
indexes and enforced `CHECK` constraints — the "exactly one entitlement target"
rule is a `CHECK`, and SQLite silently ignores it. A green build on SQLite would
prove nothing about the integrity rules protecting enrolments and payments.

Four suites, because they fail for different reasons and are read by different
people:

- **Unit** — domain rules with no database and no HTTP. This is where financial
  correctness is decided.
- **Feature** — HTTP and database.
- **Authorization** — the cross-tenant question: can a parent see another
  parent's child? Named as its own suite so it can never be quietly dropped.
- **Payment** — Paystack lifecycle, key handling and webhook abuse.

§72 of the spec is enforced structurally: no test may use a real payment
credential, `phpunit.xml` fails on warnings, risky tests and deprecations, and
`tests/Payment/PaystackKeyHandlingTest.php` asserts that no secret key reaches a
built asset, a rendered response or the source tree.

---

## Design documents

[`docs/`](docs/README.md) is the source of truth and is written before code.

| Document | Contents |
|---|---|
| [00](docs/00-environment-and-constraints.md) | Environment, constraints, decisions required |
| [01](docs/01-system-architecture.md) | Modular monolith architecture, layering |
| [02](docs/02-domain-model.md) | Bounded contexts, entities, invariants |
| [03](docs/03-database-erd.md) | Entity relationships |
| [04](docs/04-database-table-inventory.md) | 124 tables, column by column |
| [05](docs/05-role-permission-matrix.md) | 11 roles, 168 permissions |
| [06](docs/06-feature-module-matrix.md) | 202 features mapped to modules |
| [07](docs/07-user-workflows.md) | 24 end-to-end workflows |
| [08](docs/08-integrations.md) | Paystack, Google Meet, Zoom, OAuth |
| [09](docs/09-shared-hosting-deployment.md) | cPanel deployment |
| [10](docs/10-phased-implementation-plan.md) | Phased plan and exit gates |
| [11](docs/11-architecture-decisions.md) | 15 ADRs |

Two documents currently disagree with the code and will be corrected: they name
Pest 4 and `spatie/laravel-permission` v7, while the platform uses PHPUnit 12
and v8. The code follows reality; the docs follow next.

---

## Non-negotiables

These are constraints, not preferences. Each is enforced by a test or by CI
wherever it can be.

- **No dead buttons.** A feature that cannot work in this deployment is not
  rendered at all. `feature_enabled()` and the `integration.connected` middleware
  exist for this.
- **No fake integrations.** No mock payment, OAuth, Meet or Zoom path that
  pretends to succeed. If a credential is missing, the real architecture is
  present and the UI says the integration is not connected.
- **No placeholder data in production UI.** Real database queries only.
- **No hard-coded business data.** Prices, currencies, academic years, grading
  scales and commission rates come from the database or configuration.
- **A purchaser is not necessarily the beneficiary.** A parent buys; a student
  receives. This distinction is modelled explicitly (ADR-01) and drives checkout,
  entitlements and access control.
- **Minors cannot purchase.** Age, service type and purchaser relationship are
  verified server-side at checkout, on every surface.
- **Money is integer minor units plus an ISO currency code.** Never a float,
  never a bare `₦` in business logic (ADR-02).
- **Instants are stored in UTC and converted at the edge** (ADR-08).
- **Payments are never confirmed from a browser redirect.** The backend verifies
  with Paystack, and webhooks are idempotent (ADR-06).
- **Protected files are never reachable by URL.** Access goes through
  authorization, purchase, payment and entitlement checks, and every download is
  recorded (§59).
- **Secrets never reach a browser, a log line or a commit** (§41, §77).

---

## Licence

MIT — see [LICENSE](LICENSE).
