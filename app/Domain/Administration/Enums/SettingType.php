<?php

declare(strict_types=1);

namespace App\Domain\Administration\Enums;

/**
 * How a settings row's JSON value is cast on read (§60). The type is stored
 * rather than inferred so that "0", 0 and false stay distinguishable, and so
 * SettingsService can reject a value that does not match its declared type
 * instead of silently coercing it.
 */
enum SettingType: string
{
    case Text = 'string';
    case Integer = 'int';
    case Boolean = 'bool';
    case Decimal = 'decimal';
    case Json = 'json';
    case Choice = 'enum';

    /** Human-readable name for admin filters, badges and select options. */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Integer => 'Whole number',
            self::Boolean => 'Yes/no',
            self::Decimal => 'Decimal',
            self::Json => 'Structured data',
            self::Choice => 'One of a fixed set',
        };
    }
}
