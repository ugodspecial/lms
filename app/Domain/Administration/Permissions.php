<?php

declare(strict_types=1);

namespace App\Domain\Administration;

/**
 * The permission registry (§5, docs/05 §3, ADR-09).
 *
 * Code is the source of truth for *what exists*; the database is the source of
 * truth for *who has what*. PermissionSeeder syncs this list into the
 * `permissions` table idempotently, so adding a permission here and deploying is
 * enough — no SQL and no manual step — and a permission removed here stops being
 * grantable on the next seed.
 *
 * Authorization checks a PERMISSION, never a role string. Roles are convenience
 * bundles (see Roles) and `hasRole()` in a controller or component is a review
 * finding, because a role can be renamed or reassigned while a permission states
 * what is actually required (ADR-09).
 *
 * GENERATED FROM docs/05 §3. The counts are the check: PermissionsTest asserts
 * the totals and the group partition, so a change to that section which is not
 * reflected here fails the build rather than drifting quietly.
 */
final class Permissions
{
    private function __construct()
    {
        // A registry, not an object.
    }

    /** Identity & access — docs/05 §3.1 (14 permissions). */
    public const IDENTITY = [
        'users.view',
        'users.create',
        'users.update',
        'users.suspend',
        'users.deactivate',
        'users.impersonate',
        'roles.view',
        'roles.manage',
        'permissions.view',
        'user.roles.assign',
        'user.profile.update_own',
        'user.two_factor.manage_own',
        'user.connected_accounts.manage_own',
        'audit.view',
    ];

    /** Students — docs/05 §3.2 (14 permissions). */
    public const STUDENTS = [
        'students.view',
        'students.view_sensitive',
        'students.create',
        'students.update',
        'students.delete',
        'students.change_status',
        'students.assign_code',
        'students.view_documents',
        'students.upload_documents',
        'students.bulk_create',
        'students.bulk_update_status',
        'students.export',
        'students.import',
        'student.portal.access',
    ];

    /** Parents & guardians — docs/05 §3.3 (11 permissions). */
    public const PARENTS = [
        'parents.view',
        'parents.create',
        'parents.update',
        'parents.delete',
        'parents.manage',
        'parents.link_student',
        'parents.unlink_student',
        'parents.set_capabilities',
        'parents.invite',
        'parents.view_financials',
        'parent.portal.access',
    ];

    /** Admissions — docs/05 §3.4 (8 permissions). */
    public const ADMISSIONS = [
        'applications.view',
        'applications.create',
        'applications.update',
        'applications.assign_reviewer',
        'applications.review',
        'applications.decide',
        'applications.convert_to_student',
        'applications.withdraw',
    ];

    /** Academic structure — docs/05 §3.5 (22 permissions). */
    public const ACADEMIC = [
        'academic_years.view',
        'academic_years.manage',
        'academic_years.activate',
        'academic_terms.view',
        'academic_terms.manage',
        'academic_levels.manage',
        'programs.view',
        'programs.create',
        'programs.update',
        'programs.delete',
        'programs.publish',
        'programs.learning_path.manage',
        'subjects.view',
        'subjects.manage',
        'enrollments.view',
        'enrollments.create',
        'enrollments.update',
        'enrollments.withdraw',
        'enrollments.bulk_create',
        'cohorts.view',
        'cohorts.manage',
        'cohorts.bulk_enroll',
    ];

    /** Scheduling & attendance — docs/05 §3.6 (13 permissions). */
    public const SCHEDULING = [
        'schedules.view',
        'schedules.manage',
        'sessions.generate',
        'sessions.view',
        'sessions.update',
        'sessions.cancel',
        'sessions.record_summary',
        'attendance.view',
        'attendance.record',
        'attendance.bulk_record',
        'attendance.correct',
        'attendance.export',
        'holidays.manage',
    ];

    /** LMS content — docs/05 §3.7 (18 permissions). */
    public const LMS = [
        'courses.view',
        'courses.create',
        'courses.update',
        'courses.delete',
        'courses.publish',
        'courses.submit_for_review',
        'course_modules.manage',
        'lessons.manage',
        'lesson_resources.manage',
        'courses.enroll_students',
        'progress.view',
        'progress.recalculate',
        'discussions.view',
        'discussions.moderate',
        'discussions.post',
        'announcements.view',
        'announcements.create',
        'announcements.bulk_send',
    ];

