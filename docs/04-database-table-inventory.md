# 04 — Database Table Inventory

**Deliverable:** spec §102.4 — *"A complete database table inventory."*
Also satisfies §66 (normalization, FKs, indexes, uniques, statuses, audit, soft deletes,
cascading, migration ordering).

**Totals: 124 tables** — 110 application-owned + 14 framework/vendor-owned.
Column-level detail for the primary aggregates is in [03-database-erd.md](03-database-erd.md);
this document is the authoritative registry: what exists, why, when it is built, and how it
is constrained.

---

## 0. Global column conventions

| Convention | Rule |
|------------|------|
| Primary key | `id BIGINT UNSIGNED AUTO_INCREMENT` — chosen over UUID PKs for InnoDB index locality on shared hosting. A separate `uuid CHAR(36)` column is added where a record is referenced in a URL or by an external system |
| Character set | `utf8mb4` / collation `utf8mb4_unicode_ci` (names, diacritics, emoji in reviews) |
| Engine | InnoDB, `ROW_FORMAT=DYNAMIC` |
| Timestamps | `created_at` / `updated_at` nullable timestamps on mutable tables. **Append-only `*_events` tables have `created_at` only** — no `updated_at`, because a history row must never change |
| Instants | `DATETIME` in **UTC**, column name ends in `_at` (§62) |
| Wall-clock patterns | `TIME` + `timezone VARCHAR(64)` + `*_at_local VARCHAR(32)` for recurring schedules |
| Dates | `DATE` for calendar days without time semantics (`date_of_birth`, `starts_at` on a term) |
| Money | `*_amount_minor BIGINT UNSIGNED` + `*_currency CHAR(3)` — **never DECIMAL, never FLOAT** (ADR-02) |
| Scores / percentages | `DECIMAL(8,2)` scores, `DECIMAL(5,2)` percentages — exact decimal arithmetic |
| Enums | `VARCHAR` + PHP `enum` + DB `CHECK` where the value set is stable, so adding a status never needs `ALTER TABLE … MODIFY` on a large table. True MySQL `ENUM` is used only on small, frozen sets |
| Booleans | `TINYINT(1)`, always with an explicit default |
| JSON | MySQL 8 `JSON` type for genuinely schemaless payloads (`meta`, `provider_payload`, `question_snapshot`). **Never** for data that is queried by predicate — that gets a real column and index |
| Soft deletes | `deleted_at TIMESTAMP NULL` only where §58 requires archival rather than destruction |
| FK columns | `BIGINT UNSIGNED NULL` where the relationship is optional, `NOT NULL` where mandatory |
| Naming | plural snake_case tables; `*_id` FKs; `is_*`/`has_*` booleans; `*_count` denormalized counters |

### Cascading behaviour (§66.10)

| Rule | Applied to | Rationale |
|------|-----------|-----------|
| `ON DELETE CASCADE` | Owned child detail rows: `question_options`, `grading_scale_bands`, `order_items`→`invoice_items`, `lesson_topics`, `lesson_resources`, `*_events` history rows, `cart_items`, `announcement_targets`, `evaluation_form_criteria`, `evaluation_criterion_scores`, `submission_answers`, `digital_product_files`, `import_batch_rows` | They have no meaning without the parent |
| `ON DELETE RESTRICT` | Everything referencing `students`, `users`, `orders`, `payments`, `entitlements`, `assessments`, `tutor_profiles`, `academic_terms` | **Academic and financial records are never silently destroyed.** Deleting a student with grades must fail loudly; the correct operation is `status = withdrawn` + soft delete |
| `ON DELETE SET NULL` | Optional context links: `assessments.cohort_id`, `orders.coupon_id`, `students.program_id`, `courses.subject_id`, `tutoring_services.assigned_tutor_id` | Removing context shouldn't orphan or destroy the record |
| No FK (polymorphic) | `files.fileable_*`, `meetings.meetingable_*`, `audit_logs.auditable_*`, `attendance_records.session_*`, `session_notes.session_*`, `entitlements.source_*`, `products.productable_*`, `tutor_qualifications.owner_*` | Enforced in the application layer + `CHECK` on the discriminator where valuable |

---

## 1. Identity & platform core — 20 tables · **Phase 1**

