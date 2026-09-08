<?php

declare(strict_types=1);

namespace App\Domain\Administration;

/**
 * The eleven seeded roles (docs/05 §2) and which permissions each carries.
 *
 * Roles are CONVENIENCE BUNDLES. Nothing in the authorization path inspects a
 * role name: a policy asks for a permission, and `Gate::before` grants
 * everything to Super Admin alone (ADR-09). That is what makes `hasRole()` in a
 * controller a review finding — a role can be renamed, reassigned, or granted to
 * the wrong person, while a permission check states the actual requirement.
 *
 * A user may hold several roles at once (a Tutor who is also a Parent). Portal
 * switching is driven by which `*.portal.access` permissions the user ends up
 * holding, not by a "primary role" field, because there is no such thing.
 *
 * GENERATED FROM the docs/05 §3 matrix, in the same pass as Permissions, so the
 * two cannot disagree. ✅, 🔒 (granted but policy-scoped) and 👁 (granted with a
 * narrowed column set) all count as granted; ⬚ does not; `*public*` is not a
 * role grant at all and lands in Permissions::PUBLIC_CAPABILITIES instead.
 */
final class Roles
{
    private function __construct()
    {
        // A registry, not an object.
    }

    /** Super Admin. Full bypass via Gate::before. Cannot be self-assigned; assignment is audited and restricted to existing Super Admins. */
    public const SUPER_ADMIN = 'Super Admin';

    /** Administrator. Everything except settings.manage.security, roles.manage and the core-entity deletes, which stay with Super Admin. */
    public const ADMINISTRATOR = 'Administrator';

    /** Academic Admin. Education, LMS and Assessment. No finance, no tutor approval. docs/05 also calls this role Registrar. */
    public const ACADEMIC_ADMIN = 'Academic Admin';

    /** Finance Officer. Commerce, payments, refunds, invoices and financial reports. Read-only on academic data. */
    public const FINANCE_OFFICER = 'Finance Officer';

    /** Operations Officer. Cohorts, scheduling, attendance, announcements and imports. No grading, no refunds. */
    public const OPERATIONS_OFFICER = 'Operations Officer';

    /** Evaluator. Tutor applications, interviews and evaluations. No financial data (§49) and no approval authority (§28). */
    public const EVALUATOR = 'Evaluator';

    /** Tutor. Own sessions, cohorts, students, availability and earnings. Cannot see other tutors' private data (§71). docs/05 §3.9 also uses this column for a tutor CANDIDATE working on their own draft application. */
    public const TUTOR = 'Tutor';

    /** Parent. Only their linked children (§58). docs/05 also calls this role Guardian. */
    public const PARENT = 'Parent';

    /** Student. Only their own records (§71). */
    public const STUDENT = 'Student';

    /** Applicant. Self-registered, not yet admitted. Can submit and track an application only. */
    public const APPLICANT = 'Applicant';

    /**
     * Staff teachers who were not recruited through the tutor pipeline. Same
     * permission shape as Tutor — docs/05 §2 — but assigned by an administrator
     * rather than earned through application, interview and evaluation.
     */
    public const INSTRUCTOR = 'Instructor';

    /** Every role, in the order the admin UI lists them. */
    public const ALL = [
        self::SUPER_ADMIN,
        self::ADMINISTRATOR,
        self::ACADEMIC_ADMIN,
        self::FINANCE_OFFICER,
        self::OPERATIONS_OFFICER,
        self::EVALUATOR,
        self::TUTOR,
        self::PARENT,
        self::STUDENT,
        self::APPLICANT,
        self::INSTRUCTOR,
    ];

    /**
     * The two-letter codes docs/05 uses as matrix columns.
     *
     * @var array<string, string>
     */
    public const CODES = [
        'SA' => self::SUPER_ADMIN,
        'AD' => self::ADMINISTRATOR,
        'AC' => self::ACADEMIC_ADMIN,
        'FO' => self::FINANCE_OFFICER,
        'OO' => self::OPERATIONS_OFFICER,
        'EV' => self::EVALUATOR,
        'TU' => self::TUTOR,
        'PA' => self::PARENT,
        'ST' => self::STUDENT,
        'AP' => self::APPLICANT,
    ];

