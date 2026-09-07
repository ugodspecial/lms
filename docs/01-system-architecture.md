# 01 — System Architecture

**Deliverable:** spec §102.1 — *"A complete system architecture."*

---

## 1. Architectural style

A **modular monolith** with a strict four-layer dependency direction, organised by
**business domain** rather than by technical concern.

```
┌─────────────────────────────────────────────────────────────────────┐
│  PRESENTATION   Blade views · Livewire components · Blade UI kit     │
│                 Routes · Controllers (thin) · Form Requests          │
└───────────────────────────────┬─────────────────────────────────────┘
                                │ may call ▼
┌───────────────────────────────┴─────────────────────────────────────┐
│  APPLICATION    Actions (one use-case each) · DTOs · Jobs ·          │
│                 Listeners · Notifications · Commands · Policies      │
└───────────────────────────────┬─────────────────────────────────────┘
                                │ may call ▼
┌───────────────────────────────┴─────────────────────────────────────┐
│  DOMAIN         Entities · Value Objects · Enums · Domain Services   │
│                 Business rules · Contracts (ports) · Domain Events   │
│                 ⛔ knows nothing about HTTP, Eloquent, Livewire       │
└───────────────────────────────┬─────────────────────────────────────┘
                                │ implemented by ▲
┌───────────────────────────────┴─────────────────────────────────────┐
│  INFRASTRUCTURE Eloquent models · Paystack/Zoom/Google adapters ·    │
│                 Filesystem · Mail · Queue · Cache · Audit · Settings  │
└─────────────────────────────────────────────────────────────────────┘
```

**The dependency rule:** source-code dependencies point *downward only*. The Domain layer
never imports from Presentation, Application or Infrastructure. Where the domain needs an
external capability (send mail, create a meeting, charge a card) it declares a **contract**
(port) and infrastructure supplies the **adapter** — this is what makes §98 (shared hosting →
VPS → cloud) and §99 (future mobile API) achievable without rewriting business logic.

### Why not hexagonal/pure-DDD everywhere?

Spec §4: *"Do not create unnecessary abstractions."* The pragmatic reading adopted here:

| Concern | Approach | Rationale |
|---------|----------|-----------|
| Business rules (age/minors, access, grading, scheduling, entitlements) | **Full domain services + value objects** | These are the crown jewels; they must be unit-testable without HTTP or DB (§71) |
| CRUD-ish administration (academic years, subjects, categories, pages, FAQs) | **Thin Action + Form Request + Eloquent directly** | Wrapping `Subject::create()` in a repository adds no value |
| Repositories | Used **only** where a query is genuinely swappable or heavy: `SearchIndex`, `ReportDataSource` | Spec §4 explicitly permits this |
| Eloquent models | Live in `app/Domain/*/Models` and *are* the persistence model | Dual entity/ORM models would double the code for no benefit at this scale |

---

## 2. Directory layout

