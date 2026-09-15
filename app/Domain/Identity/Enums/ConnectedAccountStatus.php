<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * State of the link. `expired` is distinct from `revoked`: an expired token may
 * be refreshed silently, a revoked one must be re-authorised by the user. The UI
 * must not present either as a working connection (§63).
 */
enum ConnectedAccountStatus: string
{
    case Connected = 'connected';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
            self::Error => 'Error',
        };
    }
}
