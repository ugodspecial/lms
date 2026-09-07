# 07 — Major User Workflows

**Deliverable:** spec §102.7 — *"A list of all major user workflows."* Realises §100's three
end-to-end journeys (parent, tutor, administrator) as 24 concrete, testable workflows.

Each workflow is written so it can be turned directly into a Pest feature test:
**Actor → Trigger → Steps → Domain effects → Notifications → Failure paths.**

Notation: `→` user action · `⇢` system action · `⚠` failure path · `🔒` authorization check ·
`📧` notification · `📝` audit-log write.

---

## A. The three flagship journeys (§100)

### W1 — Parent journey: register → child learns → parent tracks → books a tutor

```
ACTOR  Parent (new visitor), then Child (student)

1  → Visits /register, completes email + password, ticks terms (NOT marketing — §83)
   ⇢ users created (status=pending) · consents row (type=terms, version, ip, ua)
   ⇢ notification_preferences defaults created (transactional on, marketing off)
   📧 VerifyEmail notification (queued)
   ⚠ Email already registered → "An account exists. Sign in or reset your password."
      (no user enumeration on the reset path)

2  → Clicks the signed verification link
   ⇢ users.email_verified_at set · status=active · parent profile created
   ⇢ role Parent + permission parent.portal.access granted
   📧 WelcomeParent
   ⚠ Expired/invalid signature → 403 page with a "resend verification" action

3  → Parent → Children → "Add child": name, DOB, level, school notes
   ⇢ students row created (status=applicant, student_code auto-generated)
   ⇢ parent_student row (status=pending or active per
      settings.parent_child_link_requires_approval, can_purchase=true,
      can_view_academics=true, can_view_financials=true, is_primary=true)
   ⇢ consent recorded on the child's behalf (data_processing, photo_release optional)
   📧 to admins: NewChildRegistered · 📧 to parent: ChildAdded
   📝 audit: students.created, guardian.linked
   ⚠ DOB makes the child 18+ → system notes "can purchase independently"; the guardian
      link still exists but the minor rule no longer blocks the student

4  → Parent browses /courses, filters by level + subject, previews a free lesson
   🔒 CourseAccessResolver allows the free-preview lesson only (course must be published)
   ⚠ Attempting a paid lesson → "Enroll to unlock" prompt, never a silent 404

5  → Applies for admission OR enrolls directly in a published course
   Path 5a (admissions): /admissions/apply → see W4
   Path 5b (direct purchase): "Enroll — ₦45,000" → see W8 (checkout)

6  → Child receives login credentials (or the parent's account is used for young children)
   ⇢ students.user_id linked · role Student + student.portal.access
   📧 StudentWelcome (to the child if they have an email, else to the parent)
   🔒 A student with no user account still exists in Education and still attends (§8)

7  → Child logs in → /learn → "My Courses" → opens Lesson 1.3, watches the video,
      marks complete
   ⇢ lesson_progress (status=completed, seconds_spent accumulated)
   ⇢ learning_activities row · course_progress recalculated (queued)
   📧 (none — progress notifications are digest-based, not per lesson)

8  → Child takes the module quiz
   ⇢ assessment_submissions (question_snapshot fixes the shuffled order)
   ⇢ submission_answers auto-scored · GradeResolver → band → letter grade
   📧 AssessmentResult to student + parent (if results_visible_to_parents)

9  → Parent opens /parent, switches child (child switcher), reviews:
      progress · attendance · grades · upcoming classes · assignments due
   🔒 every query scoped by parent_student (active + can_view_* flags)
   ⚠ Parent A requesting Parent B's child → 403 + 📝 audit warning (§71)

10 → Parent sees "Chidi is struggling with Fractions — book a tutor" (at-risk insight)
    → /tutors?subject=Mathematics&level=Primary+5, filters by price/rating/availability
    🔒 directory shows only status=active AND is_publicly_listed tutors (§87)

11 → Selects a service ("Mathematics 1-on-1, 60 min, ₦8,000"), picks a slot from the
     tutor's availability (rendered in the parent's timezone)
    ⇢ SlotFinder excludes booked slots, blocked dates, holidays, buffer, min-notice

12 → Checkout: beneficiary = Chidi (selected from the parent's children)
    ⇢ CheckoutService → PricingResolver → CouponValidator → OrderTotalsCalculator
    ⇢ ★ MinorPurchaseGuard: Chidi is 11, product.requires_parent_purchase = true,
       purchaser is an authorized guardian with can_purchase = true → PASS
    ⇢ orders + order_items (beneficiary_student_id = Chidi) + invoices created
    ⇢ payments row (provider_reference UNIQUE) · Paystack initialize → authorization_url
    ⚠ If the *child's own account* attempted this → PurchaseRestrictedForMinor with a
       helpful message and an "ask a guardian" action (§10)

13 → Pays on Paystack's hosted page (card / transfer / USSD)
    ⇢ Webhook charge.success arrives → signature verified over the RAW body
    ⇢ payment_events row inserted (UQ(provider, event_id)) → 200 returned immediately
    ⇢ handler queued → ConfirmPayment → payments.status=success, orders.status=paid
    ⇢ EntitlementGranter creates a tutoring entitlement (owner=parent, beneficiary=Chidi,
       sessions_included=1)
    ⇢ TutoringBooking confirmed · TutoringSession materialized
    ⇢ MeetingManager creates the Google Meet/Zoom room
    📧 PaymentConfirmation + TutoringBooked (with join link) to parent and child
    📝 audit: orders.paid, entitlements.granted
    ⚠ Webhook replayed 3× by Paystack → 1 payment, 1 entitlement, 1 session (§42)
    ⚠ Webhook never arrives → payments:reconcile verifies via the API within 5 min
    ⚠ Verification says "failed" while the browser said success → order stays unpaid,
       parent sees "We couldn't confirm your payment" + support CTA. Value is NEVER
       delivered on a redirect alone (§41)

14 → Session day: reminders at T-24h and T-1h
    📧 SessionReminder to parent + child + tutor (queued, deduplicated by ShouldBeUnique)

15 → Tutor conducts the session on Meet, marks attendance, writes session notes + homework
    ⇢ attendance_records (present) · session_notes (visibility=parent_visible)
    ⇢ tutoring_sessions.status=completed · entitlement_consumptions row (UQ)
    ⇢ tutor_earnings ledger row (gross, platform_fee, tutor_share, net, payout_status)
    📧 SessionCompleted + progress note to the parent

16 → Parent leaves a review (eligible only because the session is completed)
    ⇢ tutor_reviews (UQ(session, reviewer)) · rating aggregate recomputed
    📧 ReviewPublished to the tutor
    ⚠ Reviewing before the session, twice, or self-reviewing → blocked (§88)

17 → Term ends: parent downloads the report card and the course certificate
    ⇢ academic_reports PDF (queued) · certificates row + PDF
    🔒 report card gated by can_view_academics + results published
```

