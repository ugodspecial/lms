# 11 — Architecture Decision Records

**15 ADRs.** Each records a decision that is expensive to reverse, the options considered,
the choice, and the consequences we accept. Where a decision was driven by a verified
external constraint, the verification is cited.

Format: **Context → Decision → Options considered → Consequences → Reversal cost.**

> These are currently consolidated in one file for review convenience. Once implementation
> starts they will be split into `docs/adr/NNNN-*.md` (one file per ADR) so each can be
> amended independently with its own history.

---

## ADR-01 — Modular monolith with PSR-4 domain namespaces (not a module package)

**Status:** Accepted · **Spec:** §4, §98

**Context.** The platform spans nine bounded contexts with real interdependencies. Spec §4
asks for a modular monolith under `app/Domain/*` and simultaneously warns against unnecessary
abstraction. Shared hosting (§96) punishes autoloading complexity and anything that expects a
container runtime. The business is a **single organization**, so multi-tenancy is not a
current requirement.

**Decision.**
1. One Laravel application. Modules are PSR-4 namespaces under `app/Domain/{Identity,
   Education,Lms,Assessment,Tutoring,Commerce,Communication,Integration,Administration}`,
   each owning its Models, Actions, Services, Policies, Events, Enums, ValueObjects, DTOs and
   Contracts.
2. Module ownership extends to **migrations, seeders, factories and tests**, so a boundary is
   physical (file ownership), not just nominal.
