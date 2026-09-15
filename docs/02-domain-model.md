# 02 — Domain Model

**Deliverable:** spec §102.2 — *"A domain model."* Covers §5 (personae), §67 (relationships).

---

## 1. The central modelling insight: a person is not a role

Spec §5 is the requirement that most LMS implementations get wrong:

> *"Do not assume that the person making a purchase is necessarily the student receiving the service."*

The model therefore separates **four distinct concepts** that a naive design collapses into
"User":

```
┌────────────────────────────────────────────────────────────────────────────┐
│ 1. USER          an authentication subject. Has credentials, roles,        │
│                  permissions, a timezone, notification preferences.        │
│                  May exist with NO profile at all (e.g. a Finance officer).│
│                                                                            │
│ 2. PROFILE       a domain participant: Student, Parent, TutorProfile,      │
│                  Evaluator, Staff. A User may hold SEVERAL profiles        │
│                  (a Tutor who is also a Parent; a Student who grows up     │
│                  and becomes a Tutor). A profile may exist with NO User    │
│                  (a 7-year-old student managed entirely by a parent;       │
│                  a guardian an admin records before they ever register).   │
│                                                                            │
│ 3. PARTICIPATION a person acting in a specific transaction or activity:    │
│                  Customer (purchaser), Beneficiary (recipient),            │
│                  Payer, Assessor, Instructor, Attendee, Reviewer.          │
│                  These are ROLES ON A RECORD, never tables of their own.   │
│                                                                            │
│ 4. RELATIONSHIP  an authorized link between participants:                 │
│                  ParentStudent (guardian ↔ child, with per-child           │
│                  capabilities: can_purchase, can_view_academics,           │
│                  can_view_financials, is_primary, consent records).        │
└────────────────────────────────────────────────────────────────────────────┘
```

### Consequence: every commercial record carries both sides

| Record | Purchaser / actor | Beneficiary |
|--------|-------------------|-------------|
| `orders` | `customer_user_id` | `beneficiary_student_id` (nullable — self-purchase) |
| `order_items` | inherits from order | `beneficiary_student_id` (**per item**, so one cart can serve two different children) |
| `entitlements` | `owner_user_id` | `beneficiary_student_id` |
| `tutoring_bookings` | `booked_by_user_id` | `student_id` |
| `tutoring_sessions` | `parent_id` (guardian of record) | `student_id` |
| `subscriptions` | `customer_user_id` | `beneficiary_student_id` |
| `invoices` | `billed_to_user_id` | `beneficiary_student_id` |

This single decision makes §10 (minors), §45 (entitlements) and §46 (parent portal)
implementable rather than bolted on.

### Profile cardinality

```
User 1 ──── 0..1 Student
     1 ──── 0..1 Parent
     1 ──── 0..1 TutorProfile
     1 ──── 0..1 EvaluatorProfile
     1 ──── 0..1 StaffProfile
     1 ──── 0..n ConnectedAccount   (google, microsoft, zoom)
     1 ──── 0..n Consent
     1 ──── 0..1 NotificationPreference
```

A `User` with a `Parent` profile and a `TutorProfile` is fully supported: the portal
switcher offers both, and each area's middleware authorizes independently.

---

## 2. Bounded contexts

| Context | Ubiquitous language (terms used in code, UI and DB) | Owns | Talks to |
|---------|------------------------------------------------------|------|----------|
| **Identity** | User, Credential, Role, Permission, Connected Account, Consent, Session | authentication, authorization subjects, OAuth links, 2FA, privacy consents | everyone (read) |
| **Education** | Academic Year, Term, Level, Program, Subject, Student, Guardian, Relationship, Application, Enrollment, Cohort, Schedule, Session, Attendance, Announcement | the academic spine: who is taught, what they study, when | Lms, Assessment, Tutoring, Commerce (read) |
| **Lms** | Course, Module, Lesson, Topic, Resource, Enrollment, Progress, Activity, Discussion, Certificate, Learning Path | content structure, delivery, progress, completion, certification | Education (students, cohorts), Assessment, Commerce (entitlements, read) |
| **Assessment** | Assessment, Quiz, Question, Option, Assignment, Submission, Answer, Grade, Grading Scheme, Band, Result, Report Card | everything that measures learning: authoring, attempting, scoring, grading, academic records | Lms (courses/lessons), Education (students, terms), Tutoring (tutor assessments) |
| **Tutoring** | Tutor Application, Qualification, Reference, Interview, Evaluation, Criterion, Recommendation, Approval, Tutor Profile, Availability, Service, Package, Booking, Session, Note, Review, Earning | recruitment pipeline **and** the tutoring marketplace | Education (students, subjects), Integration (meetings), Commerce (services are sellable, sessions consume entitlements) |
| **Commerce** | Product, Category, Digital Product, Cart, Order, Item, Invoice, Payment, Refund, Coupon, Tax, Subscription, **Entitlement**, Consumption, Download | pricing, checkout, money movement, and the *granting of rights* | Integration (Paystack), Lms/Education/Tutoring (via entitlements — inverted) |
| **Integration** | Meeting, Provider, Capability, Connected Account (shared with Identity), Webhook Event, Integration Log | adapters for Zoom, Google Meet, Paystack; the ports everyone else uses | everyone (through contracts) |
| **Communication** | Notification, Template, Preference, Audience, Announcement, Message | rendering and dispatch; marketing vs transactional separation | everyone (event listeners only) |
| **Administration** | Setting, Audit Log, File, Import Batch, Report, Page, FAQ | cross-cutting platform services: config, audit, files, search, imports, reporting | everyone (cross-cutting) |