**Coverage:** §5, §8, §9, §10, §15, §19, §20, §21, §24, §25, §26, §32, §33, §34, §35, §36/37,
§40–§46, §88, §89, §100.

---

### W2 — Tutor journey: apply → interview → approval → teach → earn

```
ACTOR  Applicant (becomes Tutor), Evaluator, Administrator

1  → /tutors/apply, step 1: personal details, timezone, languages
   ⇢ tutor_applications (status=draft, reference TA-2026-0001)
   ⇢ draft autosaved on every step (no data loss on refresh)

2  → Steps 2–5: education, qualifications, work experience, subjects + levels +
     proficiency, expected hourly rate, bio, teaching philosophy
3  → Step 6: uploads CV (required) + degree/certifications (optional)
   ⇢ tutor_application_documents · files with visibility=private (§59)
   ⚠ Non-PDF/DOCX or oversized CV → rejected at validation, before storage

4  → Submits
   ⇢ status submitted · tutor_application_events row (draft→submitted)
   📧 TutorApplicationReceived · 📧 to admins: NewTutorApplication
   📝 audit
   ⚠ Missing required section → submission refused with per-step error summary

5  ⇢ Admin assigns an evaluator (tutors.applications.assign)
   ⇢ assigned_evaluator_id set · status under_review · event row
   🔒 Evaluators see ONLY assigned applications (§28)
   📧 ApplicationAssigned to the evaluator

6  → Evaluator opens the review workspace: CV, certificates, subjects, experience
   📝 audit: document viewed (who read a candidate's private CV matters)
   🔒 no financial data exists anywhere in the evaluator area (§49)

7  → Evaluator schedules an interview: date, start/end, timezone, provider
   ⇢ tutor_interviews (status=scheduled) · meetings row via MeetingManager
   ⇢ application status interview_scheduled · event row
   📧 InterviewScheduled to the applicant (with join link) + evaluator
   ⚠ Provider not configured → the meeting is created as provider=manual and the
      evaluator pastes a link. Explicit, labelled, never a fake "Meet created" (§93)

8  → Interview happens; evaluator records notes, score, recommendation
   ⇢ tutor_interviews.status=completed · application status interview_completed

9  → Evaluator completes the versioned evaluation form
   ⇢ evaluation_criterion_scores per criterion (validated against min/max)
   ⇢ tutor_evaluations (weighted_percentage, recommendation, rationale, is_final=true)
   ⇢ IMMUTABLE after submission — a correction creates a new row + audit (§29)
   📧 EvaluationRecorded to admins
   ⚠ Duplicate (application, evaluator, interview) → blocked by UQ
   ⚠ Score outside the criterion range → validation error

10 → Administrator reviews the roll-up and APPROVES
    🔒 requires tutors.approve — evaluators do NOT hold it (§28)
    ⇢ ApproveTutor inside one transaction:
       application.status=approved + event row
       tutor_profiles created (slug, bio, subjects copied, qualifications copied)
       users account created or linked · role Tutor · tutor.portal.access granted
       users.status=active · profile.status=active
    📝 audit: tutors.approved with before/after
    📧 TutorApproved (includes "complete your profile" + "set your availability")
    ⚠ Approval with no evaluation on file → configurable guard refuses
       (settings.tutoring.require_evaluation_before_approval)

10b ⚠ REJECTED instead
    ⇢ status rejected + reason · event row · 📧 TutorRejected (empathetic, with reapply
       window) · 📝 audit
    ⇢ NO profile is created; the person is never publicly listed or bookable (§30)

11 → New tutor completes the profile: photo, headline, methodology, public listing toggle
    ⇢ tutor_profiles updated · tutor_subjects synced
    🔒 only tutor.profile.update_own on their OWN profile
    ⚠ Tutor A editing Tutor B → 403 (§71)

12 → Sets availability: Mon/Wed/Fri 16:00–20:00 Africa/Lagos, 60-min slots, 10-min buffer,
     24-h min notice, blocks 1–7 Oct for holidays
    ⇢ tutor_availabilities (wall-clock local + IANA timezone) · tutor_unavailable_dates
    ⇢ slot cache invalidated

13 → Creates or is assigned tutoring services
    ⇢ tutoring_services (subject, level, duration, price as Money,
       requires_parent_purchase=true by default, cancellation policy)
    ⇢ products row auto-created (productable = tutoring_service) so it is sellable

14 → Parents book; tutor sees requests on the dashboard
    🔒 SlotFinder prevents any overlap with existing sessions or cohort classes

15 → Tutor teaches: joins from the session card, marks attendance, writes notes +
     homework + progress summary
    ⇢ session_notes (visibility tiers) · attendance_records · tutoring_sessions completed
    ⇢ entitlement_consumptions (UQ) · tutor_earnings ledger row
    📧 SessionSummary to the parent

16 → Tutor teaches assigned cohort classes too (Instructor role)
    ⇢ cohorts.instructor_id → course_sessions generated → attendance → assessments
    🔒 scoped to their own cohorts

17 → Reviews appear; tutor responds
    ⇢ tutor_reviews.tutor_response · rating aggregate recomputed
    ⚠ Self-review → blocked

18 → Tutor checks /tutor/earnings
    🔒 reports.revenue scoped to self — sees gross, platform fee, share, net, payout status
    ⚠ Actual payout is out of scope for v1; the ledger is payout-ready (§89) and the UI
       says "Payouts are processed manually by the finance team" — no fake button (§93)
```