```
lms/
├── app/
│   ├── Domain/                              ← BUSINESS CORE
│   │   ├── Identity/
│   │   │   ├── Models/          User, ConnectedAccount, Consent
│   │   │   ├── Enums/           UserStatus, Gender, OAuthProvider
│   │   │   ├── ValueObjects/    FullName, Email, PhoneNumber, Timezone
│   │   │   ├── Services/        TwoFactorService, ConnectedAccountService,
│   │   │   │                    OAuthTokenRefresher
│   │   │   ├── Contracts/       DirectorySearchable
│   │   │   ├── Events/          UserRegistered, RoleAssigned, AccountConnected
│   │   │   └── Policies/        UserPolicy
│   │   ├── Education/
│   │   │   ├── Models/          AcademicYear, AcademicTerm, AcademicLevel, Program,
│   │   │   │                    Subject, Student, Parent, ParentStudent,
│   │   │   │                    StudentApplication, ProgramEnrollment, Cohort,
│   │   │   │                    CohortStudent, CourseSchedule, CourseSession,
│   │   │   │                    AttendanceRecord, NonWorkingDate, Announcement
│   │   │   ├── Enums/           StudentStatus, ApplicationStatus, EnrollmentStatus,
│   │   │   │                    AttendanceStatus, SessionStatus, Relationship,
│   │   │   │                    CohortStatus, TermStatus
│   │   │   ├── ValueObjects/    DateRange, AcademicPeriod, StudentCode
│   │   │   ├── Services/        AcademicCalendarService, AttendanceService,
│   │   │   │                    CohortService, SessionGenerator
│   │   │   ├── Actions/         CreateStudent, LinkParentToStudent,
│   │   │   │                    ReviewStudentApplication, ConvertApplicantToStudent,
│   │   │   │                    EnrollStudentInProgram, RecordAttendance
│   │   │   ├── Events/          StudentStatusChanged, GuardianLinked,
│   │   │   │                    ApplicationReviewed, SessionScheduled
│   │   │   ├── Policies/        StudentPolicy, ParentPolicy, CohortPolicy,
│   │   │   │                    ApplicationPolicy, AttendancePolicy
│   │   │   └── Rules/           UniqueStudentCode, ValidAcademicDateRange
│   │   ├── Lms/
│   │   │   ├── Models/          Course, CourseModule, Lesson, LessonTopic,
│   │   │   │                    LessonResource, CourseEnrollment,
│   │   │   │                    CourseProgress, LessonProgress, LearningActivity,
│   │   │   │                    Discussion, DiscussionPost, Certificate,
│   │   │   │                    CertificateTemplate
│   │   │   ├── Enums/           CourseStatus, LessonType, ResourceType,
│   │   │   │                    EnrollmentSource, CertificateStatus
│   │   │   ├── Services/        CourseAccessResolver ★, ProgressCalculator,
│   │   │   │                    CertificateIssuer, LearningPathEvaluator
│   │   │   ├── Actions/         PublishCourse, EnrollStudentInCourse,
│   │   │   │                    MarkLessonComplete, IssueCertificate
│   │   │   ├── Events/          CoursePublished, LessonCompleted, CourseCompleted,
│   │   │   │                    CertificateIssued
│   │   │   └── Policies/        CoursePolicy, LessonPolicy, CertificatePolicy
│   │   ├── Assessment/
│   │   │   ├── Models/          Assessment, QuizSettings, AssignmentDetails,
│   │   │   │                    Question, QuestionOption, AssessmentQuestion,
│   │   │   │                    AssessmentSubmission, SubmissionAnswer,
│   │   │   │                    GradingScheme, GradingScaleBand,
│   │   │   │                    AcademicResult, AcademicReport
│   │   │   ├── Enums/           AssessmentType, QuestionType, SubmissionStatus,
│   │   │   │                    GradingBasis
│   │   │   ├── ValueObjects/    Score, Percentage, Grade ★
│   │   │   ├── Services/        QuizScorer, GradeResolver ★, SubmissionService,
│   │   │   │                    GradebookAggregator, Randomizer
│   │   │   ├── Actions/         CreateAssessment, SubmitAssessment, GradeSubmission,
│   │   │   │                    ReturnSubmission, PublishResults
│   │   │   ├── Events/          AssessmentSubmitted, AssessmentGraded,
│   │   │   │                    ResultsPublished
│   │   │   └── Policies/        AssessmentPolicy, SubmissionPolicy, GradingPolicy
│   │   ├── Tutoring/
│   │   │   ├── Models/          TutorApplication, TutorApplicationDocument,
│   │   │   │                    TutorApplicationEvent, TutorQualification,
│   │   │   │                    TutorWorkExperience, TutorReference, TutorSubject,
│   │   │   │                    TutorProfile, EvaluationForm, EvaluationCriterion,
│   │   │   │                    EvaluationFormCriterion, TutorInterview,
│   │   │   │                    TutorEvaluation, EvaluationCriterionScore,
│   │   │   │                    TutorAvailability, TutorUnavailableDate,
│   │   │   │                    TutoringService, TutoringServiceTutor,
│   │   │   │                    TutoringPackage, TutoringBooking, TutoringSession,
│   │   │   │                    SessionNote, TutorReview, TutorEarning
│   │   │   ├── Enums/           ApplicationStatus, InterviewStatus, Recommendation,
│   │   │   │                    TutorStatus, SessionStatus, BookingStatus,
│   │   │   │                    AvailabilityType, ReviewStatus, PayoutStatus
│   │   │   ├── ValueObjects/    WeeklyWindow, TimeSlot, TutorRate
│   │   │   ├── Services/        SlotFinder ★, BookingConflictDetector,
│   │   │   │                    ApplicationWorkflow, EvaluationScorer,
│   │   │   │                    EarningsCalculator, ReviewEligibility
│   │   │   ├── Actions/         SubmitTutorApplication, ShortlistApplication,
│   │   │   │                    ScheduleInterview, RecordEvaluation,
│   │   │   │                    ApproveTutor ★, RejectTutor, CreateBooking,
│   │   │   │                    CancelBooking, CompleteSession, WriteSessionNote,
│   │   │   │                    PublishReview
│   │   │   ├── Events/          ApplicationSubmitted, InterviewScheduled,
│   │   │   │                    EvaluationRecorded, TutorApproved, TutorRejected,
│   │   │   │                    BookingCreated, SessionCompleted, ReviewPublished
│   │   │   └── Policies/        TutorApplicationPolicy, InterviewPolicy,
│   │   │                        EvaluationPolicy, TutorProfilePolicy,
│   │   │                        BookingPolicy, SessionPolicy, ReviewPolicy
│   │   ├── Commerce/
│   │   │   ├── Models/          ProductCategory, Product, DigitalProduct,
│   │   │   │                    DigitalProductFile, Cart, CartItem, Order,
│   │   │   │                    OrderItem, OrderEvent, Invoice, InvoiceItem,
│   │   │   │                    Payment, PaymentEvent, Refund, Coupon,
│   │   │   │                    CouponRedemption, TaxRate, Subscription,
│   │   │   │                    SubscriptionEvent, Entitlement, EntitlementEvent,
│   │   │   │                    EntitlementConsumption, DigitalProductDownload
│   │   │   ├── Enums/           ProductType, OrderStatus, PaymentStatus,
│   │   │   │                    PaymentProvider, InvoiceStatus, RefundStatus,
│   │   │   │                    SubscriptionStatus, BillingInterval,
│   │   │   │                    EntitlementType, EntitlementStatus,
│   │   │   │                    FileVisibility
│   │   │   ├── ValueObjects/    Money ★, Price, TaxBreakdown, OrderTotals
│   │   │   ├── Services/        CheckoutService ★, MinorPurchaseGuard ★★,
│   │   │   │                    OrderTotalsCalculator, PricingResolver,
│   │   │   │                    CouponValidator, EntitlementGranter ★,
│   │   │   │                    EntitlementExpiryService, DownloadAuthorizer ★,
│   │   │   │                    SubscriptionManager, InvoiceGenerator,
│   │   │   │                    EarningsSplitter
│   │   │   ├── Actions/         AddToCart, CreateOrder, InitialisePayment,
│   │   │   │                    ConfirmPayment, FulfilOrder, IssueRefund,
│   │   │   │                    CancelSubscription, RecordDownload,
│   │   │   │                    AdminAssistedCheckout
│   │   │   ├── Events/          OrderPlaced, OrderPaid, OrderFailed, OrderRefunded,
│   │   │   │                    EntitlementGranted, EntitlementExpired,
│   │   │   │                    SubscriptionRenewed, SubscriptionCancelled,
│   │   │   │                    DownloadServed
│   │   │   └── Policies/        OrderPolicy, PaymentPolicy, ProductPolicy,
│   │   │                        SubscriptionPolicy, EntitlementPolicy, RefundPolicy
│   │   ├── Communication/
│   │   │   ├── Models/          MessageTemplate, NotificationPreference, Page, Faq
│   │   │   ├── Enums/           ChannelPreference, AudienceType, TemplateKey
│   │   │   ├── Services/        AudienceResolver, TemplateRenderer,
│   │   │   │                    NotificationPreferenceResolver, AnnouncementDispatcher
│   │   │   ├── Notifications/   (all Laravel Notification classes — §51, §52)
│   │   │   └── Channels/        (custom channels if needed)
│   │   ├── Integration/
│   │   │   ├── Contracts/       VideoMeetingProvider ★, PaymentGateway ★,
│   │   │   │                    WebhookHandler, IntegrationHealthCheck
│   │   │   ├── DTOs/            CreateMeetingRequest, MeetingResult,
│   │   │   │                    InitialisePaymentRequest, PaymentVerification,
│   │   │   │                    SubscriptionPlan, WebhookEvent
│   │   │   ├── Enums/           MeetingProvider, MeetingStatus, MeetingCapability,
│   │   │   │                    IntegrationStatus
│   │   │   ├── Models/          Meeting, IntegrationLog
│   │   │   ├── Services/        MeetingManager ★, IntegrationLogger,
│   │   │   │                    WebhookDispatcher
│   │   │   └── Adapters/
│   │   │       ├── Video/       GoogleMeetProvider, ZoomProvider, ManualProvider
│   │   │       └── Payments/    Paystack/ (PaystackClient, PaystackGateway,
│   │   │                        PaystackWebhookVerifier, PaystackSigner,
│   │   │                        Handlers/{ChargeSuccessHandler, …})
│   │   └── Administration/
│   │       ├── Models/          Setting, AuditLog, File, ImportBatch, ImportBatchRow
│   │       ├── Enums/           SettingType, SettingGroup, AuditAction,
│   │       │                    FileCategory, FileVisibility, ImportStatus
│   │       ├── Services/        SettingsService ★, AuditLogger ★, FileService ★,
│   │       │                    CsvImporter, ReportBuilder, GlobalSearch ★,
│   │       │                    PlatformDoctor
│   │       ├── Actions/         UpdateSetting, AssignRole, RevokeRole,
│   │       │                    RunImport, ExportReport
│   │       └── Policies/        SettingPolicy, AuditLogPolicy, FilePolicy
│   │
│   ├── Http/                                ← PRESENTATION
│   │   ├── Controllers/
│   │   │   ├── Public/          HomeController, TutorDirectoryController,
│   │   │   │                    CertificateVerificationController, PageController
│   │   │   ├── Auth/            OAuthController (Google/Microsoft redirect+callback)
│   │   │   ├── Admin/           … (grouped by module)
│   │   │   ├── ParentPortal/    …
│   │   │   ├── StudentPortal/   …
│   │   │   ├── TutorPortal/     …
│   │   │   ├── EvaluatorPortal/ …
│   │   │   └── Webhooks/        PaystackWebhookController
│   │   ├── Livewire/            ← full-page & nested components, by portal area
│   │   │   ├── Admin/ Education/ Lms/ Assessment/ Tutoring/ Commerce/
│   │   │   ├── ParentPortal/ StudentPortal/ TutorPortal/ EvaluatorPortal/
│   │   │   └── Shared/          GlobalSearch, DataTable, Calendar, FileUploader,
│   │   │                        NotificationsBell, Toast
│   │   ├── Requests/            ← one Form Request per mutating use-case
│   │   ├── Middleware/          AuthenticateArea, EnsurePermission, SetUserTimezone,
│   │   │                        VerifyPaystackSignature, ForceJson (api),
│   │   │                        ImpersonationGuard, SecurityHeaders
│   │   ├── Resources/           ← API Resources, ready for /api/v1 (§68)
│   │   └── Kernel-ish/          (Laravel 13: middleware registered in bootstrap/app.php)
│   │
│   ├── Support/                 Money formatting, Timezone presenter, Str helpers,
│   │                            EnumHelpers, QueryBuilder (search/filter/sort §79)
│   ├── Providers/               AppServiceProvider, DomainServiceProvider(s),
│   │                            AuthServiceProvider (policies + gates),
│   │                            EventServiceProvider, IntegrationServiceProvider
│   ├── Console/Commands/        platform:doctor, platform:seed-demo,
│   │                            entitlements:expire, payments:reconcile,
│   │                            sessions:generate, sessions:remind,
│   │                            subscriptions:check, certificates:render,
│   │                            reports:build, imports:prune, audit:prune
│   └── Exceptions/                Domain exceptions + Handler mapping to friendly pages
│
├── bootstrap/app.php            ← Laravel 13 slim bootstrap: middleware aliases,
│                                   exceptions, routing groups
├── config/                      platform.php ★ (business config), paystack.php, zoom.php,
│                                google.php, microsoft.php, meetings.php, files.php,
│                                permissions.php, fortify.php, + framework configs
├── database/
│   ├── migrations/              ← 124 migrations in dependency order (§66), prefixed
│   │                               2026_01_xx_phaseN_…  so phase boundaries are visible
│   ├── seeders/                 ← DatabaseSeeder + per-module seeders + DemoDataSeeder
│   └── factories/               ← one factory per model, realistic NG-flavoured data
├── resources/
│   ├── views/
│   │   ├── components/          ← DESIGN SYSTEM (§65): ui.button, ui.input, ui.select,
│   │   │                           ui.table, ui.card, ui.modal, ui.drawer, ui.tabs,
│   │   │                           ui.badge, ui.alert, ui.toast, ui.progress, ui.avatar,
│   │   │                           ui.dropdown, ui.pagination, ui.breadcrumbs,
│   │   │                           ui.empty-state, ui.date-picker, ui.stat, ui.skeleton
│   │   ├── layouts/             app (authenticated shell), public, auth, portal, print
│   │   ├── partials/            navigation/{admin,parent,student,tutor,evaluator},
│   │   │                        sidebar, topbar, mobile-nav, footer
│   │   ├── portals/             admin/ parent/ student/ tutor/ evaluator/
│   │   ├── public/              home, tutors, tutor-show, admissions, certificates/verify
│   │   ├── emails/              ← 16 transactional templates (§52), all extending
│   │   │                           emails.layout, text + html variants
│   │   ├── errors/              403, 404, 419, 429, 500, 503 (§76)
│   │   └── pdf/                 certificate, invoice, report-card
│   ├── lang/en/                 all user-facing strings (localisation-ready §61)
│   ├── css/app.css              Tailwind 4 `@theme` design tokens
│   └── js/app.js                Alpine plugins, Livewire hooks, chart wiring
├── routes/
│   ├── web.php                  → loads: public, auth, admin, parent, student, tutor,
│   │                               evaluator (each in routes/web/*.php)
│   ├── webhooks.php             → Paystack (CSRF-exempt, signature-verified)
│   ├── console.php              → Scheduler definitions (§70)
│   └── api.php                  → reserved /api/v1 (Phase 11+, §68)
├── tests/
│   ├── Unit/                    Domain/… (rules with NO database — §71)
│   ├── Feature/                 Auth, Education, Lms, Assessment, Tutoring, Commerce,
│   │                            Integration, Portals
│   ├── Authorization/           ★ cross-tenant access tests (§71)
│   ├── Payment/                 ★ Paystack lifecycle + webhook abuse (§72)
│   └── Support/                 Factories, fixtures (recorded Paystack payloads),
│                                RefreshDatabaseWithDemo trait
├── docs/                        ← this directory
├── .github/workflows/ci.yml     ← the verification gate (§00 §3.2)
└── public/
    ├── index.php                docroot entry (see §09 for cPanel placement)
    ├── build/                   ← COMMITTED compiled assets so prod needs no Node
    └── storage → ../storage/app/public   (symlink, public-visibility files only)
```

