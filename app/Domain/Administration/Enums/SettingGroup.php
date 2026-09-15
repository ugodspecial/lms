<?php

declare(strict_types=1);

namespace App\Domain\Administration\Enums;

/**
 * The tab a setting lives under in the admin screen — and the permission that
 * unlocks it (§60, §61, docs/01 §5.3, docs/05 §3.9).
 *
 * Groups are not cosmetic. Three of them hold values whose compromise is
 * platform-wide rather than departmental, so the registry grants a *separate*
 * permission for each — `settings.manage.security`, `settings.manage.payments`,
 * `settings.manage.integrations`. A Finance Officer who may set the tax rate must
 * not thereby be able to change the session lifetime or the payment callback
 * mode, and an administrator who may configure video providers must not thereby
 * be able to relax password policy.
 *
 * `operations` exists because docs/05 §3.9 scopes the Operations Officer's
 * `settings.view` / `settings.manage` grants to it. A scoped grant that names no
 * real group is a permission nobody can ever exercise, which is worse than not
 * granting it: the matrix would claim the role can manage settings while the
 * policy said no to every setting that exists. Support-facing configuration —
 * maintenance windows, support contact, download rate limits — belongs there.
 *
 * The group is stored as this enum's string value and constrained by a CHECK in
 * the migration, so a typo'd group name fails at write time instead of quietly
 * creating a tab no permission covers (docs/04 §0: VARCHAR + PHP enum + CHECK).
 */
enum SettingGroup: string
{
    case Organization = 'organization';
    case Academic = 'academic';
    case Commerce = 'commerce';
    case Payments = 'payments';
    case Video = 'video';
    case Notifications = 'notifications';
    case Security = 'security';
    case Operations = 'operations';

    /**
     * The permission that covers groups with no elevated grant of their own.
     *
     * This is the one permission docs/05 §3.9 scopes per role, which is why
     * SettingPolicy treats holding it as a question rather than an answer.
     */
    public const BASE_PERMISSION = 'settings.manage';

    /** The permission a write to this group must be authorized against. */
    public const VIEW_PERMISSION = 'settings.view';

    /** Human-readable tab label for the admin settings screen. */
    public function label(): string
    {
        return match ($this) {
            self::Organization => 'Organisation',
            self::Academic => 'Academic',
            self::Commerce => 'Commerce',
            self::Payments => 'Payments',
            self::Video => 'Video & meetings',
            self::Notifications => 'Notifications',
            self::Security => 'Security',
            self::Operations => 'Operations',
        };
    }

    public function requiredPermission(): string
    {
        return match ($this) {
            self::Payments => 'settings.manage.payments',
            self::Security => 'settings.manage.security',
            // Meeting defaults and provider selection are integration
            // configuration wearing a different tab label.
            self::Video => 'settings.manage.integrations',
            default => self::BASE_PERMISSION,
        };
    }

    /**
     * Whether holding {@see requiredPermission()} still leaves work to do.
     *
     * The three elevated permissions are granted whole: no role holds a
     * qualified version of `settings.manage.security`. Plain `settings.manage`
     * is different — docs/05 §3.9 grants it to Academic Admin, Finance Officer
     * and Operations Officer *for one group each*, and SettingPolicy has to
     * enforce the qualifier rather than treat the grant as global.
     */
    public function isRoleScoped(): bool
    {
        return $this->requiredPermission() === self::BASE_PERMISSION;
    }

    /**
     * Groups whose values decide how the platform protects itself, and which
     * therefore also gate reads of the current value in the admin UI.
     *
     * Kept as a question rather than a flag on each case so the answer is in one
     * place when the next sensitive group is added.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::Payments, self::Security, self::Video => true,
            default => false,
        };
    }
}