**Coverage:** §27–§35, §28, §29, §30, §31, §32, §33, §48, §88, §89, §100.

---

### W3 — Administrator journey: run the organization

```
ACTOR  Super Admin / Administrator / Academic Admin / Finance Officer / Operations Officer

Daily:
1  → /admin dashboard: live counts + charts (students, active students, parents, tutors,
     pending applications, upcoming interviews, courses, cohorts, enrollments,
     attendance, revenue, orders, subscriptions, digital sales, completion, performance)
   🔒 widgets render only for permissions the user holds — a Finance Officer sees no
      academic-performance widget; an Academic Admin sees no revenue widget
   ⚠ Zero rows → real empty states ("No enrollments this term yet — import students or
      open admissions"), never placeholder numbers (§94)

Academic setup (once per year):
2  → Create academic year 2026/2027 → terms → activate the year
   ⇢ exactly one active year (transaction + lockForUpdate)
   ⚠ Activating a second year prompts to close the current one
3  → Configure academic levels, grading scheme + bands, subjects, programs + learning paths
   ⇢ all DB-driven; nothing hard-coded (§24, §95)
4  → Import students & guardians by CSV
   ⇢ import_batches + import_batch_rows; validate-all-then-commit
   ⚠ 37 of 500 rows invalid → batch marked partially valid, downloadable error report
      with row numbers + reasons; valid rows import only after confirmation (§80)

Ongoing operations:
5  → Manage cohorts: create "Primary 5 Mathematics — September 2026", assign instructor,
     bulk-enroll 28 students
   🔒 authorization checked PER STUDENT (§81); partial success reported
6  → Define schedules → generate sessions → publish
   ⇢ course_sessions materialized in UTC + local; holidays skipped
7  → Record/monitor attendance; review at-risk students
8  → Build assessments, publish, monitor submissions, release results
9  → Recruitment: review the pipeline, assign evaluators, approve/reject tutors
10 → Commerce: create products, digital products, coupons, tax rates; monitor orders,
     reconcile stuck payments, issue refunds
    🔒 refunds require payments.refund; every refund 📝 audited (§56)
11 → Content: pages, FAQs, announcements (bulk send to an audience), email templates
12 → Settings: organization, branding, currency, timezone, academic defaults, payment mode,
     video providers, notification defaults, security
    🔒 settings.manage.security is Super-Admin-only and 2FA-gated
    📝 every change audited old→new (§60)
13 → Access control: create a custom role (e.g. "Support Agent") from permissions
    📝 role/permission changes audited (§56)
14 → Reports: run + export (queued CSV/PDF through the secure download route)
15 → Audit log: investigate "who changed Chidi's grade?" → filter by entity + event
16 → Health: platform:doctor → env, storage writability, cron last-run, queue depth,
     integration status (Paystack reachable? Zoom configured? Google connected?)
    ⚠ Unconfigured integrations report as "Not configured — see docs/08" with a link,
       never as broken buttons (§93)
```

**Coverage:** §50, §55, §56, §60, §80, §81, §84, §95, §100.

---

## B. Identity & access workflows

### W4 — Student admission / application

```
ACTOR  Applicant (or an admin on their behalf), Reviewer

1 → /admissions/apply: applicant info, DOB, guardian info, program, level, statement,
    supporting documents
2 ⇢ student_applications (status=submitted, reference APP-2026-0001) + event row
   📧 ApplicationReceived to the family · 📧 NewApplication to admissions
3 ⇢ Reviewer assigned (applications.assign) → status under_review
4 → Reviewer reads, adds notes, may request more documents
   ⚠ Documents missing → status stays under_review with a "waiting on applicant" flag
5 → Decision:
   accepted  ⇢ 📧 OfferLetter · controlled conversion available
   waitlisted⇢ position recorded · 📧 WaitlistNotice
   rejected  ⇢ reason required · 📧 Rejection (empathetic) · 📝 audit
6 → Convert to student (applications.convert_to_student)
   ⇢ students row created · parent_student link created · application.status=enrolled
   ⇢ optionally a program_enrollment in the same transaction
   ⚠ Converting twice → refused (idempotent); the existing student is shown instead
7 → Withdrawn at any point before conversion ⇢ status withdrawn + reason
```
**Coverage:** §11. **Tests:** `ApplicationWorkflowTest`, `ConversionTest`,
`DoubleConversionBlockedTest`, `ApplicationStatusHistoryTest`.

