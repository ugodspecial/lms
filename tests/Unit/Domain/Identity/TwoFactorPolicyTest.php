<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\TwoFactorPolicy;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The two-factor enforcement rule, with no database involved (docs/07 W7).
 *
 * What is being pinned here is the shape of the rule rather than its plumbing:
 * which permissions it covers, that a switched-on flag is not the same as a
 * confirmed second factor, and that a broken configuration fails loudly instead of
 * quietly enforcing nothing.
 *
 * The enforcement itself — that a Super Admin is refused `settings.manage.security`
 * through the real Gate — is in Privileged2faEnforcedTest, because it needs the
 * permission registry and the `Gate::before` ordering to be present.
 */
final class TwoFactorPolicyTest extends TestCase
{
    public function test_the_permissions_that_require_a_second_factor_are_the_documented_ones(): void
    {
        // docs/06 §2: required for holders of `settings.manage.security` and
        // `tutors.approve`. Config rather than code, so an operator can extend it
        // without an edit here — but the default is a decision, and this is where
        // changing it stops being invisible.
        $this->assertSame(
            ['settings.manage.security', 'tutors.approve'],
            TwoFactorPolicy::requiredPermissions(),
        );
    }

    public function test_enforcement_is_on_by_default(): void
    {
        $this->assertTrue(TwoFactorPolicy::isEnforced());
    }

    public function test_a_permission_the_registry_does_not_know_is_dropped_from_the_list(): void
    {
        // A renamed permission left behind in config must not become a key that
        // makes every holder's authorization check throw.
        config([
            'platform.security.two_factor.required_for_permissions' => [
                'settings.manage.security',
                'permissions.that.do.not.exist',
                '',
                42,
            ],
        ]);

        $this->assertSame(['settings.manage.security'], TwoFactorPolicy::requiredPermissions());
    }

    public function test_duplicates_in_the_configured_list_are_collapsed(): void
    {
        config([
            'platform.security.two_factor.required_for_permissions' => [
                'tutors.approve',
                'tutors.approve',
            ],
        ]);

        $this->assertSame(['tutors.approve'], TwoFactorPolicy::requiredPermissions());
    }

    public function test_a_configuration_of_the_wrong_shape_is_refused_rather_than_ignored(): void
    {
        // The alternative — returning an empty list — is a platform where every
        // privileged account is protected by nothing, with no error anywhere saying
        // the rule had been switched off.
        config(['platform.security.two_factor.required_for_permissions' => 'settings.manage.security']);

        $this->expectException(RuntimeException::class);

        TwoFactorPolicy::requiredPermissions();
    }

    public function test_a_privileged_permission_is_blocked_until_the_second_factor_is_confirmed(): void
    {
        $user = $this->userWithoutTwoFactor();

        $this->assertTrue(TwoFactorPolicy::blocks($user, 'settings.manage.security'));
        $this->assertTrue(TwoFactorPolicy::blocks($user, 'tutors.approve'));
    }

    public function test_a_switched_on_flag_that_was_never_confirmed_still_blocks(): void
    {
        // `two_factor_enabled` is set when somebody asks for 2FA; the secret only
        // becomes real once they have proved they can read a code from it. An
        // account shown as secured while a login still needs only a password is the
        // exact failure the confirmation timestamp exists to prevent.
        $user = $this->userWithoutTwoFactor();
        $user->two_factor_enabled = true;
        $user->two_factor_secret = 'a-secret-nobody-proved-they-could-read';

        $this->assertTrue(TwoFactorPolicy::blocks($user, 'settings.manage.security'));
    }

    public function test_a_confirmed_second_factor_ends_the_block(): void
    {
        $user = $this->userWithoutTwoFactor();
        $user->two_factor_enabled = true;
        $user->two_factor_confirmed_at = Carbon::now();

        $this->assertFalse(TwoFactorPolicy::blocks($user, 'settings.manage.security'));
        $this->assertFalse(TwoFactorPolicy::blocks($user, 'tutors.approve'));
    }

    public function test_everything_that_is_not_a_privileged_permission_is_unaffected(): void
    {
        // W7: the person who will not enable TOTP keeps everything else. A rule that
        // blocked `students.view` as well would be a lockout, not an enforcement.
        $user = $this->userWithoutTwoFactor();

        foreach (['students.view', 'admin.panel.access', 'files.view_restricted', 'view', 'update'] as $ability) {
            $this->assertFalse(
                TwoFactorPolicy::blocks($user, $ability),
                $ability.' should not be gated on two-factor authentication',
            );
        }
    }

    public function test_switching_enforcement_off_stops_the_block_without_touching_the_list(): void
    {
        config(['platform.security.two_factor.enforce' => false]);

        $this->assertFalse(TwoFactorPolicy::isEnforced());
        $this->assertFalse(TwoFactorPolicy::blocks($this->userWithoutTwoFactor(), 'settings.manage.security'));

        // The list still answers: an admin screen that reports who is unprotected
        // has to work whether or not enforcement is switched on.
        $this->assertSame(['settings.manage.security', 'tutors.approve'], TwoFactorPolicy::requiredPermissions());
    }

    public function test_an_empty_required_list_blocks_nothing(): void
    {
        config(['platform.security.two_factor.required_for_permissions' => []]);

        $this->assertSame([], TwoFactorPolicy::requiredPermissions());
        $this->assertFalse(TwoFactorPolicy::blocks($this->userWithoutTwoFactor(), 'settings.manage.security'));
    }

    private function userWithoutTwoFactor(): User
    {
        // Unsaved on purpose: nothing in this class touches the database, and a
        // fixture that needed one would make these assertions about the schema too.
        $user = new User;
        $user->two_factor_enabled = false;
        $user->two_factor_confirmed_at = null;

        return $user;
    }
}
