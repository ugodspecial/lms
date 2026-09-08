<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Administration;

use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use Tests\TestCase;

/**
 * The permission registry is the vocabulary every authorization decision on the
 * platform is written in, and it is generated from docs/05 §3 rather than typed
 * by hand.
 *
 * That is why these tests assert COUNTS and PARTITIONS rather than spot-checking
 * a few permissions. A generated file can be regenerated wrongly — a dropped
 * section, a row read from the wrong column, a note attributed to the wrong role
 * — and each of those mistakes looks perfectly reasonable in a diff of 214 lines.
 * An assertion on the totals turns "the generator mis-read the matrix" into a
 * failing build.
 *
 * The counts here are the contract between docs/05 §3 and the code. If that
 * section legitimately changes, the registry is regenerated and these numbers are
 * updated in the same commit, which is the moment to notice.
 */
final class PermissionsTest extends TestCase
{
    /** docs/05 §3 sums its own fourteen sections to this. */
    private const EXPECTED_TOTAL = 214;

    private const EXPECTED_GROUPS = 14;

    public function test_the_registry_holds_every_permission_the_matrix_declares(): void
    {
        $all = Permissions::all();

        $this->assertCount(self::EXPECTED_TOTAL, $all);
        $this->assertSame(
            $all,
            array_values(array_unique($all)),
            'A permission registered twice would be seeded twice and could be granted to one role and not another.'
        );
    }

    public function test_the_groups_partition_the_registry_exactly(): void
    {
        $this->assertCount(self::EXPECTED_GROUPS, Permissions::GROUPS);

        $fromGroups = [];

        foreach (Permissions::GROUPS as $slug => $group) {
            $this->assertNotSame('', $slug);
            $this->assertNotSame('', $group['label'], "group [{$slug}] has no label for the admin UI");
            $this->assertNotSame([], $group['permissions'], "group [{$slug}] is empty");

            foreach ($group['permissions'] as $permission) {
                $this->assertSame(
                    $slug,
                    Permissions::groupOf($permission),
                    "{$permission} is in group [{$slug}] but groupOf() disagrees, so a permission appears in two groups."
                );

                $fromGroups[] = $permission;
            }
        }

        // Equality, not just equal counts: all() merges the same constants in the
        // same order, so a group added to GROUPS but not to all() shows up here.
        $this->assertSame(Permissions::all(), $fromGroups);
    }

    public function test_the_group_sizes_match_the_section_headings_in_the_matrix(): void
    {
        $expected = [
            'identity' => 14,
            'students' => 14,
            'parents' => 11,
            'admissions' => 8,
            'academic' => 22,
            'scheduling' => 13,
            'lms' => 18,
            'assessment' => 21,
            'recruitment' => 16,
            'tutoring' => 15,
            'reviews' => 6,
            'commerce' => 26,
            'reports' => 12,
            'platform' => 18,
        ];

        foreach ($expected as $slug => $count) {
            $this->assertArrayHasKey($slug, Permissions::GROUPS);
            $this->assertCount(
                $count,
                Permissions::GROUPS[$slug]['permissions'],
                "docs/05 §3 declares {$count} permissions for [{$slug}]."
            );
        }

        $this->assertSame(self::EXPECTED_TOTAL, array_sum($expected));
    }