### Context map (integration style)

```
Education ──(Conformist)──► Identity          Education owns students; reads users
Lms       ──(Customer)────► Education         Lms requests cohort/student data
Assessment──(Shared Kernel)─► Lms             assessments attach to courses/modules/lessons
Tutoring  ──(Customer)────► Education         tutoring reads students + subjects
Commerce  ──(Anticorruption Layer)──► Integration/Paystack
Commerce  ──(Published Language: Entitlement)──► Lms, Tutoring, Education
            ▲ This is the key inversion: Commerce does not call Lms.
              Lms reads the Entitlement table. Commerce only writes it.
Integration ──(Open Host Service: VideoMeetingProvider)──► Education, Tutoring
Communication ──(Event订阅 / subscriber only)──► all contexts
Administration ──(Generic Subdomain)──► all contexts
```

---

## 3. Aggregates, entities and value objects

Notation: **[AR]** aggregate root · **[E]** entity (identity, mutable, owned by an AR) ·
**[VO]** value object (immutable, no identity, compared by value) · **[ES]** event-sourced-
style append-only history.

### 3.1 Identity

| Object | Kind | Notes |
|--------|------|-------|
| `User` | **[AR]** | Invariants: email unique + lowercase; password always hashed; `status` ∈ pending/active/suspended/deactivated; cannot delete while financial or academic records reference it (soft delete + `deactivated_at`) |
| `FullName` | **[VO]** | first, middle, last; `full()`, `initials()`, `sortKey()`; trims + normalizes unicode; used by Student/Parent/Tutor |
| `Email` | **[VO]** | normalized lowercase, RFC-validated, `domain()` for duplicate-guardian detection |
| `PhoneNumber` | **[VO]** | E.164 normalized with a country dial code; nullable-safe |
| `Timezone` | **[VO]** | IANA identifier, validated against `DateTimeZone::listIdentifiers()`; `convert()` helpers |
| `ConnectedAccount` | **[E]** of User | provider, provider_user_id, encrypted tokens, scopes, expires_at. Invariant: at most one connected account per (user, provider, purpose) |
| `Consent` | **[ES]** | type (terms, privacy, marketing, data_processing), version, granted_at, ip, user_agent. **Marketing consent defaults to false** (§83) |

### 3.2 Education

| Object | Kind | Invariants / notes |
|--------|------|--------------------|
| `AcademicYear` | **[AR]** | Exactly **one** `status=active` year (enforced by a service + partial-style unique guard). `ends_at > starts_at`. Cannot close a year with open terms |
| `AcademicTerm` | **[E]** of AcademicYear | Terms of a year must not overlap; at most one active per year |
| `AcademicLevel` | **[AR]** | Configurable list (Primary 1…6, JSS1…3, SSS1…3, Adult, …). Never hard-coded (§95) |
| `Program` | **[AR]** | code unique; status draft/active/suspended/archived; has ordered `ProgramCourse` items forming the **learning path** |
| `ProgramCourse` | **[E]** | course + position + `is_required` + `prerequisite_program_course_id` |
| `Subject` | **[AR]** | code unique; belongs to a level band; reusable across programs/courses |
| `Student` | **[AR]** | `student_code` unique (human-readable, generated from a configurable pattern); `date_of_birth` nullable-but-preferred; `status` ∈ applicant/active/inactive/graduated/suspended/withdrawn; `user_id` nullable and unique; may exist with **zero** purchases (§8) |
| `Parent` | **[AR]** | the Guardian. `user_id` nullable + unique; may exist before registration with an invite/claim token |
| `ParentStudent` | **[E]** — the relationship | relationship type, `is_primary`, **`can_purchase`**, `can_view_academics`, `can_view_financials`, `can_receive_communications`, status (pending/active/revoked), consent snapshot, `linked_by`, `linked_at`. Every change appends to `audit_logs` (§9) |
| `StudentApplication` | **[AR]** | admissions aggregate. status ∈ draft/submitted/under_review/accepted/rejected/waitlisted/enrolled/withdrawn. Contains applicant info, guardian info, program + level selection, documents, reviewer assignment, notes |
| `StudentApplicationEvent` | **[ES]** | immutable transition history: from, to, actor, reason, at |
| `ProgramEnrollment` | **[AR]** | student × program × year × term; status active/completed/suspended/withdrawn; `enrollment_source` (manual, application, purchase, import, admin) + `entitlement_id` when purchased |
| `Cohort` | **[AR]** | a.k.a. student group / batch (§13, §16). year + term + program + course/subject + instructor + capacity + status. Members via `CohortStudent` |
| `CohortStudent` | **[E]** | status, joined_at, left_at, role (student) — supports bulk enrollment (§81) |
| `CourseSchedule` | **[E]** of Cohort | recurring pattern: weekday, local start/end time, timezone, recurrence end, meeting provider preference, buffer |
| `CourseSession` | **[AR]** | a **materialized occurrence**: cohort, course, instructor, starts_at (UTC), ends_at, timezone, local time strings, status (scheduled/confirmed/in_progress/completed/cancelled/no_show), meeting link |
| `AttendanceRecord` | **[E]** of CourseSession | student, status (present/absent/late/excused), checked_in_at, checked_out_at, notes, recorded_by. **UNIQUE(session, student)** — one record per student per session |
| `NonWorkingDate` | **[AR]** | institution holidays / blocked dates that suppress session generation and tutoring slots |
| `DateRange` | **[VO]** | start/end + `contains()`, `overlaps()`, `days()` — used by years, terms, enrollments, entitlements |
| `AcademicPeriod` | **[VO]** | (year, term) pair used across assessment/results/reporting |
| `Announcement` | **[AR]** | title, body, audience (targets), publish window, pinned, author |