★ = critical component, described below. ★★ = the single most important business rule.

---

## 3. Module map and dependencies

Nine bounded contexts. Arrows show **permitted** dependency direction.

```
                              ┌──────────────────┐
                              │ Administration   │  (settings, audit, files, search,
                              │                  │   imports, reports)
                              └────────▲─────────┘
                                       │ used by all (cross-cutting)
   ┌──────────┐    ┌──────────┐    ┌───┴──────┐    ┌──────────────┐
   │ Identity │◄───│Education │◄───│   Lms    │◄───│  Assessment  │
   └────▲─────┘    └────▲─────┘    └────▲─────┘    └──────▲───────┘
        │               │               │                 │
        │               │               │                 │
   ┌────┴─────┐    ┌────┴──────┐   ┌────┴─────────────────┴────┐
   │Integration│◄──│ Tutoring  │   │        Commerce           │
   └────▲─────┘    └────▲──────┘   └────────────┬──────────────┘
        │               │                       │
        │               └───────────────────────┘  (Commerce grants Entitlements;
        │                                          Tutoring consumes them;
        │                                          Lms reads them for access)
   ┌────┴──────────┐
   │ Communication │  (observes domain events from every module; depends on nothing)
   └───────────────┘
```

**Hard rules:**

1. `Communication` depends on **no** module — it only listens to events. This prevents
   notification logic from becoming a hidden coupling hub.
