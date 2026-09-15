<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * External systems a user can link (docs/03 §2, §36, §37). Zoom belongs here
 * rather than under Integration because the row is a *user's* linked account and
 * carries that user's OAuth tokens.
 */
enum ConnectedProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case Zoom = 'zoom';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Microsoft => 'Microsoft',
            self::Zoom => 'Zoom',
        };
    }
}
