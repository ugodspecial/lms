<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Administration\Permissions;
use App\Domain\Identity\Models\User;
use RuntimeException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Which permissions stop working until the person holding them proves a second
 * factor (§6, docs/06 §2, docs/07 W7).
 *
 * NOT a Gate policy, despite the name the implementation plan gives it. A Gate
 * policy answers "may this user do this to this model"; this answers "may this
 * user exercise this permission at all, right now", and it is enforced from
 * `AuthServiceProvider`'s `Gate::before` — ahead of the Super Admin bypass, which
 * is the only ordering that works, because `settings.manage.security` is a
 * permission Super Admin holds and a bypass that ran first would wave through
 * exactly the account this rule exists to protect.
 *
 * Being first in OUR callback is not the same as being first in the Gate, which is
 * why `config/permission.php` sets `register_permission_check_method` to false.
 * Spatie appends its own `Gate::before` — one that answers true for any permission
 * the user holds — when the Gate is first resolved, and package providers boot
 * before the ones in bootstrap/providers.php. With it registered, every check this
 * rule exists to refuse was already answered before the rule was asked, and nothing
 * about the failure looked like an ordering problem: `blocks()` returned true in a
 * test, `can()` returned true a line later, and both were correct.
 *
 * What the rule covers is the PERMISSION, so `can('settings.manage.security')` is
 * refused. A Super Admin asking a model question — `can('update', $setting)` — is
 * answered by the bypass before any policy runs, which is ADR-09's behaviour and
 * not this rule's to change; screens that write privileged settings are gated on
 * the permission, which is the check that is enforced.
 *
 * Enforcement is a refusal, not a data change. W7 says the person who will not
 * enable TOTP keeps everything else: the account stays active, the grant stays on
 * the role, the permission simply stops answering yes. Doing it as a rule rather
 * than by removing grants is what makes it self-reversing — confirming 2FA
 * restores the permission on the next check, with no half-restored role for
 * somebody to notice three weeks later.
 *
 * Nothing here calls `can()`. A `Gate::before` callback that asked the Gate a
 * question would re-enter itself, and the stack overflow would arrive as a 500 on
 * an unrelated page. Permission lookups go straight to spatie's own methods, which
 * read the tables.
 */
final class TwoFactorPolicy
{
    /**
     * The permissions that require a confirmed second factor.
     *
     * Filtered against the registry, so a key left behind in config after a
     * permission is renamed cannot make every holder's authorization check throw.
     *
     * @return list<string>
     *
     * @throws RuntimeException if the configured value is not a list of strings
     */
    public static function requiredPermissions(): array
    {
        $configured = config('platform.security.two_factor.required_for_permissions', []);

        if (! is_array($configured)) {
            // Loud, and not "nothing requires 2FA". A config value of the wrong
            // shape is a broken deployment, and answering with an empty list would
            // turn it into a platform where every privileged account is enforced by
            // nothing — with no error anywhere to say the rule had been switched off.
            throw new RuntimeException(
                'config/platform.php must list platform.security.two_factor.required_for_permissions as an array of permission strings.',
            );
        }

        $known = [];

        foreach ($configured as $permission) {
            if (is_string($permission) && $permission !== '' && Permissions::has($permission)) {
                $known[] = $permission;
            }
        }

        return array_values(array_unique($known));
    }

    /**
     * Whether the platform is enforcing this at all.
     *
     * Read on every call rather than cached in a property: the value is config, an
     * operator changes it by deploying, and a static that remembered the answer
     * from the first request would make a long-lived queue worker enforce a rule
     * the deployment had just turned off.
     */
    public static function isEnforced(): bool
    {
        return (bool) config('platform.security.two_factor.enforce', true);
    }

    /**
     * Whether this check must refuse `$ability` for this user right now.
     *
     * Deliberately does not ask whether the user HOLDS the permission. Somebody who
     * does not hold it is refused by the rest of the Gate anyway, so asking here
     * would be a second table lookup on every authorization check in the
     * application to reach an answer that was already decided.
     */
    public static function blocks(User $user, string $ability): bool
    {
        if (! self::isEnforced() || $user->hasConfirmedTwoFactor()) {
            return false;
        }

        return in_array($ability, self::requiredPermissions(), true);
    }

    /**
     * The privileged permissions this user holds and cannot currently exercise.
     *
     * This is what a banner, a reminder email and an admin report are built from,
     * so unlike blocks() it does ask what the user holds: telling somebody to
     * enable 2FA to keep access they never had is how enforcement gets ignored.
     *
     * @return list<string>
     */
    public static function outstandingFor(User $user): array
    {
        if (self::isEnforced() === false || $user->hasConfirmedTwoFactor()) {
            return [];
        }

        $held = [];

        foreach (self::requiredPermissions() as $permission) {
            try {
                if ($user->hasPermissionTo($permission)) {
                    $held[] = $permission;
                }
            } catch (PermissionDoesNotExist) {
                // The registry declares it, this database does not have it: a deploy
                // that migrated without seeding. Skipping keeps the site up, and
                // `platform:doctor` reports the mismatch as a failure.
                continue;
            }
        }

        return $held;
    }
}