    /** Assessment, grading & certificates — docs/05 §3.8 (21 permissions). */
    public const ASSESSMENT = [
        'assessments.view',
        'assessments.create',
        'assessments.update',
        'assessments.delete',
        'assessments.publish',
        'questions.manage',
        'submissions.view',
        'submissions.grade',
        'submissions.return',
        'submissions.reopen',
        'grading_schemes.view',
        'grading_schemes.manage',
        'grades.view',
        'grades.publish',
        'grades.amend',
        'academic_results.generate',
        'report_cards.generate',
        'certificates.view',
        'certificates.issue',
        'certificates.revoke',
        'certificates.verify_public',
    ];

    /** Tutor recruitment & evaluation — docs/05 §3.9 (16 permissions). */
    public const RECRUITMENT = [
        'tutors.applications.view',
        'tutors.applications.create',
        'tutors.applications.update',
        'tutors.applications.assign',
        'tutors.applications.review',
        'tutors.applications.shortlist',
        'tutors.applications.withdraw',
        'tutors.interviews.view',
        'tutors.interviews.schedule',
        'tutors.interviews.manage',
        'tutors.evaluations.submit',
        'tutors.evaluations.view',
        'tutors.approve',
        'tutors.reject',
        'evaluation_forms.manage',
        'evaluator.portal.access',
    ];

    /** Tutor operations — docs/05 §3.10 (15 permissions). */
    public const TUTORING = [
        'tutor.portal.access',
        'tutors.view',
        'tutors.manage',
        'tutors.suspend',
        'tutor.profile.update_own',
        'tutor.availability.manage_own',
        'tutor.availability.view',
        'tutoring_services.view',
        'tutoring_services.manage',
        'tutoring_packages.manage',
        'bookings.view',
        'bookings.create',
        'bookings.cancel',
        'sessions.tutoring.manage',
        'session_notes.manage',
    ];

    /** Reviews — docs/05 §3.11 (6 permissions). */
    public const REVIEWS = [
        'reviews.view',
        'reviews.create',
        'reviews.respond',
        'reviews.moderate',
        'reviews.hide',
        'reviews.delete',
    ];

    /** Commerce & finance — docs/05 §3.12 (26 permissions). */
    public const COMMERCE = [
        'products.view',
        'products.create',
        'products.update',
        'products.publish',
        'products.delete',
        'product_categories.manage',
        'digital_products.manage',
        'orders.view',
        'orders.create',
        'orders.update',
        'orders.cancel',
        'orders.admin_assisted_checkout',
        'commerce.override_minor_restriction',
        'payments.view',
        'payments.verify',
        'payments.refund',
        'payments.record_manual',
        'invoices.view',
        'invoices.manage',
        'invoices.void',
        'coupons.manage',
        'tax_rates.manage',
        'subscriptions.view',
        'subscriptions.manage',
        'entitlements.view',
        'entitlements.grant_manual',
    ];

    /** Reports & analytics — docs/05 §3.13 (12 permissions). */
    public const REPORTS = [
        'reports.view',
        'reports.students',
        'reports.academic_performance',
        'reports.attendance',
        'reports.at_risk_students',
        'reports.tutors',
        'reports.finance',
        'reports.revenue',
        'reports.enrollment',
        'reports.lms_analytics',
        'reports.export',
        'reports.build_scheduled',
    ];

    /** Settings, files, imports & platform — docs/05 §3.14 (18 permissions). */
    public const PLATFORM = [
        'settings.view',
        'settings.manage',
        'settings.manage.security',
        'settings.manage.payments',
        'settings.manage.integrations',
        'files.upload',
        'files.view_restricted',
        'files.delete',
        'imports.run',
        'imports.view',
        'exports.run',
        'pages.manage',
        'faqs.manage',
        'message_templates.manage',
        'platform.doctor',
        'platform.maintenance_mode',
        'integration_logs.view',
        'admin.panel.access',
    ];

    /** Every group, keyed by the slug used in the admin UI, settings and audit tags. */
    public const GROUPS = [
        'identity' => ['label' => 'Identity & access', 'permissions' => self::IDENTITY],
        'students' => ['label' => 'Students', 'permissions' => self::STUDENTS],
        'parents' => ['label' => 'Parents & guardians', 'permissions' => self::PARENTS],
        'admissions' => ['label' => 'Admissions', 'permissions' => self::ADMISSIONS],
        'academic' => ['label' => 'Academic structure', 'permissions' => self::ACADEMIC],
        'scheduling' => ['label' => 'Scheduling & attendance', 'permissions' => self::SCHEDULING],
        'lms' => ['label' => 'LMS content', 'permissions' => self::LMS],
        'assessment' => ['label' => 'Assessment, grading & certificates', 'permissions' => self::ASSESSMENT],
        'recruitment' => ['label' => 'Tutor recruitment & evaluation', 'permissions' => self::RECRUITMENT],
        'tutoring' => ['label' => 'Tutor operations', 'permissions' => self::TUTORING],
        'reviews' => ['label' => 'Reviews', 'permissions' => self::REVIEWS],
        'commerce' => ['label' => 'Commerce & finance', 'permissions' => self::COMMERCE],
        'reports' => ['label' => 'Reports & analytics', 'permissions' => self::REPORTS],
        'platform' => ['label' => 'Settings, files, imports & platform', 'permissions' => self::PLATFORM],
    ];