2. `Integration` knows about DTOs, never about `Order` or `TutoringSession`. The caller
   translates. This is what lets Zoom be swapped for Teams later (§14).
3. `Commerce` **may not** import `Lms` models. It grants an `Entitlement` whose target is
   identified by `product_id` + a resolved type/id; `Lms`'s `CourseAccessResolver` reads
   entitlements. The dependency is inverted through the entitlement table (ADR-03).
4. `Tutoring` may read `Education` (students, subjects, cohorts) but never write to it.
5. Every module owns its migrations, seeders, factories, policies and tests — file ownership
   makes the module boundary physical, not just nominal.

---

## 4. Request lifecycle

### 4.1 A typical Livewire page request (parent views a child's grades)

```
GET /parent/children/{student}/academics
 │
 ├─ 1  Route group middleware (routes/web/parent.php)
 │      web → Authenticate → EnsureEmailIsVerified → SetUserTimezone
 │      → AuthenticateArea:parent  (user must hold ≥1 parent-area permission)
 │
 ├─ 2  Controller: ParentAcademicsController@show(Student $student)
 │      • $this->authorize('viewAcademics', $student)   ← StudentPolicy
 │      • StudentPolicy checks the parent_student link: is_active + can_view_academics
 │      • Returns <livewire:parent-portal.academics :student="$student"/>
 │
 ├─ 3  Livewire component mount()
 │      • Calls Assessment\GradebookQuery::forStudent($student, $term)   ← Application layer
 │      • Eager-loads to avoid N+1 (§78): assessment.submissions, gradingScheme.bands
 │      • No business logic in the component — only view state (filters, sort, page)
 │
 ├─ 4  Blade renders using design-system components (§65)
 │      • Times rendered through TimezonePresenter using $user->timezone  (§62)
 │      • Money rendered through MoneyFormatter using setting('currency')  (§61)
 │
 └─ 5  Subsequent Livewire updates re-run authorization on every action
        (Livewire 4.2 requires X-Livewire header + JSON content type)
```