| # | Table | Purpose | Key columns beyond the ERD | Indexes / constraints | Soft del | Audit |
|---|-------|---------|---------------------------|----------------------|:---:|:---:|
| 1 | `users` | Central authentication subject (§6) | see ERD §2 | `UQ(email)`, `UQ(uuid)`, `IX(status)`, `IX(email_verified_at)` | ✅ | ✅ |
| 2 | `password_reset_tokens` | Laravel password broker | `email`, `token`, `created_at` | `PK(email)` | — | — |
| 3 | `sessions` | Database session driver (§69) | `id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity` | `IX(user_id)`, `IX(last_activity)` | — | — |
| 4 | `cache` | Database cache store | `key`, `value`, `expiration` | `PK(key)` | — | — |
| 5 | `cache_locks` | Cache locks (atomic slot holds) | `key`, `owner`, `expiration` | `PK(key)` | — | — |
| 6 | `jobs` | Database queue (§69) | `queue`, `payload`, `attempts`, `reserved_at`, `available_at` | `IX(queue, reserved_at)` | — | — |
| 7 | `job_batches` | Batch tracking for bulk imports/reports | `id`, `name`, `total_jobs`, `pending_jobs`, `failed_jobs` | — | — | — |
| 8 | `failed_jobs` | Failed job ledger | `uuid`, `connection`, `queue`, `payload`, `exception`, `failed_at` | `UQ(uuid)` | — | — |
| 9 | `notifications` | Laravel database channel (§51 in-app) | `id(uuid)`, `type`, `notifiable_*`, `data`, `read_at` | `IX(notifiable_type, notifiable_id, read_at)` | — | — |
| 10 | `personal_access_tokens` | Sanctum — **future `/api/v1`** (§68) | `tokenable_*`, `name`, `token`, `abilities`, `last_used_at`, `expires_at` | `UQ(token)` | — | — |
| 11 | `roles` | spatie/laravel-permission (§6) | `name`, `guard_name` | `UQ(name, guard_name)` | — | ✅ |
| 12 | `permissions` | spatie/laravel-permission | `name`, `guard_name` | `UQ(name, guard_name)` | — | ✅ |
| 13 | `model_has_roles` | user↔role | `role_id`, `model_type`, `model_id` | `PK(role_id, model_type, model_id)`, `IX(model_type, model_id)` | — | ✅ |
| 14 | `model_has_permissions` | user↔direct permission (rare, for exceptions) | as above | `PK(permission_id, model_type, model_id)` | — | ✅ |
| 15 | `role_has_permissions` | role↔permission | `permission_id`, `role_id` | `PK(permission_id, role_id)` | — | ✅ |
| 16 | `connected_accounts` | Google/Microsoft/Zoom OAuth tokens, **encrypted** (§36, §37) | see ERD §2 | `UQ(user_id, provider, purpose)`, `IX(provider, status)`, `IX(expires_at)` | — | ✅ |
| 17 | `consents` | Privacy/marketing/data-processing consent records (§58, §83) | see ERD §2 | `IX(user_id, type)`, `IX(student_id, type)` | — | ✅ (append-only) |
| 18 | `settings` | DB-driven non-secret configuration (§60, §95) | see ERD §2 | `UQ(key)`, `IX(group)` | — | ✅ |
| 19 | `audit_logs` | Generic who/what/before/after (§56) | see ERD §2 | `IX(auditable_type, auditable_id)`, `IX(user_id, created_at)`, `IX(event, created_at)`, `IX(created_at)` for retention pruning | — | self |
| 20 | `files` | Centralized file registry with visibility classes (§40, §59) | see ERD §2 | `UQ(uuid)`, `IX(fileable_type, fileable_id)`, `IX(category, visibility)`, `IX(checksum_sha256)` for de-dup | ✅ | ✅ |

**Phase 1 also seeds:** 214 permissions, 11 roles, and ~60 settings (organization, currency,
timezone, `age_of_majority`, grading default, platform fee, retention, feature flags).

---

## 2. Education — 22 tables · **Phase 2**

| # | Table | Purpose | Indexes / constraints | Notes |
|---|-------|---------|----------------------|-------|
| 21 | `academic_years` | §7 | `UQ(name)`, `IX(status)`, `IX(starts_at, ends_at)` | Service layer enforces exactly one `active`; a partial unique index isn't available in MySQL, so the invariant lives in `AcademicCalendarService` inside a transaction with `lockForUpdate` |
| 22 | `academic_terms` | §7 | `UQ(academic_year_id, code)`, `IX(academic_year_id, status)` | Non-overlap validated by `ValidAcademicDateRange` rule |
| 23 | `academic_levels` | §7/§8 | `UQ(name)`, `UQ(code)`, `IX(position)` | Configurable — **no hard-coded "Primary 5"** (§95) |
| 24 | `programs` | §7 | `UQ(code)`, `IX(status)`, `FULLTEXT(name, description)` | Learning-path parent |
| 25 | `program_courses` | §18 learning path | `UQ(program_id, course_id)`, `IX(program_id, position)` | `is_required`, `prerequisite_program_cycle_id`; cycle detection validated in service |
| 26 | `subjects` | §7 | `UQ(code)`, `IX(academic_level_id)`, `IX(status)` | Reusable across programs and courses |
| 27 | `course_subject` | course↔subject M:N | `PK(course_id, subject_id)` | A course can span Mathematics + Further Maths |
| 28 | `students` | §8 | `UQ(student_code)`, `UQ(user_id)`, `IX(status)`, `IX(academic_year_id, status)`, `IX(academic_level_id)`, `FULLTEXT(first_name, middle_name, last_name, email)` | `student_code` generated from a configurable pattern in `settings` |
| 29 | `parents` | §9 | `UQ(user_id)`, `IX(email)`, `IX(status)` | First-class Guardian entity; may predate registration |
| 30 | `parent_student` | §9/§10 relationship | `UQ(parent_id, student_id)`, `IX(student_id, status)`, `IX(parent_id, status)` | **`can_purchase`** is the §10 authorization flag |
| 31 | `student_applications` | §11 admissions | `UQ(reference)`, `IX(status, submitted_at)`, `IX(program_id, status)`, `IX(assigned_reviewer_id)` | Self-service or admin-entered |
| 32 | `student_application_documents` | §11 supporting documents | `IX(student_application_id)` | `document_type`, `file_id`, `is_verified` |
| 33 | `student_application_events` | §11 status history | `IX(student_application_id, created_at)` | Append-only |
| 34 | `program_enrollments` | §12 | `UQ(student_id, program_id, academic_year_id, academic_term_id)`, `IX(student_id, status)`, `IX(program_id, academic_year_id)` | `entitlement_id` links a paid enrollment to its commerce origin (§12, §19) |
| 35 | `cohorts` | §13 student groups / §16 batches | `UQ(code)`, `IX(academic_year_id, status)`, `IX(instructor_id)`, `IX(course_id)` | Named e.g. "Primary 5 Mathematics — September 2026 Cohort" |
| 36 | `cohort_student` | §13 membership | `UQ(cohort_id, student_id)`, `IX(student_id, status)` | Supports bulk enrollment (§81) |
| 37 | `course_schedules` | §14 recurring pattern | `IX(cohort_id, weekday)`, `IX(status)` | Stores wall-clock local time + IANA timezone |
| 38 | `course_sessions` | §14 materialized occurrence | `IX(starts_at)`, `IX(cohort_id, starts_at)`, `IX(instructor_id, starts_at)`, `IX(status, starts_at)` | Generated by `sessions:generate` command; UTC + local columns |
| 39 | `attendance_records` | §15 | `UQ(session_type, session_id, student_id)`, `IX(student_id, created_at)`, `IX(status)` | Polymorphic session so one table serves classes **and** tutoring (§15) |
| 40 | `non_working_dates` | §32 holidays | `IX(on_date)`, `IX(scope, scope_id)` | Suppresses session generation and tutoring slots |
| 41 | `announcements` | §51/§84 | `IX(status, publish_at)`, `IX(audience_type)` | Draft/published workflow |
| 42 | `announcement_targets` | audience scoping | `UQ(announcement_id, target_type, target_id)` | Cohort/course/program/student/user targets |