### W5 — OAuth account linking (Google / Microsoft)

```
1 → /auth/google/redirect (Socialite) → Google consent → /auth/google/callback
2 ⇢ Socialite user retrieved; email + provider_user_id + token
3 ⇢ connected_accounts row (provider=google, purpose=login, tokens ENCRYPTED)
4 → Existing user with the same verified email? link the account : create the user
5 ⇢ users.auth_provider=google · password stays null · login
   📝 audit: auth.oauth_linked
⚠ Email not verified at the provider → fall back to a verification email before linking
⚠ Provider error/timeout → friendly failure + "try password sign-in" (never a stack trace)
⚠ Unconfigured provider → the button is not rendered at all (§93)
```
**Coverage:** §3 (Google/Microsoft OIDC), §36. **Tests:** `GoogleOAuthTest`,
`MicrosoftOAuthTest`, `OAuthLinkingTest`, `UnconfiguredProviderHiddenTest`.

### W6 — Tutor connects Google Calendar/Meet or Zoom (for hosting sessions)

```
1 → Tutor → Settings → Integrations → "Connect Google" / "Connect Zoom"
2 ⇢ OAuth with the meeting scope (Google: calendar.events; Zoom: meeting:read/write)
3 ⇢ connected_accounts (purpose=meetings, tokens encrypted, expires_at, scopes)
4 → Tutor sets a default provider for their sessions
5 ⇢ MeetingManager uses the tutor's own account to host → the meeting is owned by them
6 → Disconnect ⇢ tokens revoked at the provider where supported, row status=revoked,
   future sessions fall back to the organization account or provider=manual
⚠ Token expired ⇢ OAuthTokenRefresher refreshes before the call; on refresh failure the
   meeting is created via the org fallback and the tutor is notified to reconnect
⚠ Tutor's Google account lacks Meet (no Workspace licence) ⇢ Calendar returns no
   conferenceData → status=failed with a clear reason + fallback to manual (§93)
```
**Coverage:** §36, §37, ADR-05. **Tests:** `ConnectGoogleMeetTest`, `ConnectZoomTest`,
`TokenRefreshTest`, `MeetUnsupportedFallbackTest`, `DisconnectTest`.

### W7 — Privileged-account 2FA enforcement

```
1 ⇢ A user is granted tutors.approve or settings.manage.security
2 ⇢ Next login → forced to /settings/security to enable TOTP
3 → Scans the QR, enters the code, receives recovery codes (shown once)
4 ⇢ two_factor_confirmed_at set · enforcement satisfied
⚠ Refusing → privileged permissions are suspended (not the whole account) and admins are
   notified. The user can still do everything else.
⚠ Lost device → recovery code, or an admin reset (📝 audited)
```
**Coverage:** §6 (optional 2FA for privileged accounts), §57. **Tests:**
`TwoFactorTest`, `Privileged2faEnforcedTest`, `RecoveryCodeTest`.

---

## C. Commerce workflows

### W8 — Checkout & Paystack payment (the canonical money path)

```
1 → Add to cart (product, quantity, beneficiary child if required)
2 ⇢ carts/cart_items; unit price snapshotted
3 → Checkout: review items, beneficiaries, apply a coupon, see tax + total
4 ⇢ CheckoutService (ONE choke point for web, admin-assisted and future API):
   a PricingResolver    — re-reads prices from the DB (client amounts are ignored)
   b CouponValidator    — active, in range, scope match, min order, per-user limit, cap
   c OrderTotalsCalculator — subtotal, discount, tax per line, total → Money VO
   d ★ MinorPurchaseGuard — per item with a beneficiary (§10)
   e CreateOrder        — orders + order_items + order_events (transaction)
   f InvoiceGenerator   — invoices + invoice_items, sequential number
   g InitialisePayment  — payments (provider_reference UNIQUE) → Paystack initialize
5 → Redirect to Paystack hosted checkout
6 → Pay → Paystack redirects to callback_url
7 ⇢ Callback controller shows "Verifying your payment…" and dispatches
   VerifyPaystackTransaction. ⛔ It does NOT mark anything paid (§41)
8 ⇢ VerifyPaystackTransaction: GET /transaction/verify/{reference}
   compares status==success, amount==order total (minor units), currency match
9 ⇢ ConfirmPayment (idempotent):
   payments.status=success · orders.status=paid · order_events row
   invoices.status=paid · EntitlementGranter → entitlements + events
   FulfilOrder → Lms enrollment / tutoring booking / download rights
   📧 PaymentConfirmation (purchaser) · 📧 AccessGranted (beneficiary) · 📧 to tutor
   📝 audit: payments.succeeded, orders.paid, entitlements.granted
10 ⇢ Independently, the webhook arrives → payment_events (UQ) → same ConfirmPayment,
    which is a no-op the second time

⚠ Insufficient funds / abandoned → charge.failed → payments.status=failed,
   orders.status=failed, reserved slot released, 📧 FailedPayment with a retry link
⚠ Amount mismatch on verify → order NOT marked paid, 📝 audit, alert to finance
⚠ Webhook signature invalid → 4xx, no state change, 📝 warning log with IP (§42)
⚠ Duplicate webhook ×3 → exactly one payment/entitlement/session (§42)
⚠ Webhook lost entirely → payments:reconcile (every 5 min) re-verifies and recovers
⚠ User closes the browser mid-payment → order stays pending; reconciliation resolves it;
   cart is recoverable
```
**Coverage:** §10, §19, §41, §42, §44, §45. **Tests:** the whole `tests/Payment/` suite
(§72) plus `CheckoutTest`, `MinorPurchaseGuardTest`.

