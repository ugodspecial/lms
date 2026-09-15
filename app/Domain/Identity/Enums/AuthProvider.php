<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * How the user last authenticated. Recorded so an OAuth-only account is not
 * offered a password reset it cannot use, and so a password account that later
 * links Google is still recognised as password-capable.
 */
enum AuthProvider: string
{
    case Password = 'password';
    case Google = 'google';
    case Microsoft = 'microsoft';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::Password => 'Password',
            self::Google => 'Google',
            self::Microsoft => 'Microsoft',
        };
    }
}