3. Dependency direction is enforced by convention + a Larastan/PHPStan rule and a
   `platform:audit-modules` command that greps for forbidden imports
   (`Domain\Commerce` importing `Domain\Lms` models, any Domain class importing
   `Http\`/`Livewire\`).
4. **Single-tenant.** No `organization_id` column anywhere. Organization identity (name,
   logo, currency, timezone, issuer) lives in `settings`.

**Options considered.**

| Option | Verdict |
|--------|---------|
| `nwidart/laravel-modules` | Rejected. Adds a module manifest, per-module providers, separate asset pipelines and its own conventions on top of Laravel's — real cost, and its main benefit (independent deployability) is explicitly not wanted (§4: no microservices). Also an extra dependency to track across Laravel majors |
| Separate Composer packages per domain (path repositories) | Rejected for v1. Genuinely strong boundaries, but painful on shared hosting and slow to iterate while the domain is still settling |
| Multi-tenant from day one (`organization_id` on every table) | Rejected. Adds a column, an index, a global scope and a bug surface to ~110 tables for a requirement that does not exist (§95: no speculative complexity) |

**Consequences.**
+ Zero framework magic; any Laravel developer understands the layout in ten minutes.
+ Boundaries are still testable and enforceable (the audit command fails CI on a violation).
+ Domain code is transport-agnostic, so `/api/v1` (§68) and a future mobile app (§99) reuse it.
− Namespace discipline is a social contract reinforced by tooling rather than by a compiler.
− If multi-tenancy is ever required it is a **migration**, not a config change: the documented
  path is (a) add `organization_id` to the ~40 tenant-scoped tables, (b) a global scope on a
  `BelongsToOrganization` trait, (c) backfill from `settings`. Estimated as a contained,
  scriptable change precisely *because* no logic assumed tenancy.

**Reversal cost.** Low for the tenancy decision (scriptable migration). Medium for repackaging
domains into Composer packages (mechanical but wide).

---

## ADR-02 — Money as integer minor units + a `Currency` value object

**Status:** Accepted · **Spec:** §61, §41, §89

**Context.** The platform charges in NGN at launch but must be currency-ready (§61). Paystack
transmits amounts as **integers in minor units** (NGN 100 → `10000`) — verified against
Paystack documentation. Money appears in orders, order items, invoices, payments, refunds,
subscriptions, entitlements, products, services, packages, coupons, tax and the tutor earnings
ledger. Floating-point money and hard-coded `₦` are both explicitly forbidden.

**Decision.**
1. Every monetary column is a pair: `*_amount_minor BIGINT UNSIGNED` + `*_currency CHAR(3)`.
   Named `*_minor` so the unit is unmistakable at the call site.
2. A `Money` value object is the **only** way money is manipulated in PHP:
   ```php
   final readonly class Money
   {
       public function __construct(public int $minor, public Currency $currency) {}
       public static function fromMajor(string $major, string $code): self;   // '45000.00', 'NGN'
       public function add(Money $o): self;          // throws on currency mismatch
       public function subtract(Money $o): self;
       public function multiply(int|float $f): self; // rounds HALF_UP to minor units
       public function percentOf(float $p): self;
       public function allocate(array $ratios): array; // lossless split, remainder to first
       public function compareTo(Money $o): int;
       public function isZero(): bool; public function isPositive(): bool;
       public function minorUnits(): int;             // what Paystack receives
       public function format(?string $locale = null): string;  // symbol from Currency, not hard-coded
   }
   ```
3. `Currency` is a value object holding ISO-4217 code, **decimal exponent**, symbol and
   symbol position, resolved from `config('platform.currencies')` (overridable from
   `settings`). Launch set: `NGN(2), USD(2), GHS(2), ZAR(2), KES(2), XOF(0), GBP(2), EUR(2)`.
   Note `XOF` has exponent 0 — the reason a hard-coded "× 100" would have been a latent bug.
4. `CHECK (amount_minor >= 0)` on every money column. Refunds are their own table, never
   negative amounts.
5. Comparison is always integer equality — never `==` on floats, never rounding at the
   boundary.

**Options considered.**

| Option | Verdict |
|--------|---------|
| `DECIMAL(14,2)` amounts | Rejected. Ties the schema to a 2-decimal assumption (breaks XOF/JPY and 3-decimal currencies like BHD), and Laravel returns DECIMAL as a **string**, so arithmetic needs casts everywhere |
| Floats | Rejected outright. Non-negotiable for money |
| `moneyphp/money` package | Considered. Excellent, but adds a dependency for ~120 lines we must understand intimately anyway; our VO maps 1:1 to Paystack's integer model and to our DB columns. If we adopt it later, the DB representation does not change |
| Storing major units as strings | Rejected. Sorting and aggregation in SQL become painful |

**Consequences.**
+ Lossless round-trip to Paystack; verification compares integers (`4500000 === 4500000`).
+ Adding a currency is a config change, not a migration.
+ Symbol/locale rendering is centralized, so no `₦` leaks into business logic (§95).
− `BIGINT` minor units are unreadable in raw SQL (`4500000` ≠ ₦45,000). Mitigated by a MySQL
  view helper and by `platform:money` artisan formatting in support tooling.
− Every developer must use the VO, not raw ints. Enforced by code review + Larastan typing
  (services accept `Money`, never `int`).

**Reversal cost.** Very high once orders exist (a money migration rewrites financial history).
This is why it is decided now.

---

## ADR-03 — Entitlements with typed target columns as the commerce↔education boundary

**Status:** Accepted · **Spec:** §19, §45, §5, §38

**Context.** Spec §19 is explicit: *"Do not directly couple payment records to course access"*
and prescribes `Payment → Order → Order Item → Entitlement → Student Course Access`. Spec §5
adds that the purchaser is not necessarily the beneficiary. An entitlement must be able to
target a course, a program, a digital product, a tutoring service or a package — and it is
the **authority** for access, so its integrity is safety-critical.

**Decision.**
1. `entitlements` is the single access-granting record. It carries **both sides**:
   `owner_user_id` (purchaser) and `beneficiary_student_id` (nullable — a self-purchase).
2. The target is a set of **typed nullable FK columns** — `course_id`, `program_id`,
   `digital_product_id`, `tutoring_service_id`, `tutoring_package_id` — with an
   `entitlement_type` discriminator and a `CHECK` constraint that **exactly one** target
   column is non-null. Not polymorphic.
3. Source is polymorphic (`source_type`, `source_id`) because provenance is *provenance*:
   `order_item | subscription | manual_grant | promotion | migration`. This direction is safe
   to leave unenforced by FK.
4. `EntitlementGranter` is the **only** writer. `CourseAccessResolver` and
   `DownloadAuthorizer` are the only readers for access decisions.
5. `UQ(source_type, source_id, product_id, beneficiary_student_id)` makes granting idempotent
   at the database level.
6. Session-based entitlements (packages) carry `sessions_included` / `sessions_consumed`, and
   consumption is recorded in `entitlement_consumptions` with
   `UQ(entitlement_id, consumable_type, consumable_id)`.
7. Commerce **never imports** Lms/Tutoring models. On `EntitlementGranted`, an Lms listener
   creates the `course_enrollments` row with `source=entitlement`. The dependency is inverted
   through the table.

**Options considered.**

| Option | Verdict |
|--------|---------|
| Polymorphic `entitleable_type` + `entitleable_id` | Rejected. Idiomatic Laravel, but: no foreign keys (orphaned entitlements become possible and invisible), no join-based query planning (the access check is the hottest read in the platform), and no DB-level "exactly one target" guarantee. For the access-control backbone, integrity beats elegance |
| A join table per target type (`course_entitlements`, `product_entitlements`, …) | Rejected. Five near-identical tables, five granters, five expiry sweeps — and cross-type queries ("everything this child is entitled to") become a UNION of five tables |
| Granting access directly from `order_items` | Rejected by §19, and wrong: it cannot represent manual grants, promotions, subscriptions, renewals, extensions or revocation without an order |
| Storing entitlements as flags on the student | Rejected. No history, no expiry, no owner/beneficiary split, no source traceability |

**Consequences.**
+ Real FKs, real indexes (`IX(beneficiary_student_id, status, ends_at)` is the hot path), and
  `EXPLAIN`-able joins.
+ Adding a new entitlement target is a migration + a resolver branch — a deliberate, reviewable
  event rather than a silent string.
+ Idempotency is enforced in the schema, not just in code (§42).
+ Commerce and education are genuinely decoupled; the Lms works before commerce exists
  (Phase 3 before Phase 8) because the resolver checks enrollment/cohort first.
− Slightly more verbose than polymorphic.
− The `CHECK` constraint needs care on MariaDB versions < 10.2 (enforced from 10.2+; the
  deployment guide requires MariaDB ≥ 10.6, and a service-level assertion backs the DB check
  for older engines).

**Reversal cost.** High (financial + access history). Decided now.

---

## ADR-04 — The minor-purchase rule as a domain guard at one checkout choke point

**Status:** Accepted · **Spec:** §10, §90, §57, §34

**Context.** §10 is called *"a critical requirement"*: a student under 18 may learn, attend,
submit and participate, but may not independently purchase or subscribe to a service flagged
`requires_parent_purchase`. The rule must hold identically for web UI, API, a future mobile
client and admin-assisted checkout, and must be enforced in the backend.

**Decision.**
1. `MinorPurchaseGuard` is a **domain service** in `Commerce/Services`, constructed with a
   `Clock` and a `SettingsReader` so it is unit-testable with no database:
   ```php
   $guard->assert(new PurchaseContext(
       purchaser: $user, beneficiary: $student, purchasable: $product, channel: 'web'
   ));   // throws PurchaseRestrictedForMinor
   ```
2. It is invoked from **exactly one place**: `CheckoutService`, which every channel calls
   (Livewire checkout, admin-assisted checkout, subscription creation, and `/api/v1` when it
   exists). One choke point ⇒ one behaviour ⇒ one test suite.
3. Rule: reject when `age(beneficiary, today) < setting('age_of_majority')` **AND**
   `product.requires_parent_purchase` **AND** no active `parent_student` link exists where the
   purchaser is the guardian and `can_purchase = true`.
4. **Fail safe on unknown DOB:** a null `date_of_birth` is treated as a minor. Admins get a
   prompt to record it.
5. `age_of_majority` is a **setting** (default 18), never a literal (§95).
6. It throws a **domain exception**, not a validation error — it is a business-rule violation,
   not a malformed field. Presentation maps it to an actionable message with
   "invite a guardian" / "ask your guardian" / "contact support" paths.
7. Override is a **separate permission** (`commerce.override_minor_restriction`, Super Admin
   only), implemented as a distinct audited action — never a boolean parameter on the guard.
8. Rejection happens **before** any money object is created: no order, no invoice, no payment,
   no entitlement.
9. The UI also prevents it (defence in depth), but the server is the authority (§57).

**Options considered.**

| Option | Verdict |
|--------|---------|
| Form Request validation rule | Rejected. Ties the rule to HTTP; the API and admin paths would each need their own copy — exactly the drift §10 warns about |
| Middleware | Rejected. Middleware cannot see the *items* and their beneficiaries; and it is bypassed by any non-HTTP entry point |
| Policy method | Rejected as the sole mechanism. Policies answer "may this user touch this resource?", not "is this purchase legal for this beneficiary?". The guard is consulted *from* `BookingPolicy` and `OrderPolicy`, but lives in the domain |
| Database trigger / CHECK | Rejected. Requires computing age in SQL from two tables; untestable; invisible to developers |
| Per-channel checks | Rejected — that *is* the bug §10 forbids |

**Consequences.**
+ Eight unit tests cover the rule completely without a database (fast, exhaustive).
+ Any new channel (mobile API, import-driven sales) inherits the rule by calling
  `CheckoutService`.
+ The override is exceptional, attributable and audited (§56).
− `CheckoutService` becomes a required path for all sales. That is intentional, but it means
  no "quick" sale shortcut may ever bypass it — enforced by the audit command and by review.
− Fail-safe-on-null-DOB will occasionally block a legitimate adult purchase until an admin
  records the DOB. Accepted: a children's platform should fail closed.

**Reversal cost.** Low (it is one class + one call site), which is itself an argument for
getting the placement right now.

---

## ADR-05 — `VideoMeetingProvider` abstraction; Meet via the Calendar API; an honest manual fallback

**Status:** Accepted · **Spec:** §14, §36, §37, §93

**Context.** Three kinds of record need video meetings: cohort class sessions, tutoring
sessions and tutor interviews. Two providers are required (Google Meet, Zoom) and another must
be addable later. Two verified realities constrain the design:
(a) **Google Meet has no general "create meeting" REST API** — links come from the Calendar API
(`events.insert?conferenceDataVersion=1` with `conferenceSolutionKey.type = hangoutsMeet` and a
unique `requestId`), and conference data is created **asynchronously**;
(b) **Zoom JWT apps are retired** — only User-managed OAuth or Server-to-Server OAuth exist.

**Decision.**
1. A `VideoMeetingProvider` contract (`createMeeting`, `updateMeeting`, `cancelMeeting`,
   `getMeeting`, `supports(capability)`, `status()`, `requiresHostConnection()`) with
   DTO in / DTO out. Callers never see a provider payload.
2. Adapters: `GoogleMeetProvider` (Calendar API, `conference.events` scope, `requestId`
   derived from and stored with the meeting so retries are idempotent), `ZoomProvider`
   (**both** user-managed OAuth and S2S OAuth), `ManualProvider`.
3. `meetings` is **one polymorphic table** serving all three meeting owners
   (`course_session | tutoring_session | tutor_interview`), with `status ∈
   {provisioning, ready, failed, cancelled, expired}`. `provisioning` exists specifically
   because Google's conference creation is async: the UI shows "Setting up your meeting link…"
   and a `meetings:provision` sweep resolves stragglers.
4. `MeetingManager` resolves the provider (tutor's own connection → org account → manual),
   persists, retries, logs to `integration_logs`, and falls back with an explicit note.
5. **`ManualProvider` is a real feature, not a stub.** It stores a human-pasted `https://`
   join URL and labels the meeting `manual`. The UI never implies Google or Zoom created it.
6. Host/start URLs are stored encrypted and rendered only to the host; join URLs are rendered
   only to participants.
7. Per-tutor credentials live in `connected_accounts` with Laravel's `encrypted` cast
   (§36/§37: never plaintext).

**Options considered.**

| Option | Verdict |
|--------|---------|
| Zoom/Google SDK packages | Rejected. Both SDKs lag framework majors, add weight, and hide the HTTP layer we need to log and retry. A thin client on Laravel's `Http` facade is ~200 lines and fully observable |
| The standalone Google Meet REST API (`meet/v1` spaces) | Rejected. It targets pre-configured spaces with recording/REST management, not ad-hoc "attach a Meet link to this class" — the Calendar path is the supported way to generate links |
| Polymorphic `meetings` vs a meeting table per owner | Chosen polymorphic. Three owner types, identical shape, and one unified calendar. Here polymorphism *helps* (unlike ADR-03, where the target is an access-control join) |
| Faking a meeting when unconfigured | Rejected by §93. `status()=not_configured` hides the UI and `platform:doctor` explains what to set |

**Consequences.**
+ Adding Teams/Webex = one adapter + one config entry + one enum case. No schema or UI change.
+ Tutors can host from their own accounts (a real requirement for recorded sessions and
  waiting rooms), with an org fallback.
+ Async provisioning is modelled rather than papered over — no dead "Join" buttons.
− Three code paths to test (Google, Zoom, Manual) × three owner types. Mitigated by testing
  against the contract with HTTP fakes, plus a shared contract test suite every adapter must
  pass.
− Google requires OAuth verification for production use with unverified users, and the
  organizer's account must have Meet licensing; both are documented setup steps, and
  `Unsupported` is a first-class result rather than a crash.

**Reversal cost.** Low for adapters (that is the point). Medium for the `meetings` table shape.

---

## ADR-06 — First-party Paystack client, verification-before-value, four-layer idempotency

**Status:** Accepted · **Spec:** §41, §42, §43, §93

**Context.** Paystack powers all commerce. Three verified facts drive the design: webhooks are
signed with `x-paystack-signature` = **HMAC-SHA512 of the raw body using the secret key** (there
is no separate webhook secret); visiting `callback_url` proves nothing, so **verification via
the API is mandatory before delivering value**; and the same event **can arrive more than
once**.

**Decision.**
1. A thin first-party `PaystackClient` (Laravel `Http` facade) + `PaystackGateway` implementing
   our `PaymentGateway` contract with DTOs. No Paystack-shaped arrays escape the adapter.
2. `PAYSTACK_MODE=test|live` selects the key pair **independently of `APP_ENV`** (§75). The
   mode is stamped onto every `payments`/`payment_events` row so test transactions never
   pollute live revenue reporting.
3. **Verification before value.** The callback controller renders "Verifying your payment…" and
   dispatches `VerifyPaystackTransaction`. No code path sets `orders.status = paid` from
   request input. `ConfirmPayment` asserts provider status `success`, **amount equality in
   integer minor units**, and currency equality.
4. **Webhook signature verification** reads `$request->getContent()` (raw bytes) *before* any
   JSON decoding, computes `hash_hmac('sha512', …)` and compares with `hash_equals()`.
   Mismatch ⇒ `400` + a warning log with IP + UA + no state change.
5. **Four independent idempotency layers:**
   | Layer | Mechanism |
   |---|---|
   | 1 | `payment_events` `UQ(provider, provider_event_id)` — insert-first gate; duplicate ⇒ `200` and stop |
   | 2 | `payments` `UQ(provider, provider_reference)` |
   | 3 | `subscriptions` `UQ(provider, provider_subscription_id)` |
   | 4 | `entitlements` `UQ(source_type, source_id, product_id, beneficiary_student_id)` + `entitlement_consumptions` `UQ(entitlement_id, consumable_type, consumable_id)` |
6. **Respond 200 fast, process async:** the controller inserts the event row and dispatches a
   queued handler. Heavy work never blocks Paystack's retry logic.
7. `payments:reconcile` runs every 5 minutes to recover orders where a webhook was lost,
   blocked by ModSecurity, or delayed — verifying each reference with the API. An unknown
   reference is flagged suspicious and **never** marked paid.
8. IP allowlisting is available but **off by default** — shared hosts frequently sit behind
   proxies that rewrite `REMOTE_ADDR`, and the signature is the primary control. Documented as
   defence in depth, not as the guarantee.
9. The secret key never reaches a view, a JS bundle, a log line or an error message. A
   dedicated test greps rendered output and log files for `sk_`.

**Options considered.**

| Option | Verdict |
|--------|---------|
| `laravel/cashier-paystack` / community packages | Rejected. Cashier's abstractions are Stripe/Paddle-shaped; Paystack's plan/subscription semantics differ. A first-party client is ~400 lines, fully testable with `Http::fake()`, and cannot lag a Laravel major |
| Trusting the redirect | Rejected by §41 and by Paystack's own documentation |
| A single idempotency flag on `payments` | Rejected. One layer fails silently if a handler is added later; four layers mean a new handler is safe by construction |
| Processing webhooks synchronously | Rejected. Paystack expects a fast 200; entitlement granting, PDF rendering and notifications are slow |
| Storing raw webhooks unredacted | Rejected by §77; payloads pass through `Redactor::scrub()` |

**Consequences.**
+ Duplicate delivery, replayed webhooks and lost webhooks are all handled and tested (§72).
+ The `PaymentGateway` contract means a second processor is an adapter, not a rewrite.
+ `ManualPaymentGateway` lets the whole commerce suite run with zero network access — which is
  also the only way to test in this sandbox.
− We own retry/backoff/error-mapping code that a package would provide. Accepted: it is small,
  and owning it is what makes the four-layer guarantee real.
− Reconciliation adds a cron dependency; on a host with 15-minute cron, recovery latency rises
  to ~15 minutes. Documented in the deployment guide.

**Reversal cost.** Medium (the contract isolates it, but the ledger schema is load-bearing).

---

## ADR-07 — One unified `assessments` table with 1:1 detail tables

**Status:** Accepted · **Spec:** §21, §22, §23, §25

**Context.** §23 asks for a *unified* assessment system spanning quiz, assignment, examination,
project, continuous assessment and tutor assessment — while §21 and §22 describe quiz-specific
and assignment-specific behaviour in detail. Grading, results, report cards and analytics all
need to aggregate **across** types.

**Decision.**
1. `assessments` holds everything common: title, `type`, context (course/module/lesson/cohort/
   subject), academic year/term, `max_score`, `weight`, `passing_score`, grading scheme,
   publish/due/close, late policy, attempts, scope, assessor, status.
2. Type-specific behaviour lives in **1:1 tables keyed by `assessment_id`**: `quiz_settings`
   and `assignment_details` (extensible to `examination_details`, `project_details`).
3. Submissions, answers, grading and results are **type-agnostic**: one
   `assessment_submissions` table serves every type, with `version` + `is_current` for
   resubmission history and `question_snapshot` for randomization integrity.
4. `GradebookAggregator` and `AcademicResult` read `assessments` uniformly and apply `weight`,
   so a report card is one query, not a UNION of five tables.
5. Questions live in a reusable bank (`questions` + `question_options` +
   `assessment_questions`), so the same item can appear in a quiz and an examination with
   different point values.

**Options considered.**

| Option | Verdict |
|--------|---------|
| Separate `quizzes`, `assignments`, `examinations` tables (STI-free) | Rejected. Every cross-type need — gradebook, calendar, reminders, results, "my assessments" — becomes a UNION with divergent columns |
| Single table with a big nullable-column union | Rejected. Dozens of NULL columns and no way to constrain "quiz columns only when type=quiz" |
| Single table + JSON `configuration` | Rejected for the type-specific parts: unindexable, unvalidatable at the DB level, and the spec's quiz settings (duration, attempts, shuffle, visibility) deserve real columns and real validation. JSON is used only for `question_snapshot` and `meta` |
| Chosen: class-table inheritance (parent + 1:1 children) | Normalized, indexable, extensible, and cross-type queries stay simple |

**Consequences.**
+ One submission pipeline, one grading pipeline, one results pipeline.
+ Adding an assessment type = one detail table + one enum case + one authoring form.
+ Report cards and analytics are single-query aggregations.
− Creating a quiz touches two tables (wrapped in one Action + transaction, so callers never
  see it).
− 1:1 joins are needed for type-specific reads; both sides are indexed by PK so this is cheap.

**Reversal cost.** Medium-high (submissions and results reference it). Decided now.

---

## ADR-08 — UTC instants plus explicit wall-clock local patterns

**Status:** Accepted · **Spec:** §62, §32, §14

**Context.** Tutors, students and parents may be in different timezones; tutors **must** have
one (§32). Recurring schedules ("every Tuesday 16:00 Lagos") must survive DST changes.
Comparisons and ordering must be unambiguous.

**Decision.**
1. Every stored **instant** is UTC in a `DATETIME` column named `*_at`. `APP_TIMEZONE=UTC`.
2. Every **recurring pattern** stores wall-clock local time (`TIME starts_at_local`,
   `ends_at_local`) **plus** an IANA `timezone` name (`Africa/Lagos`, never an offset).
3. Materialized occurrences (`course_sessions`, `tutoring_sessions`) store **both**:
   `starts_at`/`ends_at` (UTC, authoritative for ordering and comparison) and
   `starts_at_local`/`ends_at_local` + `timezone` (authoritative for display and for
   "the class is at 4pm" semantics).
4. `SetUserTimezone` middleware applies the user's timezone (or the org default from
   `settings`) to the request; `TimezonePresenter` converts on output. Carbon is configured
   with `CarbonImmutable`.