### W9 — Digital product purchase → secure download

```
1 → /store/{slug}: an e-book, ₦3,500. Preview sample downloadable by anyone.
2 → Buy (for self, or as a gift for a child → beneficiary selected)
3 ⇢ W8 checkout → entitlement (type=digital_product, owner=parent, beneficiary=child,
   download_limit=5, ends_at = purchase + validity_days)
4 → Parent → Library → Download
   🔒 DownloadAuthorizer: authenticated? → purchased? → payment confirmed? →
      entitlement active? → downloads_used < limit? → not expired?
   ⇢ digital_product_downloads ledger row (user, ip, ua, bytes, at) (§40)
   ⇢ streamed response, sanitized Content-Disposition, no direct file URL exposed
   ⚠ Limit reached → "You've downloaded this 5 times. Contact support." (not a 500)
   ⚠ Entitlement expired → renewal prompt
   ⚠ Guessing /files/{other-uuid}/download → 403, and UUIDs are v4 (unguessable)
   ⚠ 31st download in 5 minutes → 429 (anti-abuse rate limit)
```
**Coverage:** §39, §40. **Tests:** `DownloadAuthorizationTest`, `DownloadLimitTest`,
`ExpiredEntitlementBlockedTest`, `DownloadLedgerTest`, `DownloadRateLimitTest`,
`GuessableUrlTest`.

### W10 — Refund

```
1 → Finance Officer opens an order → Refund (full or a specific line)
   🔒 payments.refund
2 → Reason + note required; amount validated ≤ paid − already refunded
3 ⇢ refunds (status=pending) · provider refund call where Paystack supports it
4 ⇢ On success: refunds.status=processed · payments.status=refunded ·
   orders.status=refunded|partially_refunded · order_events row
5 ⇢ Entitlements revoked → entitlement_events · course enrollments suspended ·
   unused tutoring sessions cancelled · download rights removed
6 📧 RefundProcessed to the customer · 📝 audit (§56 explicitly lists refunds)
⚠ Refunding an already-refunded payment → refused with the existing refund shown
⚠ Partial refund of a package with sessions already consumed → refuses to revoke
   consumed sessions; explains what remains
⚠ Provider refund unsupported → refunds.status=pending + a manual-action checklist for
   finance (explicit, not silently "refunded") (§93)
```
**Coverage:** §41, §44, §45, §56. **Tests:** `RefundTest`, `PartialRefundTest`,
`RefundRevokesEntitlementTest`, `ConsumedSessionsNotRevokedTest`.

### W11 — Subscription lifecycle (monthly tutoring)

```
1 → Parent subscribes to "Monthly Mathematics Tutoring — ₦30,000/month" for Chidi
   ⇢ ★ MinorPurchaseGuard runs on subscription creation too (§10)
2 ⇢ Paystack plan created/synced → products.provider_plan_code
3 ⇢ subscription.create webhook → subscriptions (status=active, next_billing_at) +
   subscription_events · UQ(provider, provider_subscription_id)
4 ⇢ Entitlement granted (type=subscription_access + tutoring sessions_included per cycle)
5 ⇢ Each month: charge.success with the subscription reference
   → new invoice + payment · entitlement EXTENDED (never duplicated) · sessions replenished
   📧 SubscriptionRenewed + receipt
6 ⚠ charge.failed → status=past_due → dunning: retry per settings, grace period,
   📧 PaymentRetryNotice, then 📧 SubscriptionPastDueWarning. Access continues during
   grace, suspends after.
7 → Parent cancels (self-service, no phone call required)
   ⇢ provider cancel + subscriptions.status=cancelled + cancelled_at + event row
   ⇢ access retained until ends_at (§43) · 📧 SubscriptionCancelled with the end date
8 ⇢ subscriptions:check (scheduled) expires lapsed subscriptions and their entitlements
⚠ Duplicate subscription.create webhook → UQ blocks the second row (§42)
```
**Coverage:** §43. **Tests:** `SubscribeTest`, `RenewalTest`,
`RenewalExtendsNotDuplicatesTest`, `PastDueTest`, `CancellationTest`,
`AccessRetainedUntilPeriodEndTest`, `MinorGuardOnSubscriptionTest`.

### W12 — Admin-assisted checkout (phone/support sales)

```
1 → Operations Officer: Admin → Orders → "New order"
2 → Searches the parent by name/email/phone, selects the child, adds products
3 ⇢ THE SAME CheckoutService runs — including ★ MinorPurchaseGuard
   🔒 orders.admin_assisted_checkout; orders.placed_by_user_id = the officer
4 → Records an offline/manual payment (bank transfer) OR sends a Paystack payment link
   🔒 payments.record_manual for the offline path
5 ⇢ On confirmation: the identical entitlement-granting path as W8
   📧 receipt to the parent · 📝 audit records the assisting officer
⚠ An officer without commerce.override_minor_restriction CANNOT bypass the minor rule.
   The override is a separate Super-Admin permission, always audited (§10, §56)
```
**Coverage:** §10 ("admin-assisted checkout"), §44. **Tests:**
`AdminAssistedCheckoutTest`, `AdminAssistedBlockedForMinorTest`,
`OverrideRequiresPermissionTest`, `ManualPaymentTest`.