**Key discipline:** the Livewire component is *presentation*. If a rule is written inside a
component, it is a bug — because §90 requires rules to be reusable by web, API and
admin-assisted paths alike.

### 4.2 A purchase (the most safety-critical flow)

```
Parent clicks "Checkout" on tutoring service for child
 │
 ├─ AddToCart action
 │    └─ validates product published, price unchanged, currency matches org currency
 │
 ├─ CheckoutService::checkout(CheckoutRequest $dto)          ← ONE choke point
 │    │
 │    ├─ 1. PricingResolver        → re-reads price from DB (never trusts client amount)
 │    ├─ 2. CouponValidator        → usage limits, expiry, per-user cap, product scope
 │    ├─ 3. OrderTotalsCalculator  → subtotal, discount, tax (TaxRate), total → Money VO
 │    ├─ 4. ★ MinorPurchaseGuard   → for EACH item with a beneficiary student:
 │    │        if student.ageAt(today) < setting('age_of_majority')      [default 18]
 │    │        && product.requires_parent_purchase
 │    │        && ! GuardianAuthorizer::canPurchaseFor($purchaser, $student)
 │    │             → throw PurchaseRestrictedForMinor
 │    │        (also fails safe when date_of_birth is null → treated as minor)
 │    ├─ 5. CreateOrder            → DB transaction: orders + order_items + order_events
 │    ├─ 6. InvoiceGenerator       → invoice + invoice_items, number from sequence
 │    └─ 7. InitialisePayment      → PaystackGateway::initializeTransaction()
 │             returns authorization_url; reference stored UNIQUE on payments
 │
 ├─ Browser redirected to Paystack (hosted checkout) — secret key never leaves server
 │
 ├─ Paystack callback_url → PaymentCallbackController
 │    └─ shows "Verifying your payment…" and dispatches VerifyPaystackTransaction
 │       ⛔ does NOT mark the order paid
 │
 ├─ VerifyPaystackTransaction (queued)
 │    ├─ GET /transaction/verify/{reference}
 │    ├─ signature-free but authenticated with secret key over TLS
 │    ├─ compares returned amount == order total (minor units) and currency matches
 │    └─ on success → ConfirmPayment action (idempotent)
 │
 └─ Webhook POST /webhooks/paystack  (arrives independently, possibly first)
      ├─ VerifyPaystackSignature middleware (raw body, HMAC-SHA512, hash_equals)
      ├─ INSERT INTO payment_events (provider, event_id UNIQUE, …)  ← idempotency gate
      │    duplicate key → 200 OK and stop
      ├─ respond 200 immediately, then dispatch handler to queue
      └─ ChargeSuccessHandler → ConfirmPayment action
                                    │
                                    ├─ payments.status = success (once)
                                    ├─ orders.status = paid + order_events row
                                    ├─ ★ EntitlementGranter::grantForOrder($order)
                                    │     creates entitlements (owner + beneficiary),
                                    │     never duplicates: UNIQUE(source_type, source_id,
                                    │     product_id, beneficiary_student_id)
                                    ├─ CourseAccessResolver caches are invalidated
                                    ├─ FulfilOrder → invoices.status = paid, PDF queued
                                    └─ events → notifications (parent + student + tutor)
```

Every step above is a class with a single responsibility and a test. The guard at step 4 is
the one the spec singles out (§10) — see ADR-04 for why it is a domain service and not a
middleware or a form-request rule.

---

## 5. Cross-cutting concerns

### 5.1 Authorization (§6, §57)

Three cooperating mechanisms, in this order of precedence:

| Layer | Mechanism | Example |
|-------|-----------|---------|
| 1. Global bypass | `Gate::before` for Super Admin **only** | `if ($user->hasRole(Permissions::ROLE_SUPER_ADMIN)) return true;` |
| 2. Permission gate | spatie/laravel-permission v7, permissions defined in a PHP registry `app/Domain/Administration/Permissions.php` (an enum-like final class with `const` arrays grouped by module) | `students.view`, `tutors.approve`, `payments.refund` |
| 3. Resource policy | Laravel Policy per aggregate, combining permission checks **and** ownership/relationship checks | `StudentPolicy::viewAcademics()` = has `students.view` **OR** is an authorized guardian of *this* student |