5. `SlotFinder` converts requester → tutor → UTC and back; it **never** compares naive
   datetimes. DST boundaries are a dedicated unit-test group.
6. IANA names are validated against `DateTimeZone::listIdentifiers()` at write time.

**Options considered.**

| Option | Verdict |
|--------|---------|
| Store local time only | Rejected. Cross-timezone comparison and ordering become impossible; a tutor in Lagos and a parent in London cannot agree on a slot |
| Store UTC only | Rejected for recurrence. "Every Tuesday 16:00 Lagos" would drift by an hour twice a year for any DST-observing zone, and displaying a class time would require recomputation that can be wrong for historical rows |
| Store UTC offsets (`+01:00`) | Rejected. Offsets are not timezones; they lose the DST rule and break recurrence |
| Both (chosen) | Slight redundancy, but each value has one clear authority: UTC for logic, local for humans |

**Consequences.**
+ Correct behaviour across DST, across timezones and for historical records.
+ Reports and calendars can group by local date without recomputation.
− Two representations must be kept consistent — handled by writing them together in one
  Action, never separately.
− `starts_at_local` as a string is not directly comparable in SQL; ordering always uses the UTC
  column (indexed).

**Reversal cost.** High (touches every scheduled record). Decided now.

---

## ADR-09 — RBAC via spatie/laravel-permission v7 + policies; permissions, never role strings