---

## 3. LMS — 13 tables · **Phase 3**

| # | Table | Purpose | Indexes / constraints | Notes |
|---|-------|---------|----------------------|-------|
| 43 | `courses` | §7/§16 delivery object | `UQ(slug)`, `IX(status)`, `IX(academic_level_id)`, `FULLTEXT(title, description)` | Publishing states per §85; only `published` is enrollable |
| 44 | `course_modules` | §17 | `UQ(course_id, position)`, `IX(course_id)` | Optional drip via `available_from` |
| 45 | `lessons` | §17 | `UQ(course_module_id, position)`, `IX(course_module_id)`, `IX(type)` | Body sanitized on write **and** on render |
| 46 | `lesson_topics` | §16 "Topics" | `IX(lesson_id, position)` | Sub-sections of a lesson |
| 47 | `lesson_resources` | §17 resources | `IX(lesson_id, position)`, `IX(file_id)` | Downloadable vs reference |
| 48 | `course_enrollments` | §19 | `UQ(student_id, course_id, cohort_id)`, `IX(course_id, status)`, `IX(student_id, status)`, `IX(entitlement_id)` | `source` records **how** access was gained (§19) |
| 49 | `course_progress` | §20 rollup | `UQ(course_enrollment_id)`, `IX(student_id, last_activity_at)` | Denormalized for dashboard speed (§78) |
| 50 | `lesson_progress` | §20 | `UQ(student_id, lesson_id)`, `IX(course_enrollment_id, status)` | Time-on-task tracking |
| 51 | `learning_activities` | §20 feed | `IX(student_id, occurred_at)`, `IX(course_id, activity_type)` | Append-only; partition candidate at scale |
| 52 | `discussions` | §16 | `IX(discussion_type, discussion_scope_id)`, `IX(status)` | Course/lesson/cohort scoped |
| 53 | `discussion_posts` | §16 | `IX(discussion_id, created_at)`, `IX(parent_post_id)` | Threaded, moderated |
| 54 | `certificate_templates` | §26 | `IX(is_active)` | Layout + signatories, DB-configurable |
| 55 | `certificates` | §26 | `UQ(number)`, `UQ(verification_code)`, `IX(student_id)`, `IX(awardable_type, awardable_id)` | Public verification exposes name/title/date only (§26) |

---

## 4. Assessment — 12 tables · **Phase 3–4**

| # | Table | Purpose | Indexes / constraints | Notes |
|---|-------|---------|----------------------|-------|
| 56 | `assessments` | §23 unified umbrella | `IX(status, due_at)`, `IX(course_id, status)`, `IX(cohort_id)`, `IX(academic_term_id)`, `IX(type)` | One table for quiz/assignment/exam/project/CA/tutor assessment |
| 57 | `quiz_settings` | §21 | `PK(assessment_id)` = FK | 1:1; duration, attempts, shuffle, result visibility |
| 58 | `assignment_details` | §22 | `PK(assessment_id)` = FK | 1:1; upload rules, resubmission policy |
| 59 | `questions` | §21 reusable bank | `UQ(question_code)`, `IX(type, is_active)`, `IX(subject_id)`, `FULLTEXT(stem)` | Six question types |
| 60 | `question_options` | §21 | `IX(question_id, position)` | `is_correct` + `weight` for partial credit |
| 61 | `assessment_questions` | §21 | `UQ(assessment_id, question_id)`, `IX(assessment_id, position)` | Per-assessment order + point override |
| 62 | `assessment_submissions` | §22 | `UQ(assessment_id, student_id, version)`, `IX(student_id, status)`, `IX(assessment_id, is_current)`, `IX(due_at_snapshot)` | `question_snapshot` preserves randomization (§21) |
| 63 | `submission_answers` | §22 | `UQ(submission_id, question_id)` | Per-question score + marker comment |
| 64 | `grading_schemes` | §24 | `UQ(name)`, `IX(scope, scope_id)`, `IX(is_default)` | **Configurable, never hard-coded** |
| 65 | `grading_scale_bands` | §24 | `IX(grading_scheme_id, position)`, `CHECK(max >= min)` | Contiguity validated in `GradingSchemeValidator` |
| 66 | `academic_results` | §25 gradebook | `UQ(student_id, academic_term_id, course_id, subject_id)`, `IX(academic_term_id, course_id)`, `IX(student_id, academic_year_id)` | Immutable once `published`; amendments create a new row + audit |
| 67 | `academic_reports` | §25 report cards | `UQ(student_id, academic_term_id, report_type)` | Rendered PDF via queued job |

