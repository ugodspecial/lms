# 06 — Feature / Module Matrix

**Deliverable:** spec §102.6 — *"A feature/module matrix."*

One row per feature. Every row answers: which module owns it, which phase builds it, which
tables it touches, which permissions gate it, which screens render it, which business rules
it depends on, and which tests prove it works. This is the traceability grid used to decide
whether a phase is finished (§101).

---

## 1. Module ownership summary

| Module | Namespace | Features | Tables | Phase(s) |
|--------|-----------|---------:|-------:|----------|
| Identity | `app/Domain/Identity` | 14 | 20 | 1 |
| Education | `app/Domain/Education` | 38 | 22 | 2, 4 |
| LMS | `app/Domain/Lms` | 26 | 13 | 3, 5 |
| Assessment | `app/Domain/Assessment` | 24 | 12 | 3, 4 |
| Tutoring | `app/Domain/Tutoring` | 33 | 25 | 6, 7 |
| Commerce | `app/Domain/Commerce` | 29 | 23 | 8, 9 |
| Integration | `app/Domain/Integration` | 9 | 2 | 7, 8 |
| Communication | `app/Domain/Communication` | 12 | 5 | 5, 10 |
| Administration | `app/Domain/Administration` | 17 | 2 (+3 shared) | 1, 10, 11 |
| **Total** | | **202** | **124** | 0–11 |

---

## 2. Phase 1 — Foundation (Identity + Administration core)

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Email/password registration | users, consents | — | `/register` | Terms consent recorded; duplicate email blocked; `status=pending` until verified | RegistrationTest, ConsentRecordedTest |
| Email verification | users | — | verification notice + `/email/verify/{id}/{hash}` | Signed, expiring link; unverified users blocked from portals | EmailVerificationTest |
| Password reset | password_reset_tokens | — | `/forgot-password`, `/reset-password` | Single-use token, rate-limited, no user enumeration ("if that email exists…") | PasswordResetTest, UserEnumerationTest |
| Login + throttling | sessions, users | — | `/login` | 5 attempts/min per email+IP; lockout message; audit failed attempts | LoginThrottleTest |
| Google OAuth login | users, connected_accounts | — | `/auth/google/redirect`, `/callback` | Links to existing account by verified email; creates user otherwise; stores provider + purpose | GoogleOAuthTest (Socialite faked) |
| Microsoft OAuth login | users, connected_accounts | — | `/auth/microsoft/*` | Same as Google, `azure`/Graph provider | MicrosoftOAuthTest |
| Two-factor authentication | users | `user.two_factor.manage_own` | `/settings/security` | TOTP via Fortify; recovery codes; **required** for `settings.manage.security` and `tutors.approve` holders (configurable) | TwoFactorTest, Privileged2faEnforcedTest |
| Session management | sessions | `user.profile.update_own` | `/settings/sessions` | List + revoke other sessions; single-device option for admins | SessionManagementTest |
| RBAC: roles | roles, model_has_roles | `roles.view`, `roles.manage`, `user.roles.assign` | Admin → Access → Roles (list, create, edit, permissions picker) | Role names unique per guard; Super Admin not deletable; assignment audited | RoleCrudTest, RoleAssignmentAuditTest |
| RBAC: permissions | permissions, role_has_permissions | `permissions.view` | Admin → Access → Permissions (grouped, read-only registry) | Registry synced from code; orphans reported not deleted | PermissionSyncTest |
| Permission-gated navigation | — | all | Sidebar per portal | Nav items render only when `Gate::allows` — never CSS-hidden | NavigationAuthorizationTest |
| User administration | users | `users.*` | Admin → Users (table, filters, detail, suspend, deactivate) | Cannot deactivate self; cannot delete a user with financial records (RESTRICT → friendly error) | UserAdminTest, SelfDeactivationBlockedTest |
| Impersonation (support) | audit_logs | `users.impersonate` | Admin → Users → "View as" | Time-boxed, banner always visible, every action audited as `impersonating_user_id`, cannot impersonate a Super Admin unless you are one | ImpersonationAuditTest |
| Settings registry | settings | `settings.view`, `settings.manage`, group-scoped | Admin → Settings (tabbed by group) | Typed casts; secret values never returned to the browser; changes audited old→new; cache bust on write | SettingsCrudTest, SecretNeverExposedTest |
| Organization & branding settings | settings, files | `settings.manage` | Admin → Settings → Organization | Logo upload → `files` (public); name/currency/timezone drive formatting everywhere | BrandingTest |
| Audit log viewer | audit_logs | `audit.view` | Admin → Audit (filters: user, event, entity, date range) | Read-only; no edit/delete route exists; sensitive fields redacted | AuditViewerTest, RedactionTest |
| File registry + uploads | files | `files.upload`, `files.delete`, `files.view_restricted` | Shared `FileUploader` Livewire component | MIME+extension+size validation; hashed filename; original name preserved but never used as a path; SVG sanitized/blocked | FileUploadValidationTest |
| Secure file downloads | files | `files.*` + `FilePolicy` | `/files/{uuid}/download` | Visibility classes enforced; `restricted` needs an explicit policy pass; rate-limited; streamed with sanitized `Content-Disposition` | SecureDownloadTest, GuessableUrlTest |
| Design system | — | — | Blade components (`ui.*`) | — | Component smoke tests (rendered for each state) |
| Portal shells & dashboards | — | `*.portal.access` | admin/parent/student/tutor/evaluator layouts | Area middleware requires ≥1 area permission | AreaAccessTest (one per portal) |
| Error pages | — | — | 403/404/419/429/500/503 | No stack trace when `APP_DEBUG=false`; branded | ErrorPageTest |
| `platform:doctor` command | settings | `platform.doctor` | CLI + Admin → Health | Reports missing env vars, unwritable paths, unconfigured integrations, queue/cron status — **instead of failing cryptically at runtime** | DoctorCommandTest |
| Demo accounts (dev only) | users | — | `/demo-login` (registered only when `platform.demo_accounts.enabled`) | Refuses when `APP_ENV=production`; password is fixed & obvious; banner shown | DemoLoginDisabledInProductionTest |
| API scaffolding | personal_access_tokens | — | `routes/api.php` `/api/v1` group | Sanctum + throttle + JSON error envelope; no endpoints exposed yet | ApiScaffoldTest |

---