---

## D. Learning & assessment workflows

### W13 — Course authoring → publishing → enrollment

```
1 → Academic Admin/Tutor creates a course (draft): title, level, subject, description
2 → Builds modules → lessons (text/video/audio/document/embed/external/quiz/assignment)
   ⇢ rich text sanitized on write; embeds allowlisted; files → files registry
3 → Attaches assessments from the question bank, sets weights + grading scheme
4 → Submits for review (courses.submit_for_review) → status review
5 → Reviewer checks, then publishes (courses.publish) → status published + published_at
   📝 audit · ⇢ cache invalidation · 📧 CoursePublished to interested cohorts
6 → Enrollment paths: manual · program learning path · paid purchase · parent purchase ·
   admin assignment · tutoring entitlement — each records its `source` (§19)
7 → Unpublish ⇄ archive as needed; enrollments are preserved, purchases are honoured
⚠ Publishing a course with zero lessons → refused with a checklist
⚠ Unpublished courses cannot be enrolled in or purchased (§85)
⚠ Deleting a course with enrollments → RESTRICT; archive instead (§66)
```
**Coverage:** §7, §16–§19, §85. **Tests:** `CourseBuilderTest`, `PublishingWorkflowTest`,
`UnpublishedNotEnrollableTest`, `LessonSanitizationTest`, `EnrollmentSourceRecordedTest`.

### W14 — Quiz attempt

```
1 → Student opens an open quiz (window: publish_at → close_at)
2 ⇢ Server checks attempts_allowed, then creates assessment_submissions with a
   question_snapshot (shuffled order if configured) — the shuffle is fixed for this attempt
3 → Answers questions; the timer counts down (duration enforced SERVER-SIDE)
4 → Submits → submission_answers auto-scored (MCQ/multi-select/TF/matching with partial
   credit via option weights); short/long answers queued for manual marking
5 ⇢ GradeResolver → percentage → band → letter + GPA; assessment_submissions updated
6 → Results shown per quiz_settings.show_results (immediately / after close / after
   grading / never) and show_correct_answers
7 📧 AssessmentResult to the student (+ parent if allowed)
⚠ Timer expires without submitting → auto-submitted with what exists, marked late per policy
⚠ Second attempt beyond the limit → refused; the previous result is shown
⚠ Submitting after close_at → late_policy decides (reject / accept with penalty / accept)
⚠ Editing questions mid-attempt does not change an in-flight attempt (snapshot) (§21)
⚠ Browser refresh → the attempt resumes from the snapshot, not restarted
```
**Coverage:** §21, §23. **Tests:** `QuizAttemptTest`, `ServerSideTimerTest`,
`AttemptLimitTest`, `AutoScoringTest`, `PartialCreditTest`, `RandomizationSnapshotTest`,
`LatePolicyTest`.

### W15 — Assignment submission → grading → return → resubmission

```
1 → Student opens the assignment: instructions, due date, attachments, upload rules
2 → Uploads files and/or writes a submission → submits
   ⇢ assessment_submissions (version=1, is_current=true, status=submitted|late)
   ⇢ files (category=assignment, visibility=restricted) · submission_files link
3 📧 AssignmentSubmitted to the assessor · 📝 due-date snapshot recorded
4 → Tutor grades: per-question/per-criterion scores, overall score ≤ max_score, feedback
   ⇢ GradeResolver → grade · status=graded · graded_by/graded_at
5 → Returns with feedback ⇢ status=returned · 📧 AssignmentReturned
6 → Student resubmits (if allowed and inside the window)
   ⇢ NEW submission row (version=2, is_current=true); version 1 stays is_current=false
   → history is preserved for academic-integrity disputes
7 → Results roll into the gradebook → academic_results → report card
⚠ Wrong file type / too many files / too large → refused at validation
⚠ Resubmission after the deadline → blocked unless reopened (submissions.reopen, audited)
⚠ Grading someone else's assessment → 403 unless assigned (submissions.grade + scope)
⚠ Score > max_score → validation error (never silently clamped)
```
**Coverage:** §22, §23, §25. **Tests:** `AssignmentSubmissionTest`, `LateSubmissionTest`,
`ResubmissionVersioningTest`, `GradingTest`, `ScoreBoundaryTest`, `ReturnSubmissionTest`,
`ReopenAuditTest`.

### W16 — Term results → report card → certificate

```
1 → Close of term: academic admin runs "Generate results" for a cohort
   ⇢ GradebookAggregator: weighted assessment scores per student per course/subject
   ⇢ GradeResolver applies the scheme in force → academic_results (status=provisional)
2 → Review + adjust (grades.amend) — each amendment appends + audits
3 → Publish results (grades.publish) ⇢ status=published, published_at
   📧 ResultsPublished to students + parents (per visibility flags)
4 → Generate report cards ⇢ academic_reports + queued PDF (dompdf) → files
   🔒 parent download gated by can_view_academics
5 → Certificate issuance for completions ⇢ certificates (unique number + verification
   code) + queued PDF with QR → 📧 CertificateIssued
6 → Public verification at /certificates/verify/{code}
   ⇢ shows name, award title, issuer, completion date ONLY (§26)
   ⚠ Revoked certificate → "This certificate has been revoked" (never a 404)
   ⚠ Unknown code → "No certificate found" + rate-limited to stop enumeration
7 ⇢ Published results are immutable — a later scheme edit cannot rewrite history (§25)
```
**Coverage:** §24, §25, §26. **Tests:** `GradebookAggregationTest`, `ResultsPublicationTest`,
`PublishedResultImmutableTest`, `GradeAmendmentAuditTest`, `ReportCardGenerationTest`,
`CertificateIssuanceTest`, `PublicVerificationTest`, `NoPrivateDataLeakedTest`.