**Rule R10 in practice:** controllers and Livewire components never call
`$user->hasRole('parent')`. They call `$this->authorize(...)` or `Gate::allows('students.view')`.
Role strings appear only in seeders and in the Super Admin bypass.

Route-group middleware `AuthenticateArea` maps a portal to a permission *prefix set*:

```php
// config/platform.php
'areas' => [
    'admin'     => ['prefixes' => ['students.', 'courses.', 'tutors.', 'payments.', 'reports.', 'settings.']],
    'parent'    => ['permissions' => ['parent.portal.access']],
    'student'   => ['permissions' => ['student.portal.access']],
    'tutor'     => ['permissions' => ['tutor.portal.access']],
    'evaluator' => ['permissions' => ['evaluator.portal.access']],
],
```

Portal access permissions are granted automatically when a profile is activated
(e.g. approving a tutor grants `tutor.portal.access`), so area access is data-driven.

### 5.2 Auditing (§56)

Two complementary stores — deliberately separated:

| Store | Content | Written by | Retention |
|-------|---------|-----------|-----------|
| `audit_logs` | Generic who/what/when/before/after for **all** auditable models | `Auditable` trait on Eloquent events | configurable via `settings.audit.retention_days` |
| `*_events` history tables (`order_events`, `student_application_events`, `tutor_application_events`, `entitlement_events`, `subscription_events`, `payment_events`) | **Domain workflow transitions**, append-only, immutable, business-visible | Domain actions | permanent (they are part of the academic/financial record) |

Why both? An `audit_logs` row is for compliance and debugging; an `order_events` row is part
of the product (shown on the order timeline in the UI). Mixing them would make the workflow
history queryable only through a generic JSON blob.

`AuditLogger` applies `SensitiveDataScrubber` with a redaction list
(`password`, `*_token`, `*_secret`, `two_factor*`, `card*`, `access_token`, `refresh_token`,
`paystack_secret_key`) so §77's "never log secrets" is structural, not a hope.

### 5.3 Settings (§60, §95)

| Kind of value | Where it lives | Example |
|---------------|----------------|---------|
| Secret | `.env` only | `PAYSTACK_SECRET_KEY`, `ZOOM_CLIENT_SECRET`, `APP_KEY` |
| Environment behaviour | `.env` | `PAYSTACK_MODE`, `APP_ENV`, `QUEUE_CONNECTION` |
| Business configuration | `settings` table, cached | `organization.name`, `currency`, `age_of_majority`, `grading.default_scheme_id`, `tutoring.platform_fee_percent`, `certificates.issuer_name` |

`SettingsService` caches the whole set in one cache entry (`Cache::remember`), exposes
`setting('currency', 'NGN')` and a typed `SettingCast` (string/int/bool/json/decimal).
Admin UI writes through `UpdateSetting`, which audits old→new values. **Nothing business-
critical is hard-coded** — including the age of majority, currency symbol, platform fee and
grading bands (§95, §24, §61).

### 5.4 Files (§40, §59)

One `files` registry, four visibility classes, one authorized streaming route:

```
files.visibility = public          → served from storage/app/public via the storage symlink
                       authenticated → any logged-in user (e.g. course thumbnail)
                       private       → owner + specific permissions (e.g. tutor CV)
                       restricted    → explicit policy check per request
                                       (e.g. student documents, minors' data, graded work)
```

Downloads go through `GET /files/{uuid}/download`:
`FilePolicy` → `DownloadAuthorizer` (for products: authenticated? purchased? payment
confirmed? entitlement active? download limit not exceeded? not expired?) → ledger row in
`digital_product_downloads` (timestamp, IP, user agent, count) → streamed response with
`Content-Disposition` from a **sanitized** filename. Rate-limited per user and per file
(`RateLimiter::attempt('downloads:'.$user->id, 30, perMinute: 5)`).

Protected files are **never** placed on a public disk and never referenced by a guessable
URL. UUIDs are v4; the route is signed for time-limited sharing where needed (§40, ADR-10).

### 5.5 Time and timezone (§62)

- Every `datetime` column storing an instant is **UTC** and named `*_at` / `*_at_utc`.
- Recurring patterns store **wall-clock local time + IANA timezone** (`course_schedules`,
  `tutor_availabilities`) because "every Tuesday 16:00 Lagos" must survive DST changes.
- `SessionGenerator` materializes occurrences into `course_sessions` with both
  `starts_at` (UTC) and `timezone` + `starts_at_local` (string), so the stored local time is
  authoritative for display and the UTC value authoritative for ordering/comparison.
- `SetUserTimezone` middleware applies `$user->timezone ?? setting('timezone')` to the
  request; `TimezonePresenter::forUser()` converts on output. Carbon is configured with
  `Date::use(CarbonImmutable::class)`.
- `SlotFinder` converts a parent's browser timezone → tutor timezone → UTC and back, and
  never compares naive datetimes.

### 5.6 Queues, scheduling, cache (§69, §70, §78)

| Concern | Shared-hosting default | Config-only upgrade |
|---------|------------------------|---------------------|
| Queue | `database`; cron runs `queue:work --stop-when-empty --max-time=50` every minute | `QUEUE_CONNECTION=redis` + Horizon |
| Scheduler | single cron: `* * * * * php artisan schedule:run` | same |
| Cache | `database` (Laravel 13 `cache` + `cache_locks`) | `CACHE_STORE=redis` |
| Sessions | `database` | `redis` |
| Broadcasting | none needed | Laravel 13 **Reverb database driver** |
| Filesystem | `local` (`storage/app`) | `s3`/S3-compatible — `files.disk` is per-file, so a migration can be gradual |