## 3. Phase 2 — Education Core

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Academic years | academic_years | `academic_years.*`, `.activate` | Admin → Academics → Years | Exactly one active (transaction + `lockForUpdate`); cannot close with open terms; `ends_at > starts_at` | OneActiveYearTest, CloseYearGuardTest |
| Academic terms | academic_terms | `academic_terms.*` | Admin → Academics → Terms | Terms belong to a year; non-overlapping; position ordering | TermOverlapTest |
| Academic levels | academic_levels | `academic_levels.manage` | Admin → Academics → Levels | Configurable list — nothing hard-coded (§95) | LevelsConfigurableTest |
| Programs | programs, program_courses | `programs.*` | Admin → Academics → Programs (list/create/edit/learning path builder) | Code unique; learning-path ordering; cycle detection on prerequisites | ProgramCrudTest, LearningPathCycleTest |
| Subjects | subjects, course_subject | `subjects.*` | Admin → Academics → Subjects | Code unique; level association optional | SubjectCrudTest |
| Student profiles | students | `students.*` | Admin → Students (searchable table, filters, detail, create/edit, photo, documents, notes) | `student_code` auto-generated from a configurable pattern; DOB nullable but flagged; status transitions audited | StudentCrudTest, StudentCodeGenerationTest, StatusChangeAuditTest |
| Parent/guardian profiles | parents | `parents.*` | Admin → Guardians | May exist before registration; invite token hashed; email used to claim | GuardianCrudTest, GuardianInviteClaimTest |
| Guardian ↔ student links | parent_student | `parents.link_student`, `.unlink_student`, `.set_capabilities` | Guardian detail → Children; Student detail → Guardians | Capabilities: `can_purchase`, `can_view_academics`, `can_view_financials`, `is_primary`; every change audited (§9); one primary guardian recommended but not forced | GuardianLinkTest, CapabilityChangeAuditTest, UnlinkRevokesAccessTest |
| Parent self-service "add child" | students, parent_student, consents | `students.create` (scoped) | Parent → Children → Add child | Creates a student + pending link; consent recorded; requires admin confirmation when `settings.parent_child_link_requires_approval` | ParentAddChildTest, LinkRequiresApprovalTest |
| Admissions applications | student_applications, _documents, _events | `applications.*` | Public `/admissions/apply`; Admin → Admissions; Applicant tracking page | 8-state machine; document upload; reviewer assignment; every transition logged | ApplicationWorkflowTest, ApplicationStatusHistoryTest |
| Applicant → student conversion | students, student_applications, parent_student | `applications.convert_to_student` | Application detail → "Convert to student" | Creates student, links guardian, sets `status=applicant`, marks application `enrolled`; idempotent (cannot convert twice) | ConversionTest, DoubleConversionBlockedTest |
| Program enrollment | program_enrollments | `enrollments.*` | Student detail → Enrollments; Admin → Enrollments | Unique per (student, program, year, term); source recorded; `entitlement_id` reserved for Phase 8 | ProgramEnrollmentTest, DuplicateEnrollmentBlockedTest |
| Cohorts / student groups | cohorts, cohort_student | `cohorts.*`, `cohorts.bulk_enroll` | Admin → Cohorts (list/create/edit/members) | Capacity enforcement; instructor assignment; year/term/program/course/subject context | CohortCrudTest, CapacityEnforcedTest |
| Bulk cohort enrollment | cohort_student | `cohorts.bulk_enroll` | Cohort → Members → Bulk add (multi-select + CSV) | Authorization checked **per student**, not once for the batch (§81); partial success reported with reasons | BulkEnrollmentAuthorizationTest |
| Online class schedules | course_schedules | `schedules.*` | Cohort → Schedule | Wall-clock local time + IANA timezone; weekday recurrence; validity window; provider preference | ScheduleCrudTest |
| Session generation | course_sessions, non_working_dates | `sessions.generate` | Cohort → Sessions; command `sessions:generate` | Materializes occurrences; skips `non_working_dates`; respects tutor availability conflicts; idempotent per (schedule, date) | SessionGenerationTest, HolidaySuppressionTest, IdempotentGenerationTest |
| Attendance | attendance_records | `attendance.*` | Session detail → Attendance grid; Tutor → Attendance | present/absent/late/excused; check-in/out times; recorded_by; **one record per student per session** | AttendanceRecordTest, DuplicateAttendanceBlockedTest |
| Bulk attendance | attendance_records | `attendance.bulk_record` | Attendance grid → "Mark all present" then adjust | Per-session authorization; upsert semantics; audit on correction | BulkAttendanceTest, AttendanceCorrectionAuditTest |
| Attendance correction | attendance_records, audit_logs | `attendance.correct` | Attendance grid (history drawer) | Old→new value audited; only within a configurable window | AttendanceCorrectionWindowTest |
| Parent-visible attendance summary | attendance_records | `attendance.view` (scoped) | Parent → Child → Attendance | Aggregated per term + per cohort; **no other child's data** | ParentAttendanceScopeTest |
| Holidays / non-working dates | non_working_dates | `holidays.manage` | Admin → Academics → Calendar | Scope: institution/cohort/tutor; suppresses sessions and tutoring slots | NonWorkingDateTest |
| Announcements | announcements, announcement_targets | `announcements.*` | Admin → Communications → Announcements | Audience resolution; draft/published; pinning; expiry | AnnouncementAudienceTest |
| Global search (education scope) | students, parents, cohorts, programs | `*.view` per entity | `GlobalSearch` component (⌘K) | **Authorization scope applied before filtering** — results differ per role (§54) | SearchAuthorizationTest |
| CSV import/export: students, guardians | import_batches, import_batch_rows | `students.import`, `.export`, `imports.run` | Admin → Imports | Streamed parse; validate-all-then-commit; per-row error report downloadable | StudentImportValidationTest, ImportErrorReportTest |

---