### 3.3 Lms

| Object | Kind | Invariants / notes |
|--------|------|--------------------|
| `Course` | **[AR]** | the delivery object (§7) — reusable across years/terms, **never** conflated with a cohort. status ∈ draft/review/published/unpublished/archived. Only `published` is enrollable/purchasable (§85). Has `CourseModule`s, prerequisites, level, subject links, completion rule (all lessons / % / assessments passed) |
| `CourseModule` | **[E]** | ordered section of a course; optional publish window |
| `Lesson` | **[E]** | type ∈ text/video/audio/document/embed/external/quiz/assignment; ordered within module; body (sanitized rich text); duration estimate; `is_free_preview` |
| `LessonTopic` | **[E]** | sub-section of a lesson (§16 "Topics") |
| `LessonResource` | **[E]** | file/link reference with kind + download permission flag |
| `CourseEnrollment` | **[AR]** | student × course; `source` ∈ manual/program/purchase/parent_purchase/admin/tutoring/entitlement; `entitlement_id` nullable; status active/completed/suspended/expired/withdrawn; enrolled_at, completed_at |
| `CourseProgress` | **[E]** | denormalized rollup: percent, lessons_completed, lessons_total, time_spent_seconds, last_activity_at, completed_at |
| `LessonProgress` | **[E]** | student × lesson: status not_started/in_progress/completed, first/last accessed, seconds spent, attempts. **UNIQUE(student, lesson)** |
| `LearningActivity` | **[ES]** | append-only feed powering "last activity", time-on-task and analytics |
| `Discussion` / `DiscussionPost` | **[AR]/[E]** | course- or lesson-scoped threads; moderated; only enrolled participants may post |
| `Certificate` | **[AR]** | number (unique, human-readable), verification_code (unique, unguessable), student, awardable (course/program/cohort), template, issued_at, status issued/revoked, rendered file. Public verification exposes **name, title, issuer, date only** (§26) |
| `CertificateTemplate` | **[AR]** | layout, background, signature block, seal, verification URL pattern |

**Access resolution (`CourseAccessResolver`)** — the single authority for "may this person
open this lesson?":

```
canAccess(student, course) =
      hasActiveCourseEnrollment(student, course)
   OR isMemberOfCohortDelivering(student, course)
   OR hasActiveEntitlement(student OR owner, target=course)
   OR hasActiveEntitlement(target=program containing course)
   OR user.can('courses.manage')                     // staff/instructor
   OR lesson.is_free_preview AND course.is_published  // prospect preview
```

Cached per (student, course) and invalidated on enrollment/entitlement/cohort changes.

### 3.4 Assessment