---

## 5. Tutoring & recruitment — 25 tables · **Phase 6–7**

| # | Table | Purpose | Indexes / constraints | Notes |
|---|-------|---------|----------------------|-------|
| 68 | `tutor_applications` | §27 | `UQ(reference)`, `IX(status, submitted_at)`, `IX(assigned_evaluator_id, status)`, `IX(email)` | Nine-state workflow |
| 69 | `tutor_application_documents` | §27 CV/certificates | `IX(tutor_application_id)`, `IX(document_type)` | Files are `private` visibility |
| 70 | `tutor_application_events` | §27/§56 history | `IX(tutor_application_id, created_at)` | Append-only |
| 71 | `tutor_qualifications` | §27/§31 | `IX(owner_type, owner_id)`, `IX(kind)` | Shared by application **and** approved profile (polymorphic owner) |
| 72 | `tutor_work_experiences` | §27 | `IX(owner_type, owner_id)` | `is_teaching_role` feeds experience scoring |
| 73 | `tutor_references` | §27 | `IX(owner_type, owner_id)` | Contact status tracked |
| 74 | `tutor_application_subjects` | §27 subject capability | `UQ(tutor_application_id, subject_id, academic_level_id)` | Proficiency 1–5 |
| 75 | `evaluation_criteria` | §28 criterion library | `IX(is_active)` | Reusable across form versions |
| 76 | `evaluation_forms` | §28 | `UQ(name, version)`, `IX(is_active)` | **Versioned** so submitted evaluations are never distorted |
| 77 | `evaluation_form_criteria` | §28 | `UQ(evaluation_form_id, evaluation_criterion_id)`, `IX(evaluation_form_id, position)` | Weight + required flag |
| 78 | `tutor_interviews` | §29 | `IX(primary_evaluator_id, scheduled_starts_at)`, `IX(status, scheduled_starts_at)`, `IX(tutor_application_id)` | Meeting link via `meetings` |
| 79 | `tutor_evaluations` | §28/§29 | `UQ(tutor_application_id, evaluator_id, tutor_interview_id)`, `IX(evaluator_id, submitted_at)` | **Immutable after submission** — corrections create a new row + audit (§29) |
| 80 | `evaluation_criterion_scores` | §28 | `UQ(tutor_evaluation_id, evaluation_criterion_id)` | Per-criterion score + comment |
| 81 | `tutor_profiles` | §30/§31 | `UQ(user_id)`, `UQ(slug)`, `UQ(tutor_application_id)`, `IX(status, is_publicly_listed)`, `IX(rating_average)`, `FULLTEXT(display_name, bio)` | Created **only** by `ApproveTutor` (§30) |
| 82 | `tutor_subjects` | §31 | `UQ(tutor_profile_id, subject_id, academic_level_id)` | Public directory filter source (§87) |
| 83 | `tutor_availabilities` | §32 | `IX(tutor_profile_id, weekday, is_active)`, `IX(tutoring_service_id)` | Weekly recurring, wall-clock local + timezone |
| 84 | `tutor_unavailable_dates` | §32 | `IX(tutor_profile_id, on_date)` | Blocks/holidays/leave |
| 85 | `tutoring_services` | §33 | `UQ(slug)`, `IX(subject_id, status)`, `IX(assigned_tutor_id)`, `IX(product_id)` | **`requires_parent_purchase`** (§10) |
| 86 | `tutoring_service_tutors` | §33 tutor pool | `UQ(tutoring_service_id, tutor_profile_id)` | Priority ordering for auto-assignment |
| 87 | `tutoring_packages` | §33/§43 bundles | `IX(tutoring_service_id, status)` | N sessions at a package price |
| 88 | `tutoring_bookings` | §34 | `UQ(reference)`, `IX(student_id, status)`, `IX(tutor_profile_id, status)`, `IX(booked_by_user_id)`, `IX(order_id)` | Records **both** purchaser and beneficiary (§34) |
| 89 | `tutoring_sessions` | §35 | `UQ(tutor_profile_id, starts_at)` ★, `IX(student_id, starts_at)`, `IX(starts_at, status)`, `IX(tutoring_booking_id)`, `IX(meeting_id)` | The unique constraint is the last line of defence against double-booking |
| 90 | `session_notes` | §35/§48 | `IX(session_type, session_id)` | Polymorphic so tutors can annotate classes **and** tutoring |
| 91 | `tutor_reviews` | §88 | `UQ(tutoring_session_id, reviewer_user_id)` ★, `IX(tutor_profile_id, status, published_at)`, `IX(rating)` | Session-gated; moderation states |
| 92 | `tutor_earnings` | §89 payout-ready ledger | `UQ(tutoring_session_id)`, `IX(tutor_profile_id, payout_status)`, `IX(created_at)` | Gross/fee/share/tax/net + payout status. **No payout execution in v1** |

---

## 6. Commerce — 23 tables · **Phase 8–9**

