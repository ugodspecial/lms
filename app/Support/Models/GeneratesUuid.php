<?php

declare(strict_types=1);

namespace App\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Assigns a UUID on creation for models whose table has one.
 *
 * Why not Laravel's `HasUuids`: that trait makes the UUID the *primary key*.
 * docs/04 §0 chooses `BIGINT UNSIGNED AUTO_INCREMENT` primary keys for InnoDB
 * index locality on shared hosting, and adds a separate `uuid CHAR(36)` only
 * where a record is referenced in a URL or by an external system. Both are
 * wanted, and `HasUuids` gives only one.
 *
 * The UUID is generated in the model rather than by a database default because
 * MySQL has no `DEFAULT (UUID())` that is stable across versions and replication
 * setups, and because a value the application controls can be set explicitly —
 * which is what makes an idempotent import or a replayed webhook able to reuse
 * the same UUID instead of minting a second row.
 */
trait GeneratesUuid
{
    public static function bootGeneratesUuid(): void
    {
        static::creating(function (Model $model): void {
            $column = $model->getUuidColumn();

            if (empty($model->getAttribute($column))) {
                $model->setAttribute($column, (string) Str::uuid());
            }
        });
    }

    /** The column that carries the UUID. Overridable if a table names it differently. */
    public function getUuidColumn(): string
    {
        return 'uuid';
    }
}