| Object | Kind | Invariants / notes |
|--------|------|--------------------|
| `Assessment` | **[AR]** — the unified umbrella (§23) | type ∈ quiz/assignment/examination/project/continuous/tutor_assessment; max_score, weight (for gradebook), passing_score, publish_at, due_at, close_at, late_policy, attempts_allowed, course/module/lesson context, cohort or individual scope, academic term, grading scheme, assessor. **Status transitions: draft → scheduled → open → closed → graded → published** |
| `QuizSettings` | **[E]** 1:1 with Assessment | duration_minutes, shuffle_questions, shuffle_options, show_results (immediately/on_close/never), show_correct_answers, pass percentage |
| `AssignmentDetails` | **[E]** 1:1 | instructions, allows_file_upload, allows_text, max_files, allows_resubmission, resubmission_deadline |
| `Question` | **[AR]** — a reusable bank item (§21) | type ∈ multiple_choice/multiple_select/true_false/short_answer/long_answer/matching; stem (sanitized), default points, difficulty, explanation, subject/course tags, `is_active` |
| `QuestionOption` | **[E]** | text, `is_correct`, position, weight (for partial credit on multi-select/matching) |
| `AssessmentQuestion` | **[E]** | assessment × question + position + points override. **The attempt snapshots these** so later edits never rewrite history |
| `AssessmentSubmission` | **[AR]** | assessment × student × version; status ∈ not_started/submitted/late/graded/returned; submitted_at, score, max_score, percentage, grade, feedback, graded_by, graded_at, time_spent_seconds, ip. **UNIQUE(assessment, student, version)** |
| `SubmissionAnswer` | **[E]** | question snapshot id, given answer(s), auto-score, awarded score, marker comment |
| `Score` | **[VO]** | value + max → `percentage()`, `isPass(band)`; refuses negative or > max; decimal-safe (integer hundredths internally) |
| `Grade` | **[VO]** | letter, remark, gpa_point, band_id, percentage — produced **only** by `GradeResolver` |
| `GradingScheme` | **[AR]** | name, basis (percentage/score), applies-to scope (global/program/level/course), is_default, bands. **Nothing hard-coded (§24)** |
| `GradingScaleBand` | **[E]** | letter, min/max percentage, gpa_point, remark; bands must be contiguous and non-overlapping (validated) |
| `AcademicResult` | **[AR]** | the gradebook/report-card row: student, term, program, subject, course, weighted score, percentage, grade, position (optional), remark, completion status, issued flag. Immutable once published — corrections append a new version + audit |
| `AcademicReport` | **[AR]** | generated report card document (student, term, template, file, generated_at) |

**`GradeResolver`** — pure function, no DB writes:

```
resolve(Percentage $p, GradingScheme $s): Grade
  → find band where min ≤ p ≤ max
  → no band matched ⇒ throw GradingSchemeIncomplete (a data error, surfaced loudly)
```

### 3.5 Tutoring

| Object | Kind | Invariants / notes |
|--------|------|--------------------|
| `TutorApplication` | **[AR]** | status ∈ draft/submitted/under_review/shortlisted/interview_scheduled/interview_completed/approved/rejected/withdrawn (§27). Contains personal info, education, qualifications, certifications, subjects+levels, experience, teaching experience, CV, documents, availability, bio, references. Every transition appends `TutorApplicationEvent` |
| `TutorApplicationEvent` | **[ES]** | from, to, actor, reason, at — immutable |
| `TutorQualification` / `TutorWorkExperience` / `TutorReference` | **[E]** | owned by the application, copied onto approval to the profile |
| `EvaluationForm` | **[AR]** | template: title, version, criteria with weights, min/max score per criterion, recommendation required |
| `EvaluationCriterion` / `EvaluationFormCriterion` | **[E]** | criterion definition + its weight/order in a specific form version. Versioning means a submitted evaluation is never distorted by later edits |
| `TutorInterview` | **[AR]** (§29) | application, evaluator(s), scheduled start/end + timezone, meeting (provider, url), status scheduled/in_progress/completed/cancelled/no_show, notes, score, recommendation ∈ approve/reject/further_review |
| `TutorEvaluation` | **[AR]** | application + interview + evaluator + form version + per-criterion scores + comments + recommendation + submitted_at. **Immutable after submission** (corrections create a new evaluation and audit) |
| `TutorProfile` | **[AR]** (§31) | created/activated **only** by `ApproveTutor`. slug, bio, photo, qualifications, subjects+levels, years_experience, languages, methodology, rate, currency, rating aggregate, review count, `is_publicly_listed`, status approved/suspended/withdrawn. Rejected applicants never get a profile (§30) |
| `TutorAvailability` | **[E]** | weekly recurring window: weekday, local start/end, timezone, service scope, min_notice, buffer_before/after |
| `TutorUnavailableDate` | **[E]** | explicit blocks/holidays |
| `TutoringService` | **[AR]** (§33) | name, subject, level, duration_minutes, price(Money), delivery mode (1-on-1 / small group), tutor assignment (specific tutor or pool), booking rules (min_notice, max_advance_days, cancellation window, refund policy), **`requires_parent_purchase`** (§10 flag), active flag |
| `TutoringPackage` | **[AR]** | bundle of N sessions of a service at a package price — the thing actually sold for multi-session purchases; consumed via `EntitlementConsumption` |
| `TutoringBooking` | **[AR]** (§34) | service/package, tutor, student (**beneficiary**), booked_by (**purchaser**), requested slot, status pending/awaiting_payment/confirmed/cancelled/completed, entitlement_consumption link, cancellation reason + actor |
| `TutoringSession` | **[AR]** (§35) | booking, student, guardian of record, tutor, subject, starts_at/ends_at (UTC) + timezone, meeting, status scheduled/confirmed/in_progress/completed/cancelled/no_show, attendance, notes, homework, progress, join/leave timestamps |
| `SessionNote` | **[E]** | tutor-authored: topics covered, homework, progress, next steps, visibility (tutor/parent/student) |
| `TutorReview` | **[AR]** (§88) | booking/session-gated: only a participant of a **completed** session may review; one review per session per reviewer; tutors cannot review themselves; rating 1–5 + comment; moderation status pending/published/hidden |
| `TutorEarning` | **[ES/AR]** (§89) | per completed session: gross(Money), platform_fee, tutor_share, tax, net, payout_status pending/processing/paid/failed, payout reference. **No payout execution in v1** |
| `TimeSlot` | **[VO]** | starts_at/ends_at (UTC) + timezone + tutor_id; `overlaps()`, `withBuffer()` |
| `WeeklyWindow` | **[VO]** | weekday + local start/end + timezone; `occurrencesBetween(range)` |