    /**
     * Permissions that are granted but are NOT sufficient on their own: the
     * policy must add an ownership or relationship check before allowing the
     * action.
     *
     * Keyed permission => role => the qualifier docs/05 records against that
     * cell. The role matters, because the same permission means different things
     * to different people: `students.view` is "self" to a Student, "own
     * children" to a Parent, "scoped to own cohorts" to a Tutor and "read-only,
     * no DOB/notes" to a Finance Officer. One note per permission would have
     * kept whichever role the table happened to list last.
     *
     * Instructor is absent by design: it has the Tutor permission shape
     * (docs/05 §2), so Tutor's qualifiers apply to it too.
     *
     * A permission listed here whose policy has no matching scope check is an
     * authorization defect — the thing `platform:audit-authorization` looks for
     * in Phase 11.
     *
     * @var array<string, array<string, string>>
     */
    public const SCOPED = [
        'students.view' => [
            'Finance Officer' => 'read-only, no DOB/notes',
            'Tutor' => 'scoped to own cohorts',
            'Parent' => 'own children',
            'Student' => 'self',
        ],
        'students.view_sensitive' => [
            'Parent' => 'own children',
        ],
        'students.create' => [
            'Parent' => 'create applicant child',
        ],
        'students.update' => [
            'Parent' => 'limited fields on own children',
        ],
        'students.view_documents' => [
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'students.upload_documents' => [
            'Parent' => 'own children',
        ],
        'parents.view' => [
            'Finance Officer' => 'billing only',
            'Parent' => 'self',
        ],
        'parents.update' => [
            'Parent' => 'self',
        ],
        'parents.link_student' => [
            'Parent' => 'invite own child (pending admin confirmation)',
        ],
        'parents.view_financials' => [
            'Parent' => 'own orders only',
        ],
        'applications.view' => [
            'Applicant' => 'own',
        ],
        'applications.update' => [
            'Applicant' => 'own, while draft',
        ],
        'applications.withdraw' => [
            'Applicant' => 'own',
        ],
        'enrollments.view' => [
            'Tutor' => 'own cohorts',
        ],
        'cohorts.view' => [
            'Tutor' => 'own',
        ],
        'schedules.view' => [
            'Tutor' => 'own',
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'sessions.view' => [
            'Tutor' => 'own',
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'sessions.update' => [
            'Tutor' => 'own sessions',
        ],
        'sessions.cancel' => [
            'Tutor' => 'own sessions',
        ],
        'sessions.record_summary' => [
            'Tutor' => 'own sessions',
        ],
        'attendance.view' => [
            'Tutor' => 'own sessions',
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'attendance.record' => [
            'Tutor' => 'own sessions',
        ],
        'attendance.bulk_record' => [
            'Tutor' => 'own sessions',
        ],
        'courses.view' => [
            'Tutor' => 'own + published',
            'Student' => 'enrolled only',
        ],
        'courses.create' => [
            'Tutor' => 'if `tutor.authoring` enabled',
        ],
        'courses.update' => [
            'Tutor' => 'own',
        ],
        'course_modules.manage' => [
            'Tutor' => 'own courses',
        ],
        'lessons.manage' => [
            'Tutor' => 'own courses',
        ],
        'lesson_resources.manage' => [
            'Tutor' => 'own courses',
        ],
        'progress.view' => [
            'Tutor' => 'own students',
            'Student' => 'own',
        ],
        'discussions.view' => [
            'Student' => 'enrolled courses',
        ],
        'discussions.moderate' => [
            'Tutor' => 'own courses',
        ],
        'discussions.post' => [
            'Student' => 'enrolled courses',
        ],
        'announcements.create' => [
            'Tutor' => 'own cohorts',
        ],
        'assessments.view' => [
            'Tutor' => 'own',
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'assessments.create' => [
            'Tutor' => 'own courses/cohorts',
        ],
        'assessments.update' => [
            'Tutor' => 'own',
        ],
        'assessments.publish' => [
            'Tutor' => 'own',
        ],
        'questions.manage' => [
            'Tutor' => 'own subjects',
        ],
        'submissions.view' => [
            'Tutor' => 'own assessments',
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'submissions.grade' => [
            'Tutor' => 'assigned assessor',
        ],
        'submissions.return' => [
            'Tutor' => 'assigned assessor',
        ],
        'grades.view' => [
            'Operations Officer' => 'aggregate only',
            'Tutor' => 'own students',
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'report_cards.generate' => [
            'Parent' => 'own children',
        ],
        'certificates.view' => [
            'Tutor' => 'own students',
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'tutors.applications.view' => [
            'Academic Admin' => 'summary only',
            'Evaluator' => 'assigned only',
        ],
        'tutors.applications.create' => [
            'Tutor' => 'own draft',
        ],
        'tutors.applications.update' => [
            'Academic Admin' => 'own',
            'Tutor' => 'own draft',
        ],
        'tutors.applications.withdraw' => [
            'Tutor' => 'own',
        ],
        'tutors.interviews.view' => [
            'Evaluator' => 'own',
            'Tutor' => 'own',
        ],
        'tutors.interviews.manage' => [
            'Evaluator' => 'own',
        ],
        'tutors.evaluations.view' => [
            'Evaluator' => 'own',
            'Tutor' => 'own',
        ],
        'tutors.view' => [
            'Tutor' => 'self + public directory',
            'Parent' => 'own children\'s tutors',
            'Student' => 'own tutors',
        ],
        'tutor.availability.view' => [
            'Tutor' => 'self',
            'Parent' => 'for booking own children',
        ],
        'tutoring_services.view' => [
            'Tutor' => 'own',
            'Parent' => 'published',
            'Student' => 'published',
        ],
        'tutoring_services.manage' => [
            'Tutor' => 'own services',
        ],
        'tutoring_packages.manage' => [
            'Tutor' => 'own services',
        ],
        'bookings.view' => [
            'Tutor' => 'own',
            'Parent' => 'own + own children',
            'Student' => 'own',
        ],
        'bookings.create' => [
            'Parent' => 'for linked children',
            'Student' => 'adults only (§10)',
        ],
        'bookings.cancel' => [
            'Tutor' => 'own',
            'Parent' => 'own',
            'Student' => 'own',
        ],
        'sessions.tutoring.manage' => [
            'Tutor' => 'own',
        ],
        'session_notes.manage' => [
            'Tutor' => 'own sessions',
            'Parent' => 'read own children',
            'Student' => 'read own',
        ],
        'reviews.view' => [
            'Tutor' => 'own',
            'Parent' => 'published',
            'Student' => 'published',
        ],
        'reviews.create' => [
            'Parent' => 'after a completed session',
            'Student' => 'after a completed session',
        ],
        'reviews.respond' => [
            'Tutor' => 'own',
        ],
        'products.view' => [
            'Parent' => 'published',
            'Student' => 'published',
        ],
        'products.create' => [
            'Finance Officer' => 'digital only',
        ],
        'products.update' => [
            'Finance Officer' => 'digital only',
        ],
        'orders.view' => [
            'Parent' => 'own',
            'Student' => 'own',
        ],
        'orders.create' => [
            'Student' => 'adults only',
        ],
        'orders.cancel' => [
            'Parent' => 'own, unpaid',
            'Student' => 'own, unpaid',
        ],
        'payments.view' => [
            'Operations Officer' => 'status only',
            'Parent' => 'own',
            'Student' => 'own',
        ],
        'invoices.view' => [
            'Parent' => 'own',
            'Student' => 'own',
        ],
        'subscriptions.view' => [
            'Parent' => 'own',
            'Student' => 'own',
        ],
        'subscriptions.manage' => [
            'Parent' => 'cancel own',
            'Student' => 'cancel own',
        ],
        'entitlements.view' => [
            'Parent' => 'own',
            'Student' => 'own',
        ],
        'reports.view' => [
            'Academic Admin' => 'education',
            'Operations Officer' => 'operations',
        ],
        'reports.students' => [
            'Tutor' => 'own students',
        ],
        'reports.academic_performance' => [
            'Tutor' => 'own cohorts',
            'Student' => 'own',
        ],
        'reports.attendance' => [
            'Tutor' => 'own sessions',
            'Parent' => 'own children',
            'Student' => 'own',
        ],
        'reports.tutors' => [
            'Tutor' => 'self',
        ],
        'reports.revenue' => [
            'Tutor' => 'own earnings',
        ],
        'reports.lms_analytics' => [
            'Tutor' => 'own courses',
        ],
        'reports.export' => [
            'Academic Admin' => 'scoped',
            'Finance Officer' => 'scoped',
            'Operations Officer' => 'scoped',
        ],
        'settings.view' => [
            'Academic Admin' => 'academic',
            'Finance Officer' => 'commerce',
            'Operations Officer' => 'operations',
        ],
        'settings.manage' => [
            'Academic Admin' => 'academic',
            'Finance Officer' => 'commerce',
            'Operations Officer' => 'operations',
        ],
        'settings.manage.payments' => [
            'Finance Officer' => 'read + test-mode only',
        ],
        'files.view_restricted' => [
            'Academic Admin' => 'scoped',
            'Finance Officer' => 'scoped',
        ],
        'files.delete' => [
            'Academic Admin' => 'own uploads',
            'Finance Officer' => 'own uploads',
            'Operations Officer' => 'own uploads',
            'Tutor' => 'own uploads',
            'Parent' => 'own uploads',
            'Student' => 'own uploads',
        ],
        'imports.run' => [
            'Finance Officer' => 'products',
        ],
        'exports.run' => [
            'Academic Admin' => 'scoped',
            'Finance Officer' => 'scoped',
            'Operations Officer' => 'scoped',
        ],
        'message_templates.manage' => [
            'Finance Officer' => 'commerce templates',
        ],
        'integration_logs.view' => [
            'Finance Officer' => 'paystack only',
        ],
    ];

    /**
     * Permissions a role is deliberately NOT granted, where docs/05 records why.
     *
     * These are the denials that must not be "fixed" by someone who finds the
     * gap surprising. Each one cites the section that requires it: an Evaluator
     * cannot approve a tutor (§28) or see financial data (§49), and nobody can
     * review themselves (§88). A missing grant with no reason is an oversight; a
     * missing grant with a reason is a rule.
     *
     * @var array<string, array<string, string>>
     */
    public const DENIED = [
        'tutors.approve' => [
            'Evaluator' => '§28: evaluators cannot approve',
        ],
        'reviews.create' => [
            'Tutor' => 'cannot review self (§88)',
        ],
        'payments.view' => [
            'Evaluator' => '§49',
        ],
        'reports.finance' => [
            'Evaluator' => '§49',
        ],
    ];

    /**
     * Capabilities that no role holds, because they are not reached by logging
     * in.
     *
     * docs/05 marks these `*public*` rather than granting them to a role, and
     * §4 is explicit that public certificate verification is "a separate
     * unauthenticated controller with a rate limit". Registering the name keeps
     * the registry complete and the audit trail able to reference the capability,
     * while granting it to nobody keeps it out of every role bundle. Its
     * protection is the rate limiter and the unguessability of the certificate
     * code — not a permission check, which an anonymous visitor could never
     * pass.
     *
     * @var list<string>
     */
    public const PUBLIC_CAPABILITIES = [
        'certificates.verify_public',
    ];

    /**
     * Every permission on the platform, in registry order.
     *
     * A method rather than an `ALL` constant for two reasons. A constant
     * expression cannot spread an array, so a constant would have to list all 214
     * strings a second time — and two copies of the same list drift, which is
     * exactly the failure this registry exists to prevent. Merging the group
     * constants keeps one copy.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        // Memoized: this is on the authorization path, and merging fourteen
        // arrays on every `has()` call is work that never changes.
        /** @var list<string>|null $all */
        static $all = null;

        // array_values() is not decoration: it is what makes the result a list
        // rather than an int-keyed array, which is what the @return promises.
        return $all ??= array_values(array_merge(
            self::IDENTITY,
            self::STUDENTS,
            self::PARENTS,
            self::ADMISSIONS,
            self::ACADEMIC,
            self::SCHEDULING,
            self::LMS,
            self::ASSESSMENT,
            self::RECRUITMENT,
            self::TUTORING,
            self::REVIEWS,
            self::COMMERCE,
            self::REPORTS,
            self::PLATFORM,
        ));
    }

    public static function has(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /** The slug of the group a permission belongs to, or null if it is unregistered. */
    public static function groupOf(string $permission): ?string
    {
        foreach (self::GROUPS as $slug => $group) {
            if (in_array($permission, $group['permissions'], true)) {
                return $slug;
            }
        }

        return null;
    }
}
