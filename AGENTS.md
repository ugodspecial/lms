# Agent guide

Guidance for AI agents (and new contributors) working in this repository. It
exists because several of the rules here are the kind that a competent engineer
would otherwise reasonably violate, and the violation is invisible until it costs
money or exposes a child's records.

---

## Read the design docs first

[`docs/`](docs/README.md) is the source of truth and was written before any code.
Thirteen documents: architecture, domain model, ERD, a 124-table inventory, an
11-role / 168-permission matrix, a 202-feature module map, 24 workflows, the
integration contracts, shared-hosting deployment, the phased plan with exit gates,
and 15 ADRs.

Before changing a domain concept, find it in `docs/02-domain-model.md` or
`docs/04-database-table-inventory.md`. If the code and the docs disagree, one of
them is wrong — say which, and fix both. Do not silently pick one.

The docs originally named **Pest 4** and **`spatie/laravel-permission` v7**; both
have been corrected to **PHPUnit 12** and **v8**, which is what the Laravel 13
skeleton ships and supports. Do not "fix" the code back to Pest.

The general rule that came out of it: the docs own *intent* — domains, rules,
invariants, workflows. `composer.json` and `package.json` own *versions*. A
dependency constraint is decided by what the framework release actually supports,
which cannot be known when a design document is written, so a version quoted in
prose is a plan and never a fact.

---

## Delivery is phased. Stay in the phase.

Work one phase at a time (see `docs/10-phased-implementation-plan.md`), in the
order it specifies: architecture → database → models → business rules →
permissions → UI → tests.

- **Keep the application runnable after every phase.** A commit that breaks
  `php artisan serve` or `migrate:fresh` is not a work-in-progress; it is a
  regression.
- **A phase is complete when CI is green**, not when the code is written.
- **Do not build ahead.** Phase 0 must not contain a half-finished enrolment
  flow. Partial features are worse than absent ones because they look finished.

---

## The rules that are easy to violate

### No dead buttons

If a feature cannot work in this deployment, **do not render its entry point**.
There is no such thing as a harmless disabled-looking button: a user who clicks
"Join meeting" and gets an error has been told the platform is broken.

- Structural availability: `feature_enabled('...')` from `config/platform.php`.
- Integration readiness: the `integration.connected:<name>` middleware, which
  reads `config('platform.integrations')` — the same list `platform:doctor`
  reports on, so the guard, the UI and the operator's checklist cannot disagree.
- `resources/views/home.blade.php` derives module links from `Route::has()`. Keep
  that pattern: a link is real or it is not rendered.

### No fake functionality

Never write a mock payment, a stubbed OAuth callback, a hardcoded meeting URL or
a simulated approval that *looks like* the real thing. If an integration cannot
be configured because credentials are missing, implement the real architecture
and make the missing configuration explicit and actionable.

A fake that succeeds is indistinguishable from a real integration to everyone
who is not reading the source — including whoever deploys this to production.

### No placeholder or hard-coded business data

- No seed data or lorem content in production UI. Real database queries only.
- No hard-coded prices, currencies, academic years, grading scales, commission
  rates, roles or credentials. Business values come from the `settings` table or
  from configuration; secrets come from `.env`.
- **No currency symbol in business logic or markup.** `₦` never appears in a
  service, policy, migration or Blade file. Use the `money()` helper or
  `App\Support\Money\MoneyFormatter`, which resolves the symbol from
  `config('platform.currencies')`.

### Money is integer minor units

Never a float, never a decimal string in arithmetic. Use
`App\Domain\Commerce\ValueObjects\Money`. Note that the exponent is **not always
2** — `XOF` has zero minor units — so never write `* 100`. Read
`Currency::minorUnitFactor()`.

Cross-currency arithmetic throws rather than converting. An exchange rate is a
business decision that must be explicit and auditable, never an accident of two
objects meeting in a method.

### Time is stored in UTC

`config('app.timezone')` must stay `UTC` forever. Display conversion happens only
at the edge, through `App\Support\Time\TimezonePresenter` or the `in_timezone()`
helper. `platform:doctor` fails a deployment that has changed it.