**Status:** Accepted · **Spec:** §6, §28, §30, §49, §57, §81

**Context.** §6 forbids relying on hard-coded role checks and gives the exact permission
vocabulary to reproduce (`students.view`, `tutors.approve`, `payments.refund`, …). Users may
hold several profiles/roles simultaneously. §28/§49 require that evaluators be structurally
unable to approve tutors or see financial data.

**Decision.**
1. `spatie/laravel-permission` **v7** (verified: requires `php: ^8.3` and
   `illuminate/*: ^12.0|^13.0` — the Laravel-13-compatible line).
2. **168 permission strings** defined in a code registry
   (`app/Domain/Administration/Permissions.php`) and synced to the database by an idempotent
   seeder. Code is the source of truth for *what exists*; the database is the source of truth
   for *who has what*.
3. 11 seeded roles are **convenience bundles**. Authorization never inspects them.
4. Policies combine a permission check **with** an ownership/relationship check
   (`StudentPolicy::view()` = `students.view` **OR** an active guardian link with the relevant
   capability).
5. `Gate::before` grants Super Admin a bypass — the **only** place a role name appears in
   authorization logic, and it returns `null` (not `false`) so other gates still run.
6. Portal access is itself a permission (`parent.portal.access`, …) granted on profile
   activation, so area middleware is data-driven.
