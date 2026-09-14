<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Domain\Administration\Enums\SettingGroup;
use App\Domain\Administration\Enums\SettingType;
use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Models\Setting;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * Holding a permission is not the whole answer for settings (§60, docs/05 §3.9).
 *
 * The registry records three qualifications that a flat `can('settings.manage')`
 * would throw away, and SettingPolicy is where they are honoured:
 *
 *   settings.view / settings.manage
 *       Academic Admin      → the `academic` group only
 *       Finance Officer     → the `commerce` group only
 *       Operations Officer  → the `operations` group only
 *   settings.manage.payments
 *       Finance Officer     → "read + test-mode only"
 *
 * A permission that is granted and still not sufficient is the failure mode these
 * tests exist for, because it is invisible from either side on its own: the matrix
 * says the role may manage settings, the database agrees, and an Academic Admin who
 * edits the tax rate gets a 403 they cannot explain — or, if the qualifier is
 * ignored, edits something their department has no business owning.
 *
 * The audit trail is tested here too. `audit.view` is narrower than the
 * permissions around it — Super Admin and Administrator only — because the trail
 * holds before-and-after values from every domain, including payment amounts and
 * other departments' student records. And no principal that reaches the policy may
 * write to it at all: an editable audit trail is not an audit trail.
 */