**`ApproveTutor`** is the only path from application to profile (§30):

```
ApproveTutor::execute(applicationId, actorId, note)
  requires permission tutors.approve
  inside DB transaction:
    1. assert application.status ∈ {interview_completed, shortlisted, under_review}
    2. assert at least one TutorEvaluation exists (configurable: require recommendation)
    3. application.status = approved  + TutorApplicationEvent
    4. create-or-activate TutorProfile (copy qualifications, subjects, documents)
    5. create-or-link User account; grant role Tutor + permission tutor.portal.access
    6. user.status = active
    7. audit_logs ← 'tutors.approved' with before/after
    8. dispatch TutorApproved event → notifications + integration welcome email
```

**`SlotFinder`** (the scheduling engine, §32) is a pure-ish domain service:

```
findSlots(tutor, service, requesterTimezone, range): Collection<TimeSlot>
  1. load TutorAvailability windows → expand to concrete local times in range
  2. subtract TutorUnavailableDate + NonWorkingDate (institution holidays)
  3. subtract existing TutoringSession / CourseSession where the tutor teaches
  4. apply service.duration_minutes + tutor.buffer_before/after
  5. apply service.min_notice_hours and max_advance_days
  6. drop slots that started in the past (evaluated in UTC, compared in UTC)
  7. convert to requesterTimezone for display, keep UTC for booking
  8. optional: intersect with student availability if provided
```

Booking is then wrapped in a DB transaction with `lockForUpdate` on the tutor's sessions for
that window **plus** a `UNIQUE(tutor_id, starts_at)` guard on `tutoring_sessions`, so two
parents clicking the same slot cannot both win (§57 data integrity).

### 3.6 Commerce

| Object | Kind | Invariants / notes |
|--------|------|--------------------|
| `Product` | **[AR]** — the sellable catalog entry | sku unique, name, description, `product_type` ∈ digital/service/course/program/tutoring/package/subscription, price (Money), compare_at_price, `productable_type`+`productable_id` → the underlying domain object (`DigitalProduct`, `Course`, `Program`, `TutoringService`, `TutoringPackage`), status draft/published/unpublished/archived (§86), `requires_parent_purchase`, tax_rate_id, is_subscription, billing_interval, download/expiry rules, visibility flags |
| `DigitalProduct` | **[AR]** (§39) | author, category, files, preview file, download_limit, expiry_days, sample flag |
| `Cart` / `CartItem` | **[AR]/[E]** | server-side cart (DB, not session — so admin-assisted checkout and multi-device work). Item stores product snapshot: name, unit_price(Money), quantity, beneficiary_student_id |
| `Order` | **[AR]** (§44) | number unique, customer, beneficiary, currency, subtotal/discount/tax/total (all Money minor units), coupon, status ∈ pending/processing/paid/failed/cancelled/refunded/partially_refunded, placed_by (self/admin), notes |
| `OrderItem` | **[E]** | product snapshot + **own beneficiary_student_id** + entitlement_id once granted |
| `OrderEvent` | **[ES]** | status timeline |
| `Invoice` | **[AR]** | number unique, order or subscription, billed_to, issued_at, due_at, status draft/issued/paid/void/overdue, totals, pdf file |
| `Payment` | **[AR]** | provider, provider_reference **UNIQUE**, order/subscription, amount(Money), status pending/processing/success/failed/abandoned/refunded, verified_at, provider_payload (redacted), gateway_message. Never mutated except through `ConfirmPayment`/`MarkPaymentFailed` |
| `PaymentEvent` | **[ES]** | webhook ledger: provider, `event_id` **UNIQUE with provider**, event_type, reference, payload, signature_valid, processed_at. This is the idempotency gate (§42) |
| `Refund` | **[AR]** | payment, amount(Money), reason, status pending/processed/failed, provider_refund_id, processed_by |
| `Coupon` | **[AR]** | code unique, type (percent/fixed), value, max redemptions, per-user limit, product/program scope, valid range, min order, active |
| `TaxRate` | **[AR]** | name, percent, applies-to scope, inclusive flag |
| `Subscription` | **[AR]** (§43) | customer, beneficiary, product/plan, price(Money), billing_interval, provider_plan_code, provider_subscription_id **UNIQUE per provider**, status active/past_due/cancelled/expired/paused, starts_at, next_billing_at, cancelled_at, ends_at |
| `SubscriptionEvent` | **[ES]** | renewals, failures, cancellations |
| `Entitlement` | **[AR]** ★ (§45) | **the decoupling device.** owner (purchaser), beneficiary (student, nullable), product, `entitlement_type` ∈ course_access/program_access/digital_product/tutoring_service/tutoring_package/subscription_access, resolved target columns (`course_id` / `program_id` / `digital_product_id` / `tutoring_service_id` / `tutoring_package_id` — exactly one non-null, CHECK-enforced), `source_type`+`source_id` (order_item / subscription / manual_grant / promotion), starts_at, ends_at, `sessions_included` + `sessions_consumed`, status pending/active/suspended/expired/revoked/consumed |
| `EntitlementEvent` | **[ES]** | granted/activated/consumed/suspended/expired/revoked + actor |
| `EntitlementConsumption` | **[E]** | one row per tutoring session consumed; **UNIQUE(entitlement, tutoring_session_id)** — this is what stops a duplicate webhook from granting two sessions |
| `DigitalProductDownload` | **[ES]** | entitlement, file, user, ip, user_agent, downloaded_at (§40) |
| `Money` | **[VO]** ★ | `amountMinor:int` + `currency:Currency`. `add/subtract/multiply/allocate/percentageOf/compareTo/isZero/isPositive`. Throws on currency mismatch. `Currency` VO holds ISO code + exponent + symbol from a **config table**, never a hard-coded ₦ (§61, ADR-02) |
| `OrderTotals` | **[VO]** | subtotal, discount, taxBreakdown, shipping(n/a), total; `allocateToItems()` for per-line entitlements and refunds |