7. Bulk operations reuse the singular permission **plus** a per-record check (§81) and a
   dedicated `*.bulk_*` permission where the blast radius differs.
8. §49 is enforced **twice**: the seeder never grants finance permissions to Evaluator, and a
   test asserts every finance route 403s for an evaluator — so a configuration mistake cannot
   silently leak money data.

**Options considered.**

| Option | Verdict |
|--------|---------|
| Hand-rolled roles/permissions | Rejected. We would rebuild caching, teams, wildcards and the middleware — and then maintain them |
| Role-string checks (`hasRole('admin')`) | Rejected by §6. It cannot express "Academic Admin can publish courses but not refund payments" without an explosion of role combinations |
| A policy-only design with no permission table | Rejected. Admins must be able to compose new roles (e.g. "Support Agent") without a deploy |
| Spatie + permission *wildcards* (`students.*`) | Rejected for the registry. Wildcards make authorization audits ambiguous; the matrix is explicit, and grouping is a UI concern |

**Consequences.**
+ New roles are data, not code. The permission matrix is reviewable in one document.
+ Spatie's permission cache keeps the hot path cheap on shared hosting.
+ §28/§30/§49 become structural facts provable by test.
− 168 permissions is a lot to keep tidy. Mitigated by the code registry, the matrix document,
  and a `platform:audit-authorization` command that reports orphans and unmapped routes.
− Package upgrade risk across Laravel majors. Mitigated: v7 is verified for Laravel 13, and
  the package touches only `roles`/`permissions`/pivot tables, which we could reimplement if
  ever necessary.

**Reversal cost.** Medium (the permission vocabulary is embedded in policies and tests, but the
storage layer is swappable).

---

## ADR-10 — Four file visibility classes with authorized streaming downloads

**Status:** Accepted · **Spec:** §40, §58, §59, §57

**Context.** The platform stores children's documents, tutor CVs, graded work, digital products
people paid for, certificates and invoices. §40 forbids publicly guessable URLs and prescribes
the exact download decision chain. Shared hosting means no S3 signed URLs by default.

**Decision.**
1. One `files` registry with `visibility ∈ {public, authenticated, private, restricted}` and a
   `category` (profile_photo, cv, certification, student_document, course_resource,
   assignment, digital_product, certificate, invoice, report).
2. Four physical disks mapping to those classes; only `public` is symlinked into the document
   root. `private`/`restricted` live **outside** the web root on cPanel.
3. All non-public access goes through `GET /files/{uuid}/download`: `FilePolicy` →
   (for products) `DownloadAuthorizer` running the §40 chain **in order** —
   authenticated → purchased → payment confirmed → entitlement active → download limit not
   exceeded → not expired → ledger row → streamed response with a **sanitized**
   `Content-Disposition`.
4. UUIDs are v4; the original filename is stored but **never** used as a path (paths are
   `{category}/{yyyy}/{mm}/{uuid}.{ext}`).
5. Rate limiting per user and per file, plus a download ledger (`ip`, `user_agent`, bytes,
   timestamp) for anti-abuse and for §40's record-keeping.
6. Uploads are validated on MIME **and** extension **and** size; filenames are hashed; SVG is
   sanitized or rejected; executables are rejected.
7. `files.disk` is **per row**, so a gradual S3 migration needs no schema change (§98).

**Options considered.**

| Option | Verdict |
|--------|---------|
| `spatie/laravel-medialibrary` | Rejected. Its conversions pipeline assumes Imagick/GD and a mutable public disk, and its URL model does not naturally express a four-tier *authorization* chain. We keep the ledger + policy in our own hands |
| Storing files under `public/` with obfuscated names | Rejected by §40. Security by obscurity; one leaked URL exposes a child's document forever |
| X-Sendfile / X-Accel-Redirect | Not available on typical cPanel (no Apache `mod_xsendfile`, no Nginx). PHP streaming with `readfile`/`StreamedResponse` is the portable choice; the abstraction allows swapping in X-Accel on a VPS |
| Signed temporary URLs on the local disk | Adopted **as an option** for sharing (Laravel `URL::temporarySignedRoute`) but not as the default, since the ledger + policy must run on every access |

**Consequences.**
+ The §40 chain is one function, tested once, used by every download.
+ Sensitive files are physically unreachable by URL, not just policy-protected.
+ A per-file disk column makes the S3 migration incremental and reversible.
− Streaming through PHP costs request time and memory vs a webserver-served file. Mitigated by
  chunked streaming, `max_execution_time` guidance, and queueing large exports.
− Every download hits the DB (policy + ledger). Accepted: correctness over throughput, and the
  queries are indexed.

