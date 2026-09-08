<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Why the account is linked. One Google identity can be linked once to sign in
 * and once to provision Meet rooms, with different scopes and different tokens,
 * so the unique key is (user, provider, purpose) — not (user, provider).
 */
enum ConnectedPurpose: string
{
    case Login = 'login';
    case Meetings = 'meetings';
    case Calendar = 'calendar';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::Login => 'Sign-in',
            self::Meetings => 'Meetings',
            self::Calendar => 'Calendar',
        };
    }
}