| # | Table | Purpose | Indexes / constraints | Notes |
|---|-------|---------|----------------------|-------|
| 93 | `product_categories` | §38 | `UQ(slug)`, `IX(parent_id)`, `IX(category_type, is_active)` | Nested via self-referencing `parent_id` |
| 94 | `products` | §38/§39/§86 sellable catalog | `UQ(sku)`, `UQ(slug)`, `UQ(productable_type, productable_id)`, `IX(product_type, status)`, `IX(category_id, status)`, `FULLTEXT(name, description)` | One checkout path for digital, course, program, tutoring, packages |
| 95 | `digital_products` | §39 | `IX(content_type)` | Author, language, download limit, validity, sample |
| 96 | `digital_product_files` | §39/§40 | `IX(digital_product_id, position)` | Files always `visibility = private` |
| 97 | `carts` | §34 checkout | `IX(user_id, status)` | DB-backed so admin-assisted checkout works (§10) |
| 98 | `cart_items` | §34 | `IX(cart_id)`, `IX(beneficiary_student_id)` | Per-item beneficiary ★ |
| 99 | `orders` | §44 | `UQ(number)`, `IX(customer_user_id, status)`, `IX(status, placed_at)`, `IX(beneficiary_student_id)`, `IX(payment_status)` | Purchaser **and** beneficiary (§5) |
| 100 | `order_items` | §44 | `UQ(order_id, product_id, beneficiary_student_id)`, `IX(product_id)` | Full price/name snapshot so history survives catalog edits |
| 101 | `order_events` | §44/§56 timeline | `IX(order_id, created_at)` | Append-only |
| 102 | `invoices` | §38/§44 | `UQ(number)`, `IX(billed_to_user_id, status)`, `IX(due_at, status)`, `IX(order_id)`, `IX(subscription_id)` | One per order **or** per subscription renewal |
| 103 | `invoice_items` | §44 | `IX(invoice_id)` | Accounting lines |
| 104 | `payments` | §41 | `UQ(provider, provider_reference)` ★★, `IX(order_id, status)`, `IX(status, initiated_at)`, `IX(paid_by_user_id)` | Never trusted from a redirect alone (§41) |
| 105 | `payment_events` | §41/§42 webhook ledger | `UQ(provider, provider_event_id)` ★★, `IX(event_type, received_at)`, `IX(reference)`, `IX(processing_status)` | **The idempotency gate** |
| 106 | `refunds` | §41/§44 | `IX(payment_id)`, `IX(order_id, status)` | Triggers entitlement revocation |
| 107 | `coupons` | §38 | `UQ(code)`, `IX(is_active, valid_until)` | Scope + limits + per-user caps |
| 108 | `coupon_redemptions` | §38 | `UQ(coupon_id, order_id)`, `IX(user_id)` | Enforces per-user limits in the DB |
| 109 | `tax_rates` | §44 | `IX(scope, is_active)` | Optional; Nigeria VAT-ready |
| 110 | `subscriptions` | §43 | `UQ(provider, provider_subscription_id)` ★, `IX(customer_user_id, status)`, `IX(next_billing_at, status)`, `IX(beneficiary_student_id)` | Customer **and** beneficiary |
| 111 | `subscription_events` | §43 | `IX(subscription_id, created_at)` | Append-only renewal/failure log |
| 112 | `entitlements` | §45 ★★ | `UQ(uuid)`, `UQ(source_type, source_id, product_id, beneficiary_student_id)` ★★, `IX(beneficiary_student_id, status, ends_at)`, `IX(owner_user_id, status)`, `IX(course_id, status)`, `IX(status, ends_at)`, `CHECK(exactly one target column set)` | **The commerce↔education decoupler** (ADR-03) |
| 113 | `entitlement_events` | §45/§56 | `IX(entitlement_id, created_at)` | Append-only |
| 114 | `entitlement_consumptions` | §43/§35 | `UQ(entitlement_id, consumable_type, consumable_id)` ★★ | Stops a duplicate webhook granting two sessions |
| 115 | `digital_product_downloads` | §40 ledger | `IX(entitlement_id, downloaded_at)`, `IX(user_id, downloaded_at)`, `IX(digital_product_file_id)` | IP + user agent + bytes; anti-abuse source |

---

## 7. Integration — 2 tables · **Phase 7**

| # | Table | Purpose | Indexes / constraints | Notes |
|---|-------|---------|----------------------|-------|
| 116 | `meetings` | §14/§36/§37 provider-agnostic meeting record | `UQ(uuid)`, `UQ(meetingable_type, meetingable_id, provider)`, `IX(provider, status)`, `IX(starts_at)`, `IX(connected_account_id)` | One row serves class sessions, tutoring sessions and interviews; `status = provisioning` covers Meet's async conference creation |
| 117 | `integration_logs` | §77 observability | `IX(provider, created_at)`, `IX(successful, created_at)`, `IX(related_type, related_id)`, `IX(request_id)` | Secrets redacted by `SensitiveDataScrubber` before write |

---

## 8. Communication — 5 tables · **Phase 5 / 10**

| # | Table | Purpose | Indexes / constraints | Notes |
|---|-------|---------|----------------------|-------|
| 118 | `message_templates` | §52 configurable email templates | `UQ(key, locale)`, `IX(category, is_active)` | `is_transactional` rows cannot be disabled by users (§82) |
| 119 | `notification_preferences` | §82 | `UQ(user_id, notification_key)` | Transactional keys are read-only in the UI |
| 120 | `pages` | §84 static/CMS pages | `UQ(slug)`, `IX(page_type, status)` | Draft/published workflow |
| 121 | `faq_categories` | §84 | `UQ(slug)`, `IX(position)` | — |
| 122 | `faqs` | §84 | `IX(category_id, position)`, `IX(audience, is_published)` | Audience-scoped |

