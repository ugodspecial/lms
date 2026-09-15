<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Enums\UserStatus;

/**
 * What may follow what in a user's lifecycle (docs/02 §1, docs/04 §11).
 *
 * Four states, and the reason they are written down as a table instead of as `if`
 * statements inside whatever controller happens to need one is that an illegal
 * change then cannot be expressed rather than merely being untested.
 *
 * `deactivated` has no successors at all. It is the state this platform uses
 * INSTEAD of deletion, because academic and financial rows reference `users` with
 * ON DELETE RESTRICT — so a deactivated account that could quietly become active
 * again would be a resurrection nobody authorized and nobody audited. Bringing
 * somebody back after erasure is a deliberate act that has to be written as a new
 * account, which is the point.
 *
 * `pending` cannot be suspended. Suspension is a response to conduct by somebody
 * who can act; a person who has not verified an email address cannot enrol, book,
 * buy or upload anything, so there is nothing to stop — and an administrator who
 * needs to close such an account has `deactivated` for it.
 */
final class UserStatusTransitions
{
    /**
     * Successor states, keyed by the state they follow.
     *
     * @var array<string, list<string>>
     */
    private const SUCCESSORS = [
        'pending' => ['active', 'deactivated'],
        'active' => ['suspended', 'deactivated'],
        'suspended' => ['active', 'deactivated'],
        'deactivated' => [],
    ];

    public static function allows(UserStatus $from, UserStatus $to): bool
    {
        return in_array($to->value, self::SUCCESSORS[$from->value] ?? [], true);
    }

    /**
     * The states a user in `$from` may move to, as the enums themselves.
     *
     * Returned as enums rather than strings because the two callers — the refusal
     * message and the admin change-status form — both need the cases, and a form
     * built from raw strings is a form that can post a value the enum will reject.
     *
     * @return list<UserStatus>
     */
    public static function allowedFrom(UserStatus $from): array
    {
        $successors = self::SUCCESSORS[$from->value] ?? [];

        return array_values(array_filter(
            UserStatus::cases(),
            static fn (UserStatus $case): bool => in_array($case->value, $successors, true),
        ));
    }

    /**
     * Whether nothing may follow this state.
     */
    public static function isTerminal(UserStatus $status): bool
    {
        return (self::SUCCESSORS[$status->value] ?? []) === [];
    }
}