## 4. Phase 3 — LMS

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Course catalogue | courses | `courses.view` | Public `/courses`; Admin → Courses | Only `published` visible publicly; search + filters | CourseCatalogueTest, UnpublishedHiddenTest |
| Course builder | courses, course_modules, lessons, lesson_topics, lesson_resources | `courses.*`, `course_modules.manage`, `lessons.manage` | Admin/Tutor → Courses → Builder (drag-order modules & lessons) | Ordering integrity; module/lesson publish states; drip via `available_from` | CourseBuilderTest, ReorderIntegrityTest |
| Lesson content types | lessons | `lessons.manage` | Lesson editor (text/video/audio/document/embed/external/quiz/assignment) | Rich text sanitized on write **and** render; embed codes allowlisted; external URLs validated | LessonSanitizationTest, XssInLessonBodyTest |
| Lesson resources | lesson_resources, files | `lesson_resources.manage` | Lesson → Resources | Downloadable flag; file visibility `authenticated`/`private` | ResourceDownloadAuthorizationTest |
| Course publishing workflow | courses | `courses.submit_for_review`, `courses.publish` | Course detail → Publish | draft → review → published ⇄ unpublished → archived; **only published is enrollable/purchasable** (§85); audited | PublishingWorkflowTest, UnpublishedNotEnrollableTest |
| Manual enrollment | course_enrollments | `courses.enroll_students` | Course → Students; Student → Courses | Source recorded; duplicate blocked; progress row initialized | ManualEnrollmentTest |
| Learning path evaluation | program_courses, course_enrollments | `enrollments.view` | Program → Path | Required vs optional; prerequisites gate the next course; completion % | LearningPathPrerequisiteTest |
| Lesson player | lesson_progress, learning_activities | `courses.view` (scoped) | Student → Learn → Lesson | Access via `CourseAccessResolver`; free-preview lessons allowed on published courses | LessonAccessTest, FreePreviewTest |
| Lesson completion tracking | lesson_progress | — | Lesson → "Mark complete" | One row per (student, lesson); status transitions; seconds spent accumulated | LessonCompletionTest |
| Course progress calculation | course_progress | `progress.view` | Student dashboard; Parent → Progress; Tutor → Students | Percent from completion rule (all_lessons/percent/assessments); recalculated on event + nightly sweep | ProgressCalculatorTest, RecalculationJobTest |
| Course completion & certificate trigger | course_progress, certificates | `certificates.issue` | — | Completion emits `CourseCompleted` → queued certificate issuance when `issues_certificate` | CompletionTriggersCertificateTest |
| Discussions | discussions, discussion_posts | `discussions.*` | Course → Discussion; Lesson → Discussion | Only enrolled participants post; threading; moderation (hide/report); instructors can pin/lock | DiscussionAuthorizationTest, ModerationTest |
| Entitlement-driven enrollment | course_enrollments, entitlements | — | — | `EntitlementGranted` listener creates enrollment with `source=entitlement` | EntitlementCreatesEnrollmentTest |
| Certificates: templates | certificate_templates | `settings.manage` | Admin → Certificates → Templates | Landscape/portrait, seal, signature, body tokens | TemplateValidationTest |
| Certificates: issuance | certificates | `certificates.issue` | Admin → Certificates; queued render | Unique number from a configurable prefix sequence; unguessable verification code; PDF via queued job (`ShouldBeUnique`) | CertificateIssuanceTest, UniqueNumberTest |
| Certificates: public verification | certificates | `certificates.verify_public` | `/certificates/verify/{code}` | **Exposes name, award title, issuer, date only** — never DOB, contact or grades (§26); rate-limited; revoked certificates show as revoked | PublicVerificationTest, NoPrivateDataLeakedTest, VerificationRateLimitTest |
| Certificate PDF (dompdf + QR) | certificates, files | — | `resources/views/pdf/certificate.blade.php` | Pure-PHP render (no `wkhtmltopdf` binary); QR encodes the verification URL | CertificatePdfRenderTest |
| Course analytics | learning_activities, course_progress | `reports.lms_analytics` | Admin → Reports → LMS | Enrollments, completion rate, average progress, drop-off lesson | LmsAnalyticsTest |

---

## 5. Phase 4 — Academic Management (Assessment)

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Unified assessment authoring | assessments | `assessments.*` | Admin/Tutor → Assessments | 6 types; max_score, weight, passing_score, publish/due/close, late policy, scope (cohort/individual/course) | AssessmentCrudTest |
| Question bank | questions, question_options | `questions.manage` | Admin/Tutor → Questions | 6 question types; reusable across assessments; tags; difficulty; explanation | QuestionBankTest |
| Quiz assembly | assessment_questions | `assessments.update` | Assessment → Questions (pick from bank, set points & order) | Points override; required flag; ordering | QuizAssemblyTest |
| Quiz settings | quiz_settings | `assessments.update` | Assessment → Settings | Duration, attempts, shuffle questions/options, result visibility, pass % | QuizSettingsTest |
| Quiz attempt engine | assessment_submissions, submission_answers | `submissions.view` (scoped) | Student → Take quiz | `question_snapshot` fixes order at start; timer enforced **server-side**; attempts limit enforced; late policy applied | QuizAttemptTest, ServerSideTimerTest, AttemptLimitTest |
| Auto-scoring | submission_answers | — | — | MCQ/multi-select/TF/matching scored automatically with partial credit via option weights; short/long answer queued for manual marking | AutoScoringTest, PartialCreditTest |
| Randomization integrity | assessment_submissions | — | — | Shuffled order reproducible from the snapshot for dispute resolution | RandomizationSnapshotTest |
| Assignment authoring | assignment_details | `assessments.create` | Assessment (type=assignment) | Instructions, due date, attachments, upload rules (max files, size, MIME), resubmission policy | AssignmentCrudTest |
| Assignment submission | assessment_submissions, files | `submissions.view` | Student → Submit assignment | File + text submission; late detection vs `due_at_snapshot`; version increments on resubmission; **only the latest version is `is_current`** | AssignmentSubmissionTest, LateSubmissionTest, ResubmissionVersioningTest |
| Grading & feedback | assessment_submissions, submission_answers | `submissions.grade`, `.return` | Tutor → To grade; Grading UI | Score ≤ max_score; percentage computed; grade resolved from the scheme; feedback required when `returning`; graded_by/graded_at recorded | GradingTest, ScoreBoundaryTest |
| Return for resubmission | assessment_submissions | `submissions.return` | Grading UI → Return | Status `returned`; resubmission window; notified | ReturnSubmissionTest |
| Reopen a submission | assessment_submissions | `submissions.reopen` | Admin → Submission | Audited; only before results published | ReopenAuditTest |
| Configurable grading schemes | grading_schemes, grading_scale_bands | `grading_schemes.*` | Admin → Academics → Grading | Bands contiguous & non-overlapping (validated); scope global/program/level/course; default flag; **nothing hard-coded (§24)** | GradingSchemeValidationTest, BandOverlapTest |
| Grade resolution | academic_results | `grades.view` | — | `GradeResolver` maps percentage → band → letter + GPA + remark; missing band throws loudly | GradeResolverTest, MissingBandThrowsTest |
| Gradebook aggregation | academic_results | `academic_results.generate` | Admin/Tutor → Gradebook | Weighted contribution across assessments per (student, term, course/subject); provisional until published | GradebookAggregationTest |
| Results publication | academic_results | `grades.publish` | Admin → Results | Status `published` → visible to student/parent per assessment flags; **immutable afterwards** — amendments append + audit | ResultsPublicationTest, PublishedResultImmutableTest |
| Grade amendment | academic_results, audit_logs | `grades.amend` | Result detail → Amend | New row with `status=amended`; old→new audited; reason required | GradeAmendmentAuditTest |
| Academic history / transcript | academic_results | `grades.view` (scoped) | Student → Results; Parent → Academics | Per year/term/program/subject/course with remarks & completion | AcademicHistoryTest |
| Report cards | academic_reports, files | `report_cards.generate` | Admin → Reports; Parent → Download | Queued PDF render; one per (student, term, type); shared-with-parent flag | ReportCardGenerationTest |
| At-risk detection | attendance_records, course_progress, academic_results | `reports.at_risk_students` | Admin → Reports → At-risk | Configurable thresholds in `settings` (attendance %, progress stall, failing grade) — not hard-coded | AtRiskRulesTest |

