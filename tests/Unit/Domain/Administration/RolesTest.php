<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Administration;

use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use Tests\TestCase;

/**
 * The role bundles, and the rules docs/05 §2 states about them in prose.
 *
 * Most of these are not "does the generator work" tests — they are the platform's
 * separation-of-duties rules expressed as assertions, so that a regenerated or
 * hand-edited bundle cannot quietly violate one:
 *
 *   §10  a minor cannot book tutoring without an adult
 *   §28  an evaluator cannot approve a tutor
 *   §49  an evaluator sees no financial data
 *   §58  a parent reaches only their linked children
 *   §71  a student and a tutor see only their own records
 *
 * Each of those is a legal or safeguarding obligation, not a preference. The
 * assertions name the section so the reason survives the code.
 */
final class RolesTest extends TestCase
{
    public function test_the_eleven_roles_of_docs_05_are_seeded(): void
    {
        $this->assertCount(11, Roles::ALL);
        $this->assertSame(Roles::ALL, array_values(array_unique(Roles::ALL)));

        foreach (Roles::ALL as $role) {
            $this->assertTrue(Roles::exists($role));
            $this->assertNotNull(Roles::portalFor($role), "{$role} belongs to no portal, so it could never be routed anywhere.");
        }

        // The two names docs/05 gives for one role are one role, not two.
        $this->assertContains('Academic Admin', Roles::ALL);
        $this->assertNotContains('Registrar', Roles::ALL);
        $this->assertContains('Parent', Roles::ALL);
        $this->assertNotContains('Guardian', Roles::ALL);
    }

    public function test_every_role_can_reach_the_portal_it_belongs_to(): void
    {
        $access = [
            'admin' => 'admin.panel.access',
            'evaluator' => 'evaluator.portal.access',
            'tutor' => 'tutor.portal.access',
            'parent' => 'parent.portal.access',
            'student' => 'student.portal.access',
        ];

        foreach (Roles::ALL as $role) {
            $portal = (string) Roles::portalFor($role);

            if ($portal === 'public') {
                // An Applicant has no portal. It uses the public site plus the
                // application tracker, so granting it any portal access would let
                // an unadmitted self-registration reach an authenticated area.
                foreach ($access as $permission) {
                    $this->assertNotContains($permission, Roles::permissionsFor($role), "{$role} must not reach {$permission}.");
                }

                continue;
            }

            $this->assertArrayHasKey($portal, $access, "{$role} belongs to unknown portal [{$portal}].");
            $this->assertContains(
                $access[$portal],
                Roles::permissionsFor($role),
                "{$role} belongs to the {$portal} portal but lacks {$access[$portal]}, so AuthenticateArea would refuse it entry to its own home."
            );
        }
    }

    public function test_instructor_has_exactly_the_tutor_permission_shape(): void
    {
        $this->assertSame(
            Roles::permissionsFor(Roles::TUTOR),
            Roles::permissionsFor(Roles::INSTRUCTOR),
            'docs/05 §2: Instructor is the same shape as Tutor, assigned by an administrator rather than recruited.'
        );

        $this->assertNotEmpty(Roles::permissionsFor(Roles::INSTRUCTOR));
        $this->assertSame('tutor', Roles::portalFor(Roles::INSTRUCTOR));

        // "Who can do this?" must name both, or the admin UI implies a Tutor-only
        // capability that an Instructor also holds.
        $this->assertContains(Roles::INSTRUCTOR, Roles::rolesWith('tutor.portal.access'));
        $this->assertNotContains(Roles::INSTRUCTOR, Roles::rolesWith('parents.manage'));
    }

    public function test_a_super_admin_bundle_is_everything_except_participation_and_the_public_endpoint(): void
    {
        $granted = Roles::permissionsFor(Roles::SUPER_ADMIN);

        $this->assertCount(211, $granted);

        $missing = array_values(array_diff(Permissions::all(), $granted));
        sort($missing);

        $expected = ['certificates.verify_public', 'reviews.create', 'reviews.respond'];
        sort($expected);

        $this->assertSame(
            $expected,
            $missing,
            'Super Admin should lack only the two participant permissions and the unauthenticated public one. Anything else missing means the bypass is doing work the bundle should also do.'
        );
    }