Recurring schedules additionally store a wall-clock time and an IANA timezone
alongside the UTC instant, because "16:00 every Tuesday in Lagos" is a different
fact from "15:00 UTC every Tuesday" once anyone travels.

### A purchaser is not necessarily the beneficiary

A parent buys; a student receives. This is modelled explicitly (ADR-01) and is
not a convenience — it drives checkout authorization, entitlement targets,
notifications and refund policy. Never collapse "the user who paid" and "the user
who gets access" into one field.

### Minors cannot purchase

Students under 18 may learn, attend and submit. They may **not** independently
buy tutoring or subscriptions. Checkout must verify age, service type and the
purchaser's relationship to the student **server-side**, on every surface — web,
API, mobile and admin. A parent or guardian must be the purchaser.

The rules live in named domain services (`CanStudentPurchaseTutoring`,
`CanParentPurchaseForStudent`), not in a form request, not in a Livewire
component and never only in the UI. A rule enforced in the UI is not enforced.

### Payments are never confirmed from a browser

A redirect can be forged, replayed, or never arrive. The backend verifies with
Paystack (`GET /transaction/verify/{reference}`) and records what Paystack says,
not what the URL says.

Webhooks are **idempotent**: `payment_events` has a unique
`(provider, provider_event_id)`. Duplicate orders, entitlements and subscriptions
are prevented by database unique constraints, not by application-level checks a
race can defeat. Never weaken one of those constraints to make a test pass.

Payments are not coupled directly to access:
`Payment → Order → OrderItem → Entitlement → access`. Keep that indirection; it
is what makes refunds, partial refunds, transfers and parent-buys-for-child
expressible.

### Protected files have no URLs

`authenticated`, `private` and `restricted` disks have `serve => false`. There is
no URL that returns their bytes. Access goes through a controller that checks
authorization → purchase → payment → entitlement, and records the download.

Only `public` is symlinked into the document root. `config('filesystems.links')`
has exactly one entry; adding a second exposes student records.

### Secrets never leave the server

The Paystack **secret** key must never reach a view, a compiled asset, a log line
or a commit. The **public** key must reach the browser. Getting that backwards is
not a style problem.

`tests/Payment/PaystackKeyHandlingTest.php` enforces it: it scans built assets,
the source tree and every unauthenticated response. Log redaction patterns live in
`config('platform.logging')`.

### Authorization is in policies, and it fails closed

Business rules belong in **services, actions, policies, form requests, DTOs,
events, jobs and notifications** — not controllers, not Blade (ADR-14).

The same check must be reachable from the web UI, the API and the admin panel. A
route that only exists in the API becomes the easy way to bypass a rule.

`App\Http\Middleware\AuthenticateArea` deliberately denies access when the
permission system cannot answer. A gate that defaults to "allow" while the
permission layer is being built is the kind of defect that reaches production
unnoticed. Do not soften it.

### Student data is sensitive

Least privilege everywhere. A parent sees only their own linked children. An
evaluator can assess but cannot approve a tutor unless explicitly granted that
permission — and has no financial permissions at all. Audit-log changes to
student records. Treat deletion as a retention decision, not a `DELETE`.

Marketing email is a separate consent from transactional email. Never
auto-subscribe anyone, and never carry marketing content in a transactional
message.

---

## Where things go

```
app/Domain/<Context>/
  Actions/         One verb, one class: ApproveTutorApplication
  Services/        Multi-step business logic with collaborators
  Policies/        Authorization
  ValueObjects/    Immutable, self-validating (Money, Currency)
  DTOs/            Typed input/output boundaries
  Events/          Facts that happened, past tense
  Jobs/            Queued work
  Models/          Eloquent, thin
app/Support/       Presentation & framework-shaped helpers
routes/web/<area>.php   One file per portal area
```

Controllers stay thin: validate with a Form Request, call an Action or Service,
return a response. If a controller has an `if` about business rules, the rule is
in the wrong place.

`app/Support/helpers.php` is autoloaded and must stay small. A helper cannot be
injected, mocked or unit-tested in isolation, so nothing with behaviour belongs
there.

---

## Conventions already established