---

## 6. Phase 5 — Parent & Student Portals

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Parent dashboard | (reads many) | `parent.portal.access` | `/parent` | Children cards, upcoming classes, pending payments, recent grades, announcements — **all from real queries (§94)** | ParentDashboardTest, NoPlaceholderDataTest |
| Child switching | parent_student | `students.view` (scoped) | Child switcher in the topbar | Only active links appear; switching re-scopes every query; deep links carry the child id and re-authorize | ChildSwitchingTest, CannotSwitchToUnlinkedChildTest |
| Parent: academics view | academic_results, assessment_submissions | `grades.view` (scoped) | Parent → Child → Academics | Gated by `can_view_academics` **and** `results_visible_to_parents` | ParentAcademicAccessTest |
| Parent: attendance view | attendance_records | `attendance.view` (scoped) | Parent → Child → Attendance | Per-term + per-cohort summary | ParentAttendanceTest |
| Parent: courses & progress | course_enrollments, course_progress | `progress.view` (scoped) | Parent → Child → Learning | Progress bars, last activity, completion | ParentProgressTest |
| Parent: upcoming classes | course_sessions | `sessions.view` (scoped) | Parent → Calendar | Only the child's cohort sessions; timezone-converted | ParentCalendarTest |
| Parent: tutoring | tutoring_bookings, tutoring_sessions | `bookings.view` (scoped) | Parent → Tutoring | Bookings for linked children; session notes visible per `visibility` | ParentTutoringTest |
| Parent: tutor information | tutor_profiles | `tutors.view` | Parent → Tutoring → Tutor | Public profile fields only — never contact details or earnings | ParentTutorInfoScopeTest |
| Parent: payments/orders/invoices | orders, payments, invoices | `payments.view`, `invoices.view` (scoped) | Parent → Billing | Own orders only; gated by `can_view_financials` for child-scoped records | ParentBillingScopeTest |
| Parent: subscriptions | subscriptions | `subscriptions.view` (scoped) | Parent → Billing → Subscriptions | Own subscriptions; cancel self-service | ParentSubscriptionTest |
| Parent: digital products & downloads | entitlements, digital_product_downloads | `downloads.authorize` | Parent → Library | Download only with an active entitlement | ParentLibraryTest |
| Student dashboard | (reads many) | `student.portal.access` | `/learn` | My Courses, continue-learning, due assignments, upcoming classes, progress | StudentDashboardTest |
| Student: my courses | course_enrollments | `courses.view` (scoped) | `/learn/courses` | Enrolled only | StudentCoursesScopeTest |
| Student: assignments/quizzes/exams | assessments, assessment_submissions | `assessments.view` (scoped) | `/learn/assessments` | Open/due/completed tabs; only assessments in scope | StudentAssessmentsTest |
| Student: results | academic_results | `grades.view` (scoped) | `/learn/results` | Published only | StudentResultsTest |
| Student: schedule | course_sessions, tutoring_sessions | `sessions.view` (scoped) | `/learn/schedule` | Unified calendar | StudentScheduleTest |
| Student: certificates | certificates | `certificates.view` (scoped) | `/learn/certificates` | Own only; download + share/verify link | StudentCertificatesTest |
| Unified calendar | course_sessions, tutoring_sessions, assessments, tutor_interviews | role-scoped | `/calendar` (all portals) | Role-aware event sources (§53): parent sees children's events; student sees own; tutor sees teaching + interviews; admin sees all | CalendarRoleScopingTest (one per role) |
| In-app notifications | notifications | — | Bell + `/notifications` | Database channel; unread count; mark read; grouped | NotificationBellTest |
| Notification preferences | notification_preferences, message_templates | `user.profile.update_own` | `/settings/notifications` | Per key × channel; **transactional keys are read-only and cannot be disabled (§82)** | PreferenceTest, TransactionalCannotBeDisabledTest |
| Marketing opt-in separation | consents, notification_preferences | — | Registration + settings | **Defaults to false**; never implied by account creation (§83) | MarketingOptInDefaultFalseTest |

---