    public function test_no_role_is_granted_a_permission_that_is_not_registered(): void
    {
        foreach (Roles::ALL as $role) {
            foreach (Roles::permissionsFor($role) as $permission) {
                $this->assertTrue(
                    Permissions::has($permission),
                    "{$role} is granted [{$permission}], which is not in the registry, so it would never be seeded and could never be checked."
                );
            }
        }
    }

    public function test_hard_deletion_of_core_records_stays_with_super_admin(): void
    {
        // docs/05 §2: an Administrator has everything except the deletion-adjacent
        // powers. A deleted student takes their enrolments, submissions and
        // safeguarding history with them, so this is not a routine admin action.
        // assessments.delete and files.delete are NOT in this list on purpose: the
        // first is academic administration, the second is scoped to own uploads.
        foreach ([
            'students.delete',
            'parents.delete',
            'courses.delete',
            'programs.delete',
            'products.delete',
            'reviews.delete',
            'users.deactivate',
            'users.impersonate',
            'roles.manage',
            'settings.manage.security',
            'platform.maintenance_mode',
        ] as $permission) {
            $this->assertSame(
                [Roles::SUPER_ADMIN],
                Roles::rolesWith($permission),
                "{$permission} must be held by Super Admin alone."
            );
        }

        // The two deletes an Administrator does hold, and they are not
        // exceptions to the rule above: assessments are academic administration,
        // and files.delete is scoped to a user's own uploads for every role that
        // holds it, including Student and Parent.
        $this->assertContains('assessments.delete', Roles::permissionsFor(Roles::ADMINISTRATOR));
        $this->assertNotContains('assessments.delete', Roles::permissionsFor(Roles::ACADEMIC_ADMIN));
        $this->assertSame('own uploads', Permissions::SCOPED['files.delete'][Roles::STUDENT] ?? null);
    }

    public function test_the_minor_purchase_override_is_super_admin_only(): void
    {
        // §10. This permission is the escape hatch around the rule that a child
        // cannot buy tutoring. If a role below Super Admin held it, the checkout
        // guard would be a formality: one compromised Operations Officer account
        // could book sessions for minors with no adult involved, and the audit
        // entry would name an officer rather than the person who decided it.
        $this->assertSame([Roles::SUPER_ADMIN], Roles::rolesWith('commerce.override_minor_restriction'));

        // Unscoped on purpose: the permission IS the exception, so there is no
        // narrower ownership rule to add. Holding it at all is the decision.
        $this->assertArrayNotHasKey('commerce.override_minor_restriction', Permissions::SCOPED);
    }

    public function test_an_evaluator_has_no_approval_authority_and_no_financial_data(): void
    {
        $granted = Roles::permissionsFor(Roles::EVALUATOR);

        // §28: evaluation and approval are different people, so one compromised
        // or bribed evaluator cannot both score and admit a tutor.
        $this->assertNotContains('tutors.approve', $granted, '§28');
        $this->assertNotContains('tutors.reject', $granted, '§28');

        // §49: the recruitment panel must not see what a tutor is paid, or the
        // interview becomes a salary negotiation.
        $this->assertNotContains('payments.view', $granted, '§49');
        $this->assertNotContains('reports.finance', $granted, '§49');
        $this->assertNotContains('tutors.applications.assign', $granted, 'An evaluator works only what it is assigned.');

        // What it does have: enough to run an interview, and nothing to conclude one.
        $this->assertContains('tutors.applications.review', $granted);
        $this->assertContains('tutors.evaluations.submit', $granted);
        $this->assertContains('tutors.interviews.manage', $granted);

        // The matrix scopes what an evaluator may SEE; §4's policy mapping
        // extends "assigned only" across the whole TutorApplicationPolicy, so the
        // policy must scope review and submit too even though those cells carry a
        // plain ✅.
        $this->assertSame('assigned only', Permissions::SCOPED['tutors.applications.view'][Roles::EVALUATOR] ?? null);
        $this->assertSame('own', Permissions::SCOPED['tutors.interviews.view'][Roles::EVALUATOR] ?? null);
        $this->assertSame('own', Permissions::SCOPED['tutors.evaluations.view'][Roles::EVALUATOR] ?? null);
    }