---

## E. Scheduling, sessions & communication

### W17 — Cohort schedule → generated sessions → reminders

```
1 → Create a schedule on a cohort: Tue & Thu 16:00–17:00 Africa/Lagos, from 15 Sep to
   18 Dec, provider=google_meet, auto-generate on
2 ⇢ sessions:generate (command or scheduler) materializes course_sessions
   ⇢ each gets a meetings row (status=provisioning → ready once the join URL exists)
   ⇢ non_working_dates and tutor conflicts are skipped, with a report of skipped dates
3 📧 ClassReminder at T-24h and T-1h to students + parents + instructor (deduplicated)
4 → Instructor starts the class → status=in_progress → completes it with a summary
5 ⇢ attendance prompt · learning_activities rows
6 → Reschedule one occurrence ⇢ update + meeting update at the provider + 📧 change notice
7 → Cancel an occurrence ⇢ reason recorded · 📧 SessionCancelled · make-up option
⚠ Regenerating is idempotent per (schedule, date) — no duplicate sessions
⚠ Provider not configured → meeting created as provider=manual; the UI shows
   "Add a meeting link" instead of a dead Join button (§93)
⚠ DST boundary → sessions keep their local wall-clock time; UTC values shift correctly
```
**Coverage:** §14, §36/37, §51, §62. **Tests:** `SessionGenerationTest`,
`IdempotentGenerationTest`, `HolidaySuppressionTest`, `RescheduleTest`,
`CancellationNoticeTest`, `DstBoundaryTest`.

### W18 — Unified calendar (§53)

```
Parent   → children's classes, tutoring sessions, assessments, enrolment deadlines
Student  → classes, tutoring, assignment due dates, exams
Tutor    → cohort classes, tutoring sessions, (interviews if also an evaluator)
Evaluator→ assigned interviews only
Admin    → everything, filterable by module/instructor/cohort
⇢ One query object per role with an authorization scope applied BEFORE filtering
⇢ Month/week/agenda views; timezone-converted to the viewer; iCal export (optional)
⚠ A parent never sees another child's events; a tutor never sees sessions they don't teach
```
**Tests:** `CalendarRoleScopingTest` (one case per role), `CalendarTimezoneTest`.

### W19 — Announcements & notifications

```
1 → Admin composes an announcement: title, body, audience (all / students / parents /
   tutors / cohort X / course Y / program Z), priority, publish window, pin
2 ⇢ AudienceResolver expands targets to concrete user ids at dispatch time
3 ⇢ Queued dispatch: database (in-app bell) + email (per message_templates)
4 ⇢ notification_preferences honoured — EXCEPT transactional keys, which cannot be
   disabled (§82)
5 → Bulk send to several audiences at once (announcements.bulk_send)
⚠ Marketing content requires an opt-in audience filter; users who never opted in are
   excluded automatically (§83)
⚠ A failed email is retried by the queue, then lands in failed_jobs with an alert
```
**Coverage:** §51, §52, §82, §83. **Tests:** `AnnouncementAudienceTest`,
`NotificationPreferenceTest`, `TransactionalCannotBeDisabledTest`,
`MarketingOptInDefaultFalseTest`, `BulkSendTest`.

### W20 — Global search (§54)

```
1 → ⌘K / search box: "chidi maths"
2 ⇢ SearchIndex fans out across authorized providers: students, parents, tutors, courses,
   programs, cohorts, products, orders, assessments
3 ⇢ EACH provider applies its authorization scope FIRST, then filters
4 ⇢ Grouped, paginated results with type badges and deep links
⚠ A Finance Officer searching "chidi" sees orders but not the student's DOB or notes
⚠ An Evaluator sees applications, never orders
⚠ A Parent sees only their children
⚠ Empty result → real empty state with suggestions, not an error
```
**Tests:** `GlobalSearchTest`, `SearchAuthorizationTest` (one case per role).

---

## F. Guardian, privacy & safety workflows

### W21 — Guardian link management (§9)

```
1 → Admin links a guardian to a student: relationship, capabilities
   (can_purchase, can_view_academics, can_view_financials, can_receive_communications,
    is_primary), effective dates, consent version
2 ⇢ parent_student row · 📝 audit (before/after) · 📧 GuardianLinked
3 → Guardian has no account yet ⇢ parents.status=invited + hashed invite_token
   → they register and claim the record by matching email
4 → Second guardian added (multiple guardians supported)
5 → Capabilities changed (e.g. revoke purchasing after a custody change)
   ⇢ 📝 audit · access changes take effect immediately (cache invalidated)
6 → Unlink ⇢ status=revoked + reason + effective_to · 📝 audit · 📧 GuardianUnlinked
   ⇢ portal access to that child stops on the next request
⚠ Revoking a link does NOT delete historical orders/grades (they are records)
⚠ A student must always retain ≥1 active guardian with can_receive_communications,
   or admins are warned (configurable, not blocking)
```
**Tests:** `GuardianLinkTest`, `CapabilityChangeAuditTest`, `UnlinkRevokesAccessTest`,
`GuardianInviteClaimTest`, `MultipleGuardiansTest`.