**`EntitlementGranter`** — the only writer of entitlements:

```
grantForOrder(Order $order): Collection<Entitlement>
  foreach item:
     resolve target from item.product.productable
     skip if entitlement already exists for (source_type, source_id, product, beneficiary)  ← idempotent
     compute starts_at/ends_at from product expiry rules
     compute sessions_included from package/service quantity
     create Entitlement(status: active) + EntitlementEvent('granted')
  emit EntitlementGranted events → Lms creates CourseEnrollment when type=course_access
```

**`MinorPurchaseGuard`** — see §5 below and ADR-04.

### 3.7 Communication & Administration

| Object | Kind | Notes |
|--------|------|-------|
| `MessageTemplate` | **[AR]** | key (welcome, payment_confirmation, session_reminder, tutor_approved, …), subject, html body, text body, variables, locale, active flag. **DB-driven so §52 templates are configurable without a deploy**; falls back to Blade views if a key is missing |
| `NotificationPreference` | **[AR]** | per user × notification key × channel (mail/database), with a `is_transactional` flag that **cannot be turned off** (§82) |
| `Audience` | **[VO]** | resolves a target set (all parents of a cohort, students of a course, tutors of a subject) into concrete user ids at dispatch time |
| `Setting` | **[AR]** | group, key (unique), value (JSON), type, is_secret, is_public, cast |
| `AuditLog` | **[ES]** | §5.2 of doc 01 |
| `File` | **[AR]** | uuid, disk, path, original_name, mime, size, checksum, `category` ∈ profile_photo/cv/certification/student_document/course_resource/assignment_submission/digital_product/certificate/invoice/report, **`visibility`** ∈ public/authenticated/private/restricted, owner (polymorphic), uploaded_by, meta |
| `ImportBatch` / `ImportBatchRow` | **[AR]/[ES]** | CSV import/export tracking with per-row validation results and an error report (§80) |

---

## 4. Domain events (the nervous system)

Every event is a plain PHP object dispatched through Laravel's event dispatcher; listeners
are **queued** where they touch the network. This is how modules stay decoupled and how §51's
notifications never leak into business logic.

