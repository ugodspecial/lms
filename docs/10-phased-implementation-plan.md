# 10 — Phased Implementation Plan

**Deliverable:** spec §102.10 — *"A phased implementation plan."* Implements §91 (11 phases)
and §92 (the mandatory 13-step process per phase).

**Governing constraints**

- **C1 — The app is runnable after every phase** (§91). No phase leaves migrations broken,
  routes 500ing, or tests red.
- **C2 — The §92 loop is followed for each phase, in order:** explain architecture → DB
  changes → models → business rules → permissions → UI screens → tests → implement → run
  tests → fix → security review → authorization review → proceed.
- **C3 — No silent skips** (§92). A failing test blocks the phase. A skipped test must carry a
  written reason and a tracking issue.
- **C4 — Definition of done per feature** (§101): migrations, models, relationships,
  validation, authorization, business rules, UI, error handling, tests, notifications, audit,
  mobile responsiveness, security review, docs.
- **C5 — Verification is executed, not assumed.** Because the build sandbox has no PHP
  (§00 §3), each phase ends with a CI run on `arena/01a07c5b-lms` whose output is read back
  and acted on.

**Phase exit gate (identical for every phase):**

```
✔ composer install resolves            ✔ php artisan migrate:fresh --seed --force
✔ php artisan test → 100% pass         ✔ php artisan pint --test → clean
✔ vendor/bin/phpstan analyse → clean   ✔ php artisan route:list → no conflicts
✔ php artisan platform:doctor → ok     ✔ npm run build → assets compile
✔ every new permission has a policy + test
✔ every new screen has an empty state, a loading state and an error state
✔ mobile viewport check on every new screen (§63)
✔ security review notes + authorization review notes appended to docs/phase-reports/
```

---

## Phase 0 — Scaffold & toolchain (prerequisite, not in §91)

The repo contains only a LICENSE. Phase 0 makes it a real Laravel 13 application.

| §92 step | Content |
|---|---|
| **Architecture** | Composer skeleton pinned to `laravel/framework: ^13.17`, `php: ^8.3`. PSR-4 map for `app/Domain/*`. Vite 8 + Tailwind 4 + Alpine. PHPUnit 12, Pint, Larastan. GitHub Actions CI with a MySQL 8 service container |
| **Database** | `config/database.php` for MySQL 8 + MariaDB; `utf8mb4_unicode_ci`; strict mode; a `.env.testing` using a dedicated CI database |
| **Models** | none yet |
| **Business rules** | none yet |
| **Permissions** | none yet |
| **UI** | Tailwind 4 `@theme` design tokens (colour, spacing, radius, type scale, shadows) in `resources/css/app.css`; base layouts stubbed so Phase 1 has a shell to build on |
| **Tests** | `tests/TestCase.php`, `phpunit.xml`, one smoke test asserting the app boots and `/` returns 200 |
| **Files created** | `composer.json`, `package.json`, `vite.config.js`, `artisan`, `bootstrap/app.php`, `bootstrap/providers.php`, all `config/*.php`, `public/index.php`, `public/.htaccess`, `.env.example`, `.env.testing`, `.gitignore`, `.editorconfig`, `phpunit.xml`, `pint.json`, `phpstan.neon`, `.github/workflows/ci.yml`, `README.md`, `SETUP.md`, `deploy/cpanel/*` |

**Exit:** CI green on a bare Laravel 13 app; `platform:doctor` command stub exists and reports
PHP version, extensions, DB connectivity, storage writability.

**Decision required (D2, §00 §4):** the skeleton is **hand-written** because Composer cannot
run in this sandbox. It is written to be byte-compatible with `composer create-project
laravel/laravel:^13.0` plus our additions, so `composer install` on a real machine resolves
cleanly. If you prefer, run `composer create-project` yourself and I will layer onto it.

---

## Phase 1 — Foundation (§91.1)