---

## 9. Administration — 2 tables · **Phase 10**

| # | Table | Purpose | Indexes / constraints | Notes |
|---|-------|---------|----------------------|-------|
| 123 | `import_batches` | §80 CSV import/export tracking | `IX(import_type, status)`, `IX(started_by, started_at)` | Summary + error report file |
| 124 | `import_batch_rows` | §80 per-row results | `IX(import_batch_id, status)`, `IX(import_batch_id, row_number)` | Validates **before** committing; failed rows are reported, not silently dropped |

---

## 10. Migration plan and ordering (§66)

Spec §66: *"Create migrations in dependency order. Do not create one giant migration."*

One migration **per table** (plus separate migrations for indexes that must be added after
data exists), named with a phase prefix so the build order is visible in `ls`:

```
database/migrations/
  2026_01_01_000001_phase1_create_users_table.php
  2026_01_01_000002_phase1_create_cache_table.php
  2026_01_01_000003_phase1_create_cache_locks_table.php
  2026_01_01_000004_phase1_create_jobs_table.php
  2026_01_01_000005_phase1_create_sessions_table.php
  2026_01_01_000006_phase1_create_password_reset_tokens_table.php
  2026_01_01_000007_phase1_create_notifications_table.php
  2026_01_01_000008_phase1_create_personal_access_tokens_table.php
  2026_01_01_000009_phase1_create_permission_tables.php        ← spatie (5 tables)
  2026_01_01_000010_phase1_create_settings_table.php
  2026_01_01_000011_phase1_create_audit_logs_table.php
  2026_01_01_000012_phase1_create_files_table.php
  2026_01_01_000013_phase1_create_connected_accounts_table.php
  2026_01_01_000014_phase1_create_consents_table.php
  …
  2026_02_01_000001_phase2_create_academic_levels_table.php
  2026_02_01_000002_phase2_create_academic_years_table.php
  2026_02_01_000003_phase2_create_academic_terms_table.php
  2026_02_01_000004_phase2_create_subjects_table.php
  2026_02_01_000005_phase2_create_programs_table.php           ← needs academic_levels
  2026_02_01_000006_phase2_create_students_table.php           ← needs users, levels, programs
  2026_02_01_000007_phase2_create_parents_table.php
  2026_02_01_000008_phase2_create_parent_student_table.php
  2026_02_01_000009_phase2_create_student_applications_table.php
  2026_02_01_000010_phase2_create_cohorts_table.php            ← needs courses? NO — see note
  …
```

**Deliberate ordering decisions:**

| Issue | Resolution |
|-------|-----------|
| `cohorts.course_id` needs `courses`, but `courses` is a Phase 3 (LMS) table while cohorts are Phase 2 | Phase 2 creates `cohorts` **without** `course_id`; a Phase 3 migration `add_course_id_to_cohorts_table` adds it. Keeps the application runnable at every phase boundary (§91) |
| `program_courses` needs `courses` (Phase 3) | Same pattern: table created in Phase 3, not Phase 2 |
| `course_enrollments.entitlement_id` needs `entitlements` (Phase 8) | Column added by a Phase 8 migration `add_entitlement_reference_to_course_enrollments` |
| `program_enrollments.entitlement_id` | Same |
| `assessments.assessor_id` may reference `tutor_profiles` (Phase 6) but assessments land in Phase 3 | Column stays a plain `BIGINT` + `assessor_type` discriminator; no FK until Phase 6 adds one |
| `products.productable_*` points at tables from Phases 2/3/7 | Polymorphic by design (ADR-03) — no FK, discriminator `CHECK` instead |
| `meetings` is polymorphic across three tables from three phases | Created in Phase 7 with a `CHECK` on `meetingable_type` |
| `entitlements` references `courses`, `programs`, `digital_products`, `tutoring_services`, `tutoring_packages` | All exist by Phase 8, so real FKs are possible — this is *why* entitlement targets are typed columns, not polymorphic |
| Index-heavy columns on tables seeded with demo data | Indexes are created in the same migration as the table (MySQL 8 `ALTER` is fine at these volumes; no separate "add index later" pass needed) |

Every migration implements `down()`. Migrations never read application models — they use
`DB::table()` and `Schema` only, so an old migration still runs after a model changes.

---

## 11. Normalization review (§66.3)

| Check | Result |
|-------|--------|
| **1NF** — atomic columns | Pass. Repeating groups are tables (`tutor_subjects`, `question_options`, `lesson_resources`), not comma-joined strings. JSON is used only for genuinely document-shaped data (`provider_payload`, `question_snapshot`, `meta`, `billing_address`) |
| **2NF** — no partial dependencies | Pass. Composite-key tables (`course_subject`, `role_has_permissions`, `model_has_roles`) carry no non-key attributes. `parent_student` and `cohort_student` are **promoted to real tables with a surrogate PK** precisely because they carry attributes (capabilities, status, dates) — a bare pivot would violate 2NF |
| **3NF** — no transitive dependencies | Pass with two documented, intentional exceptions (below) |

**Deliberate denormalizations** (each with a stated maintenance mechanism — §78 permits
this where it removes a hot query):

