<?php

declare(strict_types=1);

namespace App\Domain\Administration\Policies;

use App\Domain\Administration\Enums\SettingGroup;
use App\Domain\Administration\Models\Setting;
use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Models\User;

/**
 * Who may see and change a setting (§60, §61, docs/05 §3.9).
 *
 * The interesting part is not the permission check — it is that holding the
 * permission is not the whole answer. docs/05 §3.9 records three qualifications
 * that a flat `can('settings.manage')` would throw away, and this policy is
 * where they are honoured:
 *
 *   settings.view / settings.manage
 *       Academic Admin      → the `academic` group only
 *       Finance Officer     → the `commerce` group only
 *       Operations Officer  → the `operations` group only
 *   settings.manage.payments
 *       Finance Officer     → "read + test-mode only"
 *
 * `Permissions::SCOPED` is the registry of exactly those qualifications, kept
 * separate from the grants so that a permission can be true and still not be
 * sufficient. Reading the qualifier from the registry rather than hard-coding it
 * here means the matrix stays the single source of truth: change a role's scope
 * in one array and the policy follows, and `platform:audit-authorization`
 * (Phase 11) can compare the two without parsing prose.
 *
 * A role that holds the permission with NO qualifier holds it outright — that is
 * the Administrator, and it is why the loop below returns true on the first
 * unqualified grant rather than intersecting scopes. Somebody who is both an
 * Administrator and an Academic Admin is not demoted by the second role.
 *
 * Super Admin never reaches this class: `Gate::before` in AuthServiceProvider
 * returns true for every ability that is not participation-only. That is the one
 * place a role name appears in authorization logic, and it is deliberate.
 *
 * Reading a setting's VALUE is a separate question from reading the setting, and
 * is answered by SettingsService, not here: `is_secret` rows are filtered out of
 * every read path, so an administrator who may view the payments group still
 * cannot see a secret value through `setting()`. Knowing that a secret exists is
 * not knowing what it is.
 */
final class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(SettingGroup::VIEW_PERMISSION);
    }

    public function view(User $user, Setting $setting): bool
    {
        $group = $setting->group;

        // An elevated grant carries its own read. docs/05 §3.9 gives the Finance
        // Officer `settings.manage.payments` as "read + test-mode only", and the
        // read half cannot depend on their `settings.view` grant, which is scoped
        // to `commerce` — otherwise the qualification would forbid the very thing
        // it promises. Only the elevated groups get this: for a role-scoped group
        // the general permission is what they hold, so it says nothing about reach.
        if (! $group->isRoleScoped() && $user->can($group->requiredPermission())) {
            return true;
        }

        if (! $user->can(SettingGroup::VIEW_PERMISSION)) {
            return false;
        }

        return $this->withinScope($user, SettingGroup::VIEW_PERMISSION, $group);
    }

    public function update(User $user, Setting $setting): bool
    {
        $group = $setting->group;
        $required = $group->requiredPermission();

        if (! $user->can($required)) {
            return false;
        }

        // "read + test-mode only" is what the registry says a Finance Officer's
        // payment-settings grant means. There is no per-setting flag for it, and
        // inventing one would put the decision in the data instead of the rule —
        // so the rule reads the platform's own payment mode: while the platform
        // is live, a qualified grant cannot write. Test mode is where an officer
        // configures a key or a callback URL; live mode is where a mistake moves
        // real money (§30.7, §34).
        if ($group === SettingGroup::Payments
            && $this->holdsOnlyQualified($user, $required)
            && config('services.paystack.mode') === 'live') {
            return false;
        }

        // An elevated permission is granted whole — no role holds a qualified
        // `settings.manage.security` — so holding it settles the question.
        if (! $group->isRoleScoped()) {
            return true;
        }

        return $this->withinScope($user, $required, $group);
    }

    /**
     * Nobody may add or remove a setting through the application.
     *
     * The registry of settings — which keys exist, what type they hold, whether
     * they are secret or public, what a choice may be — is code. It is reviewed
     * and shipped, because those attributes decide how a value behaves and who
     * may see it; letting a form create them would let the same form widen
     * `allowed_values` or clear `is_secret` one request later. See Setting's
     * fillable list and UpdateSetting.
     *
     * Super Admin is the exception, and it comes from `Gate::before`, not from
     * here.
     */
    public function create(User $user): false
    {
        return false;
    }

    public function delete(User $user, Setting $setting): false
    {
        return false;
    }

    public function restore(User $user, Setting $setting): false
    {
        return false;
    }

    public function forceDelete(User $user, Setting $setting): false
    {
        return false;
    }

    /**
     * Whether one of the user's roles grants this permission for this group.
     *
     * @param  string  $permission  a permission listed in Permissions::SCOPED
     */
    private function withinScope(User $user, string $permission, SettingGroup $group): bool
    {
        $notes = Permissions::SCOPED[$permission] ?? [];
        $grantedTo = Roles::rolesWith($permission);

        foreach ($this->roleNames($user) as $role) {
            if (! in_array($role, $grantedTo, true)) {
                continue;
            }

            // No qualifier recorded for this role means the grant is not scoped.
            if (! array_key_exists($role, $notes)) {
                return true;
            }

            if ($notes[$role] === $group->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The names of the roles this user holds.
     *
     * `getRoleNames()` hands back a Collection whose values are not typed at this
     * boundary, and role names are used as array keys against the registry below —
     * so they are narrowed once, here, rather than at each lookup.
     *
     * @return list<string>
     */
    private function roleNames(User $user): array
    {
        $names = [];

        foreach ($user->getRoleNames() as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Whether every role through which the user holds a permission holds it with
     * a qualifier — that is, whether the qualification applies to them at all.
     *
     * Returns false for somebody who also holds the permission unqualified
     * (a Finance Officer who is later made an Administrator), because the broader
     * grant is the one that describes what they may do.
     */
    private function holdsOnlyQualified(User $user, string $permission): bool
    {
        $notes = Permissions::SCOPED[$permission] ?? [];
        $grantedTo = Roles::rolesWith($permission);
        $holds = false;

        foreach ($this->roleNames($user) as $role) {
            if (! in_array($role, $grantedTo, true)) {
                continue;
            }

            $holds = true;

            if (! array_key_exists($role, $notes)) {
                return false;
            }
        }

        return $holds;
    }
}