**Reversal cost.** Low-medium (paths and the registry are stable; the delivery mechanism can
change).

---

## ADR-11 — Data protection for minors: classification, least privilege, retention

**Status:** Accepted · **Spec:** §58, §9, §26, §77, §83

**Context.** The platform processes children's personal data: names, dates of birth, addresses,
academic records, documents, attendance, and possibly images. §58 requires least-privilege
access, parent-child access control, strict student-record authorization, private file
storage, audit logs, secure deletion/archive, configurable retention, privacy settings and
consent records. §26 requires public certificate verification **without** exposing private
student data.

**Decision.**
1. **Classification.** Student data is treated as sensitive by default. Columns and files are
   tagged into tiers that map to authorization and retention:
   | Tier | Examples | Access | Retention default |
   |---|---|---|---|
   | Identity | name, student code, status | role-scoped | life of record + 7 y |
   | Sensitive personal | DOB, address, phone, notes, emergency contact | `students.view_sensitive` | 2 y after withdrawal |
   | Academic record | results, submissions, attendance, certificates | scoped by `can_view_academics` | 7 y |
   | Documents | IDs, consent scans, medical notes | `files.visibility = restricted` | 2 y after withdrawal |
   | Financial | orders, payments, invoices | `can_view_financials` / finance roles | 7 y (tax) |
   | Telemetry | sessions, activities, integration logs | staff | 30–90 d |
2. **Least privilege by construction.** `students.view` and `students.view_sensitive` are
   separate permissions; list endpoints project a narrow column set; the Finance Officer role
   gets financial data with **no** DOB and **no** notes.
3. **Parent-child access is data, not code.** Every parent-facing query joins
   `parent_student` and filters on `status=active` plus the relevant capability
   (`can_view_academics`, `can_view_financials`, `can_receive_communications`). Capability
   changes are audited and take effect on the next request (cache invalidated).
4. **Consent records** (`consents`) capture type, version, granted flag, IP, user agent and
   timestamp — including consent recorded by a guardian on a minor's behalf. **Marketing
   consent defaults to false** and is never implied by registration (§83).
5. **Retention is configuration, not code.** `settings.retention.*` drives
   `platform:apply-retention`, which anonymizes/purges on schedule and writes an audit entry.
6. **Deletion is archival.** Students are soft-deleted and moved to `withdrawn`; FK `RESTRICT`
   makes accidental hard deletion of a student with grades impossible (§66). Document files are
   purged from disk after the window while the `files` row keeps a tombstone (checksum, size,
   deleted_at) so an audit can prove what existed.
7. **Public surfaces expose the minimum.** Certificate verification returns name, award title,
   issuer and date — never DOB, contact or grades (§26). The tutor directory exposes only
   opted-in public fields (§87).
8. **Logging discipline.** `SensitiveDataScrubber` redacts before any log write; the
   `paystack`/`webhooks`/`integrations` channels are the only ones retaining request detail,
   and they retain longer than debug noise (evidence for disputes) while debug logs are short-
   lived (§77).

**Options considered.**

| Option | Verdict |
|--------|---------|
| Column-level encryption for all student PII | Rejected as a blanket policy. It breaks searching, sorting and reporting on names/emails — the core admin workflows. Adopted **selectively** for OAuth tokens, host URLs and payment authorization codes, where search is never needed |
| Hard delete on request | Rejected. Conflicts with financial/tax retention and academic-record integrity. Anonymization + archival satisfies erasure while preserving lawful records |
| Per-request "is this my child?" checks scattered in controllers | Rejected. Centralized in `ChildContext` + policies so a missed check is impossible rather than unlikely |
| A single `students.view` permission | Rejected. Cannot express "finance sees the order but not the DOB" |

**Consequences.**
+ Compliance posture is legible: classification table, capability columns, consent records,
  retention settings, purge command, audit trail.
+ The §71 negative tests (Parent A ↛ Student B, Student A ↛ Student B) are the enforcement
  proof, and they run in CI.
− Narrow column projections mean more explicit resource/view classes. Accepted: this is the
  work that makes least privilege real.
− Retention windows are policy decisions someone must own; the defaults are documented as
  defaults, not legal advice, and are configurable.

**Reversal cost.** Low (policies and settings), except that audit/consent records must never be
retro-created — so they are implemented from Phase 1.

---

## ADR-12 — Dual audit: generic `audit_logs` plus immutable domain `*_events` tables

**Status:** Accepted · **Spec:** §56, §29, §44, §9

**Context.** §56 requires a who/what/before/after audit log for important operations.
Separately, §29 requires an **immutable audit trail of evaluation decisions**, §44 implies an
order timeline users can see, and §9 requires relationship-change history. These are two
different needs that a single table serves badly.

**Decision.**
1. `audit_logs` — generic, model-driven (`Auditable` trait on Eloquent events) plus explicit
   `AuditLogger::record()` calls in domain actions. Holds user, event, auditable type/id,
   redacted old/new values, IP, user agent, tags. **Compliance and debugging.**
2. Seven append-only domain history tables — `student_application_events`,
   `tutor_application_events`, `order_events`, `payment_events`, `entitlement_events`,
   `subscription_events`, plus immutable-by-design `tutor_evaluations` and
   `evaluation_criterion_scores`. These have **no `updated_at`**, no update path in code, and
   `RESTRICT` deletes. **They are product features**: rendered as timelines in the UI.
3. Both are written by the domain action inside the same transaction as the state change, so
   history cannot diverge from state.
4. Redaction is mandatory and centralized (`SensitiveDataScrubber` with a configured key list),
   so §77's "never log passwords/secrets" is structural.
5. Retention: `audit_logs` pruned per `settings.audit.retention_days`; domain history is
   permanent (it *is* the academic/financial record).

**Options considered.**

| Option | Verdict |
|--------|---------|
| One generic audit table for everything | Rejected. Workflow timelines would be reconstructed from JSON blobs — slow, unindexable, and unusable as a product feature |
| One history table per aggregate only (no generic audit) | Rejected. §56 explicitly lists model-level changes (student status, grades, roles) that need before/after diffs, which per-aggregate tables would duplicate badly |
| An event-sourced domain | Rejected. Far beyond the need; would make shared-hosting operation and developer onboarding much harder (§4: no unnecessary abstraction) |
| A third-party audit package (e.g. `owen-it/laravel-auditing`) | Rejected. It handles the generic half well but not the domain-history half, and its config-driven auditing is easier to get subtly wrong than a 120-line trait we control |