### W22 — Data privacy, retention & deletion (§58)

```
1 → A parent requests their child's data / deletion
2 → Admin runs the retention workflow: export (all linked records, queued) → then
   anonymize or archive per settings.retention.*
3 ⇢ Students are soft-deleted + status=withdrawn; academic_results and orders are
   RETAINED (financial/legal records) but pseudonymized where the law allows
4 ⇢ Restricted files are purged from disk after the retention window; the files row
   keeps a tombstone with checksum + deleted_at
5 ⇢ consents rows record the erasure request
6 📝 audit for every step
⚠ Hard-deleting a student with grades → blocked by FK RESTRICT; the correct operation
   is withdrawal + anonymization (§66)
```
**Tests:** `RetentionCommandTest`, `AnonymizationTest`, `RestrictedFilePurgeTest`,
`HardDeleteBlockedTest`.

### W23 — Minor attempts a restricted purchase (the §10 negative path)

```
1 → A 15-year-old student, logged in, clicks "Book this tutor"
2 ⇢ CheckoutService → ★ MinorPurchaseGuard
   age_at(today) = 15 < age_of_majority (18, from settings)
   product.requires_parent_purchase = true
   no active parent_student link where the purchaser is the guardian → REJECT
3 ⇢ PurchaseRestrictedForMinor thrown (a DOMAIN exception, not a validation error)
4 → UI: "A parent or guardian must complete this purchase for you.
        [Invite a guardian] [Ask your guardian to buy] [Contact support]"
   ⇢ optional 📧 nudge to the linked guardian
5 📝 audit: purchase.blocked_minor (so patterns are visible without exposing the child)
⚠ No order, no invoice, no payment, no entitlement is created — the rejection happens
   BEFORE any money object exists
⚠ The same rejection occurs through: web UI · admin-assisted checkout · future /api/v1 ·
   subscription creation (one choke point, four entry points) (§10)
⚠ DOB unknown → treated as a minor (fail-safe), with an admin prompt to record the DOB
⚠ Guardian link exists but can_purchase=false → still rejected
⚠ Guardian link revoked/expired → still rejected
✅ Non-restricted products (e.g. a ₦500 worksheet) may still be purchased by the student
   if settings allow — the rule is per-product, not a blanket ban
```
**Tests:** `MinorPurchaseGuardTest` (unit, 8 cases), `CheckoutBlockedForMinorTest`,
`AdminAssistedBlockedForMinorTest`, `ApiBlockedForMinorTest`,
`SubscriptionBlockedForMinorTest`, `UnknownDobFailsSafeTest`,
`RevokedGuardianLinkRejectedTest`, `NoMoneyObjectsCreatedOnRejectionTest`.

### W24 — Payment dispute / stuck-order recovery

```
1 → A parent emails: "I was charged but nothing shows."
2 → Finance opens Admin → Payments → Reconcile, or waits for payments:reconcile (5-min cron)
3 ⇢ Reconciliation finds orders in awaiting_confirmation older than N minutes,
   re-verifies each reference with Paystack, and:
   • provider says success → ConfirmPayment runs (idempotent) → entitlements granted
   • provider says failed  → order marked failed, parent notified, refund checked
   • provider says pending → left alone, retried next cycle
4 ⇢ integration_logs carries the full request/response trail for the investigation
5 📧 Resolution to the parent · 📝 audit
⚠ Amount mismatch → NOT auto-confirmed; escalated to a human with an alert
⚠ Reference unknown to Paystack → flagged as suspicious, logged, never marked paid
```
**Coverage:** §41, §77. **Tests:** `ReconciliationTest`, `StuckOrderRecoveredTest`,
`AmountMismatchEscalatedTest`, `UnknownReferenceFlaggedTest`.

---

## 3. Workflow → notification map (§52)

| Workflow | Transactional emails (cannot be disabled) | Optional / digest |
|----------|-------------------------------------------|-------------------|
| W1, W5 | Email verification, Password reset, Welcome, Payment confirmation, Failed payment, Tutoring booked, Session reminder, Receipt | Progress digest, Announcement |
| W2 | Application received, Interview scheduled, Tutor approved, Tutor rejected | Review published, Earnings summary (weekly) |
| W3 | — | Weekly operations digest |
| W4 | Application received, Offer, Waitlist, Rejection, Enrollment confirmation | — |
| W8, W10, W11, W12 | Payment confirmation, Failed payment, Refund processed, Subscription renewed, Subscription past due, Subscription cancelled, Invoice issued | — |
| W13 | Course announcement, Course published | — |
| W14, W15 | Assignment due reminder, Assessment result published, Assignment returned | — |
| W16 | Results published, Report card available, Certificate issued | — |
| W17 | Class reminder, Session cancelled, Session rescheduled | — |
| W19 | (announcement email itself) | Marketing — **opt-in only** (§83) |
| W21, W23 | Guardian linked, Guardian unlinked, Guardian nudge | — |

All 16 template keys from §52 are covered: welcome, email verification, password reset,
student enrollment, parent enrollment, payment confirmation, failed payment, tutoring
booking, session reminder, course announcement, assignment due, assessment result, tutor
application received, interview scheduled, tutor approved, tutor rejected.

---

## 4. Workflow → test index

Every workflow above has at least one named feature test; the negative paths (⚠) are tests
too, not comments. Full test tree in [01 §6](01-system-architecture.md#6-testing-architecture-71-72);
phase assignment in [10](10-phased-implementation-plan.md).
