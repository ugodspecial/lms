<?php

declare(strict_types=1);

namespace App\Domain\Administration\Enums;

/**
 * The four storage tiers (§59, ADR-10). Each maps to a filesystem disk in
 * config/filesystems.php and to a different authorization path in
 * DownloadAuthorizer. `public` is symlinked and served by the web server; the
 * other three have `serve => false` and are streamed only through an authorized
 * route, so a guessed URL returns 404 rather than a file.
 */
enum FileVisibility: string
{
    case IsPublic = 'public';
    case IsAuthenticated = 'authenticated';
    case IsPrivate = 'private';
    case IsRestricted = 'restricted';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::IsPublic => 'Public',
            self::IsAuthenticated => 'Signed-in users',
            self::IsPrivate => 'Owner and staff',
            self::IsRestricted => 'Explicitly authorized',
        };
    }

    /**
     * How much protection this tier asserts, least to most.
     *
     * A ranking rather than a set of flags, because the rule FileService enforces
     * is an ordering: FileCategory declares the LEAST protective tier its files may
     * be stored under, and a row may always be more protective than that, never
     * less. Comparing ranks is what makes "a student document may not be public"
     * one line instead of a table of special cases.
     */
    public function rank(): int
    {
        return match ($this) {
            self::IsPublic => 0,
            self::IsAuthenticated => 1,
            self::IsPrivate => 2,
            self::IsRestricted => 3,
        };
    }
}