final class SettingPolicyTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    /**
     * A user holding every role given. Roles are assigned rather than simulated so
     * the check runs the whole way through spatie, `Gate::before` and the policy.
     */
    private function user(string ...$roles): User
    {
        $user = $this->makeUser();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * A setting in the given group.
     *
     * The key carries a random suffix because `settings.key` is unique and several
     * assertions in one test need a row from the same group — a second call with a
     * fixed key would fail on the index and look like an authorization failure.
     */
    private function settingIn(SettingGroup $group): Setting
    {
        return $this->makeSetting(
            'tests.'.$group->value.'.'.Str::lower(Str::random(8)),
            'value',
            SettingType::Text,
            ['group' => $group],
        );
    }

    // ── The scoped grants ───────────────────────────────────────────────────

    public function test_an_academic_admin_may_change_academic_settings_only(): void
    {
        $academicAdmin = $this->user(Roles::ACADEMIC_ADMIN);
        $academic = $this->settingIn(SettingGroup::Academic);

        $this->assertTrue($academicAdmin->can('settings.manage'), 'The grant is real; what is qualified is its reach.');
        $this->assertTrue($academicAdmin->can('update', $academic));

        foreach ([
            SettingGroup::Commerce,
            SettingGroup::Organization,
            SettingGroup::Notifications,
            SettingGroup::Operations,
            SettingGroup::Payments,
            SettingGroup::Security,
            SettingGroup::Video,
        ] as $group) {
            $this->assertFalse(
                $academicAdmin->can('update', $this->settingIn($group)),
                sprintf('Academic Admin is scoped to the academic group and must not reach %s.', $group->value),
            );
        }
    }

    public function test_a_finance_officer_may_change_commerce_settings_only(): void
    {
        $finance = $this->user(Roles::FINANCE_OFFICER);

        $this->assertTrue($finance->can('update', $this->settingIn(SettingGroup::Commerce)));

        foreach ([SettingGroup::Academic, SettingGroup::Organization, SettingGroup::Operations] as $group) {
            $this->assertFalse(
                $finance->can('update', $this->settingIn($group)),
                sprintf('Finance Officer is scoped to the commerce group and must not reach %s.', $group->value),
            );
        }
    }

    public function test_an_operations_officer_may_change_operations_settings_only(): void
    {
        // `operations` exists as a group because docs/05 §3.9 scopes this role's
        // grant to it. Without the case in SettingGroup the grant would name a
        // group that could not exist, and the role could manage nothing at all.
        $operations = $this->user(Roles::OPERATIONS_OFFICER);

        $this->assertTrue($operations->can('update', $this->settingIn(SettingGroup::Operations)));
        $this->assertFalse($operations->can('update', $this->settingIn(SettingGroup::Academic)));
        $this->assertFalse($operations->can('update', $this->settingIn(SettingGroup::Commerce)));
    }

    public function test_a_finance_officer_may_change_payment_settings_only_while_the_platform_is_in_test_mode(): void
    {
        $finance = $this->user(Roles::FINANCE_OFFICER);
        $payments = $this->settingIn(SettingGroup::Payments);

        $this->assertTrue($finance->can('settings.manage.payments'));

        config(['services.paystack.mode' => 'test']);
        $this->assertTrue(
            $finance->can('update', $payments),
            'Test mode is where an officer configures a key or a callback URL.',
        );

        // "read + test-mode only" is what the registry says the grant means. Live
        // mode is where a mistake moves real money (§30.7, §34).
        config(['services.paystack.mode' => 'live']);
        $this->assertFalse($finance->can('update', $payments));

        // Reading survives in both modes: the qualification is on writing.
        $this->assertTrue($finance->can('view', $payments));
    }

    public function test_an_administrator_holds_settings_manage_without_a_group_qualifier(): void
    {
        $administrator = $this->user(Roles::ADMINISTRATOR);

        foreach ([
            SettingGroup::Organization,
            SettingGroup::Academic,
            SettingGroup::Commerce,
            SettingGroup::Notifications,
            SettingGroup::Operations,
            // settings.manage.integrations, which Administrator also holds.
            SettingGroup::Video,
        ] as $group) {
            $this->assertTrue(
                $administrator->can('update', $this->settingIn($group)),
                sprintf('Administrator holds settings.manage outright and must reach %s.', $group->value),
            );
        }

        // ... except security, which the registry grants to Super Admin alone.
        $this->assertFalse($administrator->can('settings.manage.security'));
        $this->assertFalse($administrator->can('update', $this->settingIn(SettingGroup::Security)));
    }

    public function test_a_role_holding_both_a_scoped_and_an_unscoped_grant_is_not_demoted(): void
    {
        // Somebody who is an Academic Admin and is later made an Administrator
        // holds settings.manage twice: once qualified, once not. The broader grant
        // describes what they may do, and intersecting the two would quietly
        // demote them on promotion.
        $both = $this->user(Roles::ACADEMIC_ADMIN, Roles::ADMINISTRATOR);

        $this->assertTrue($both->can('update', $this->settingIn(SettingGroup::Academic)));
        $this->assertTrue($both->can('update', $this->settingIn(SettingGroup::Commerce)));
        $this->assertTrue($both->can('update', $this->settingIn(SettingGroup::Organization)));
    }

    public function test_only_a_super_admin_may_change_security_settings(): void
    {
        $security = $this->settingIn(SettingGroup::Security);

        $this->assertTrue($this->user(Roles::SUPER_ADMIN)->can('update', $security));

        foreach ([Roles::ADMINISTRATOR, Roles::ACADEMIC_ADMIN, Roles::FINANCE_OFFICER, Roles::OPERATIONS_OFFICER] as $role) {
            $this->assertFalse(
                $this->user($role)->can('update', $security),
                sprintf('%s must not be able to relax password, session or 2FA policy.', $role),
            );
        }
    }

    public function test_video_settings_need_the_integrations_permission_not_the_general_one(): void
    {
        $video = $this->settingIn(SettingGroup::Video);

        // Administrator holds settings.manage.integrations; Academic Admin holds
        // only the scoped settings.manage, which does not reach this group.
        $this->assertTrue($this->user(Roles::ADMINISTRATOR)->can('update', $video));
        $this->assertFalse($this->user(Roles::ACADEMIC_ADMIN)->can('update', $video));
        $this->assertFalse($this->user(Roles::FINANCE_OFFICER)->can('update', $video));
    }

    public function test_reading_a_setting_is_scoped_the_same_way_as_changing_it(): void
    {
        $academicAdmin = $this->user(Roles::ACADEMIC_ADMIN);

        $this->assertTrue($academicAdmin->can('viewAny', Setting::class));
        $this->assertTrue($academicAdmin->can('view', $this->settingIn(SettingGroup::Academic)));
        $this->assertFalse($academicAdmin->can('view', $this->settingIn(SettingGroup::Commerce)));
    }

    public function test_a_participant_may_neither_see_nor_change_any_setting(): void
    {
        foreach ([Roles::STUDENT, Roles::PARENT, Roles::TUTOR, Roles::EVALUATOR] as $role) {
            $user = $this->user($role);

            $this->assertFalse($user->can('settings.view'), sprintf('%s must not hold settings.view.', $role));
            $this->assertFalse($user->can('viewAny', Setting::class));
            $this->assertFalse($user->can('view', $this->settingIn(SettingGroup::Organization)));
            $this->assertFalse($user->can('update', $this->settingIn(SettingGroup::Organization)));
        }

        // And somebody holding no role at all is denied rather than defaulted.
        $nobody = $this->makeUser();

        $this->assertFalse($nobody->can('viewAny', Setting::class));
        $this->assertFalse($nobody->can('update', $this->settingIn(SettingGroup::Organization)));
    }

    public function test_no_policy_principal_may_create_or_delete_a_setting(): void
    {
        $administrator = $this->user(Roles::ADMINISTRATOR);
        $setting = $this->settingIn(SettingGroup::Organization);

        // The registry of settings is code. Letting a form add rows would let the
        // same form widen `allowed_values` or clear `is_secret` one request later.
        $this->assertFalse($administrator->can('create', Setting::class));
        $this->assertFalse($administrator->can('delete', $setting));
        $this->assertFalse($administrator->can('forceDelete', $setting));
        $this->assertFalse($administrator->can('restore', $setting));
    }

    public function test_a_super_admin_bypasses_the_policy_rather_than_satisfying_it(): void
    {
        $superAdmin = $this->user(Roles::SUPER_ADMIN);

        // Recorded because it is a consequence of the design, not an oversight:
        // `Gate::before` returns true for every ability that is not
        // participation-only, so a policy's `false` never reaches Super Admin. It
        // is the one place a role name appears in authorization logic.
        $this->assertTrue($superAdmin->can('create', Setting::class));
        $this->assertTrue($superAdmin->can('delete', $this->settingIn(SettingGroup::Security)));
        $this->assertTrue($superAdmin->can('update', $this->settingIn(SettingGroup::Commerce)));
    }

    // ── The audit trail ─────────────────────────────────────────────────────

    public function test_the_trail_is_readable_by_administrators_only(): void
    {
        $entry = $this->makeAuditLog('settings.updated');

        $this->assertTrue($this->user(Roles::ADMINISTRATOR)->can('viewAny', AuditLog::class));
        $this->assertTrue($this->user(Roles::ADMINISTRATOR)->can('view', $entry));
        $this->assertTrue($this->user(Roles::SUPER_ADMIN)->can('view', $entry));

        // Narrower than the settings permissions around it on purpose: the trail
        // holds before-and-after values from every domain, including payment
        // amounts and other departments' student records.
        foreach ([
            Roles::ACADEMIC_ADMIN,
            Roles::FINANCE_OFFICER,
            Roles::OPERATIONS_OFFICER,
            Roles::EVALUATOR,
            Roles::TUTOR,
            Roles::PARENT,
            Roles::STUDENT,
        ] as $role) {
            $user = $this->user($role);

            $this->assertFalse($user->can('audit.view'), sprintf('%s must not hold audit.view.', $role));
            $this->assertFalse($user->can('viewAny', AuditLog::class));
            $this->assertFalse($user->can('view', $entry));
        }
    }

    public function test_an_audit_entry_cannot_be_changed_or_deleted_by_any_policy_principal(): void
    {
        $administrator = $this->user(Roles::ADMINISTRATOR);
        $entry = $this->makeAuditLog('settings.updated');

        $this->assertTrue($administrator->can('view', $entry), 'Reading is the whole point of the trail.');

        // The first of three layers: no route exists (docs/06), this policy denies,
        // and the model itself throws — so a route added later by somebody who did
        // not read the first line still cannot edit history.
        $this->assertFalse($administrator->can('create', AuditLog::class));
        $this->assertFalse($administrator->can('update', $entry));
        $this->assertFalse($administrator->can('delete', $entry));
        $this->assertFalse($administrator->can('restore', $entry));
        $this->assertFalse($administrator->can('forceDelete', $entry));
    }

}
