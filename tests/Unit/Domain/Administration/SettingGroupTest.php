<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Administration;

use App\Domain\Administration\Enums\SettingGroup;
use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use Tests\TestCase;

/**
 * The enum that decides which permission unlocks a setting, and therefore the
 * place a mistake is cheapest to make and most expensive to find (§60, docs/05
 * §3.9).
 *
 * Three invariants are checked here rather than left to the policy tests, because
 * each one fails silently in production:
 *
 * • A permission string that is not in the registry grants nothing to anybody —
 *   `Gate::before` only maps registered strings, so a typo makes every setting in
 *   that group unmanageable and reports it as "the form does not save".
 *
 * • A group no role holds the permission for is a dead tab: the settings screen
 *   would render fields that nobody on the platform can write.
 *
 * • A role scoped to a group that does not exist holds a grant it can never
 *   exercise. docs/05 §3.9 scopes the Operations Officer's `settings.manage` to
 *   `operations`; without that case in the enum, the matrix would claim the role
 *   can manage settings while the policy said no to every setting there is.
 */
final class SettingGroupTest extends TestCase
{
    public function test_every_group_demands_a_permission_the_registry_actually_grants(): void
    {
        foreach (SettingGroup::cases() as $group) {
            $permission = $group->requiredPermission();

            $this->assertTrue(
                Permissions::has($permission),
                sprintf('%s demands "%s", which is not a registered permission.', $group->value, $permission),
            );

            // Reading the permission is a separate grant, and every group has to
            // be readable by somebody who can manage it.
            $this->assertTrue(Permissions::has(SettingGroup::VIEW_PERMISSION));

            $this->assertNotSame(
                [],
                Roles::rolesWith($permission),
                sprintf('No role holds "%s", so nothing in the %s group could ever be written.', $permission, $group->value),
            );
        }
    }

    public function test_the_groups_whose_compromise_is_platform_wide_need_their_own_permission(): void
    {
        // A Finance Officer who may set the tax rate must not thereby be able to
        // change the session lifetime, and an administrator who may configure
        // video providers must not thereby be able to relax password policy.
        $this->assertSame('settings.manage.security', SettingGroup::Security->requiredPermission());
        $this->assertSame('settings.manage.payments', SettingGroup::Payments->requiredPermission());
        $this->assertSame('settings.manage.integrations', SettingGroup::Video->requiredPermission());

        foreach ([
            SettingGroup::Organization,
            SettingGroup::Academic,
            SettingGroup::Commerce,
            SettingGroup::Notifications,
            SettingGroup::Operations,
        ] as $group) {
            $this->assertSame(
                SettingGroup::BASE_PERMISSION,
                $group->requiredPermission(),
                sprintf('%s should be covered by the ordinary settings permission.', $group->value),
            );
        }
    }

    public function test_only_the_base_permission_is_scoped_per_role(): void
    {
        foreach (SettingGroup::cases() as $group) {
            // The two methods have to agree, or SettingPolicy would either skip
            // the scope check for a scoped grant or run it for a whole one.
            $this->assertSame(
                $group->requiredPermission() === SettingGroup::BASE_PERMISSION,
                $group->isRoleScoped(),
                sprintf('isRoleScoped() disagrees with requiredPermission() for %s.', $group->value),
            );
        }

        $this->assertFalse(SettingGroup::Security->isRoleScoped());
        $this->assertTrue(SettingGroup::Academic->isRoleScoped());
    }

    public function test_every_group_a_role_is_scoped_to_exists(): void
    {
        $scoped = 0;

        foreach ([SettingGroup::VIEW_PERMISSION, SettingGroup::BASE_PERMISSION] as $permission) {
            foreach (Permissions::SCOPED[$permission] as $role => $group) {
                $scoped++;

                $this->assertNotNull(
                    SettingGroup::tryFrom($group),
                    sprintf(
                        '%s scopes %s to "%s", which is not a SettingGroup — that grant could never be exercised.',
                        $permission,
                        $role,
                        $group,
                    ),
                );

                // The role has to hold the permission it is being scoped in, or the
                // qualifier describes something the registry does not grant.
                $this->assertContains(
                    $role,
                    Roles::rolesWith($permission),
                    sprintf('%s is scoped for %s, but %s is not granted it.', $permission, $role, $permission),
                );
            }
        }

        // If the registry ever stops scoping settings, this test would pass while
        // proving nothing.
        $this->assertGreaterThanOrEqual(6, $scoped);
    }

    public function test_labels_are_human_and_distinct(): void
    {
        $labels = [];

        foreach (SettingGroup::cases() as $group) {
            $label = $group->label();

            $this->assertNotSame('', trim($label));

            // A label is what an administrator reads on a tab, not the slug that
            // is stored: no underscores, sentence case, and not the raw value.
            $this->assertNotSame($group->value, $label);
            $this->assertStringNotContainsString('_', $label);
            $this->assertSame(1, preg_match('/^[A-Z]/', $label), sprintf('"%s" does not read like a tab label.', $label));

            $labels[] = $label;
        }

        $this->assertCount(count(SettingGroup::cases()), array_unique($labels), 'Two tabs may not share a label.');
    }

    public function test_the_sensitive_groups_are_the_elevated_ones(): void
    {
        foreach (SettingGroup::cases() as $group) {
            $this->assertSame(
                $group->isSensitive(),
                ! $group->isRoleScoped(),
                sprintf('%s is sensitive exactly when it has an elevated permission of its own.', $group->value),
            );
        }
    }
}