Everything queued is written to be **idempotent and retry-safe** (`tries`, `backoff`,
`uniqueId` for `ShouldBeUnique` jobs such as certificate rendering and payment verification),
because on shared hosting a cron-driven worker can be killed mid-job by `max-time`.

### 5.7 Error handling & observability (§76, §77)

- Domain exceptions extend `PlatformException` carrying a machine `code`, a user-facing
  message and an optional `reportable` flag. `bootstrap/app.php` maps them to flash +
  redirect (web) or JSON with a stable error shape (api).
- Branded error pages for 403/419/404/429/500/503; stack traces only when
  `APP_DEBUG=true`.
- Structured **channels** rather than one big log: `paystack`, `webhooks`, `integrations`,
  `audit`, `scheduler`, `auth-failures`. Each has its own daily file + level, so a host with
  limited disk can retain payment logs longer than debug noise.
- `IntegrationLog` rows persist request/response metadata (status, latency, redacted body)
  for Paystack/Zoom/Google calls — the single most valuable debugging artefact on a platform
  with three external providers.
- Failed auth attempts, permission denials and webhook signature failures are logged at
  `warning` with IP + user agent, and rate-limited.

### 5.8 Performance (§78)

- **Index-first schema** — every FK is indexed; hot paths get composite indexes
  (`course_enrollments(student_id, status)`, `attendance_records(session_id, student_id)`
  UNIQUE, `entitlements(beneficiary_student_id, status, ends_at)`).
- **MySQL FULLTEXT** indexes on `students`, `courses`, `products`, `tutor_profiles` behind a
  `SearchIndex` contract so §54 works without a search daemon and can be swapped for Scout.
- `QueryBuilder` support class standardizes search/sort/filter/date-range/pagination (§79)
  and always applies an authorization scope before filtering — never after.
- Eager loading declared in query objects; `Model::preventLazyLoading(!app()->isProduction())`
  in development so N+1s fail loudly in tests.
- Large tables paginated (never `all()`); reports and exports are **queued and chunked**
  (`chunkById`), CSV imports stream via `league/csv`.
- Expensive derived values (course progress %, tutor rating aggregate) are stored and
  recalculated by queued jobs/events, not computed per request.

### 5.9 Security (§57, §58)

| Control | Implementation |
|---------|----------------|
| CSRF | Laravel default on `web`; webhook routes are CSRF-exempt **and** HMAC-verified instead |
| Passwords | bcrypt via Fortify (`HASH strength` from config); optional 2FA TOTP for privileged roles; passkeys noted as a Laravel 13 future option |
| Session security | `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE=strict` for portals, `lax` for OAuth callback compatibility; idle timeout + single-device option for admins |
| Mass assignment | `$fillable` allowlists everywhere; `$guarded` on sensitive models (`User`, `Order`, `Payment`, `Entitlement`) |
| Input validation | Form Requests for every mutation; Livewire components validate through the same Form Request rules via a shared `Rules` class so web/API cannot diverge |
| SQL injection | Eloquent/Query Builder bindings only; raw expressions reviewed and parameterized; FULLTEXT via `whereRaw` with bound params |
| XSS | Blade `{{ }}` escaping; lesson rich-text sanitized server-side with an allowlist HTML purifier before storage, and again on render |
| File uploads | MIME + extension + size validation, hashed filenames, original name stored but never used as a path, SVG blocked or sanitized, executables rejected |
| Secrets | `.env` outside docroot; `connected_accounts` tokens stored with Laravel's `encrypted` cast; Paystack secret never rendered into a view or JS bundle |
| Headers | `SecurityHeaders` middleware: CSP, `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, HSTS when SSL |
| Rate limiting | login, password reset, webhook, downloads, search, booking-slot polling, certificate verification (public, unauthenticated → strict) |
| Minors' data | `restricted` file visibility, guardian-scoped policies, no public student endpoints, retention settings, consent records (§58) |

---

## 6. Testing architecture (§71, §72)

```
tests/
├── Unit/                     ← fast, no DB, no HTTP: the domain rules
│   ├── Commerce/MoneyTest, MinorPurchaseGuardTest, OrderTotalsCalculatorTest,
│   │            CouponValidatorTest, EntitlementGranterTest (with fakes)
│   ├── Assessment/GradeResolverTest, QuizScorerTest, PercentageTest
│   ├── Lms/ProgressCalculatorTest, CourseAccessResolverTest
│   ├── Tutoring/SlotFinderTest, BookingConflictDetectorTest, EarningsCalculatorTest,
│   │             ReviewEligibilityTest
│   └── Education/AcademicCalendarServiceTest, AgeCalculationTest
│
├── Feature/                  ← HTTP + DB (RefreshDatabase)
│   ├── Auth/  Registration, EmailVerification, PasswordReset, GoogleOAuth,
│   │          MicrosoftOAuth, TwoFactor
│   ├── Education/  StudentCrud, GuardianLinking, AdmissionsWorkflow,
│   │               ProgramEnrollment, CohortBulkEnroll, Attendance
│   ├── Lms/  CourseBuilder, Publishing, Enrollment, Progress, CertificateIssuance,
│   │         PublicVerification
│   ├── Assessment/  QuizAttempt, AssignmentSubmission, Grading, Results, Gradebook
│   ├── Tutoring/  ApplicationSubmission, Evaluation, InterviewScheduling,
│   │              Approval, Availability, Booking, SessionLifecycle, Reviews
│   ├── Commerce/  Cart, Checkout, Orders, Invoices, Refunds, Subscriptions,
│   │              Entitlements, DigitalDownloads
│   ├── Integration/  PaystackWebhook, PaystackVerification, ZoomMeetings,
│   │                 GoogleMeetMeetings, MeetingManagerFallback
│   └── Portals/  ParentDashboard, ChildSwitching, StudentDashboard, TutorDashboard,
│                 EvaluatorDashboard, AdminDashboard, Calendar, GlobalSearch
│
├── Authorization/            ← the tests spec §71 demands by name
│   ├── ParentCannotAccessOtherParentsChildTest
│   ├── StudentCannotAccessOtherStudentsRecordTest
│   ├── TutorCannotAccessOtherTutorsPrivateInformationTest
│   ├── EvaluatorCannotAccessFinancialDataTest
│   ├── MinorCannotSelfPurchaseTest
│   └── UnauthenticatedCannotAccessProtectedFileTest
│
└── Payment/                  ← spec §72, driven by recorded fixtures, never live keys
    ├── SuccessfulTransactionTest        (verify endpoint mocked)
    ├── FailedTransactionTest
    ├── DuplicateWebhookTest             (assert exactly one entitlement/payment)
    ├── InvalidSignatureWebhookTest      (assert 4xx + no state change + warning log)
    ├── AmountMismatchVerificationTest
    ├── OrderAlreadyPaidTest             (idempotent replay)
    ├── RefundTest
    ├── SubscriptionCancellationTest
    └── EntitlementCreationTest