## 7. Phase 6 — Tutor Recruitment

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Public "Become a tutor" | tutor_applications | `tutors.applications.create` | `/tutors/apply` (multi-step) | Draft autosave; submit requires mandatory sections + CV | ApplicationFormTest, DraftAutosaveTest |
| Application profile data | tutor_applications | same | Steps: personal, education, experience, subjects, availability, bio, references | Subject + level selection; languages; expected rate; timezone | ApplicationValidationTest |
| Document upload | tutor_application_documents, files | `files.upload` | Steps: documents | CV required; certifications optional; **files stored `private`** (§59) | DocumentVisibilityTest |
| Application status machine | tutor_applications, tutor_application_events | `tutors.applications.*` | Admin → Recruitment → Applications | 9 states; every transition appends an immutable event with actor + reason | ApplicationStateMachineTest, TransitionHistoryTest |
| Reviewer/evaluator assignment | tutor_applications | `tutors.applications.assign` | Application detail → Assign | Evaluators see **only assigned** applications (§28) | AssignmentScopeTest, EvaluatorSeesOnlyAssignedTest |
| Application review workspace | tutor_applications, documents | `tutors.applications.review` | Evaluator → Application (documents + history side by side) | Document viewing audited; no financial data anywhere in this area (§49) | ReviewWorkspaceTest, NoFinancialDataForEvaluatorTest |
| Evaluation forms (versioned) | evaluation_forms, evaluation_criteria, evaluation_form_criteria | `evaluation_forms.manage` | Admin → Recruitment → Evaluation forms | Criteria with weights; min/max per criterion; **version frozen at submission** | EvaluationFormTest, FormVersionFreezeTest |
| Interview scheduling | tutor_interviews, meetings | `tutors.interviews.schedule` | Evaluator/Admin → Interviews → Schedule | Creates a `meetings` row via `MeetingManager`; notifies applicant + evaluator; timezone-aware | InterviewSchedulingTest, InterviewMeetingCreatedTest |
| Interview conduct & notes | tutor_interviews | `tutors.interviews.manage` | Interview detail | status scheduled→in_progress→completed/cancelled/no_show; notes; score | InterviewLifecycleTest |
| Evaluation submission | tutor_evaluations, evaluation_criterion_scores | `tutors.evaluations.submit` | Interview → Evaluation form | Per-criterion scores validated against min/max; weighted total computed; recommendation required; **immutable after submit** (§29) | EvaluationSubmissionTest, EvaluationImmutableTest |
| Duplicate evaluation prevention | tutor_evaluations | — | — | `UQ(application, evaluator, interview)` | DuplicateEvaluationBlockedTest |
| Recommendation roll-up | tutor_evaluations | `tutors.applications.view` | Application detail → Panel | Shows each evaluator's recommendation; does **not** auto-approve | RecommendationRollupTest |
| Tutor approval | tutor_profiles, users, tutor_applications | **`tutors.approve`** | Application → Approve | The **only** path to a profile (§30): creates/activates profile, copies qualifications, links/creates user, grants Tutor role + portal permission, audits | ApproveTutorTest, EvaluatorCannotApproveTest, ApprovalIsAtomicTest |
| Tutor rejection | tutor_applications | `tutors.reject` | Application → Reject | Reason required; notified; **no profile created; never publicly bookable** (§30) | RejectTutorTest, RejectedNotBookableTest |
| Further review loop | tutor_applications | `tutors.applications.review` | Application → Return to review | Loops to `under_review`, audited | FurtherReviewTest |
| Withdrawal | tutor_applications | `tutors.applications.withdraw` | Applicant → My application | Allowed from any non-terminal state | WithdrawalTest |
| Tutor profile editing | tutor_profiles, tutor_subjects, files | `tutor.profile.update_own` | Tutor → Profile | Slug unique; bio sanitized; photo upload; subjects/levels; languages; methodology | ProfileEditTest |
| Public tutor directory | tutor_profiles, tutor_subjects | public | `/tutors` (filters: subject, level, language, experience, price, availability, rating) | **Only `status=active` AND `is_publicly_listed`** (§87); private fields never serialized | DirectoryTest, UnapprovedNotListedTest, PrivateFieldsHiddenTest |
| Tutor public profile page | tutor_profiles | public | `/tutors/{slug}` | Public fields only; reviews; services; availability summary | PublicProfileTest |
| Recruitment notifications | — | — | Emails: application received, interview scheduled, approved, rejected | Queued; templated (§52) | RecruitmentNotificationTest |

---

## 8. Phase 7 — Tutoring Delivery

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Tutoring services | tutoring_services | `tutoring_services.manage` | Admin/Tutor → Services | Subject, level, duration, price (Money), delivery mode, tutor assignment (specific/pool/any), booking & cancellation rules, **`requires_parent_purchase`** (§33) | ServiceCrudTest, RequiresParentPurchaseDefaultTest |
| Tutoring packages | tutoring_packages | `tutoring_packages.manage` | Service → Packages | N sessions, package price, validity days | PackageCrudTest |
| Tutor availability | tutor_availabilities | `tutor.availability.manage_own` | Tutor → Availability (weekly grid) | Working days/hours, timezone, recurrence, service scope, slot duration | AvailabilityCrudTest, TimezoneStoredTest |
| Blocked dates & holidays | tutor_unavailable_dates, non_working_dates | `tutor.availability.manage_own`, `holidays.manage` | Tutor → Availability → Blocked | Date or datetime range; reason | BlockedDateTest |
| Slot finder | (reads availability, sessions, holidays) | `tutoring_services.view` | Booking flow → Pick a time | Respects availability, existing bookings, service duration, buffer, min notice, max advance, **timezones** (§32) | SlotFinderTest (unit, many cases), DstBoundaryTest, BufferRespectedTest |
| Double-booking prevention | tutoring_sessions | — | — | Transaction + `lockForUpdate` **and** `UQ(tutor_id, starts_at)` | ConcurrentBookingTest |
| Booking (parent for child) | tutoring_bookings | `bookings.create` | Parent → Book tutoring | Purchaser = parent, beneficiary = child, both recorded (§34); guardian link verified | BookingRecordsBothPartiesTest |
| Booking (adult student for self) | tutoring_bookings | `bookings.create` | Student → Book | Allowed only when 18+ **or** the service doesn't require a guardian | AdultSelfBookingTest, MinorSelfBookingBlockedTest |
| Booking awaiting payment | tutoring_bookings, orders | `bookings.create` | Booking → Pay | Slot held with a configurable expiry; released on timeout/cancel | SlotHoldExpiryTest |
| Admin-assisted booking | tutoring_bookings | `orders.admin_assisted_checkout` | Admin → Bookings → New | Same `CheckoutService` choke point → same minor rule (§10) | AdminAssistedBookingEnforcesMinorRuleTest |
| Session materialization | tutoring_sessions | `sessions.tutoring.manage` | — | One session per booked slot; inherits beneficiary, guardian, rate snapshot | SessionMaterializationTest |
| Session lifecycle | tutoring_sessions | `sessions.tutoring.manage` | Tutor → Sessions | scheduled→confirmed→in_progress→completed / cancelled / no_show; join/leave timestamps; actual duration | SessionLifecycleTest |
| Cancellation & rescheduling | tutoring_bookings, tutoring_sessions | `bookings.cancel` | Booking detail → Cancel | Cancellation window + fee percent from the service policy; reason + actor recorded; slot released | CancellationPolicyTest, CancellationFeeTest |
| Session notes & homework | session_notes | `session_notes.manage` | Tutor → Session → Notes | topics/homework/observations/next steps; visibility tiers (tutor-only / parent / student / all) | SessionNotesTest, NoteVisibilityTest |
| Session progress record | tutoring_sessions | `sessions.tutoring.manage` | Tutor → Session → Progress | Progress summary feeds the parent progress report (§100) | SessionProgressTest |
| Tutoring attendance | attendance_records | `attendance.record` | Session → Attendance | Same polymorphic table as class attendance (§15) | TutoringAttendanceTest |
| Review eligibility | tutor_reviews | `reviews.create` | Parent/Student → Review | Only after a **completed** session; only participants; one review per session per reviewer; tutors cannot review themselves (§88) | ReviewEligibilityTest, NoSelfReviewTest, DuplicateReviewBlockedTest, ReviewBeforeSessionBlockedTest |
| Review moderation | tutor_reviews | `reviews.moderate`, `.hide`, `.delete` | Admin → Reviews | pending→published/hidden/reported; tutor response allowed | ReviewModerationTest |
| Rating aggregate | tutor_profiles | — | — | Recomputed on publish/moderation inside the transaction | RatingAggregateTest |
| Earnings ledger | tutor_earnings | `reports.revenue` (scoped) | Tutor → Earnings; Admin → Finance → Tutor earnings | gross, platform_fee (from `settings`), tutor_share, tax, net, payout_status; **no payout execution in v1** (§89) | EarningsCalculatorTest, FeeSnapshotImmutableTest |
| Tutor dashboard | (reads many) | `tutor.portal.access` | `/tutor` | Upcoming classes, tutoring sessions, students, cohorts, to-grade, availability, calendar, earnings, reviews | TutorDashboardTest |
| Evaluator dashboard | tutor_applications, tutor_interviews, tutor_evaluations | `evaluator.portal.access` | `/evaluator` | Assigned applications, pending reviews, upcoming/completed interviews, recommendations, history — **no financial widgets exist in this area** (§49) | EvaluatorDashboardTest, EvaluatorDashboardHasNoFinanceTest |