    public function test_a_parent_holds_no_unscoped_power_over_other_families(): void
    {
        $granted = Roles::permissionsFor(Roles::PARENT);

        // §58. A parent manages their own children and their own record. Nothing
        // else: creating, deleting, re-linking or re-capabilitying another family
        // is administration.
        foreach ([
            'parents.manage',
            'parents.create',
            'parents.delete',
            'parents.set_capabilities',
            'parents.invite',
            'parents.unlink_student',
            'students.delete',
            'students.bulk_create',
        ] as $permission) {
            $this->assertNotContains($permission, $granted, "§58: a Parent must not hold {$permission}.");
        }

        // Every parents.* permission a Parent does hold reaches other people's
        // records, so each one must carry a scope the policy enforces.
        $held = array_values(array_filter($granted, static fn (string $p): bool => str_starts_with($p, 'parents.')));

        $this->assertNotSame([], $held);

        foreach ($held as $permission) {
            $this->assertArrayHasKey(
                $permission,
                Permissions::SCOPED,
                "{$permission} is granted to a Parent and reaches other records, so it must be policy-scoped."
            );
            $this->assertArrayHasKey(Roles::PARENT, Permissions::SCOPED[$permission] ?? []);
        }

        $this->assertSame('own children', Permissions::SCOPED['students.view'][Roles::PARENT] ?? null);
        $this->assertSame('self', Permissions::SCOPED['parents.view'][Roles::PARENT] ?? null);
        $this->assertSame('own orders only', Permissions::SCOPED['parents.view_financials'][Roles::PARENT] ?? null);
    }

    public function test_a_student_may_not_book_tutoring_without_an_adult(): void
    {
        // §10, expressed in the matrix rather than in prose. The Student grant is
        // conditional on being an adult; the Parent grant is conditional on the
        // child being linked. CanStudentPurchaseTutoring enforces both, and this
        // is where the rule it enforces is recorded.
        $this->assertContains('bookings.create', Roles::permissionsFor(Roles::STUDENT));
        $this->assertContains('bookings.create', Roles::permissionsFor(Roles::PARENT));

        $scopes = Permissions::SCOPED['bookings.create'] ?? [];

        $this->assertSame('adults only (§10)', $scopes[Roles::STUDENT] ?? null);
        $this->assertSame('for linked children', $scopes[Roles::PARENT] ?? null);
    }

    public function test_a_student_and_a_tutor_see_only_their_own_records(): void
    {
        // §71.
        $this->assertSame('self', Permissions::SCOPED['students.view'][Roles::STUDENT] ?? null);
        $this->assertSame('own', Permissions::SCOPED['certificates.view'][Roles::STUDENT] ?? null);
        $this->assertSame('scoped to own cohorts', Permissions::SCOPED['students.view'][Roles::TUTOR] ?? null);

        foreach (['students.view', 'submissions.view', 'grades.view'] as $permission) {
            $this->assertArrayHasKey(Roles::STUDENT, Permissions::SCOPED[$permission] ?? []);
        }
    }

    public function test_an_unknown_role_carries_nothing_and_reaches_nothing(): void
    {
        // Fail closed. An unrecognized role string must yield no permissions
        // rather than throwing or, worse, defaulting to a bundle.
        $this->assertSame([], Roles::permissionsFor('Ghost'));
        $this->assertSame([], Roles::permissionsFor(''));
        $this->assertSame([], Roles::permissionsFor('super admin'));
        $this->assertNull(Roles::portalFor('Ghost'));
        $this->assertFalse(Roles::exists('Ghost'));
        $this->assertSame([], Roles::rolesWith('users.teleport'));
    }
}
