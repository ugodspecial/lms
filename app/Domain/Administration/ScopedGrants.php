<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use App\Domain\Identity\Models\User;

/**
 * Reads the qualifications `Permissions::SCOPED` records, so a policy can tell
 * *granted* from *sufficient* (docs/05 §3.9).
 *
 * The registry says things like: a Parent holds `students.view` for "own
 * children"; an Academic Admin holds `settings.manage` for the `academic` group; a
 * Finance Officer holds `settings.manage.payments` as "read + test-mode only". A
 * flat `$user->can()` throws all of that away, and the result is the two opposite
 * failures this class exists to prevent — an administrator who can reach data
 * belonging to another department, and a role the matrix says is permitted getting
 * a 403 it cannot explain.
 *
 * It is one class rather than a loop in each policy because the interpretation has
 * to be identical everywhere. Two policies reading the same registry slightly
 * differently is not a bug anybody can see: both look correct in isolation, and
 * the disagreement only appears as an inconsistent answer between two screens.
 *
 * The answers come from the roles the user holds and the registry's own record of
 * what each grant means — not from the database's permission rows. That is
 * deliberate. A qualifier is a property of a ROLE ("a Parent sees own children"),
 * so it is read from where roles are described, and it stays correct if an
 * administrator grants a permission to somebody ad hoc. Callers check
 * `$user->can($permission)` themselves first; this class answers the follow-up
 * question of how far that grant reaches.
 *
 * Somebody who holds a permission twice — once qualified, once not — is answered by
 * the broader grant. Being promoted from Academic Admin to Administrator must not
 * quietly narrow what they can reach.
 */
final class ScopedGrants
{
    /**
     * Whether one of the user's roles holds this permission with no qualifier.
     *
     * True means "all of it": there is no scope to enforce, because the registry
     * records none for that role.
     */
    public static function holdsUnscoped(User $user, string $permission): bool
    {
        $notes = self::qualifiers($permission);

        foreach (self::heldRoles($user, $permission) as $role) {
            if (! array_key_exists($role, $notes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user holds the permission, but only ever with a qualifier.
     *
     * This is the case where a policy has to do something other than allow: the
     * Finance Officer and payment settings, where the qualifier is a rule about
     * test mode rather than a subset of rows.
     */
    public static function holdsOnlyQualified(User $user, string $permission): bool
    {
        $notes = self::qualifiers($permission);
        $holds = false;

        foreach (self::heldRoles($user, $permission) as $role) {
            $holds = true;

            if (! array_key_exists($role, $notes)) {
                return false;
            }
        }

        return $holds;
    }

    /**
     * The qualifiers attached to the roles through which the user holds this
     * permission, in role order and without duplicates.
     *
     * What a qualifier MEANS is the permission's own business: a group name for
     * `settings.manage`, "own children" for `students.view`, "own uploads" for
     * `files.delete`. This class does not interpret them, because interpreting them
     * is the policy's rule and the registry is only where the rule is written down.
     *
     * @return list<string>
     */
    public static function qualifiersFor(User $user, string $permission): array
    {
        $notes = self::qualifiers($permission);
        $qualifiers = [];

        foreach (self::heldRoles($user, $permission) as $role) {
            if (isset($notes[$role])) {
                $qualifiers[] = $notes[$role];
            }
        }

        return array_values(array_unique($qualifiers));
    }

    /**
     * Whether a permission is qualified for anybody at all — the check that lets a
     * policy skip the scope question entirely for a permission the registry grants
     * whole.
     */
    public static function isScopedForSomebody(string $permission): bool
    {
        return self::qualifiers($permission) !== [];
    }

    /**
     * @return array<string, string> role name => qualifier
     */
    private static function qualifiers(string $permission): array
    {
        $notes = Permissions::SCOPED[$permission] ?? [];

        return is_array($notes) ? $notes : [];
    }

    /**
     * The roles this user holds that the registry grants the permission to, with
     * every alias replaced by the role whose qualifiers it reads against.
     *
     * @return list<string>
     */
    private static function heldRoles(User $user, string $permission): array
    {
        $grantedTo = Roles::rolesWith($permission);
        $held = [];

        // getRoleNames() returns a Collection whose values are not typed at this
        // boundary, and role names are used as array keys against the registry, so
        // they are narrowed once here rather than at every lookup.
        foreach ($user->getRoleNames() as $role) {
            if (! is_string($role) || ! in_array($role, $grantedTo, true)) {
                continue;
            }

            // REPLACED, not added alongside. An Instructor carries the Tutor
            // permission shape (Roles::QUALIFIER_ALIASES) and Permissions::SCOPED
            // writes the qualifier against 'Tutor'. Counting both names would leave
            // the alias in the list, and an alias has no qualifier of its own — so
            // it reads as "this role holds the permission with no scope at all",
            // which is exactly the wrong answer: 'own uploads' becomes 'any file',
            // with no exception and nothing in the log to say it happened.
            $primary = Roles::QUALIFIER_ALIASES[$role] ?? null;

            $held[] = is_string($primary) && in_array($primary, $grantedTo, true)
                ? $primary
                : $role;
        }

        return array_values(array_unique($held));
    }
}