---

## 9. Phase 8 — Commerce

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Product catalogue | products, product_categories | `products.*`, `product_categories.manage` | Admin → Commerce → Products | SKU unique; `productable` link to course/program/digital/service/package; publish states (§86) | ProductCrudTest, ProductPublishingTest |
| Digital products | digital_products, digital_product_files | `digital_products.manage` | Admin → Commerce → Digital products | E-books/PDFs/worksheets/guides/recordings; author; category; download limit; expiry rules (§39) | DigitalProductTest |
| Storefront | products | public | `/store` (category filters, search, sort) | Published only; price rendered from `Money` + org currency | StorefrontTest |
| Product detail + purchase | products | public | `/store/{slug}` | Variant/package selection; beneficiary selection when required | ProductPageTest |
| Cart | carts, cart_items | `orders.create` | `/cart` | DB-backed; per-item beneficiary; price snapshot at add + revalidated at checkout | CartTest, PriceRevalidatedAtCheckoutTest |
| Checkout | orders, order_items, invoices | `orders.create` | `/checkout` | `CheckoutService` — the single choke point: pricing → coupon → tax → totals → **minor guard** → order → invoice → payment init | CheckoutTest, CheckoutNeverTrustsClientAmountTest |
| ★ Minor purchase guard | parent_student, students, products | `commerce.override_minor_restriction` (override only) | — | Age + service type + purchaser relationship, enforced server-side for **every** channel (§10) | MinorPurchaseGuardTest (unit), CheckoutBlockedForMinorTest, AdminAssistedBlockedForMinorTest, ApiBlockedForMinorTest, UnknownDobFailsSafeTest |
| Paystack initialization | payments | — | — | `POST /transaction/initialize`; reference generated server-side & stored UNIQUE; secret never in the browser (§41) | PaystackInitializeTest, SecretNotExposedTest |
| Paystack hosted checkout | — | — | Redirect | Authorization URL used; callback shows "verifying…" only | CallbackShowsPendingTest |
| Server-side verification | payments, orders | `payments.verify` | — | `GET /transaction/verify/{reference}`; amount + currency matched against the order; **redirect alone never marks paid** (§41) | VerificationTest, AmountMismatchTest, RedirectDoesNotMarkPaidTest |
| Webhook receiver | payment_events | — | `POST /webhooks/paystack` | CSRF-exempt; HMAC-SHA512 over the **raw** body with `hash_equals`; 200 returned before heavy work (§42) | WebhookSignatureTest, InvalidSignatureRejectedTest, WebhookReturns200FastTest |
| Webhook idempotency | payment_events, payments, entitlements | — | — | `UQ(provider, event_id)`; replayed events change nothing (§42) | DuplicateWebhookTest, ReplayDoesNotDuplicateEntitlementTest |
| Charge success handling | payments, orders, entitlements, invoices | — | — | Marks payment success once, order paid, grants entitlements, issues invoice PDF, notifies | ChargeSuccessTest, OrderAlreadyPaidIdempotentTest |
| Charge failure handling | payments, orders | — | — | Marks failed; order → failed; slot hold released; notified | ChargeFailedTest |
| Entitlement granting | entitlements, entitlement_events | `entitlements.view` | — | `EntitlementGranter` — one entitlement per order item; expiry from product rules; sessions_included for packages (§45) | EntitlementGrantTest, EntitlementExpiryTest, GrantIsIdempotentTest |
| Manual entitlement grant | entitlements | `entitlements.grant_manual` | Admin → Entitlements → Grant | Source `manual_grant`; reason required; audited | ManualGrantTest |
| Entitlement revocation | entitlements | `entitlements.revoke` | Admin → Entitlement | Revokes access, suspends enrollments, audited | RevocationTest |
| Entitlement expiry sweep | entitlements | — | `entitlements:expire` (scheduled) | `ends_at` passed or sessions consumed → `expired`; enrollment suspended; notified | ExpirySweepTest |
| Course access resolution | course_enrollments, entitlements, cohort_student | — | — | `CourseAccessResolver` (enrollment OR cohort OR entitlement OR staff OR free preview) | CourseAccessResolverTest (unit + feature) |
| Secure digital downloads | entitlements, digital_product_downloads, files | `downloads.authorize` | `/files/{uuid}/download` | authenticated → purchased → payment confirmed → entitlement active → limit not exceeded → not expired; ledger row with IP/UA/count; rate-limited (§40) | DownloadAuthorizationTest, DownloadLimitTest, ExpiredEntitlementBlockedTest, DownloadLedgerTest, DownloadRateLimitTest |
| Invoices | invoices, invoice_items, files | `invoices.*` | Admin → Invoices; Parent → Billing | Number sequence; issued/due/paid/overdue/void; queued PDF | InvoiceTest, InvoicePdfTest |
| Refunds | refunds, payments, orders, entitlements | `payments.refund` | Payment detail → Refund | Full/partial; provider call where supported; entitlements revoked; order → refunded/partially_refunded; audited (§44, §56) | RefundTest, RefundRevokesEntitlementTest, PartialRefundTest |
| Coupons | coupons, coupon_redemptions | `coupons.manage` | Admin → Commerce → Coupons; checkout → apply | Percent/fixed; max redemptions; per-user limit; product/program scope; min order; discount cap; expiry | CouponTest, CouponLimitTest, CouponScopeTest, ExpiredCouponTest |
| Tax | tax_rates, order_items | `tax_rates.manage` | Checkout summary | Rate resolution by scope; inclusive/exclusive; per-line tax | TaxCalculationTest |
| Payment reconciliation | payments, orders, payment_events | `payments.verify` | `payments:reconcile` (scheduled) + Admin → Payments → Reconcile | Finds orders stuck in `awaiting_confirmation`, re-verifies with the provider, alerts on mismatch (§41) | ReconciliationTest, StuckOrderRecoveredTest |
| Order administration | orders, order_events | `orders.*` | Admin → Orders (filters: status, customer, date, payment status) | Timeline view; cancel; notes; §79 filtering | OrderAdminTest, OrderFiltersTest |
| Payment history | payments | `payments.view` (scoped) | Parent → Billing → History; Admin → Payments | Own records only for parents | PaymentHistoryScopeTest |

