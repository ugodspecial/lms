<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Administration;

use App\Domain\Administration\Models\AuditLog;
use Tests\TestCase;

/**
 * The shape of an audit entry (§56).
 *
 * The absence of `updated_at` is asserted here rather than left to the migration,
 * because it is a property of the trail and not of the table: an entry that
 * records when it was last modified is an entry that admits it was modified.
 *
 * The guards that stop an entry being edited or deleted once written are tested
 * against real rows in AdministrationPersistenceTest, along with the retention
 * path that has to stay open.
 */
final class AuditLogModelTest extends TestCase
{
    public function test_the_model_never_writes_an_updated_at_column(): void
    {
        $this->assertNull(AuditLog::UPDATED_AT);
        $this->assertNull((new AuditLog)->getUpdatedAtColumn());
    }

    public function test_created_at_is_still_stamped_while_updated_at_is_not_a_column_at_all(): void
    {
        // Timestamps stay on: an entry with no time is useless. Only the second
        // column is removed, and Eloquent asks for its name before writing it —
        // a null name is what stops an insert reaching a column that does not exist.
        $log = new AuditLog;
        $log->event = 'subscriptions.expired';

        $this->assertTrue($log->usesTimestamps());
        $this->assertSame('created_at', $log->getCreatedAtColumn());
        $this->assertNull($log->getUpdatedAtColumn());
    }

    public function test_nothing_on_an_audit_entry_is_mass_assignable(): void
    {
        // An entry is written by the auditing layer, which is the only thing that
        // knows what happened. Fillable here would let any code path compose its
        // own history, including a false actor.
        $log = new AuditLog;

        $this->assertSame([], $log->getFillable());
        $this->assertTrue($log->totallyGuarded());
    }

    public function test_tags_are_stored_as_a_list_but_read_as_one(): void
    {
        $log = new AuditLog;
        $log->tags = 'security, finance ,, gdpr';

        $this->assertSame(['security', 'finance', 'gdpr'], $log->tagList());
    }

    public function test_an_untagged_entry_reads_as_an_empty_list_not_as_one_blank_tag(): void
    {
        foreach ([null, '', ',,,'] as $tags) {
            $log = new AuditLog;
            $log->tags = $tags;

            $this->assertSame([], $log->tagList(), var_export($tags, true).' must yield no tags');
        }
    }
}