```

**Payment testing rule:** all Paystack HTTP interactions are faked with
`Http::fake()` / a `FakePaystackGateway` bound in `IntegrationServiceProvider` when
`APP_ENV=testing`. Fixtures in `tests/Support/fixtures/paystack/*.json` are **recorded from
Paystack test-mode responses** and committed with keys redacted. No test ever reads
`PAYSTACK_SECRET_KEY` from a real environment; CI sets `sk_test_fake…` placeholders.

**Coverage targets by phase** (enforced in CI, ratcheted upward — never allowed to fall):

| Phase | Line coverage floor | Mandatory |
|-------|--------------------|-----------|
| 1 | 60% | all domain services introduced in the phase |
| 2–5 | 70% | + authorization tests for every new policy |
| 6–8 | 75% | + payment suite green |
| 9–11 | 80% | + every business rule in §90 has a named unit test |

---

## 7. API readiness (§68)

Not built in v1, but the shape is fixed now so it costs nothing later:

- Domain services and Actions accept **DTOs**, not `Request` objects. A controller and an
  API controller call the same Action.
- `app/Http/Resources/` holds API Resources from the start, used by Livewire components for
  consistent serialization where useful.
- `routes/api.php` exists with a versioned `/api/v1` group, `auth:sanctum`, `throttle:api`,
  `ForceJson` middleware, and a documented error envelope:
  ```json
  { "message": "…", "errors": { "field": ["…"] }, "code": "PURCHASE_RESTRICTED_FOR_MINOR" }
  ```
- `personal_access_tokens` is migrated in Phase 1 so token auth needs no migration later.
- No route or resource ever returns a raw Eloquent model.

---

## 8. Environments (§75)

| | `local` | `staging` | `production` |
|---|---|---|---|
| `APP_DEBUG` | true | true | **false** |
| `APP_ENV` | local | staging | production |
| `PAYSTACK_MODE` | test | test | **live** |
| `PAYSTACK_*_KEY` | test keys | test keys | live keys |
| Zoom/Google | sandbox/dev apps | dev apps | production apps |
| Mail | `log` driver | SMTP sandbox | SMTP production |
| Queue | `sync` (fast dev) or `database` | database | database + cron |
| Seed data | `DemoDataSeeder` allowed | demo allowed | **blocked** — `platform:seed-demo` refuses when `APP_ENV=production` unless `--i-know-this-is-production` |
| Demo logins | enabled | enabled | **disabled** — the demo-login route is registered only when `config('platform.demo_accounts.enabled')`, which is false unless `APP_ENV!=production` (§74) |

Payment mode is fully independent of `APP_ENV`, exactly as §75 requires: a production
deployment can run `PAYSTACK_MODE=test` during a soft launch.

---

## 9. How the ten spec deliverables map to this architecture

| Spec §102 deliverable | Where satisfied |
|----------------------|-----------------|
| 1. System architecture | this document |
| 2. Domain model | [02-domain-model.md](02-domain-model.md) |
| 3. Database ERD | [03-database-erd.md](03-database-erd.md) |
| 4. Table inventory | [04-database-table-inventory.md](04-database-table-inventory.md) |
| 5. Role/permission matrix | [05-role-permission-matrix.md](05-role-permission-matrix.md) |
| 6. Feature/module matrix | [06-feature-module-matrix.md](06-feature-module-matrix.md) |
| 7. User workflows | [07-user-workflows.md](07-user-workflows.md) |
| 8. Integrations | [08-integrations.md](08-integrations.md) |
| 9. Shared-hosting deployment | [09-shared-hosting-deployment.md](09-shared-hosting-deployment.md) |
| 10. Phased plan | [10-phased-implementation-plan.md](10-phased-implementation-plan.md) |