---

## 10. Phase 9 — Subscriptions

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Subscription products | products | `products.manage` | Product editor → Recurring | `is_subscription`, `billing_interval`, trial days; **not every product is recurring (§43)** | SubscriptionProductTest |
| Paystack plan sync | products, subscriptions | — | Admin → Commerce → Plans | Creates/updates the provider plan code; stored on the product | PlanSyncTest |
| Subscribe (parent for child) | subscriptions, entitlements | `subscriptions.view` | `/subscribe/{product}` | Customer = parent, beneficiary = child; **minor guard applies** (§10) | SubscribeTest, MinorGuardOnSubscriptionTest |
| `subscription.create` webhook | subscriptions, subscription_events | — | — | Idempotent by `UQ(provider, provider_subscription_id)` | SubscriptionCreateWebhookTest, DuplicateSubscriptionBlockedTest |
| Renewal handling | subscriptions, invoices, payments, entitlements | — | — | `charge.success` with a subscription reference → new invoice + payment, entitlement extended (never duplicated) | RenewalTest, RenewalExtendsNotDuplicatesTest |
| Failed renewal / dunning | subscriptions, subscription_events | — | — | `charge.failed` → `past_due`; retry schedule; grace period from settings; notify | PastDueTest, DunningNotificationTest |
| Subscription status machine | subscriptions | `subscriptions.manage` | — | active / past_due / cancelled / expired / paused / pending | SubscriptionStateMachineTest |
| Cancellation | subscriptions, subscription_events | `subscriptions.manage` (scoped) | Parent → Billing → Subscriptions → Cancel | Provider call + local state; `cancelled_at`; access retained until `ends_at` (§43) | CancellationTest, AccessRetainedUntilPeriodEndTest |
| Expiry sweep | subscriptions, entitlements | — | `subscriptions:check` (scheduled) | Expire lapsed subscriptions and their entitlements | SubscriptionExpiryTest |
| Recurring tutoring sessions | tutoring_bookings, tutoring_sessions, entitlement_consumptions | `bookings.create` | — | Each renewal consumes one session from the entitlement; `UQ(entitlement, session)` prevents double-spend | RecurringSessionTest, ConsumptionUniqueTest |
| Subscription administration | subscriptions | `subscriptions.view`, `.manage` | Admin → Commerce → Subscriptions | Filters by status/customer/next billing; manual status correction audited | SubscriptionAdminTest |

---

## 11. Phase 10 — Reporting & Analytics

| Feature | Tables | Permissions | Screens | Business rules | Tests |
|---------|--------|-------------|---------|----------------|-------|
| Admin dashboard | (reads many) | `admin.panel.access` | `/admin` | Total/active students, parents, tutors, pending applications, upcoming interviews, active courses/cohorts, enrollments, attendance, revenue, orders, subscriptions, digital sales, completion, performance, tutor performance — **all live queries + charts (§50, §94)** | AdminDashboardTest, DashboardUsesRealDataTest |
| Student reports | academic_results, attendance_records, course_progress | `reports.students`, `.attendance`, `.academic_performance` | Admin → Reports → Students | Enrollment, attendance, performance, progress, completion, at-risk | StudentReportTest |
| Parent reports | orders, subscriptions, students | `reports.view` (scoped) | Parent → Reports | Children, purchases, payments, subscriptions | ParentReportTest |
| Tutor reports | tutoring_sessions, attendance_records, tutor_reviews, academic_results | `reports.tutors` | Admin → Reports → Tutors; Tutor → My stats | Sessions, students, attendance, ratings, performance | TutorReportTest |
| Finance reports | orders, payments, refunds, invoices, subscriptions, products | `reports.finance`, `.revenue` | Admin → Reports → Finance | Revenue, orders, payments, failed payments, refunds, product sales, subscription revenue (§55) | FinanceReportTest, FinanceReportForbiddenForEvaluatorTest |
| Education reports | program_enrollments, course_enrollments, attendance_records, academic_results | `reports.enrollment`, `.academic_performance` | Admin → Reports → Education | Enrollment by program/course, attendance, assessment results, completion rates | EducationReportTest |
| LMS analytics | learning_activities, course_progress | `reports.lms_analytics` | Admin → Reports → LMS | Engagement, drop-off, time-on-task | LmsAnalyticsTest |
| Date-range & filters | — | `reports.view` | Report controls | Every report supports date range, status filter, program/course/cohort filter, sort, pagination (§79) | ReportFilterTest |
| Queued report export | import_batches, files | `reports.export`, `exports.run` | Report → Export CSV/PDF | Chunked, queued, downloaded through the secure file route | ReportExportTest |
| CSV import: grades, attendance, products, cohort members | import_batches, import_batch_rows | `imports.run` + entity permission | Admin → Imports | Validate-then-commit; per-row error report; rollback on failure (§80) | ImportValidationTest, ImportRollbackTest, ImportErrorReportTest |
| Bulk operations | various | per-entity bulk permissions | Tables → row selection | Authorization checked **per record** (§81); partial-success reporting | BulkOperationAuthorizationTest |
| Scheduled report delivery | — | `reports.build_scheduled` | Admin → Reports → Schedules | Queued build + emailed link to a secure download | ScheduledReportTest |

---

## 12. Phase 11 — Hardening (cross-cutting)