| Event | Fired by | Listeners |
|-------|----------|-----------|
| `UserRegistered` | Identity | send welcome + verification email; create default notification preferences |
| `GuardianLinked` / `GuardianUnlinked` | Education | audit log; notify guardian; grant/portal access |
| `StudentStatusChanged` | Education | audit; notify parent/guardian; suspend entitlements if withdrawn |
| `ApplicationSubmitted` / `ApplicationReviewed` | Education | notify admissions + applicant; audit |
| `StudentEnrolledInProgram` | Education | create cohort suggestions; notify parent |
| `CoursePublished` / `CourseUnpublished` | Lms | audit; invalidate caches; notify enrolled students on publish |
| `StudentEnrolledInCourse` | Lms | initialize progress row; notify student |
| `LessonCompleted` | Lms | recalc progress; check course completion |
| `CourseCompleted` | Lms | queue `IssueCertificate`; notify student + parent |
| `CertificateIssued` | Lms | render PDF (queued); notify; audit |
| `AssessmentPublished` / `AssessmentDue` | Assessment | reminders to students + parents |
| `AssessmentSubmitted` | Assessment | notify assessor; queue auto-scoring for quizzes |
| `AssessmentGraded` / `ResultsPublished` | Assessment | notify student + guardian; update `AcademicResult`; audit grade changes |
| `TutorApplicationSubmitted` | Tutoring | notify admins/evaluators; audit |
| `InterviewScheduled` | Tutoring | create Meeting via `MeetingManager`; notify applicant + evaluator; calendar event |
| `EvaluationRecorded` | Tutoring | audit (immutable); notify admin if recommendation = approve/reject |
| `TutorApproved` / `TutorRejected` | Tutoring | activate profile + user; grant role/permissions; notify; audit |
| `TutorAvailabilityChanged` | Tutoring | invalidate slot cache |
| `BookingRequested` | Commerce/Tutoring | create order if unpaid; else confirm |
| `OrderPlaced` | Commerce | create invoice; initialise payment; notify |
| `OrderPaid` | Commerce | `EntitlementGranter`; fulfil; notify purchaser + beneficiary + tutor |
| `OrderFailed` / `OrderCancelled` | Commerce | notify; release reserved slots |
| `EntitlementGranted` | Commerce | Lms creates enrollment; Tutoring marks sessions bookable |
| `EntitlementConsumed` | Tutoring | decrement sessions; warn at threshold |
| `EntitlementExpired` / `EntitlementRevoked` | Commerce | suspend enrollments; notify |
| `PaymentRefunded` | Commerce | revoke entitlements; notify; audit |
| `SubscriptionRenewed` / `SubscriptionCancelled` / `SubscriptionPastDue` | Commerce | extend entitlements; dunning notifications |
| `SessionScheduled` / `SessionUpdated` / `SessionCancelled` | Education/Tutoring | `MeetingManager` sync; reminders; calendar invalidation |
| `SessionCompleted` | Tutoring | write earnings ledger row; open review window; prompt session notes |
| `AttendanceRecorded` | Education | notify parent on repeated absence (at-risk rule) |
| `ReviewPublished` | Tutoring | update tutor rating aggregate; notify tutor; moderation queue |
| `DownloadServed` | Commerce | ledger; anti-abuse counter |
| `FileUploaded` | Administration | virus/extension validation hook; audit for restricted categories |
| `SettingUpdated` | Administration | audit; bust settings cache |
| `RoleAssigned` / `RoleRevoked` / `PermissionChanged` | Identity | audit (§56) |

---

## 5. The minor-purchase rule as a domain invariant (§10)

This deserves its own section because the spec calls it *"critical"* and because it is the
clearest example of the model doing work.

### Participants

```
PurchaserContext  { User $purchaser, ?Student $beneficiary, Product $product, string $channel }
GuardianAuthority { ParentStudent $link }   // the only source of truth for "authorized"
```

### The invariant

```
GIVEN  beneficiary.date_of_birth is known AND age(beneficiary, today) < age_of_majority
  AND  product.requires_parent_purchase == true
  AND  NOT ∃ ParentStudent p where
            p.parent.user_id == purchaser.id
        AND p.student_id     == beneficiary.id
        AND p.status         == active
        AND p.can_purchase   == true
THEN   the checkout MUST be rejected with PurchaseRestrictedForMinor
```

### Deliberate design choices

| Choice | Reason |
|--------|--------|
| **Fail safe on unknown DOB.** If `date_of_birth` is null, treat as a minor | A platform serving children must not let a missing field become a loophole. Admins see a clear message asking them to record the DOB |
| `age_of_majority` comes from `settings`, default 18 | §95 forbids hard-coding; jurisdictions differ |
| Age is evaluated at **time of purchase**, and re-checked at **booking confirmation** for services delivered later | A student who turns 18 mid-course shouldn't be blocked from a session already paid for; a purchase made at 17 must still have guardian authority |
| Enforced in `CheckoutService`, which **all** channels call (web Livewire, future `/api/v1`, admin-assisted checkout, subscription renewal) | §10: *"The same rule must apply regardless of whether checkout occurs through Web UI, API, mobile client in the future, admin-assisted checkout."* One choke point ⇒ one test suite ⇒ no drift |
| Admin override is a **separate, permissioned, audited action** (`commerce.override_minor_restriction`), not a flag on the guard | Overrides must be exceptional, attributable and visible in the audit log |
| The guard throws a **domain exception**, not a validation error | It is a business-rule violation, not a malformed field. The presentation layer maps it to a helpful message ("A parent or guardian must complete this purchase for Chidi, who is 14. Invite a guardian or contact support.") |
| The UI *also* prevents it | UX, not security. Defence in depth; the server is the authority (§57: "Never trust client-side validation") |

### Unit test sketch (no DB required — §71)

```php
it('rejects a minor self-purchasing a parent-required tutoring service', function () {
    $student   = Student::minor(age: 14);            // VO-based test double
    $product   = Product::tutoring(requiresParentPurchase: true);
    $purchaser = User::student($student);            // the minor's own account

    $guard = new MinorPurchaseGuard(Clock::fake('2026-09-07'), Settings::fake(['age_of_majority' => 18]));

    expect(fn () => $guard->assert(new PurchaserContext($purchaser, $student, $product, 'web')))
        ->toThrow(PurchaseRestrictedForMinor::class);
});

it('allows an authorized guardian to purchase for a minor', …);
it('allows a minor to purchase when the product does not require a guardian', …);
it('allows an adult student to purchase for themselves', …);
it('fails safe when date of birth is unknown', …);
it('rejects a guardian link that is revoked or lacks can_purchase', …);
```