    /**
     * Which portal each role belongs to, for AuthenticateArea and for the portal
     * switcher. `public` means the role has no portal of its own: an Applicant
     * uses the public site plus the application tracker.
     *
     * @var array<string, string>
     */
    public const PORTALS = [
        self::SUPER_ADMIN => 'admin',
        self::ADMINISTRATOR => 'admin',
        self::ACADEMIC_ADMIN => 'admin',
        self::FINANCE_OFFICER => 'admin',
        self::OPERATIONS_OFFICER => 'admin',
        self::EVALUATOR => 'evaluator',
        self::TUTOR => 'tutor',
        self::PARENT => 'parent',
        self::STUDENT => 'student',
        self::APPLICANT => 'public',
        self::INSTRUCTOR => 'tutor',
    ];

    /**
     * Permissions that `Gate::before` must NOT satisfy for a Super Admin.
     *
     * ADR-09 gives Super Admin a full bypass so a new permission is usable by an
     * administrator the moment it is deployed, with no data migration. But two
     * permissions in the registry are not administrative powers at all: they
     * record that somebody took part in something. docs/05 §3.11 marks
     * `reviews.create` and `reviews.respond` as ⬚ for both Super Admin and
     * Administrator, because a review is written by a parent or student who
     * attended a session and answered by the tutor who was reviewed. An
     * administrator who sat in neither seat has no review to write, and a bypass
     * that let them write one would put a fabricated testimonial in front of
     * other parents — the harm §88's "cannot review self" exists to prevent.
     *
     * So the bypass is total for administration and stops at participation. The
     * review policy still enforces §88 itself; this list only stops the bypass
     * from short-circuiting it.
     *
     * @var list<string>
     */
    public const PARTICIPANT_ONLY = [
        'reviews.create',
        'reviews.respond',
    ];