| Denormalized data | Why | Kept correct by |
|-------------------|-----|-----------------|
| `course_progress.percent_complete`, `lessons_completed`, `seconds_spent` | Student/parent dashboards read this constantly; recomputing from `lesson_progress` on every request is an N+1 trap | `LessonCompleted` event → queued `RecalculateCourseProgress`; nightly `progress:recalculate` sweep |
| `tutor_profiles.rating_average`, `rating_count` | Public tutor directory sorts/filters by rating (§87) | `ReviewPublished`/`ReviewModerated` events recompute inside the same transaction |
| `order_items.product_name_snapshot`, `unit_price_minor`, `productable_*_snapshot` | An invoice/order must reflect what was actually sold, even if the product is later renamed, repriced or deleted (§44) | Snapshots are written once at order creation and never updated |
| `assessment_submissions.question_snapshot` | Randomized question/option order must be reproducible for marking disputes (§21) | Written at attempt start, immutable |
| `tutor_earnings.platform_fee_percent`, `session_rate_minor` | Fee policy changes must not retroactively alter settled earnings (§89) | Snapshot at session completion |
| `academic_results.percentage`, `grade_letter` | A published report card must not change when a grading scheme is edited later (§25) | Immutable after publish; amendments append + audit |
| `discussions.posts_count`, `products`/`orders` counters | List performance | Model `withCount`-free counters maintained by observers |

---

## 12. Status columns inventory (§66.7)

Every status field, its value set, and where it is defined as a PHP `enum`:

| Table.column | Values | Enum class |
|--------------|--------|-----------|
| `users.status` | pending, active, suspended, deactivated | `Identity\Enums\UserStatus` |
| `academic_years.status` | draft, active, closed, archived | `Education\Enums\AcademicYearStatus` |
| `academic_terms.status` | draft, active, closed | `Education\Enums\TermStatus` |
| `programs.status` | draft, active, suspended, archived | `Education\Enums\ProgramStatus` |
| `subjects.status` | draft, active, archived | `Education\Enums\SubjectStatus` |
| `students.status` | applicant, active, inactive, graduated, suspended, withdrawn (§8) | `Education\Enums\StudentStatus` |
| `parents.status` | invited, active, inactive, blocked | `Education\Enums\ParentStatus` |
| `parent_student.status` | pending, active, revoked | `Education\Enums\GuardianLinkStatus` |
| `student_applications.status` | draft, submitted, under_review, accepted, rejected, waitlisted, enrolled, withdrawn (§11) | `Education\Enums\ApplicationStatus` |
| `program_enrollments.status` | active, completed, suspended, withdrawn (§12) | `Education\Enums\EnrollmentStatus` |
| `cohorts.status` | draft, open, in_progress, completed, cancelled, archived | `Education\Enums\CohortStatus` |
| `cohort_student.status` | active, completed, withdrawn, removed | `Education\Enums\CohortMembershipStatus` |
| `course_sessions.status` | scheduled, confirmed, in_progress, completed, cancelled, no_show | `Education\Enums\SessionStatus` |
| `attendance_records.status` | present, absent, late, excused (§15) | `Education\Enums\AttendanceStatus` |
| `announcements.status` | draft, published, archived | `Communication\Enums\AnnouncementStatus` |
| `courses.status` | draft, review, published, unpublished, archived (§85) | `Lms\Enums\CourseStatus` |
| `lessons.status` | draft, published | `Lms\Enums\LessonStatus` |
| `course_enrollments.status` | active, completed, suspended, expired, withdrawn | `Lms\Enums\CourseEnrollmentStatus` |
| `lesson_progress.status` | not_started, in_progress, completed | `Lms\Enums\LessonProgressStatus` |
| `certificates.status` | issued, revoked | `Lms\Enums\CertificateStatus` |
| `assessments.status` | draft, scheduled, open, closed, graded, published | `Assessment\Enums\AssessmentStatus` |
| `assessment_submissions.status` | not_started, in_progress, submitted, late, graded, returned (§22) | `Assessment\Enums\SubmissionStatus` |
| `academic_results.status` | provisional, published, amended | `Assessment\Enums\ResultStatus` |
| `discussions.status` | open, closed, hidden | `Lms\Enums\DiscussionStatus` |
| `discussion_posts.status` | visible, hidden, reported | `Lms\Enums\PostStatus` |
| `tutor_applications.status` | draft, submitted, under_review, shortlisted, interview_scheduled, interview_completed, approved, rejected, withdrawn (§27) | `Tutoring\Enums\ApplicationStatus` |
| `tutor_interviews.status` | scheduled, in_progress, completed, cancelled, no_show (§29) | `Tutoring\Enums\InterviewStatus` |
| `tutor_interviews.recommendation` / `tutor_evaluations.recommendation` | approve, reject, further_review (§29) | `Tutoring\Enums\Recommendation` |
| `tutor_profiles.status` | active, suspended, withdrawn, inactive (§30) | `Tutoring\Enums\TutorStatus` |
| `tutoring_services.status` | draft, active, paused, archived | `Tutoring\Enums\ServiceStatus` |
| `tutoring_bookings.status` | pending, awaiting_payment, confirmed, cancelled, completed, expired (§34) | `Tutoring\Enums\BookingStatus` |
| `tutoring_sessions.status` | scheduled, confirmed, in_progress, completed, cancelled, no_show (§35) | `Tutoring\Enums\TutoringSessionStatus` |
| `tutor_reviews.status` | pending, published, hidden, reported (§88) | `Tutoring\Enums\ReviewStatus` |
| `tutor_earnings.payout_status` | pending, processing, paid, failed, on_hold (§89) | `Tutoring\Enums\PayoutStatus` |
| `products.status` | draft, published, unpublished, archived (§86) | `Commerce\Enums\ProductStatus` |
| `orders.status` | pending, processing, paid, failed, cancelled, refunded, partially_refunded (§44) | `Commerce\Enums\OrderStatus` |
| `orders.payment_status` | unpaid, awaiting_confirmation, partially_paid, paid, refunded, partially_refunded, failed | `Commerce\Enums\OrderPaymentStatus` |
| `payments.status` | pending, processing, success, failed, abandoned, refunded, partially_refunded | `Commerce\Enums\PaymentStatus` |
| `invoices.status` | draft, issued, paid, partially_paid, overdue, void, refunded | `Commerce\Enums\InvoiceStatus` |
| `refunds.status` | pending, approved, processed, failed, rejected | `Commerce\Enums\RefundStatus` |
| `subscriptions.status` | active, past_due, cancelled, expired, paused, pending (§43) | `Commerce\Enums\SubscriptionStatus` |
| `entitlements.status` | pending, active, suspended, expired, revoked, consumed (§45) | `Commerce\Enums\EntitlementStatus` |
| `carts.status` | active, converted, abandoned | `Commerce\Enums\CartStatus` |
| `meetings.provider` | google_meet, zoom, manual, none (§14) | `Integration\Enums\MeetingProvider` |
| `meetings.status` | provisioning, ready, failed, cancelled, expired | `Integration\Enums\MeetingStatus` |
| `files.visibility` | public, authenticated, private, restricted (§59) | `Administration\Enums\FileVisibility` |
| `import_batches.status` | uploaded, validating, valid, invalid, importing, completed, failed, rolled_back | `Administration\Enums\ImportStatus` |
| `pages.status` | draft, published, archived (§84) | `Communication\Enums\PageStatus` |
| `connected_accounts.status` | connected, expired, revoked, error | `Identity\Enums\ConnectionStatus` |