| §92 step | Content |
|---|---|
| **Architecture** | Four layers established. `Identity` + `Administration` domains. Fortify for password/2FA/verification/reset (headless, so the Blade/Livewire UI stays ours). spatie/laravel-permission v8 for RBAC. Policy registration in `AuthServiceProvider` with the Super-Admin `Gate::before`. Middleware: `AuthenticateArea`, `EnsurePermission`, `SetUserTimezone`, `SecurityHeaders`. Design system (`ui.*` Blade components). Audit + settings + files services |
| **Database changes** | 20 tables: `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `notifications`, `personal_access_tokens`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `connected_accounts`, `consents`, `settings`, `audit_logs`, `files` |
| **Models** | `User`, `ConnectedAccount`, `Consent`, `Setting`, `AuditLog`, `File` (+ spatie's `Role`, `Permission`) |
| **Business rules** | `SettingsService` (typed casts, single cache key, audit on change) · `AuditLogger` + `SensitiveDataScrubber` · `FileService` + `DownloadAuthorizer` (visibility classes) · `TwoFactorPolicy` (privileged roles must have 2FA) · `OAuthLinkingService` · user-status transitions |
| **Permissions** | 214 permissions seeded from the `Permissions` registry; 11 roles; area-access permissions (`admin.panel.access`, `parent.portal.access`, `student.portal.access`, `tutor.portal.access`, `evaluator.portal.access`) |
| **UI screens** | Design-system showcase · auth: login, register, forgot/reset password, verify email, 2FA challenge · `/settings/profile`, `/settings/security`, `/settings/sessions`, `/settings/notifications` · Admin: dashboard shell, Users (list/detail/suspend/impersonate), Access → Roles, Access → Permissions, Settings (tabbed), Audit log viewer, Files/Health · error pages 403/404/419/429/500/503 · five portal layout shells · `/demo-login` (non-production only) |
| **Tests** | ~70. Registration, verification, reset, throttling, user-enumeration, Google/Microsoft OAuth (Socialite faked), 2FA + recovery codes, session revocation, role/permission CRUD + sync, permission-gated navigation, settings (incl. secret-never-exposed), audit redaction, file upload validation, secure download (visibility × 4, rate limit, guessable URL), impersonation audit, area access × 5 portals, error pages, `platform:doctor` |
| **Security review** | password hashing + Fortify config, session cookie flags, CSRF coverage, rate limits on all auth routes, header middleware, `.env`/secret handling, mass-assignment allowlists, upload MIME/extension/size validation |
| **Authorization review** | every admin route mapped to a permission; `Gate::before` limited to Super Admin; no `hasRole()` in controllers/components; impersonation cannot target a Super Admin unless you are one |

**Exit:** a Super Admin can log in, manage roles/permissions/settings/users/files, and see an
audit trail. All five portal shells exist and correctly refuse unauthorized users.

---

## Phase 2 — Education Core (§91.2)

| §92 step | Content |
|---|---|
| **Architecture** | `Education` domain: models, enums, `DateRange`/`AcademicPeriod` VOs, services (`AcademicCalendarService`, `CohortService`, `SessionGenerator`, `AttendanceService`), actions per use-case, policies per aggregate. `Administration`'s `GlobalSearch` + `CsvImporter` get their first providers. Admin area becomes real |
| **Database changes** | 22 tables (§04 §2): academic years/terms/levels, programs, program_courses (deferred course FK), subjects, course_subject, students, parents, parent_student, student_applications + documents + events, program_enrollments, cohorts, cohort_student, course_schedules, course_sessions, attendance_records, non_working_dates, announcements + targets |
| **Models** | `AcademicYear`, `AcademicTerm`, `AcademicLevel`, `Program`, `Subject`, `Student`, `Parent`, `ParentStudent`, `StudentApplication`, `ProgramEnrollment`, `Cohort`, `CohortStudent`, `CourseSchedule`, `CourseSession`, `AttendanceRecord`, `NonWorkingDate`, `Announcement` |
| **Business rules** | exactly-one-active academic year (transaction + `lockForUpdate`) · non-overlapping terms · `student_code` generation from a configurable pattern · age computation from DOB (used later by §10) · guardian-link capabilities · admissions state machine + conversion (idempotent) · enrollment uniqueness · cohort capacity · session generation from schedules with holiday suppression (idempotent per schedule+date) · attendance uniqueness + correction window · audience resolution for announcements · **authorization-before-filtering** search scope |
| **Permissions** | `students.*` (14), `parents.*` (11), `applications.*` (8), academic-structure (22), scheduling & attendance (13), `announcements.*`, `holidays.manage`, `imports.*`, `exports.run` |
| **UI screens** | Admin → Academics: Years, Terms, Levels, Programs (+ learning-path builder), Subjects · Admin → Students: list (search/filter/sort/paginate), detail (profile, documents, guardians, enrollments, cohorts, notes), create/edit, status change, bulk actions · Admin → Guardians: list, detail (children + capabilities), invite, link/unlink · Admin → Admissions: pipeline board + table, application detail, reviewer assignment, decision, convert · Admin → Cohorts: list, detail, members (+ bulk enroll), schedule, sessions · Session detail → attendance grid · Public `/admissions/apply` (multi-step) · Parent → Children (+ add child) · Global search (⌘K) · Imports (upload, mapping, validation report, commit) |
| **Tests** | ~110. Unit: date ranges, student-code generation, age calculation, session generation (incl. DST + holidays), audience resolution, search scoping. Feature: CRUD for every aggregate, one-active-year guard, term overlap, admissions 8-state machine + history, conversion idempotency, enrollment uniqueness, cohort capacity, bulk enrollment **per-record authorization**, attendance uniqueness + correction audit, announcements, CSV import validation + error report + rollback. Authorization: Parent A ↛ Student B, Student A ↛ Student B, evaluator has no education-write access |
| **Security review** | minors' data classified `restricted`; notes/documents not exposed in list responses; guardian-link changes audited; import files stored privately and virus/extension checked; search never returns fields the role lacks |
| **Authorization review** | every policy method has a scoped test; `students.view_sensitive` separated from `students.view`; Finance Officer gets a narrowed column set |

**Exit:** the full academic spine works end-to-end: year → term → program → subject → student →
guardian → application → enrollment → cohort → schedule → sessions → attendance.

---

## Phase 3 — LMS (§91.3)

| §92 step | Content |
|---|---|
| **Architecture** | `Lms` domain. `CourseAccessResolver` becomes the single access authority (enrollment OR cohort OR entitlement OR staff OR free preview). Progress pipeline: events → queued recalculation → denormalized `course_progress`. Certificate pipeline: completion event → queued dompdf render with QR → `files` |
| **Database changes** | 13 tables (§04 §3) + `add_course_id_to_cohorts_table` + `program_courses` FK now resolvable |
| **Models** | `Course`, `CourseModule`, `Lesson`, `LessonTopic`, `LessonResource`, `CourseEnrollment`, `CourseProgress`, `LessonProgress`, `LearningActivity`, `Discussion`, `DiscussionPost`, `CertificateTemplate`, `Certificate` |
| **Business rules** | publishing workflow (draft→review→published⇄unpublished→archived; only published enrollable/purchasable) · lesson access via the resolver · completion rules (all_lessons / percent / assessments_passed) · progress calculation + recalculation · learning-path prerequisites + required/optional · discussion participation limited to enrolled users · certificate number sequencing + unguessable verification codes · **public verification exposes name/title/issuer/date only** · HTML sanitization on write and render |
| **Permissions** | `courses.*`, `course_modules.manage`, `lessons.manage`, `lesson_resources.manage`, `courses.enroll_students`, `progress.*`, `discussions.*`, `certificates.*`, `announcements.create` (tutor-scoped) |
| **UI screens** | Public: `/courses` catalogue, course detail, free-preview lesson · Admin/Tutor: course builder (drag-reorder modules/lessons), lesson editor per content type, resource manager, publish workflow · Student: `/learn` dashboard, `/learn/courses`, lesson player (text/video/audio/document/embed/external), mark-complete, resources, discussions · Parent: child's learning + progress · Certificates: templates, issued list, student's certificates, **public `/certificates/verify/{code}`** |
| **Tests** | ~95. Unit: `CourseAccessResolver` (every branch), `ProgressCalculator`, completion rules, prerequisite evaluation, certificate number generation. Feature: builder + reorder integrity, sanitization/XSS, publishing states, enrollment sources, lesson access (incl. free preview), progress events + recalculation job, discussions + moderation, certificate issuance + PDF render + QR, public verification (incl. revoked, unknown code, rate limit, no private data leaked) |
| **Security review** | stored XSS in lesson bodies and embeds; download authorization for resources; certificate verification enumeration resistance; unpublished content invisible to all queries |
| **Authorization review** | a student cannot reach a lesson by direct URL; a tutor cannot edit another tutor's course; public routes expose only published content |

**Exit:** real online learning works: a published course with modules, lessons of all six
content types, resources, discussions, progress tracking, and verifiable certificates.

---

## Phase 4 — Academic Management (§91.4)

| §92 step | Content |
|---|---|
| **Architecture** | `Assessment` domain with the unified `assessments` umbrella + 1:1 `quiz_settings` / `assignment_details`. Pure value objects `Score`, `Percentage`, `Grade`. `GradeResolver` and `QuizScorer` as pure functions. `GradebookAggregator` for term results. Submission versioning for resubmission integrity. Question snapshots for randomization integrity |
| **Database changes** | 12 tables (§04 §4) |
| **Models** | `Assessment`, `QuizSettings`, `AssignmentDetails`, `Question`, `QuestionOption`, `AssessmentQuestion`, `AssessmentSubmission`, `SubmissionAnswer`, `GradingScheme`, `GradingScaleBand`, `AcademicResult`, `AcademicReport` |
| **Business rules** | 6 question types + auto-scoring with partial credit · server-side timer and attempt limits · late policy (reject / penalty / accept) · resubmission versioning with a single `is_current` · grading scheme validation (contiguous, non-overlapping bands) · grade resolution with a loud failure on a missing band · weighted gradebook aggregation · publication immutability + audited amendments · at-risk detection from configurable thresholds · report-card generation |
| **Permissions** | `assessments.*`, `questions.manage`, `submissions.*` (view/grade/return/reopen), `grading_schemes.*`, `grades.*` (view/publish/amend), `academic_results.generate`, `report_cards.generate` |
| **UI screens** | Admin/Tutor: assessment list + builder, question bank, quiz settings, assignment settings, grading queue, gradebook, results publication, grading schemes editor, report cards · Student: assessments (open/due/done), quiz player (timer, navigation, autosave), assignment submission (upload + text), results · Parent: child's results + report card download · Reports: academic performance, at-risk |
| **Tests** | ~110. Unit: `QuizScorer` per question type + partial credit, `GradeResolver` (incl. missing band, boundary values), `Score`/`Percentage` VOs, late-policy calculation, gradebook weighting, at-risk rules. Feature: authoring, publishing, attempt lifecycle, timer enforcement, attempt limits, snapshot stability under question edits, submission + late detection, resubmission versioning, grading + feedback + return, reopen audit, results publication + immutability + amendment, report card PDF, parent/student visibility flags. Authorization: Student A ↛ Student B submission; a tutor cannot grade an assessment they don't assessor; a parent without `can_view_academics` sees nothing |
| **Security review** | answer leakage (correct answers never serialized to the client before results are released), upload validation for submissions, timing of result publication, restricted visibility of graded work |
| **Authorization review** | assessor scoping; `grades.amend` audited; parents gated by both the capability flag and the assessment's `results_visible_to_parents` |

**Exit:** the complete assessment lifecycle — author, attempt, score, grade, aggregate,
publish, report — with configurable grading and immutable academic records.

---

## Phase 5 — Parent & Student Portals (§91.5)

| §92 step | Content |
|---|---|
| **Architecture** | Portal-first presentation: a shared `ChildContext` resolver so every parent query is scoped by the selected child and re-authorized per request. Unified calendar query object with role-based event sources. Notification layer: `message_templates` + `notification_preferences` + Laravel notifications (database + mail), queued and deduplicated |
| **Database changes** | `message_templates`, `notification_preferences`, `pages`, `faq_categories`, `faqs` (5 tables) |
| **Models** | `MessageTemplate`, `NotificationPreference`, `Page`, `FaqCategory`, `Faq` |
| **Business rules** | child switching + deep-link re-authorization · capability-gated visibility (`can_view_academics`, `can_view_financials`) · role-scoped calendar composition (§53) · transactional notifications cannot be disabled (§82) · marketing opt-in defaults false and is filtered at dispatch (§83) · audience resolution · reminder deduplication via `ShouldBeUnique` |
| **Permissions** | portal-access permissions wired to real content; `reports.view` (parent/student scoped); template + page + FAQ management |
| **UI screens** | **Parent:** dashboard (children cards, upcoming, payments due, recent grades, announcements), child switcher, Academics, Attendance, Learning/Progress, Assignments, Schedule/Calendar, Tutoring, Billing (orders/invoices/payments/subscriptions), Library (digital products + downloads), Notifications, Settings · **Student:** dashboard, My Courses, My Learning, Assignments, Quizzes, Exams, Results, Progress, Schedule, Tutoring, Certificates, Notifications, Settings · **Shared:** unified calendar (month/week/agenda), notification bell + inbox, CMS pages, FAQs · All screens mobile-first with intentional responsive navigation (§63, §64) |
| **Tests** | ~85. Feature: every parent and student screen with real seeded data (asserting **no placeholder numbers**, §94) · child switching + unlinked-child rejection · capability gating × 4 combinations · calendar role scoping × 5 roles · notification preferences incl. transactional read-only · marketing default-false · digest delivery · responsive smoke tests (Livewire renders at mobile breakpoints) |
| **Security review** | every parent/student query reviewed for scoping; no route accepts a student id without a policy check; IDOR sweep across both portals |
| **Authorization review** | the full §71 negative suite passes: Parent A ↛ Student B, Student A ↛ Student B, guardian without `can_view_financials` ↛ invoices |

**Exit:** the two most-used portals are complete, fast on mobile, and provably scoped.

---

## Phase 6 — Tutor Recruitment (§91.6)

| §92 step | Content |
|---|---|
| **Architecture** | `Tutoring` domain, recruitment half. Versioned evaluation forms (frozen at submission). `ApplicationWorkflow` owns the 9-state machine and every transition writes an immutable event. `ApproveTutor` is a single transactional action that creates the profile, links/creates the user and grants permissions |
| **Database changes** | 13 tables: `tutor_applications`, `_documents`, `_events`, `_subjects`, `tutor_qualifications`, `tutor_work_experiences`, `tutor_references`, `evaluation_criteria`, `evaluation_forms`, `evaluation_form_criteria`, `tutor_interviews`, `tutor_evaluations`, `evaluation_criterion_scores` (+ `tutor_profiles`, `tutor_subjects` created here so approval can complete) |
| **Models** | `TutorApplication`, `TutorApplicationDocument`, `TutorApplicationEvent`, `TutorQualification`, `TutorWorkExperience`, `TutorReference`, `EvaluationCriterion`, `EvaluationForm`, `EvaluationFormCriterion`, `TutorInterview`, `TutorEvaluation`, `EvaluationCriterionScore`, `TutorProfile`, `TutorSubject` |
| **Business rules** | application state machine + immutable history · evaluator assignment scoping (evaluators see only assigned applications, §28) · document-view auditing · evaluation form versioning + criterion range validation · weighted evaluation scoring · recommendation roll-up **without** auto-approval · approval atomicity (profile + user + role + permissions in one transaction) · rejection creates no profile and never becomes bookable (§30) · **evaluators cannot approve** (§28) · public directory listing rules (approved + opted in, §87) |
| **Permissions** | `tutors.applications.*` (7), `tutors.interviews.*` (3), `tutors.evaluations.*` (2), `tutors.approve`, `tutors.reject`, `evaluation_forms.manage`, `tutors.*`, `tutor.profile.update_own`, `evaluator.portal.access` |
| **UI screens** | Public: `/tutors/apply` (multi-step with autosave), `/tutors` directory (filters: subject, level, language, experience, price, availability, rating), `/tutors/{slug}` · Evaluator: dashboard (assigned, pending reviews, upcoming/completed interviews, recommendations, history — **no financial widgets**), application review workspace, interview scheduling, evaluation form · Admin: recruitment pipeline (board + table with filters), application detail, assignment, approval/rejection dialogs, evaluation forms CRUD, tutor list, tutor detail (private view), suspend · Tutor: profile editor, subjects, public-preview |
| **Tests** | ~100. Unit: state machine transitions (legal/illegal), evaluation weighted scoring, form version freezing, eligibility rules. Feature: application form + validation + autosave, document upload (private visibility), assignment scoping, review workspace, interview scheduling, evaluation submission + immutability + duplicate prevention, approval (atomicity, role grant, profile activation, audit, notification), rejection (no profile, not bookable), further-review loop, withdrawal, directory listing rules, public profile field exposure. **Authorization:** evaluator ↛ financial data (every finance route 403), evaluator ↛ `tutors.approve`, evaluator sees only assigned applications, Tutor A ↛ Tutor B private data |
| **Security review** | CV/certification files `private`; document reads audited; no PII in the public directory beyond opted-in fields; rejection reasons not leaked to other applicants |
| **Authorization review** | §28 and §49 proven by test, not by seeder config alone |

**Exit:** the full recruitment funnel works, with approval authority correctly separated from
evaluation authority, and a public directory that exposes nothing private.

---

## Phase 7 — Tutoring Delivery (§91.7)

| §92 step | Content |
|---|---|
| **Architecture** | `Tutoring` delivery half + `Integration` domain. `VideoMeetingProvider` contract with `GoogleMeetProvider` (Calendar API + `conferenceDataVersion=1`), `ZoomProvider` (user-managed OAuth **and** S2S), `ManualProvider`. `MeetingManager` resolves, persists, retries, falls back. `SlotFinder` is the scheduling engine; double-booking is prevented by transaction + `lockForUpdate` **and** `UQ(tutor_id, starts_at)` |
| **Database changes** | 12 tutoring tables (`tutor_availabilities`, `tutor_unavailable_dates`, `tutoring_services`, `tutoring_service_tutors`, `tutoring_packages`, `tutoring_bookings`, `tutoring_sessions`, `session_notes`, `tutor_reviews`, `tutor_earnings`) + 2 integration tables (`meetings`, `integration_logs`) |
| **Models** | `TutorAvailability`, `TutorUnavailableDate`, `TutoringService`, `TutoringServiceTutor`, `TutoringPackage`, `TutoringBooking`, `TutoringSession`, `SessionNote`, `TutorReview`, `TutorEarning`, `Meeting`, `IntegrationLog` |
| **Business rules** | availability windows (wall-clock local + IANA timezone) · slot finding (availability − blocked − holidays − existing bookings − buffer − min notice − max advance, converted across three timezones) · double-booking prevention · booking lifecycle with slot holds + expiry release · cancellation window + fee policy · session materialization + lifecycle (incl. no-show) · session notes visibility tiers · review eligibility (completed session, participant only, one per reviewer, no self-review) + moderation · rating aggregate maintenance · earnings ledger (gross/fee/share/tax/net/payout status) with snapshotted fee policy · meeting provisioning incl. Google's **async** conference creation (`provisioning → ready`) and honest fallback when unconfigured/unsupported |
| **Permissions** | `tutor.portal.access`, `tutor.availability.*`, `tutoring_services.*`, `tutoring_packages.manage`, `bookings.*`, `sessions.tutoring.manage`, `session_notes.manage`, `reviews.*` (6), `tutoring_services.view` (public), `integration_logs.view` |
| **UI screens** | Public: tutor profile → services → availability → book · Parent: booking wizard (child → subject/service → tutor → slot → review → pay/confirm), My Tutoring (bookings, sessions, notes, cancel/reschedule), review composer · Student: My Tutoring, upcoming sessions, join, notes · Tutor: dashboard (upcoming classes + sessions, students, cohorts, to-grade, availability, calendar, earnings, reviews), availability editor (weekly grid + blocked dates), service/package manager, session workspace (join, attendance, notes, homework, progress, complete), earnings · Evaluator/Admin: interview meetings reuse the same `meetings` component · Integrations: connect/disconnect Google & Zoom, provider status |
| **Tests** | ~120. **Unit:** `SlotFinder` (many cases: buffer, min notice, max advance, holidays, blocked dates, existing sessions, DST boundary, timezone conversion), `BookingConflictDetector`, `EarningsCalculator`, `ReviewEligibility`, cancellation fee policy, availability expansion. **Feature:** availability CRUD, service/package CRUD, booking (parent-for-child records both parties), adult self-booking, minor self-booking blocked, admin-assisted booking, slot-hold expiry, session lifecycle, cancellation + reschedule, notes + visibility, attendance, reviews (eligibility, duplicates, self-review, moderation, aggregate), earnings ledger, meetings for Google/Zoom/Manual (all faked at the HTTP layer), fallback when unconfigured, interview meetings. **Authorization:** Tutor A ↛ Tutor B sessions/earnings/availability; parent ↛ another child's booking; student ↛ another student's session |
| **Security review** | host/start URLs encrypted and never shown to participants; join URLs scoped to participants; OAuth tokens encrypted at rest; provider payloads redacted before logging; no secret reaches a Blade view or JS bundle |
| **Authorization review** | every session/booking/earnings route scoped; `tutoring_services.view` public exposure limited to published+active services and public tutor fields |

**Exit:** a parent can find a tutor, pick a real available slot, book it, get a working
Meet/Zoom link, and the tutor can deliver, record notes, mark attendance and see earnings.

---

## Phase 8 — Commerce (§91.8)

| §92 step | Content |
|---|---|
| **Architecture** | `Commerce` domain + the Paystack adapter. **`CheckoutService` is the single choke point** for web, admin-assisted and (future) API checkouts — this is what makes §10 enforceable once. `EntitlementGranter` is the only writer of entitlements. `MinorPurchaseGuard` and `CourseAccessResolver` are the two rules that connect commerce to education. Webhook pipeline: signature middleware → idempotency insert → 200 → queued handler |
| **Database changes** | 21 tables (§04 §6) + `add_entitlement_reference_to_course_enrollments` + `add_entitlement_reference_to_program_enrollments` |
| **Models** | `ProductCategory`, `Product`, `DigitalProduct`, `DigitalProductFile`, `Cart`, `CartItem`, `Order`, `OrderItem`, `OrderEvent`, `Invoice`, `InvoiceItem`, `Payment`, `PaymentEvent`, `Refund`, `Coupon`, `CouponRedemption`, `TaxRate`, `Entitlement`, `EntitlementEvent`, `EntitlementConsumption`, `DigitalProductDownload` |
| **Business rules** | `Money` VO arithmetic (integer minor units, currency-mismatch errors, allocation) · server-side pricing (client amounts ignored) · coupon validation (scope, limits, caps, expiry, per-user) · tax resolution + per-line totals · **★ minor purchase guard** (age + service type + purchaser relationship, fail-safe on unknown DOB, per item, per channel) · order state machine + snapshots · Paystack initialize/verify · **verification-before-value** (a redirect never marks an order paid) · webhook signature verification (raw body, HMAC-SHA512, `hash_equals`) · **four-layer idempotency** · entitlement granting + expiry + revocation · download authorization chain (authenticated → purchased → payment confirmed → entitlement active → limit → expiry) + ledger + rate limit · refunds with entitlement revocation (and consumed sessions preserved) · reconciliation of stuck orders · invoice numbering + PDF |
| **Permissions** | `products.*` (5), `product_categories.manage`, `digital_products.manage`, `orders.*` (4 + `admin_assisted_checkout`), `commerce.override_minor_restriction`, `payments.*` (4), `invoices.*` (3), `coupons.manage`, `tax_rates.manage`, `entitlements.*` (4), `downloads.*` (2), `subscriptions.view` |
| **UI screens** | Public: `/store` (categories, search, filters, sort), product detail (digital/service/course), sample download · Cart + checkout (items, beneficiaries, coupon, tax, total, Paystack redirect), callback "verifying…" screen · Parent: Billing (orders, order detail + timeline, invoices + PDF, payment history, library/downloads) · Student: store + own purchases (adults) · Admin: Products (list/editor/publish), Categories, Digital products, Orders (filters: status/customer/date/payment status; timeline; cancel), Payments (list, detail, verify, reconcile, refund dialog), Invoices, Coupons, Tax rates, Entitlements (list, grant manual, revoke, extend), Downloads ledger · Finance reports preview |
| **Tests** | ~140 — the largest suite. **Unit:** `Money` (arithmetic, currency mismatch, allocation, rounding), `OrderTotalsCalculator`, `CouponValidator`, `EarningsSplitter`, `MinorPurchaseGuard` (8 cases), `DownloadAuthorizer`, entitlement expiry. **Feature:** catalogue + publishing, cart (snapshot, revalidation, beneficiary), checkout (happy path, coupon, tax, guest→login merge), Paystack initialize (secret never in the response), callback shows pending only, verification (success, failure, amount mismatch, unknown reference), entitlement granting (+ idempotency), course access via entitlement, digital download (all six gates + limit + expiry + ledger + rate limit), invoices + PDF, refunds (full, partial, consumed sessions preserved), coupons (limits, scope, expiry, cap), reconciliation, order administration + filters, admin-assisted checkout. **Payment suite (§72):** successful transaction, failed transaction, duplicate webhook ×3, invalid signature, verification failure, order already paid, refund, entitlement creation — all with `Http::fake()` + committed fixtures, **never live keys** |
| **Security review** | secret-key exposure sweep (no `PAYSTACK_SECRET_KEY` in any response, view, JS bundle or log), webhook signature + idempotency, amount/currency validation on verify, price tampering (client amounts ignored), coupon abuse, download URL guessing + rate limiting, refund authorization, mass assignment on money models (`$guarded`), PCI-adjacent data never stored (Paystack hosted checkout means no card data touches us) |
| **Authorization review** | customers see only their own orders/payments/invoices; beneficiaries' guardians see child orders per `can_view_financials`; `commerce.override_minor_restriction` is Super-Admin-only and always audited; evaluators have zero commerce access |

**Exit:** money moves correctly and safely: a purchase creates an entitlement that grants real
educational access, webhooks are replay-proof, downloads are authorized, refunds revoke access.

---

## Phase 9 — Subscriptions (§91.9)

| §92 step | Content |
|---|---|
| **Architecture** | Subscription manager layered on the same `PaymentGateway` contract. Plan sync to Paystack. Webhook handlers for `subscription.create` / `subscription.disable` and renewal `charge.success`. Entitlements are **extended**, never duplicated. Recurring tutoring consumes one session per cycle through `entitlement_consumptions` |
| **Database changes** | `subscriptions`, `subscription_events` (2) + `invoices.subscription_id` + `products` subscription columns (already present from Phase 8) |
| **Models** | `Subscription`, `SubscriptionEvent` |
| **Business rules** | subscription state machine (active/past_due/cancelled/expired/paused/pending) · plan creation/sync · **minor guard applies to subscriptions** · renewal → new invoice + payment + entitlement extension + session replenishment · dunning (retry schedule, grace period from `settings`, suspension after grace) · cancellation with access retained to `ends_at` · expiry sweep · `UQ(provider, provider_subscription_id)` idempotency · recurring session booking + `UQ` consumption guard |
| **Permissions** | `subscriptions.view`, `subscriptions.manage` (parent self-cancel), admin `subscriptions.manage` |
| **UI screens** | Public/parent: subscription products, subscribe flow (with beneficiary selection), confirmation · Parent → Billing → Subscriptions (status, next billing, invoices, cancel, payment method) · Admin → Commerce → Subscriptions (filters by status/customer/next billing, detail + event timeline, manual correction audited), Plans sync · Tutor: recurring session schedule |
| **Tests** | ~55. Unit: renewal date arithmetic, dunning schedule, grace-period logic. Feature: subscribe (parent-for-child), minor guard on subscription, `subscription.create` webhook (+ duplicate blocked), renewal (invoice + payment + entitlement extended not duplicated + sessions replenished), `charge.failed` → past_due → dunning notifications → suspension after grace, cancellation (provider call + local state + access retained to period end), expiry sweep, recurring session consumption (incl. double-consume blocked), admin management. Payment suite: subscription cancellation (§72) |
| **Security review** | provider subscription ids unique per provider; no privilege escalation via renewal; authorization codes stored encrypted; cancellation cannot be forged by replaying a webhook |
| **Authorization review** | customers manage only their own subscriptions; a guardian's cancellation affects only their own child's entitlement |

**Exit:** "Monthly Mathematics Tutoring" bills, renews, fails gracefully, and cancels cleanly —
with the minor rule enforced on the recurring path too.

---

## Phase 10 — Reporting & Analytics (§91.10)

| §92 step | Content |
|---|---|
| **Architecture** | `ReportBuilder` with one report class per report, each declaring: permission, scope filter, columns, aggregations, filters, export format. Reports run on read-replica-friendly queries and are **queued** for exports. Bulk operations reuse per-record authorization. CSV import/export extended to grades, attendance, products, cohort members |
| **Database changes** | `import_batches`, `import_batch_rows` (2) — report definitions are code, not tables (they change with releases, not with data) |
| **Models** | `ImportBatch`, `ImportBatchRow` |
| **Business rules** | report scoping (every report applies an authorization scope before aggregating) · at-risk thresholds from `settings` · rating/performance aggregation · revenue recognition rules (paid orders only, refunds deducted, test-mode excluded from live revenue) · completion-rate calculation · import validation-then-commit + per-row error reporting + rollback · bulk-operation per-record authorization + partial-success reporting |
| **Permissions** | `reports.*` (12), `imports.run`, `imports.view`, `exports.run`, `reports.build_scheduled` |
| **UI screens** | **Admin dashboard** (§50): all 16 stat cards + charts (students, active students, parents, tutors, pending applications, upcoming interviews, active courses, active cohorts, enrollments, attendance, revenue, orders, subscriptions, digital sales, completion, performance, tutor performance) — all live queries · Reports hub with per-domain sections (Students, Parents, Tutors, Finance, Education, LMS), each with date range/status/program/course/cohort filters, sortable columns, pagination, CSV/PDF export · Bulk actions UI on every admin table · Imports hub (type selector, upload, column mapping, validation preview, error report download, commit) · Scheduled report delivery setup |
| **Tests** | ~80. Unit: revenue recognition (refunds, test-mode exclusion, partial refunds), completion rate, at-risk thresholds, rating aggregation. Feature: every report with seeded data + expected totals, date-range and filter correctness, export generation + secure download, admin dashboard widget-by-widget against the DB (**no placeholder data**, §94), report permission enforcement per role, bulk operations with mixed authorization (partial success), imports for all four types (valid, invalid, mixed, rollback, error report), scheduled delivery |
| **Security review** | report exports go through the secure file route (never a temp public file); finance reports exclude test-mode transactions from live revenue; aggregate reports do not leak individual minors' data to roles lacking `students.view_sensitive` |
| **Authorization review** | **Finance reports return 403 for evaluators** (§49); each report's scope filter is asserted with a role that should see a subset |

**Exit:** administrators can answer every operational and financial question from the UI, with
exports, and every number is a real query.

---

## Phase 11 — Hardening (§91.11)

| §92 step | Content |
|---|---|
| **Architecture** | No new features. Systematic review: authorization coverage, security controls, query performance, test coverage, documentation, deployment rehearsal |
| **Database changes** | Index additions from `EXPLAIN` review of the 20 hottest queries; `CHECK` constraint additions; partitioning candidates documented (not applied) |
| **Models** | none new |
| **Business rules** | every rule in §90 verified to live in a domain service/action — `CanStudentPurchaseTutoring`, `CanParentPurchaseForStudent`, `CanBookTutor`, `CanAccessCourse`, `CanDownloadProduct`, `CanPublishCourse`, `CanApproveTutor`, `CanEvaluatorReviewApplication`, `CanSubmitAssessment` — each with a named unit test |
| **Permissions** | full matrix re-verified against routes; orphan permissions reported; unused permissions flagged |
| **UI** | empty states, loading skeletons, error states, confirmation dialogs for destructive actions, keyboard navigation, focus management, ARIA labels, contrast, mobile pass on every screen (§63, §64) |
| **Work performed** | **Authorization audit:** `platform:audit-authorization` maps every route + Livewire action → policy → permission and fails on gaps · **Security review:** the §10 checklist from [09](09-shared-hosting-deployment.md) executed and recorded · **Performance:** N+1 sweep (`Model::preventLazyLoading` in tests), `EXPLAIN` on hot queries, eager-loading fixes, pagination everywhere, cache review, 10k-student/100k-submission load sanity (dashboards < 500 ms) · **Testing:** coverage ratcheted to 80%, mutation-style review of the money and access paths, Larastan level 6→8 · **Docs:** deployment guide rehearsed on a real cPanel account, `SETUP.md`, `CONTRIBUTING.md`, ADRs updated, runbooks for payment incidents and webhook failures · **Backup:** restore drill executed |
| **Tests** | the full suite plus ~40 new: IDOR sweep across every route parameter, rate-limit tests, CSRF tests, header tests, log-redaction tests, concurrency tests (double booking, duplicate webhook, simultaneous checkout), retention/pruning commands, doctor-command assertions |
| **Security review** | recorded in `docs/phase-reports/11-security-review.md` with findings + resolutions |
| **Authorization review** | recorded in `docs/phase-reports/11-authorization-review.md`; **zero** unmapped routes permitted |

**Exit (the §101 definition of done, applied platform-wide):** every feature has migrations,
models, working relationships, validation, authorization, centralized business rules, UI,
error handling, tests, notifications, audit coverage, mobile responsiveness, a security
review and updated documentation. Coverage ≥ 80%, Larastan clean, Pint clean, CI green,
`platform:doctor` clean on a production-shaped environment, and a successful restore drill.

---

## Sequencing & dependencies

```
Phase 0 ─► Phase 1 ─► Phase 2 ─► Phase 3 ─► Phase 4 ─► Phase 5
                        │           │                     │
                        │           └── entitlement hooks reserved (nullable columns)
                        │                                 │
                        └────────────────► Phase 6 ─► Phase 7 ─► Phase 8 ─► Phase 9 ─► Phase 10 ─► Phase 11
                                                                    │
                                                    entitlement FKs added here
                                                    (course_enrollments, program_enrollments)
