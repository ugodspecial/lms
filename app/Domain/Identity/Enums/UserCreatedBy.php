<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Provenance of the account. An admin-created or imported account has no
 * password and no verification, so its first-login flow differs; an OAuth
 * account arrived from a provider. Without this the UI cannot tell an unfinished
 * registration from a deliberately passwordless one.
 */
enum UserCreatedBy: string
{
    case SelfRegistered = 'self';
    case ByAdmin = 'admin';
    case ByOAuth = 'oauth';
    case ByImport = 'import';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::SelfRegistered => 'Self-registered',
            self::ByAdmin => 'Created by an administrator',
            self::ByOAuth => 'Created via OAuth',
            self::ByImport => 'Imported',
        };
    }
}