---

## 6. Lifecycle state machines

### 6.1 Student

```
            ┌────────────► withdrawn ◄──────────┐
            │                                   │
applicant ──┴─► active ──► inactive ──► graduated
                  │  ▲          │
                  ▼  │          │
              suspended ────────┘
   (every transition writes audit_logs + notifies guardians)
```

### 6.2 Student application (admissions)

```
draft → submitted → under_review → ┬ accepted → enrolled
                                   ├ waitlisted → (accepted | rejected)
                                   ├ rejected
                                   └ withdrawn      (from any non-terminal state)
```

### 6.3 Tutor application & recruitment

```
draft → submitted → under_review → shortlisted → interview_scheduled
   → interview_completed → ┬ approved ──► TutorProfile activated
                           ├ rejected
                           └ further_review → under_review (loop, audited)
   withdrawn (from any non-terminal state)
```

### 6.4 Order & payment

```
Order:    pending → processing → paid → (refunded | partially_refunded)
                    └────────► failed
                    └────────► cancelled
Payment:  pending → processing → success
                    └────────► failed | abandoned
                    └────────► refunded (via Refund aggregate)
```

### 6.5 Entitlement

```
pending → active → ┬ expired      (ends_at passed, or sessions_included consumed)
                   ├ suspended    (refund pending, student suspended)
                   ├ consumed     (all sessions used)
                   └ revoked      (refund completed, admin action — audited)
```

### 6.6 Tutoring session

```
scheduled → confirmed → in_progress → completed
     │           │           │
     └───────────┴───────────┴──► cancelled   (before start; reason + actor recorded)
                          completed → no_show  (tutor marks within the review window)
```

### 6.7 Course & assessment publishing

```
Course:      draft → review → published ⇄ unpublished → archived
Assessment:  draft → scheduled → open → closed → graded → published
Product:     draft → published ⇄ unpublished → archived
```

---

## 7. Data-integrity rules enforced at the database level

Business rules live in the domain, but the database is the last line of defence. These are
**constraints, not conventions**:

| Constraint | Purpose |
|------------|---------|
| `UNIQUE(users.email)`, `UNIQUE(students.student_code)`, `UNIQUE(parents.user_id)`, `UNIQUE(students.user_id)` | identity integrity |
| `UNIQUE(parent_student(parent_id, student_id))` | one link per pair; capabilities are columns on that row |
| `UNIQUE(attendance_records(session_type, session_id, student_id))` | no double attendance rows |
| `UNIQUE(assessment_submissions(assessment_id, student_id, version))` | resubmission history without duplicates |
| `UNIQUE(payments(provider, provider_reference))` | **no duplicate payment from a replayed webhook** |
| `UNIQUE(payment_events(provider, event_id))` | **idempotent webhook processing** |
| `UNIQUE(subscriptions(provider, provider_subscription_id))` | no duplicate subscription |
| `UNIQUE(entitlements(source_type, source_id, product_id, beneficiary_student_id))` | **no duplicate entitlement** |
| `UNIQUE(entitlement_consumptions(entitlement_id, tutoring_session_id))` | no double-consuming a session |
| `UNIQUE(tutoring_sessions(tutor_id, starts_at))` | **no tutor double-booking** |
| `UNIQUE(order_items(order_id, product_id, beneficiary_student_id))` | sane cart lines |
| `UNIQUE(tutor_reviews(session_id, reviewer_user_id))` | no duplicate review (§88) |
| `CHECK (entitlements: exactly one target column non-null)` | typed entitlement integrity (ADR-03) |
| `CHECK (amount_minor >= 0)` on all money columns | no negative money (refunds use a separate `refunds` table) |
| `CHECK (grading_scale_bands.max_percentage >= min_percentage)` | sane grading bands |
| `CHECK (order_items.quantity > 0)` | sane quantities |
| FKs with `ON DELETE RESTRICT` for academic/financial records, `CASCADE` only for owned child rows (options, bands, items, events) | §66 "define cascading behavior carefully" — a student is never silently deleted along with their grades |
| Soft deletes on `students`, `parents`, `users`, `courses`, `products`, `orders`, `tutor_profiles`, `files` | archive rather than destroy (§58) |

---

## 8. Model-to-table map

The full table list is in [04-database-table-inventory.md](04-database-table-inventory.md).
Mapping conventions:

- Domain object `ParentStudent` → table `parent_student` (pivot with payload, so it gets a
  model rather than a bare `belongsToMany`).
- Aggregate roots own tables named in plural snake_case.
- `*[ES]` history objects map to `*_events` tables that are **append-only**: no `updated_at`,
  no update paths in code, `RESTRICT` deletes.
- Value objects do **not** get tables — they are stored as columns (`Money` →
  `*_amount_minor` + `*_currency`) or as validated strings/JSON.
- Polymorphic associations are used **only** where the alternative is worse: `files.fileable`,
  `meetings.meetingable`, `announcements` targets, `entitlements.source`, `audit_logs.auditable`.
  Entitlement *targets* are deliberately **not** polymorphic (ADR-03).