**Consequences.**
+ Compliance questions ("who changed this grade?") and product questions ("what happened to
  this order?") are both first-class queries.
+ Immutability is enforced by schema (no `updated_at`, no update path, RESTRICT), not by
  convention.
− Two writes per state change. Accepted: they are in the same transaction, and the volume is
  bounded by business events, not by reads.
− Developers must remember to write the domain event. Enforced by making the transition a
  method on the aggregate (`$order->transitionTo(…)`) that writes the event itself, so it
  cannot be skipped.

**Reversal cost.** Low-medium. The tables are additive; removing either would lose history.

---

## ADR-13 — Shared-hosting-first infrastructure: database drivers, pure-PHP rendering, Livewire, committed assets

**Status:** Accepted · **Spec:** §3, §63–§65, §69, §78, §96, §98

**Context.** The application must deploy on conventional cPanel shared hosting: no persistent
Node server, no Docker/Kubernetes, no Redis requirement, cron-driven scheduling, filesystem
storage. It must also feel like a modern product on mobile (§63, §64) and must not require a
rewrite to move to a VPS or cloud (§98).

**Decision.**
1. **Drivers:** `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`,
   `FILESYSTEM_DISK=local` — each a config value, so `redis`/`s3` is an env change, not a
   refactor. Laravel 13's Reverb *database* driver is the noted path if real-time is ever
   needed without Redis.
2. **Queue worker via cron:** `queue:work --stop-when-empty --max-time=50 --max-jobs=100`
   every minute, with priority queue names (`payments`, `emails`, `reports`). Every job is
   idempotent and retry-safe because a worker can be killed at second 50. Latency-critical
   security mails (password reset, 2FA) are sent **synchronously**.
3. **Scheduler via one cron entry** (`schedule:run`), with `withoutOverlapping()` everywhere
   and long commands written to process a bounded batch per run.
4. **PDF via `barryvdh/laravel-dompdf`, QR via `bacon/bacon-qr-code`** — both **pure PHP**.
   `wkhtmltopdf`/Snappy need an external binary and `exec()`, which cPanel almost always
   disables. Fonts are bundled so rendering is host-independent.
5. **Frontend: Blade + Livewire 4 + Alpine + Tailwind 4** (§3). No SPA. Livewire 4.2+ is the
   Laravel-13-compatible line and its hardened update requests (X-Livewire header + JSON
   content type required) are respected by our middleware and documented for proxies.
6. **Assets are compiled in CI and `public/build/**` is committed** (an explicit, commented
   `.gitignore` exception), so production needs **no Node at all**.
7. **Search: MySQL FULLTEXT** behind a `SearchIndex` contract with a per-provider
   authorization scope; Scout/Meilisearch is a swap, not a rewrite (§54).
8. **Performance discipline** (§78): index-first schema, eager loading declared in query
   objects, `Model::preventLazyLoading()` enabled in dev/tests so N+1s fail loudly, pagination
   everywhere, denormalized counters maintained by events, chunked imports/exports.

**Options considered.**

| Option | Verdict |
|--------|---------|
| Inertia + React/Vue SPA | Rejected by §3 and by the shared-hosting constraint (a build step on the server, or a separate deploy target) |
| Redis required from day one | Rejected by §3/§69. Config-only upgrade instead |
| `wkhtmltopdf` for prettier PDFs | Rejected. Binary + `exec()` dependency is the single most common cPanel failure. dompdf output is good enough for certificates/invoices/report cards, and the `DocumentRenderer` contract allows swapping later |
| Livewire's `wire:navigate` + full SPA-ish feel with a Node dev server in production | Rejected for production; Vite is dev/CI only |
| `.gitignore`-ing `public/build` | Rejected. It would make Node a production dependency, violating §96 |

**Consequences.**
+ The deploy target needs exactly: PHP 8.3+, MySQL, a writable filesystem, two cron entries.
+ The upgrade path to VPS/cloud is configuration (Supervisor + Redis + S3), not code (§98).
+ Committing built assets makes releases reproducible and reviewable.
− Committed build artefacts add diff noise. Mitigated by keeping them on the release branch and
  out of code review scope.
− Cron-driven queues mean up to ~60 s of latency for background work. Accepted: reminders are
  offset-based and idempotent, and synchronous sending covers the latency-critical cases.
− dompdf is less capable than a headless browser for complex layouts. Accepted: certificate and
  invoice templates are designed within its capabilities.

**Reversal cost.** Low — these are configuration and adapter choices by design.

---

## ADR-14 — Business rules centralized in domain services and Actions, behind a DTO boundary

**Status:** Accepted · **Spec:** §90, §4, §68, §99, §101

**Context.** §90 names the rules that must be centralized (`CanStudentPurchaseTutoring`,
`CanParentPurchaseForStudent`, `CanBookTutor`, `CanAccessCourse`, `CanDownloadProduct`,
`CanPublishCourse`, `CanApproveTutor`, `CanEvaluatorReviewApplication`, `CanSubmitAssessment`)
and explains why: to prevent duplication across web, API and admin paths. §68/§99 require the
same services to be reachable from a future API and mobile app.

**Decision.**
1. **Actions** (one per use-case, `__invoke(DTO): Result`) are the entry point for every state
   change. Controllers and Livewire components contain **view state only** — filters, sorting,
   pagination, form binding.
2. **Domain services** hold rules that span aggregates or need pure computation:
   `MinorPurchaseGuard`, `CourseAccessResolver`, `GradeResolver`, `QuizScorer`, `SlotFinder`,
   `EntitlementGranter`, `DownloadAuthorizer`, `EarningsCalculator`, `ReviewEligibility`,
   `AcademicCalendarService`, `ProgressCalculator`, `CouponValidator`, `OrderTotalsCalculator`.
3. **DTOs at the boundary.** Actions accept readonly DTOs, never `Illuminate\Http\Request`.
   A controller, a Livewire component, a console command and a future API controller all build
   the same DTO. This is what makes §68/§99 nearly free.
4. The §90 rule names map to concrete classes:

   | §90 rule | Implementation |
   |---|---|
   | `CanStudentPurchaseTutoring` | `MinorPurchaseGuard` (student side) |
   | `CanParentPurchaseForStudent` | `GuardianAuthorizer` reading `parent_student.can_purchase` |
   | `CanBookTutor` | `BookingPolicy` + `SlotFinder` + `BookingConflictDetector` |
   | `CanAccessCourse` | `CourseAccessResolver` |
   | `CanDownloadProduct` | `DownloadAuthorizer` |
   | `CanPublishCourse` | `CoursePolicy::publish` + `PublishCourse` action guards |
   | `CanApproveTutor` | `TutorApprovalPolicy` (`tutors.approve`) inside `ApproveTutor` |
   | `CanEvaluatorReviewApplication` | `TutorApplicationPolicy::review` (assignment-scoped) |
   | `CanSubmitAssessment` | `SubmissionPolicy` + `AssessmentWindow` rule (open, in scope, attempts left) |

5. Every rule in that table has a **named unit test** (§71), and the Phase 11 authorization
   audit fails CI if a route or Livewire action mutates state without going through an Action.
6. Rules never read `request()`, `auth()->user()` implicitly, or `now()` directly — they take
   the actor, the subject and a `Clock` as parameters. This is what makes them testable without
   a framework.

**Options considered.**

| Option | Verdict |
|--------|---------|
| Logic in controllers (classic Laravel) | Rejected by §90 — the API and admin paths would each need a copy |
| Logic in Livewire components | Rejected. Components are presentation; rules there are invisible to the API and untestable without HTTP |
| Logic in models ("fat models") | Partially accepted: models own **their own invariants** and state transitions (`$order->transitionTo()`), but cross-aggregate rules live in services. Fat models alone cannot express `MinorPurchaseGuard` (three aggregates + settings) |
| A full CQRS/command-bus package | Rejected. Laravel's container + Actions + DTOs give the same clarity without a bus abstraction (§4) |
| Service classes with 20 methods | Rejected in favour of one Action per use-case: discoverable, testable, and each has one reason to change |

**Consequences.**
+ One implementation of every rule; four entry points (web, admin-assisted, console, future
  API) inherit it.
+ Unit tests need no database for the crown-jewel rules — fast and exhaustive.
+ The mobile/API story (§99) is "add controllers + Resources", not "rewrite".
− More small classes than a conventional Laravel app. Accepted: each is short, named after a
  use-case, and located by module.
− Discipline is required to keep components thin. Enforced by review, by the audit command, and
  by tests that call Actions directly rather than through HTTP.

**Reversal cost.** Low individually, high in aggregate — this is the architectural spine, which
is why it is fixed before Phase 1.

---

## ADR-15 — Identity separation: User vs Profile vs Participation

**Status:** Accepted · **Spec:** §5, §6, §8, §9, §10, §34

**Context.** §5 requires the system to distinguish User, Parent/Guardian, Student, Tutor,
Evaluator, Administrator, Instructor, Customer, Purchaser and Beneficiary — and warns not to
assume the purchaser is the student. §8 allows a student to exist **without** a user account.
§9 makes Parent/Guardian a first-class entity with an optional account.

**Decision.**
1. **`users`** = authentication subject only: credentials, roles, permissions, timezone,
   notification preferences, status. A user may have **no** profile (a Finance Officer).
2. **Profiles** = domain participants, each 1:0..1 with a user: `students.user_id` (nullable,
   unique), `parents.user_id` (nullable, unique), `tutor_profiles.user_id` (unique).
   A user may hold **several** profiles (a tutor who is also a parent).
3. **Participation** = a role on a record, never a table: `orders.customer_user_id`
   (purchaser) vs `orders.beneficiary_student_id`; `order_items.beneficiary_student_id`
   (**per item**, so one cart serves two children); `tutoring_bookings.booked_by_user_id` vs
   `.student_id`; `entitlements.owner_user_id` vs `.beneficiary_student_id`;
   `subscriptions.customer_user_id` vs `.beneficiary_student_id`; `assessments.assessor_id`;
   `attendance_records.recorded_by`.
4. **Relationships** are first-class: `parent_student` carries per-child capabilities
   (`can_purchase`, `can_view_academics`, `can_view_financials`, `can_receive_communications`,
   `is_primary`, status, consent snapshot). This table is the **authority** for §10 and for
   every parent-portal scope.
5. Nullable `user_id` supports the real workflows: an admin records a 7-year-old student and a
   guardian before either has an account; the guardian later **claims** the record by matching
   email via a hashed invite token; a student account is created only when it is useful.
6. Portal switching is driven by which `*.portal.access` permissions the user holds, so a
   tutor-parent sees both portals and each is authorized independently.

**Options considered.**

| Option | Verdict |
|--------|---------|
| One `users` table with a `role` column and all profile fields inline | Rejected. Cannot represent a student without an account, a user with two profiles, or per-child guardian capabilities |
| Separate `students`/`parents`/`tutors` tables each with their own credentials | Rejected. Duplicate authentication, four login flows, no single session, and a tutor-parent would need two accounts |
| Polymorphic `profiles` table | Rejected. Student, parent and tutor profiles share almost no columns; one wide table of NULLs, with no per-type constraints |
| Chosen: central `users` + typed profile tables + participation columns + a relationship table | Matches the spec's own vocabulary (§5), supports account-less participants, and makes the purchaser/beneficiary split explicit at every commercial record |

**Consequences.**
+ §5's distinction is structural: no query can accidentally conflate purchaser and beneficiary.
+ §10 becomes a lookup on `parent_student`, not an inference.
+ Young children are managed entirely by guardians without creating unused accounts (and
  without exposing them to a login they cannot manage).
− Joins are needed to go from a user to a student/parent. Mitigated by named scopes and eager
  loading; the join is always on a unique index.
− "Which profile is this user acting as?" must be resolved per request. Handled by
  `ChildContext` (parent portal) and by area middleware elsewhere.

**Reversal cost.** Very high — it shapes every table. Decided first, before any migration.

---

## Appendix — decision → spec traceability

| ADR | Spec sections |
|-----|---------------|
| 01 Modular monolith | §4, §91, §98 |
| 02 Money | §61, §41, §89, §95 |
| 03 Entitlements | §5, §19, §38, §45 |
| 04 Minor purchase guard | §10, §34, §57, §90 |
| 05 Video providers | §14, §36, §37, §93 |
| 06 Paystack | §41, §42, §43, §72, §93 |
| 07 Unified assessments | §21, §22, §23, §25 |
| 08 Time & timezone | §32, §62, §14 |
| 09 RBAC | §6, §28, §30, §49, §57, §81 |
| 10 Files & downloads | §40, §57, §58, §59 |
| 11 Minors' data protection | §9, §26, §58, §77, §83 |
| 12 Dual audit | §9, §29, §44, §56 |
| 13 Shared-hosting infra | §3, §63–§65, §69, §78, §96, §98 |
| 14 Centralized rules + DTOs | §4, §68, §90, §99, §101 |
| 15 Identity separation | §5, §6, §8, §9, §10, §34 |
