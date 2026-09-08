<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Lifecycle of an authentication subject (docs/02 §3.1, docs/03 §2).
 *
 * `pending` is a real state, not a placeholder: a self-registered user who has
 * not verified their email can authenticate but must not appear in directory
 * listings or be granted portal access. `suspended` is reversible and keeps the
 * record; `deactivated` is the terminal state used instead of deletion, because
 * academic and financial records reference the user with ON DELETE RESTRICT.
 */
enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting verification',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Deactivated => 'Deactivated',
        };
    }
}