| Feature | Scope | Verification |
|---------|-------|--------------|
| Authorization audit | Every route + Livewire action mapped to a policy method; every policy method to a permission | `platform:audit-authorization` command emits a coverage report; gaps fail CI |
| Security review | CSRF, XSS, SQLi, mass assignment, upload validation, session config, headers, rate limits, secret handling | Checklist in [09 §10](09-shared-hosting-deployment.md); automated tests where possible |
| Performance pass | N+1 detection, index review, slow-query log, eager-loading audit, pagination everywhere | `Model::preventLazyLoading` in tests; `EXPLAIN` review of the 20 hottest queries |
| Data privacy pass | Retention settings, archive/delete policies, consent records, restricted-file review | Retention command + tests |
| Test coverage ratchet | 80% line coverage; every §90 rule has a named unit test | CI enforces; coverage cannot decrease |
| Static analysis | larastan level 6 → 8 | CI gate |
| Deployment documentation | cPanel guide, cron, queues, SSL, webhooks, OAuth redirects, backups | [09](09-shared-hosting-deployment.md) verified against a real cPanel checklist |
| Backup strategy | DB + files + config, stored outside the webroot | Documented + a `platform:backup` command |
| Load sanity | 10k students / 100k submissions seeded; dashboards still < 500 ms | Benchmark script + recorded results |

---

## 13. Feature → spec traceability

Every numbered requirement in the brief maps to at least one row above. Coverage check:

| Spec § | Requirement | Feature rows |
|--------|-------------|--------------|
| §6 | RBAC, granular permissions | Phase 1 rows 8–11; [05](05-role-permission-matrix.md) |
| §7 | Years, terms, programs, subjects, courses | Phase 2 rows 1–5; Phase 3 row 1 |
| §8 | Student profile + statuses | Phase 2 "Student profiles" |
| §9 | Parent/guardian, multiple children, link/unlink + audit | Phase 2 "Guardian ↔ student links" |
| §10 | **Minor purchase rule** | Phase 8 "★ Minor purchase guard" (+ ADR-04) |
| §11 | Admissions workflow + conversion | Phase 2 "Admissions applications", "Applicant → student conversion" |
| §12 | Program enrollment | Phase 2 "Program enrollment" |
| §13 | Cohorts + bulk enrollment | Phase 2 "Cohorts", "Bulk cohort enrollment" |
| §14 | Online scheduling + provider interface | Phase 2 "Schedules"/"Session generation"; Phase 7 "Session materialization" |
| §15 | Attendance (+ parent summaries) | Phase 2 "Attendance", "Parent-visible attendance summary" |
| §16–§18 | LMS structure, lessons, learning paths | Phase 3 rows 1–7 |
| §19 | Enrollment/access via entitlements | Phase 3 "Entitlement-driven enrollment"; Phase 8 "Course access resolution" |
| §20 | Progress tracking + dashboards | Phase 3 "Lesson completion", "Course progress"; Phase 5 dashboards |
| §21 | Quiz engine (6 types, settings, attempts) | Phase 4 rows 2–7 |
| §22 | Assignments + submissions + grading | Phase 4 rows 8–12 |
| §23 | Unified assessments | Phase 4 row 1 |
| §24 | Configurable grading | Phase 4 "Configurable grading schemes", "Grade resolution" |
| §25 | Academic records + reports | Phase 4 rows 15–19 |
| §26 | Certificates + public verification | Phase 3 rows 15–19 |
| §27 | Tutor applications | Phase 6 rows 1–4 |
| §28 | Evaluators (+ no approval authority) | Phase 6 rows 5–12, "Tutor approval" |
| §29 | Interviews + immutable audit trail | Phase 6 rows 8–11 |
| §30 | Approval → profile activation | Phase 6 "Tutor approval", "Tutor rejection" |
| §31 | Tutor profile | Phase 6 "Tutor profile editing" |
| §32 | Availability + scheduling engine | Phase 7 rows 3–5 |
| §33 | Tutoring services | Phase 7 rows 1–2 |
| §34 | Booking (purchaser + beneficiary) | Phase 7 rows 6–9 |
| §35 | Sessions | Phase 7 rows 10–12 |
| §36–§37 | Google Meet + Zoom behind one abstraction | Phase 7 (meetings); [08](08-integrations.md) |
| §38–§40 | Commerce, digital products, secure downloads | Phase 8 |
| §41–§42 | Paystack + webhook security/idempotency | Phase 8 rows 7–14 |
| §43 | Subscriptions | Phase 9 |
| §44 | Orders | Phase 8 |
| §45 | Entitlements | Phase 8 rows 15–19 |
| §46–§50 | Five dashboards | Phase 5, 6, 7, 10 |
| §51–§52 | Notifications + email templates | Phase 5; [08 §6](08-integrations.md) |
| §53 | Unified calendar | Phase 5 "Unified calendar" |
| §54 | Global search | Phase 2 "Global search"; extended per phase |
| §55 | Reporting | Phase 10 |
| §56 | Audit log | Phase 1 "Audit log viewer" + history tables throughout |
| §57–§58 | Security + privacy | Phase 11 |
| §59 | File management | Phase 1 "File registry", "Secure file downloads" |
| §60 | Administration/settings | Phase 1 "Settings registry" |
| §61–§62 | Multi-currency + timezones | Cross-cutting (ADR-02, ADR-08) |
| §63–§65 | Responsive UX + design system | Phase 1 "Design system"; every screen thereafter |
| §66–§67 | Database design + relationships | [03](03-database-erd.md), [04](04-database-table-inventory.md) |
| §68 | API readiness | Phase 1 "API scaffolding" |
| §69–§70 | Queues + scheduler | Phase 1; [09 §7–8](09-shared-hosting-deployment.md) |
| §71–§72 | Testing incl. payments | Test columns in every table above |
| §73–§74 | Seed + demo data | Phase 1 "Demo accounts"; Phase 10 seed completeness |
| §75 | Environments | [00 §8](00-environment-and-constraints.md), [09](09-shared-hosting-deployment.md) |
| §76–§78 | Errors, observability, performance | Phase 1 "Error pages"; Phase 11 |
| §79 | Search & filtering | Every admin table row |
| §80 | Import/export | Phase 2 + Phase 10 |
| §81 | Bulk operations | Phase 2 "Bulk cohort enrollment"; Phase 10 |
| §82–§83 | Notification preferences, marketing separation | Phase 5 |
| §84–§86 | CMS + publishing workflows | Phase 2 "Announcements"; Phase 3 "Publishing"; Phase 8 "Product catalogue" |
| §87–§89 | Directory, reviews, earnings prep | Phase 6 + Phase 7 |
| §90 | Centralized business rules | ADR-14 + the "Business rules" column above |