```

| Dependency | Handling |
|------------|----------|
| `cohorts.course_id` needs `courses` (Phase 3) but cohorts land in Phase 2 | Column added by a Phase 3 migration; the app is runnable at the Phase 2 boundary |
| `program_courses` needs `courses` | Same pattern |
| `*.entitlement_id` on enrollments needs `entitlements` (Phase 8) | Nullable columns added in Phase 8; `CourseAccessResolver` checks enrollment/cohort first and entitlement second, so Phase 3 works before commerce exists |
| Tutoring services need `products` to be sellable (Phase 8) | Phase 7 stores the price on `tutoring_services` directly; Phase 8 auto-creates the `products` wrapper and backfills |
| Assessments need `tutor_profiles.assessor_id` (Phase 6) but land in Phase 3 | Discriminator column (`assessor_type`) instead of an early FK |
| Portals (Phase 5) show tutoring data that lands in Phase 7 | Portal components are permission-gated and render nothing when the module is absent; Phase 7 fills them in |
| Subscriptions (Phase 9) need commerce (Phase 8) | Strictly sequential |

Each phase ships behind **feature flags** in `settings` (`features.tutoring_enabled`,
`features.commerce_enabled`, …) so a partially-configured deployment never shows a broken
feature — the entry point is simply hidden (§93).

---

## Effort & risk

| Phase | Relative size | Highest risk | Mitigation |
|-------|---------------|--------------|------------|
| 0 | S | Skeleton drift from a real `composer create-project` | Pin exact versions; CI resolves on the first push |
| 1 | L | RBAC design mistakes propagate everywhere | Permission registry in code + matrix reviewed before implementation |
| 2 | XL | Academic-calendar edge cases (terms, holidays, generation) | Unit-test the calendar service exhaustively before wiring UI |
| 3 | L | Stored XSS in lesson content | Sanitize on write **and** render; dedicated XSS tests |
| 4 | XL | Grading correctness + immutability | Pure `GradeResolver`/`QuizScorer` with property-style tests; publication immutability enforced at the DB level |
| 5 | L | IDOR across two portals | Systematic scoping review + the §71 negative suite |
| 6 | L | Approval authority leaking to evaluators | Permission-gated action + explicit test that evaluators 403 |
| 7 | XL | Timezone/DST scheduling + double-booking | `SlotFinder` unit-tested across DST boundaries; transaction + unique constraint |
| 8 | XL | Money correctness + webhook replay | Integer minor units; four-layer idempotency; recorded fixtures; the full §72 suite |
| 9 | M | Renewal duplication | Entitlement extension, never re-creation; `UQ` guards |
| 10 | L | Report accuracy + export performance | Queued, chunked exports; expected-total assertions on seeded data |
| 11 | M | Coverage claims without execution | CI is the arbiter; coverage ratchet cannot decrease |

---

## Working agreement

1. One phase at a time; the phase is not started until the previous exit gate is green.
2. At the start of each phase I post the §92 items 1–7 (architecture, DB, models, rules,
   permissions, screens, tests) **before** writing code, so you can correct course cheaply.
3. At the end of each phase I post: what was built, the CI result, test counts, the security
   review notes, the authorization review notes, and any deviations from this plan with
   reasons.
4. Nothing is marked complete on the strength of a UI page existing (§101).
5. Deviations from this document are recorded here with a dated note, not silently absorbed.

---

## Deviations log

Working agreement item 5. An entry is added when the build does something these
documents do not say, or when a document turns out to contradict itself or the
code. Corrections to the documents themselves are made in the same commit, so
this log records *why*, not *what is currently wrong*.

| Date | Phase | Deviation | Reason |
|------|-------|-----------|--------|
| 2026-09-08 | 0 | Pest 4 → PHPUnit 12; spatie/laravel-permission v7 → v8 | The Laravel 13 skeleton ships PHPUnit, and v8 is the line compatible with it. Documents corrected in `71e15dc`. |
| 2026-09-08 | 1 | `notification_preferences` is built in Phase 1, not Phase 5 | Phase 1 ships `/settings/notifications`. Rendering a toggle that stores nothing is a dead control (§63, item 4 of the constraints), so the table has to exist before the screen does. Cardinality follows docs/04 — `UQ(user_id, notification_key)`, one row per notification — not the ERD's one-to-one, which cannot express a different choice per notification. `message_templates` stays in Phase 5: Blade bodies are the documented fallback (docs/02 §3.7), not a shortcut. |
| 2026-09-08 | 1 | The permission registry holds **214** permissions, not 168 | docs/05 §3 summed its own sections to "214 permission slots, of which 168 distinct permission strings (some rows are scoped variants of the same string)". The matrix contains no such variants: all 214 rows are distinct strings, no string appears twice, and none appears in two groups. The sum beside the claim was right and the claim was stale. The principle it was protecting is kept — scoping lives in policies, and the 93 granted-but-scoped permissions are recorded with their per-role qualifier in `Permissions::SCOPED` rather than being split into near-duplicate permission names. docs/05, 04, 10, 11, README and AGENTS.md corrected. |
| 2026-09-08 | 1 | `FileVisibility` cases are `IsPublic` / `IsAuthenticated` / `IsPrivate` / `IsRestricted` | `case Public` and `case Private` are parse errors: PHP keywords are case-insensitive and enum cases are class constants. The stored values, and therefore the CHECK constraints and the docs/02 vocabulary, are unchanged. |
