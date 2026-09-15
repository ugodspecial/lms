<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Domain\Administration\Enums\SettingGroup;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\TwoFactorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * Privileged accounts on password-only are refused the privileged permission
 * (docs/07 W7, docs/06 §2).
 *
 * Every assertion here goes through the Gate rather than calling TwoFactorPolicy
 * directly, because the interesting property is not the rule — it is where the rule
 * sits. `Gate::before` runs its callbacks in registration order and the first
 * non-null answer wins, so this rule has to be registered ahead of the Super Admin
 * bypass or it never gets asked: `settings.manage.security` is a permission Super
 * Admin holds, which means the bypass is precisely what would wave through the
 * account the rule exists to protect.
 *
 * That is also why the first test is about Super Admin rather than about a tutor.
 * An enforcement that worked for everybody except the most privileged account would
 * pass every test written against a lower role, and would be worthless.
 */
final class Privileged2faEnforcedTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    public function test_a_super_admin_without_a_confirmed_second_factor_is_refused_the_permission_this_rule_exists_for(): void
    {
        $administrator = $this->user(Roles::SUPER_ADMIN);

        $this->assertFalse($administrator->hasConfirmedTwoFactor());
        $this->assertTrue($administrator->hasPermissionTo('settings.manage.security'));

        // Holds the permission, and cannot exercise it. That distinction is the
        // whole design: the grant stays on the role, so confirming 2FA restores
        // access on the next check with nothing to put back.
        $this->assertFalse($administrator->can('settings.manage.security'));
    }

    public function test_a_super_admin_who_has_confirmed_a_second_factor_is_refused_nothing(): void
    {
        $administrator = $this->user(Roles::SUPER_ADMIN);
        $this->confirmTwoFactor($administrator);

        $this->assertTrue($administrator->can('settings.manage.security'));
        $this->assertTrue($administrator->can('tutors.approve'));
    }

    public function test_a_switched_on_flag_nobody_confirmed_does_not_satisfy_the_rule(): void
    {
        $administrator = $this->user(Roles::SUPER_ADMIN);
        $administrator->two_factor_enabled = true;
        $administrator->two_factor_secret = 'a-secret-nobody-proved-they-could-read';
        $administrator->save();

        // An account displayed as secured while a login still needs only a password
        // is the exact failure `two_factor_confirmed_at` exists to prevent.
        $this->assertFalse($administrator->can('settings.manage.security'));
    }

    public function test_everything_that_is_not_a_privileged_permission_keeps_working(): void
    {
        // W7: the person who will not enable TOTP keeps everything else. If this
        // were not true the rule would be a lockout rather than an enforcement, and
        // the reasonable response to a lockout is to switch the rule off.
        $administrator = $this->user(Roles::SUPER_ADMIN);

        foreach (['users.suspend', 'users.view', 'audit.view', 'admin.panel.access', 'settings.view'] as $ability) {
            $this->assertTrue(
                $administrator->can($ability),
                $ability.' must survive an unconfirmed second factor',
            );
        }
    }

    public function test_a_grant_made_directly_to_a_user_is_enforced_too(): void
    {
        // The rule follows the permission, not the role. A custom role — or a direct
        // grant to one evaluator who also approves tutors — is enforced the same way,
        // which is why the list in config is a list of permissions.
        $evaluator = $this->user(Roles::EVALUATOR);
        $evaluator->givePermissionTo('tutors.approve');

        $this->assertTrue($evaluator->hasPermissionTo('tutors.approve'));
        $this->assertFalse($evaluator->can('tutors.approve'));

        $this->confirmTwoFactor($evaluator);

        $this->assertTrue($evaluator->can('tutors.approve'));
    }

    public function test_the_rule_reaches_a_policy_that_asks_for_the_permission(): void
    {
        // SettingPolicy asks `can('settings.manage.security')` rather than spatie
        // directly, so the veto reaches it. Had it asked spatie, the policy would keep
        // answering yes for an account the Gate refuses — and a rule enforced in one
        // layer and bypassed in the next is not enforced.
        //
        // The fixture holds the grant DIRECTLY and has no Super Admin role, because a
        // Super Admin never reaches the policy at all: the bypass answers first. With
        // this user the only thing standing between them and the setting is the
        // policy, so the assertion is about the policy.
        $setting = $this->makeSetting(
            key: 'security.session_lifetime_minutes',
            value: 120,
            overrides: ['group' => SettingGroup::Security],
        );

        $holder = $this->user();
        $holder->givePermissionTo('settings.manage.security');

        $this->assertTrue($holder->hasPermissionTo('settings.manage.security'));
        $this->assertFalse($holder->can('update', $setting));

        $this->confirmTwoFactor($holder);

        $this->assertTrue($holder->can('update', $setting));
    }

    public function test_an_administrator_keeps_administering_and_loses_only_the_privileged_grant(): void
    {
        // docs/05 §3 gives Administrator `tutors.approve` and not
        // `settings.manage.security`, so this role is the case where the rule takes
        // one permission and leaves a working administrator behind.
        $administrator = $this->user(Roles::ADMINISTRATOR);

        $this->assertTrue($administrator->hasPermissionTo('tutors.approve'));
        $this->assertFalse($administrator->hasPermissionTo('settings.manage.security'));

        $this->assertTrue($administrator->can('users.suspend'));
        $this->assertTrue($administrator->can('users.view'));
        $this->assertFalse($administrator->can('tutors.approve'));

        $this->assertSame(['tutors.approve'], TwoFactorPolicy::outstandingFor($administrator));
    }

    public function test_a_role_that_holds_no_privileged_permission_has_nothing_outstanding(): void
    {
        // §28 is why an Evaluator is the fixture: evaluators are deliberately never
        // granted `tutors.approve`, so this role reaches the rule and is untouched
        // by it.
        $evaluator = $this->user(Roles::EVALUATOR);

        $this->assertSame([], TwoFactorPolicy::outstandingFor($evaluator));

        // `blocks()` answers about the ABILITY, not about the holder, so it is true
        // here too — and that is harmless, because an evaluator is refused
        // `tutors.approve` by the rest of the Gate either way. What has to differ is
        // what gets reported to the person, and that is what outstandingFor() is for.
        $this->assertTrue(TwoFactorPolicy::blocks($evaluator, 'tutors.approve'));
        $this->assertFalse($evaluator->can('tutors.approve'));
    }

    public function test_switching_enforcement_off_restores_the_permission_without_touching_the_grant(): void
    {
        config(['platform.security.two_factor.enforce' => false]);

        $administrator = $this->user(Roles::SUPER_ADMIN);

        $this->assertTrue($administrator->can('settings.manage.security'));
        $this->assertSame([], TwoFactorPolicy::outstandingFor($administrator));
    }

    public function test_the_outstanding_list_names_only_what_the_person_actually_holds(): void
    {
        // A banner telling somebody to enable 2FA to keep access they never had is
        // how enforcement gets ignored, so this asks what is held rather than what
        // is configured. Order follows config, which is the order a report reads.
        $evaluator = $this->user(Roles::EVALUATOR);
        $evaluator->givePermissionTo('tutors.approve');

        $this->assertSame(['tutors.approve'], TwoFactorPolicy::outstandingFor($evaluator));
    }

    public function test_the_outstanding_list_empties_itself_when_the_second_factor_is_confirmed(): void
    {
        $administrator = $this->user(Roles::SUPER_ADMIN);

        $this->assertSame(
            ['settings.manage.security', 'tutors.approve'],
            TwoFactorPolicy::outstandingFor($administrator),
        );

        $this->confirmTwoFactor($administrator);

        $this->assertSame([], TwoFactorPolicy::outstandingFor($administrator));
    }

    public function test_both_privileged_permissions_are_reported_to_an_account_that_holds_both(): void
    {
        // Super Admin holds both by role — docs/05 §3 grants `tutors.approve` to
        // Super Admin and Administrator, and `settings.manage.security` to Super
        // Admin alone — so this is the account with the most to lose and the one a
        // report has to name completely.
        $administrator = $this->user(Roles::SUPER_ADMIN);

        $this->assertSame(
            ['settings.manage.security', 'tutors.approve'],
            TwoFactorPolicy::outstandingFor($administrator),
        );
    }

    private function user(string ...$roles): User
    {
        $user = $this->makeUser();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }
}