- **Package discovery is deferred per phase.** Fortify, Socialite, Sanctum,
  `spatie/laravel-permission` and Livewire are installed but sit in
  `composer.json` → `extra.laravel.dont-discover`, because each auto-registers
  routes, guards or assets on discovery. Remove an entry in the phase that
  configures that package — and in the same commit publish its config, add its
  views and wire its tests. Never remove an entry without doing all three, or
  you have shipped a `/login` route that renders a view which does not exist.
- **Laravel 13 idioms.** PHP 8 attributes on models (`#[Fillable]`, `#[Hidden]`
  from `Illuminate\Database\Eloquent\Attributes`); `casts()` as a method;
  `bootstrap/providers.php`; `Application::configure()` chaining in
  `bootstrap/app.php`.
- **`declare(strict_types=1)`** at the top of every PHP file, and explicit casts
  at every `config()` / `env()` read, because both return `mixed`.
- **Final classes** for value objects, middleware and services that are not meant
  to be extended.
- **Immutable value objects.** Operations return new instances.
- **Fail loudly on programmer error, gracefully on user error.** An unknown
  currency or portal area is a 500 with a clear message. An expected business
  refusal is a `PlatformException` with a stable machine-readable
  `errorCode`, a user-safe message and an HTTP status.
- **Comments explain *why*.** The non-obvious constraint, the failure mode being
  prevented, the section reference. Not what the next line does.
- **Tailwind tokens only.** Every colour, radius and type step is in the
  `@theme` block of `resources/css/app.css`. A hex literal in a Blade file is a
  bug: it cannot be re-themed and will not adapt to dark mode. Components use the
  semantic aliases (`surface-raised`, `border-default`, `text-muted`), never a raw
  palette step.
- **Status colour is meaning.** `success` always means safe to proceed, `danger`
  always means this will fail or has failed. Do not repurpose them.
- **Alpine is not imported in `resources/js/app.js`.** Livewire 4 ships its own
  Alpine; loading a second copy produces two competing instances and silently
  broken `x-data`.

---

## Front-end assets are committed

`public/build` is in the repository (ADR-13), because shared hosting has no Node
runtime and deploys by `git pull`.

After changing anything under `resources/`, run `npm run build` and **commit the
result**. CI rebuilds and fails if the committed copy is stale — editing
`app.css` and forgetting to rebuild ships the old styles while the source looks
correct.

---

## Testing is mandatory

Write tests with the code, not after it. Every phase adds unit, feature and
authorization tests.

- **Never use real payment credentials in a test.** Fakes only (`Http::fake()`,
  `Bus::fake()`, `Mail::fake()`, or a manual gateway double).
- **Never skip or delete a failing test to get a build green.** Fix it, or explain
  precisely why it is wrong. `phpunit.xml` sets `failOnWarning`, `failOnRisky` and
  `failOnDeprecation`, so a test that silently asserts nothing fails the build.
- **Tests run on MySQL, never SQLite.** The schema uses FULLTEXT indexes and
  enforced `CHECK` constraints; SQLite ignores both, so a green SQLite build
  proves nothing about the integrity rules protecting enrolments and payments.
- **Authorization gets its own suite** (`tests/Authorization/`) so the
  cross-tenant question — can a parent see another parent's child? — can never be
  quietly dropped by a reorganisation.
- A test must assert something that could fail. `assertTrue(true)`, asserting a
  value against itself, or `assertNotFalse()` on something that cannot be false
  are all worse than no test: they create the appearance of coverage.

---

## Verification

There is no PHP runtime in every working environment. When one is unavailable:

1. `git push` to the session branch and let CI run.
2. Read the results via the GitHub API (`repos/{owner}/{repo}/actions/runs`).
3. Fix what it reports, in the same branch.

CI runs four jobs: **tests** (MySQL 8 service, migrations, PHPUnit), **analysis**
(PHPStan/Larastan), **assets** (rebuild and diff against the committed bundle)
and **style** (Pint). Do not lower a gate to make a build pass — fix the code, or
explain the gate is wrong and why.

`php artisan platform:doctor` is the deployment equivalent. It reports observed
state, not a written checklist, and exits non-zero on failure.