    /**
     * The permission matrix: permission name => the role codes granted it.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        // Identity & access
        'users.view' => ['SA', 'AD'],
        'users.create' => ['SA', 'AD'],
        'users.update' => ['SA', 'AD'],
        'users.suspend' => ['SA', 'AD'],
        'users.deactivate' => ['SA'],
        'users.impersonate' => ['SA'],
        'roles.view' => ['SA', 'AD'],
        'roles.manage' => ['SA'],
        'permissions.view' => ['SA', 'AD'],
        'user.roles.assign' => ['SA', 'AD'],
        'user.profile.update_own' => ['SA', 'AD', 'AC', 'FO', 'OO', 'EV', 'TU', 'PA', 'ST', 'AP'],
        'user.two_factor.manage_own' => ['SA', 'AD', 'AC', 'FO', 'OO', 'EV', 'TU', 'PA', 'ST', 'AP'],
        'user.connected_accounts.manage_own' => ['SA', 'AD', 'AC', 'FO', 'OO', 'EV', 'TU', 'PA', 'ST', 'AP'],
        'audit.view' => ['SA', 'AD'],
        // Students
        'students.view' => ['SA', 'AD', 'AC', 'FO', 'OO', 'TU', 'PA', 'ST'],
        'students.view_sensitive' => ['SA', 'AD', 'AC', 'PA'],
        'students.create' => ['SA', 'AD', 'AC', 'OO', 'PA'],
        'students.update' => ['SA', 'AD', 'AC', 'OO', 'PA'],
        'students.delete' => ['SA'],
        'students.change_status' => ['SA', 'AD', 'AC', 'OO'],
        'students.assign_code' => ['SA', 'AD', 'AC'],
        'students.view_documents' => ['SA', 'AD', 'AC', 'PA', 'ST'],
        'students.upload_documents' => ['SA', 'AD', 'AC', 'OO', 'PA'],
        'students.bulk_create' => ['SA', 'AD', 'AC'],
        'students.bulk_update_status' => ['SA', 'AD', 'AC', 'OO'],
        'students.export' => ['SA', 'AD', 'AC'],
        'students.import' => ['SA', 'AD', 'AC', 'OO'],
        'student.portal.access' => ['SA', 'AD', 'AC', 'FO', 'OO', 'EV', 'TU', 'ST'],
        // Parents & guardians
        'parents.view' => ['SA', 'AD', 'AC', 'FO', 'OO', 'PA'],
        'parents.create' => ['SA', 'AD', 'AC', 'OO'],
        'parents.update' => ['SA', 'AD', 'AC', 'OO', 'PA'],
        'parents.delete' => ['SA'],
        'parents.manage' => ['SA', 'AD', 'AC'],
        'parents.link_student' => ['SA', 'AD', 'AC', 'OO', 'PA'],
        'parents.unlink_student' => ['SA', 'AD', 'AC'],
        'parents.set_capabilities' => ['SA', 'AD', 'AC'],
        'parents.invite' => ['SA', 'AD', 'AC', 'OO'],
        'parents.view_financials' => ['SA', 'AD', 'FO', 'PA'],
        'parent.portal.access' => ['SA', 'AD', 'AC', 'FO', 'OO', 'PA'],
        // Admissions
        'applications.view' => ['SA', 'AD', 'AC', 'OO', 'AP'],
        'applications.create' => ['SA', 'AD', 'AC', 'OO', 'AP'],
        'applications.update' => ['SA', 'AD', 'AC', 'AP'],
        'applications.assign_reviewer' => ['SA', 'AD', 'AC'],
        'applications.review' => ['SA', 'AD', 'AC'],
        'applications.decide' => ['SA', 'AD', 'AC'],
        'applications.convert_to_student' => ['SA', 'AD', 'AC'],
        'applications.withdraw' => ['SA', 'AD', 'AC', 'AP'],
        // Academic structure
        'academic_years.view' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'academic_years.manage' => ['SA', 'AD', 'AC'],
        'academic_years.activate' => ['SA', 'AD'],
        'academic_terms.view' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'academic_terms.manage' => ['SA', 'AD', 'AC'],
        'academic_levels.manage' => ['SA', 'AD', 'AC'],
        'programs.view' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'programs.create' => ['SA', 'AD', 'AC'],
        'programs.update' => ['SA', 'AD', 'AC'],
        'programs.delete' => ['SA'],
        'programs.publish' => ['SA', 'AD', 'AC'],
        'programs.learning_path.manage' => ['SA', 'AD', 'AC'],
        'subjects.view' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'subjects.manage' => ['SA', 'AD', 'AC'],
        'enrollments.view' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'enrollments.create' => ['SA', 'AD', 'AC', 'OO'],
        'enrollments.update' => ['SA', 'AD', 'AC', 'OO'],
        'enrollments.withdraw' => ['SA', 'AD', 'AC'],
        'enrollments.bulk_create' => ['SA', 'AD', 'AC', 'OO'],
        'cohorts.view' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'cohorts.manage' => ['SA', 'AD', 'AC', 'OO'],
        'cohorts.bulk_enroll' => ['SA', 'AD', 'AC', 'OO'],
        // Scheduling & attendance
        'schedules.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        'schedules.manage' => ['SA', 'AD', 'AC', 'OO'],
        'sessions.generate' => ['SA', 'AD', 'AC', 'OO'],
        'sessions.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        'sessions.update' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'sessions.cancel' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'sessions.record_summary' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'attendance.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        'attendance.record' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'attendance.bulk_record' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'attendance.correct' => ['SA', 'AD', 'AC'],
        'attendance.export' => ['SA', 'AD', 'AC', 'OO'],
        'holidays.manage' => ['SA', 'AD', 'AC', 'OO'],
        // LMS content
        'courses.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'ST'],
        'courses.create' => ['SA', 'AD', 'AC', 'TU'],
        'courses.update' => ['SA', 'AD', 'AC', 'TU'],
        'courses.delete' => ['SA'],
        'courses.publish' => ['SA', 'AD', 'AC'],
        'courses.submit_for_review' => ['SA', 'AD', 'AC', 'TU'],
        'course_modules.manage' => ['SA', 'AD', 'AC', 'TU'],
        'lessons.manage' => ['SA', 'AD', 'AC', 'TU'],
        'lesson_resources.manage' => ['SA', 'AD', 'AC', 'TU'],
        'courses.enroll_students' => ['SA', 'AD', 'AC', 'OO'],
        'progress.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'ST'],
        'progress.recalculate' => ['SA', 'AD', 'AC'],
        'discussions.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'ST'],
        'discussions.moderate' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'discussions.post' => ['SA', 'AD', 'AC', 'OO', 'TU', 'ST'],
        'announcements.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'ST'],
        'announcements.create' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'announcements.bulk_send' => ['SA', 'AD', 'AC', 'OO'],
        // Assessment, grading & certificates
        'assessments.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'ST', 'PA'],
        'assessments.create' => ['SA', 'AD', 'AC', 'TU'],
        'assessments.update' => ['SA', 'AD', 'AC', 'TU'],
        'assessments.delete' => ['SA', 'AD'],
        'assessments.publish' => ['SA', 'AD', 'AC', 'TU'],
        'questions.manage' => ['SA', 'AD', 'AC', 'TU'],
        'submissions.view' => ['SA', 'AD', 'AC', 'TU', 'ST', 'PA'],
        'submissions.grade' => ['SA', 'AD', 'AC', 'TU'],
        'submissions.return' => ['SA', 'AD', 'AC', 'TU'],
        'submissions.reopen' => ['SA', 'AD', 'AC'],
        'grading_schemes.view' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'grading_schemes.manage' => ['SA', 'AD', 'AC'],
        'grades.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'ST', 'PA'],
        'grades.publish' => ['SA', 'AD', 'AC'],
        'grades.amend' => ['SA', 'AD', 'AC'],
        'academic_results.generate' => ['SA', 'AD', 'AC'],
        'report_cards.generate' => ['SA', 'AD', 'AC', 'PA'],
        'certificates.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'ST', 'PA'],
        'certificates.issue' => ['SA', 'AD', 'AC'],
        'certificates.revoke' => ['SA', 'AD'],
        'certificates.verify_public' => [],
        // Tutor recruitment & evaluation
        'tutors.applications.view' => ['SA', 'AD', 'AC', 'EV'],
        'tutors.applications.create' => ['SA', 'AD', 'AC', 'TU'],
        'tutors.applications.update' => ['SA', 'AD', 'AC', 'TU'],
        'tutors.applications.assign' => ['SA', 'AD', 'AC'],
        'tutors.applications.review' => ['SA', 'AD', 'AC', 'EV'],
        'tutors.applications.shortlist' => ['SA', 'AD', 'AC'],
        'tutors.applications.withdraw' => ['SA', 'AD', 'TU'],
        'tutors.interviews.view' => ['SA', 'AD', 'AC', 'EV', 'TU'],
        'tutors.interviews.schedule' => ['SA', 'AD', 'AC', 'EV'],
        'tutors.interviews.manage' => ['SA', 'AD', 'AC', 'EV'],
        'tutors.evaluations.submit' => ['SA', 'AD', 'AC', 'EV'],
        'tutors.evaluations.view' => ['SA', 'AD', 'AC', 'EV', 'TU'],
        'tutors.approve' => ['SA', 'AD'],
        'tutors.reject' => ['SA', 'AD'],
        'evaluation_forms.manage' => ['SA', 'AD', 'AC'],
        'evaluator.portal.access' => ['SA', 'AD', 'AC', 'EV'],
        // Tutor operations
        'tutor.portal.access' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'tutors.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        'tutors.manage' => ['SA', 'AD', 'AC', 'OO'],
        'tutors.suspend' => ['SA', 'AD'],
        'tutor.profile.update_own' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'tutor.availability.manage_own' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'tutor.availability.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA'],
        'tutoring_services.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        'tutoring_services.manage' => ['SA', 'AD', 'AC', 'TU'],
        'tutoring_packages.manage' => ['SA', 'AD', 'AC', 'TU'],
        'bookings.view' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        'bookings.create' => ['SA', 'AD', 'AC', 'OO', 'PA', 'ST'],
        'bookings.cancel' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        'sessions.tutoring.manage' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'session_notes.manage' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        // Reviews
        'reviews.view' => ['SA', 'AD', 'TU', 'PA', 'ST'],
        'reviews.create' => ['PA', 'ST'],
        'reviews.respond' => ['TU'],
        'reviews.moderate' => ['SA', 'AD'],
        'reviews.hide' => ['SA', 'AD'],
        'reviews.delete' => ['SA'],
        // Commerce & finance
        'products.view' => ['SA', 'AD', 'FO', 'OO', 'PA', 'ST'],
        'products.create' => ['SA', 'AD', 'FO'],
        'products.update' => ['SA', 'AD', 'FO'],
        'products.publish' => ['SA', 'AD', 'FO'],
        'products.delete' => ['SA'],
        'product_categories.manage' => ['SA', 'AD', 'FO', 'OO'],
        'digital_products.manage' => ['SA', 'AD', 'FO'],
        'orders.view' => ['SA', 'AD', 'FO', 'OO', 'PA', 'ST'],
        'orders.create' => ['SA', 'AD', 'FO', 'OO', 'PA', 'ST'],
        'orders.update' => ['SA', 'AD', 'FO'],
        'orders.cancel' => ['SA', 'AD', 'FO', 'PA', 'ST'],
        'orders.admin_assisted_checkout' => ['SA', 'AD', 'FO', 'OO'],
        'commerce.override_minor_restriction' => ['SA'],
        'payments.view' => ['SA', 'AD', 'FO', 'OO', 'PA', 'ST'],
        'payments.verify' => ['SA', 'AD', 'FO'],
        'payments.refund' => ['SA', 'AD', 'FO'],
        'payments.record_manual' => ['SA', 'AD', 'FO'],
        'invoices.view' => ['SA', 'AD', 'FO', 'OO', 'PA', 'ST'],
        'invoices.manage' => ['SA', 'AD', 'FO'],
        'invoices.void' => ['SA', 'AD', 'FO'],
        'coupons.manage' => ['SA', 'AD', 'FO'],
        'tax_rates.manage' => ['SA', 'AD', 'FO'],
        'subscriptions.view' => ['SA', 'AD', 'FO', 'OO', 'PA', 'ST'],
        'subscriptions.manage' => ['SA', 'AD', 'FO', 'PA', 'ST'],
        'entitlements.view' => ['SA', 'AD', 'FO', 'OO', 'PA', 'ST'],
        'entitlements.grant_manual' => ['SA', 'AD'],
        // Reports & analytics
        'reports.view' => ['SA', 'AD', 'AC', 'OO'],
        'reports.students' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'reports.academic_performance' => ['SA', 'AD', 'AC', 'TU', 'ST'],
        'reports.attendance' => ['SA', 'AD', 'AC', 'OO', 'TU', 'PA', 'ST'],
        'reports.at_risk_students' => ['SA', 'AD', 'AC', 'OO'],
        'reports.tutors' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'reports.finance' => ['SA', 'AD', 'FO'],
        'reports.revenue' => ['SA', 'AD', 'FO', 'TU'],
        'reports.enrollment' => ['SA', 'AD', 'AC', 'OO'],
        'reports.lms_analytics' => ['SA', 'AD', 'AC', 'OO', 'TU'],
        'reports.export' => ['SA', 'AD', 'AC', 'FO', 'OO'],
        'reports.build_scheduled' => ['SA', 'AD', 'AC', 'FO'],
        // Settings, files, imports & platform
        'settings.view' => ['SA', 'AD', 'AC', 'FO', 'OO'],
        'settings.manage' => ['SA', 'AD', 'AC', 'FO', 'OO'],
        'settings.manage.security' => ['SA'],
        'settings.manage.payments' => ['SA', 'FO'],
        'settings.manage.integrations' => ['SA', 'AD'],
        'files.upload' => ['SA', 'AD', 'AC', 'FO', 'OO', 'TU', 'PA', 'ST'],
        'files.view_restricted' => ['SA', 'AD', 'AC', 'FO'],
        'files.delete' => ['SA', 'AD', 'AC', 'FO', 'OO', 'TU', 'PA', 'ST'],
        'imports.run' => ['SA', 'AD', 'AC', 'FO', 'OO'],
        'imports.view' => ['SA', 'AD', 'AC', 'FO', 'OO'],
        'exports.run' => ['SA', 'AD', 'AC', 'FO', 'OO'],
        'pages.manage' => ['SA', 'AD', 'AC', 'OO'],
        'faqs.manage' => ['SA', 'AD', 'AC', 'OO'],
        'message_templates.manage' => ['SA', 'AD', 'FO'],
        'platform.doctor' => ['SA', 'AD'],
        'platform.maintenance_mode' => ['SA'],
        'integration_logs.view' => ['SA', 'AD', 'FO'],
        'admin.panel.access' => ['SA', 'AD', 'AC', 'FO', 'OO'],
    ];

    /**
     * Every permission a role carries.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        // Instructor is deliberately not a column in the matrix: it has the Tutor
        // permission shape, and duplicating seventy entries to say so would give
        // the two a chance to drift apart silently.
        $code = $role === self::INSTRUCTOR
            ? 'TU'
            : array_search($role, self::CODES, true);

        if (! is_string($code)) {
            return [];
        }

        $permissions = [];

        foreach (self::GRANTS as $permission => $granted) {
            if (in_array($code, $granted, true)) {
                $permissions[] = (string) $permission;
            }
        }

        return $permissions;
    }

    /**
     * Every role granted a permission — for the admin UI's "who can do this?"
     * view and for the Phase 11 authorization audit.
     *
     * @return list<string>
     */
    public static function rolesWith(string $permission): array
    {
        $granted = self::GRANTS[$permission] ?? [];

        $roles = [];

        foreach ($granted as $code) {
            $role = self::CODES[$code] ?? null;

            if ($role !== null) {
                $roles[] = $role;
            }
        }

        if (in_array('TU', $granted, true)) {
            $roles[] = self::INSTRUCTOR;
        }

        return $roles;
    }

    public static function portalFor(string $role): ?string
    {
        return self::PORTALS[$role] ?? null;
    }

    public static function exists(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
