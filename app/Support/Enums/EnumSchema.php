<?php

declare(strict_types=1);

namespace App\Support\Enums;

use BackedEnum;

/**
 * Bridge between a PHP enum and the database CHECK constraint that mirrors it.
 *
 * docs/04 §0 stores enums as VARCHAR + a PHP enum + a CHECK constraint rather
 * than as a MySQL ENUM, so adding a case never needs `ALTER TABLE … MODIFY` on a
 * large table. That only holds if the constraint's value list is *derived* from
 * the enum: a hand-written IN-list silently drifts the first time someone adds a
 * case and forgets the migration.
 *
 * These helpers are used from migrations, which is why they take a class-string
 * rather than being a trait on the enums themselves — a trait would put schema
 * vocabulary into domain classes that have no business knowing about SQL.
 */
final class EnumSchema
{
    /**
     * Every case value, in declaration order.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return list<string>
     */
    public static function values(string $enum): array
    {
        $values = [];

        /** @var BackedEnum $case */
        foreach ($enum::cases() as $case) {
            $values[] = (string) $case->value;
        }

        return $values;
    }

    /**
     * The values as a quoted, comma-separated list for `CHECK (col IN (...))`.
     *
     * Single quotes are doubled, which is the SQL escape. Enum values are
     * declared in code and never come from user input, so this is not a
     * sanitisation boundary — it is just correct quoting.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function sqlList(string $enum): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            self::values($enum)
        ));
    }

    /**
     * A full CHECK clause, with a stable constraint name so `down()` and any
     * later ALTER can reference it deterministically.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function check(string $table, string $column, string $enum): string
    {
        return "CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN (".self::sqlList($enum).'))';
    }
}
