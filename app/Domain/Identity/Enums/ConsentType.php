<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Privacy and marketing consents (§58, §83). Recorded append-only with the
 * version of the document consented to, the IP and the user agent, because the
 * evidential value is that *this* person accepted *that* wording at *that* time.
 *
 * `marketing` is opt-in and defaults to false — never bundled with acceptance of
 * the terms or the privacy policy.
 */
enum ConsentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Marketing = 'marketing';
    case DataProcessing = 'data_processing';
    case PhotoRelease = 'photo_release';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::Terms => 'Terms of service',
            self::Privacy => 'Privacy policy',
            self::Marketing => 'Marketing communications',
            self::DataProcessing => 'Data processing',
            self::PhotoRelease => 'Photo release',
        };
    }
}