    public function test_permission_names_follow_the_resource_action_convention(): void
    {
        foreach (Permissions::all() as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/',
                $permission,
                "{$permission} is not lower_snake.resource.action, so it will not sort with its siblings in the admin UI."
            );

            // spatie stores names in VARCHAR(125); the real limit is readability.
            $this->assertLessThanOrEqual(64, strlen($permission), "{$permission} is too long to read in a policy.");
        }
    }

    public function test_the_area_access_permissions_phase_1_gates_on_are_registered(): void
    {
        // AuthenticateArea refuses a portal to anybody lacking its permission, so
        // a missing one of these locks every user out of that portal — including
        // every administrator, in the case of admin.panel.access.
        foreach ([
            'admin.panel.access',
            'parent.portal.access',
            'student.portal.access',
            'tutor.portal.access',
            'evaluator.portal.access',
        ] as $permission) {
            $this->assertTrue(Permissions::has($permission), "{$permission} must be registered.");
        }
    }

    public function test_scoped_permissions_name_a_role_and_a_reason(): void
    {
        $this->assertCount(93, Permissions::SCOPED);

        foreach (Permissions::SCOPED as $permission => $byRole) {
            $this->assertTrue(Permissions::has($permission), "{$permission} is scoped but not registered.");
            $this->assertNotSame([], $byRole, "{$permission} is listed as scoped with no role attached.");

            foreach ($byRole as $role => $note) {
                $this->assertTrue(Roles::exists($role), "{$permission} is scoped for [{$role}], which is not a seeded role.");
                $this->assertNotSame('', $note, "{$permission} for [{$role}] carries an empty qualifier.");
                $this->assertContains(
                    $permission,
                    Roles::permissionsFor($role),
                    "{$permission} is scoped for [{$role}] but that role is not granted it, so the scope note describes a grant that does not exist."
                );
            }
        }
    }

    public function test_a_permission_can_mean_different_things_to_different_roles(): void
    {
        // The reason SCOPED is keyed by role. One note per permission would have
        // kept whichever role the matrix happened to list last, and a policy
        // author reading "self" would not know it applies only to the Student.
        $students = Permissions::SCOPED['students.view'] ?? [];

        $this->assertSame('self', $students[Roles::STUDENT] ?? null);
        $this->assertSame('own children', $students[Roles::PARENT] ?? null);
        $this->assertSame('scoped to own cohorts', $students[Roles::TUTOR] ?? null);
        $this->assertSame('read-only, no DOB/notes', $students[Roles::FINANCE_OFFICER] ?? null);
    }

    public function test_denials_cite_the_section_that_requires_them(): void
    {
        $this->assertCount(4, Permissions::DENIED);

        foreach (Permissions::DENIED as $permission => $byRole) {
            $this->assertTrue(Permissions::has($permission));

            foreach ($byRole as $role => $reason) {
                // A denial with no citation reads as an oversight, and oversights
                // get "fixed" by granting the permission. §28, §49 and §88 are the
                // rules; the citation is what stops them being reverted.
                $this->assertStringContainsString('§', $reason, "{$permission} is denied to [{$role}] without a citation.");
                $this->assertNotContains(
                    $role,
                    Roles::rolesWith($permission),
                    "{$permission} is documented as denied to [{$role}] but the matrix grants it."
                );
            }
        }

        $this->assertArrayHasKey('tutors.approve', Permissions::DENIED);
        $this->assertSame('§28: evaluators cannot approve', Permissions::DENIED['tutors.approve'][Roles::EVALUATOR] ?? null);
        $this->assertSame('cannot review self (§88)', Permissions::DENIED['reviews.create'][Roles::TUTOR] ?? null);
    }

    public function test_public_capabilities_are_registered_but_belong_to_no_role(): void
    {
        $this->assertSame(['certificates.verify_public'], Permissions::PUBLIC_CAPABILITIES);

        foreach (Permissions::PUBLIC_CAPABILITIES as $permission) {
            $this->assertTrue(Permissions::has($permission), "{$permission} must be registered so the audit trail can name it.");

            // If this were bundled onto a role, an employer checking a
            // certificate would need an account — and if it were checked as a
            // permission on an anonymous route, it would deny everybody.
            $this->assertSame(
                [],
                Roles::rolesWith($permission),
                "{$permission} guards an unauthenticated, rate-limited endpoint and must not be in any role bundle."
            );
        }
    }

    public function test_has_accepts_registered_permissions_and_rejects_everything_else(): void
    {
        $this->assertTrue(Permissions::has('users.view'));
        $this->assertTrue(Permissions::has('commerce.override_minor_restriction'));

        // The typo case: a near-miss must not resolve, because a permission that
        // does not exist denies silently rather than raising an error.
        $this->assertFalse(Permissions::has('users.views'));
        $this->assertFalse(Permissions::has('Users.view'));
        $this->assertFalse(Permissions::has(''));
        $this->assertNull(Permissions::groupOf('users.views'));
    }
}