---

## 13. Audit & soft-delete matrix (§66.8, §66.9)

| Table | Soft delete | Generic audit | Dedicated history table | Reason |
|-------|:---:|:---:|:---:|--------|
| `users` | ✅ | ✅ | — | Accounts are deactivated, never destroyed (§58) |
| `students` | ✅ | ✅ | — | Minors' records require archival + retention policy |
| `parents` | ✅ | ✅ | — | Financial + consent history attached |
| `parent_student` | — | ✅ | — | Relationship changes are explicitly auditable (§9) |
| `student_applications` | — | ✅ | ✅ `student_application_events` | Admissions decisions must be defensible |
| `courses` | ✅ | ✅ | — | Published content may be withdrawn, not deleted |
| `products` | ✅ | ✅ | — | Referenced by historical orders |
| `orders` | ✅ | ✅ | ✅ `order_events` | Financial record; timeline is user-facing |
| `payments` | — | ✅ | ✅ `payment_events` | Never deleted; webhook ledger is append-only |
| `entitlements` | — | ✅ | ✅ `entitlement_events` | Access decisions must be explainable |
| `subscriptions` | — | ✅ | ✅ `subscription_events` | Billing disputes |
| `tutor_applications` | ✅ | ✅ | ✅ `tutor_application_events` | Recruitment decisions must be defensible (§29) |
| `tutor_evaluations` | — | ✅ | self (immutable) | §29 "immutable audit trail" |
| `tutor_profiles` | ✅ | ✅ | — | Public directory listings |
| `tutoring_sessions` | — | ✅ | — | Delivered service; earnings reference it |
| `assessment_submissions` | — | ✅ | self (versioned) | Academic integrity |
| `academic_results` | — | ✅ | self (status=amended) | Published grades are legal-ish records (§25) |
| `certificates` | — | ✅ | — | Revocation is a status change, not a delete (§26) |
| `files` | ✅ | ✅ | — | Restricted documents (§59) |
| `grading_schemes` / bands | — | ✅ | — | Grade changes must be traceable to the scheme in force |
| `roles` / `permissions` / `model_has_*` | — | ✅ | — | §56 explicitly lists role/permission changes |
| `settings` | — | ✅ | — | Old→new value diff on every change (§60) |
| `refunds` | — | ✅ | — | §56 explicitly lists refunds |
| All other tables | — | ✅ (where user-mutated) | — | Baseline coverage |

---

## 14. Table count by phase

| Phase | Tables added | Cumulative |
|-------|--------------|-----------|
| 1 — Foundation | 20 | 20 |
| 2 — Education core | 22 | 42 |
| 3 — LMS | 13 | 55 |
| 4 — Academic management (assessment) | 12 | 67 |
| 5 — Portals | 0 new (2 communication tables move here: `notification_preferences`, `pages`/`faqs` if needed earlier) | 67 |
| 6 — Tutor recruitment | 13 | 80 |
| 7 — Tutoring delivery | 12 + 2 integration | 94 |
| 8 — Commerce | 21 | 115 |
| 9 — Subscriptions | 2 (`subscriptions`, `subscription_events`) | 117 |
| 10 — Reporting | 2 imports + 3 communication (`message_templates`, `faq_categories`, `faqs`) | 122 |
| 11 — Hardening | 2 (`academic_reports` if deferred, `import_batch_rows` alignment) + index passes | **124** |

Exact phase assignment per table is in
[10-phased-implementation-plan.md](10-phased-implementation-plan.md).
