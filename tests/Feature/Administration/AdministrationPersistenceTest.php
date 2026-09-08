<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Enums\SettingType;
use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Models\File;
use App\Domain\Administration\Models\Setting;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * The three administrative records the rest of the platform leans on: the files
 * that hold private documents (ADR-06), the settings that keep business values out
 * of code (§30), and the audit trail that makes both defensible (§56).
 *
 * What ties them together is that each one is a claim other code trusts. A file row
 * claims a visibility that the download path acts on. A setting claims a type that
 * callers compare against. An audit entry claims an actor and a moment. In every
 * case the failure mode of getting it wrong is silence: the row still saves, the
 * page still renders, and the claim is simply false.
 */
final class AdministrationPersistenceTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    // ── Files ───────────────────────────────────────────────────────────────

    public function test_a_file_row_cannot_be_built_from_an_array(): void
    {
        // The array below is what a crafted upload request would post, including a
        // visibility of `public` for a document nobody made public. Nothing on this
        // model is mass assignable, so the whole request is refused rather than
        // quietly stripped — a file's visibility is written by the file service
        // after it has checked the caller (§59).
        $this->expectException(MassAssignmentException::class);

        File::create([
            'category' => FileCategory::StudentDocument->value,
            'visibility' => FileVisibility::IsPublic->value,
            'path' => 'documents/report-card.pdf',
            'original_name' => 'report-card.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4096,
            'checksum_sha256' => str_repeat('a', 64),
        ]);
    }

    public function test_no_file_can_reach_the_database_without_a_declared_visibility(): void
    {
        // Deny by default, enforced by the schema rather than by remembering to set
        // a property: `visibility` is NOT NULL with no default, so an incomplete
        // row is refused outright and cannot fall back to being readable.
        $file = new File;
        $file->category = FileCategory::StudentDocument;
        $file->disk = 'local';
        $file->path = 'documents/report-card.pdf';
        $file->original_name = 'report-card.pdf';
        $file->mime_type = 'application/pdf';
        $file->size_bytes = 4096;
        $file->checksum_sha256 = str_repeat('a', 64);

        try {
            $file->save();
            $this->fail('a file with no declared visibility must not be written');
        } catch (QueryException $e) {
            $this->assertStringContainsString('visibility', $e->getMessage());
        }

        $this->assertSame(0, File::count());
    }

    public function test_a_file_is_given_a_uuid_because_its_id_must_not_appear_in_a_url(): void
    {
        $file = $this->makeFile($this->makeUser());

        // Download URLs carry the uuid, not the sequential id, so the route gives
        // nothing away about how many files exist or in what order (§59).
        $this->assertMatchesRegularExpression(self::UUID, $file->uuid);
        $this->assertNotSame((string) $file->id, $file->uuid);
    }

    public function test_deleting_a_file_keeps_the_row_so_a_reference_still_resolves(): void
    {
        $file = $this->makeFile($this->makeUser());
        $file->delete();

        $this->assertNull(File::find($file->id), 'gone from every normal query');
        $this->assertDatabaseHas('files', ['id' => $file->id]);

        $trashed = File::withTrashed()->find($file->id);
        $this->assertNotNull($trashed->deleted_at);

        // A submission, an invoice or a certificate can be evidence. Removing the
        // row while the record that points at it survives would leave a dangling
        // reference and no way to answer "was anything here?" (§58).
        $this->assertTrue($trashed->trashed());
    }

    public function test_a_file_knows_its_owner_its_uploader_and_what_is_in_its_metadata(): void
    {
        $owner = $this->makeUser();
        $uploader = $this->makeUser();

        $file = $this->makeFile($uploader, $owner, [
            'category' => FileCategory::ProfilePhoto,
            'meta' => ['width' => 800, 'height' => 600],
        ])->fresh();

        $this->assertTrue($file->fileable->is($owner), 'the polymorphic owner resolves');
        $this->assertTrue($file->uploader->is($uploader));
        $this->assertSame(['width' => 800, 'height' => 600], $file->meta);
        $this->assertSame(FileCategory::ProfilePhoto, $file->category);
        $this->assertSame(FileVisibility::IsPrivate, $file->visibility);
        $this->assertSame(20480, $file->size_bytes);
        $this->assertFalse($file->isPubliclyVisible());
    }

    // ── Settings ────────────────────────────────────────────────────────────

    public function test_a_setting_key_is_unique(): void
    {
        $this->makeSetting('platform.currency', 'NGN');

        // Two rows for one key means two answers to "what is the currency", and
        // whichever one a query happens to return first wins.
        $this->expectException(QueryException::class);

        $this->makeSetting('platform.currency', 'USD');
    }

    public function test_the_client_exposed_scope_never_returns_a_secret(): void
    {
        $this->makeSetting('organization.name', 'Academy', SettingType::Text, ['is_public' => true, 'group' => 'organization']);
        $this->makeSetting('payments.paystack_secret_key', 'sk_test_not_a_real_key', SettingType::Text, [
            'is_secret' => true,
            'group' => 'payments',
        ]);
        $this->makeSetting('platform.maintenance_notice', 'Down on Sunday', SettingType::Text);

        $keys = Setting::exposedToClient()->pluck('key')->all();

        // §30.7: the payment secret keys live in this table, and the rule is
        // deny-by-default at the presentation boundary. A setting is exposed only
        // when it was declared public AND never declared secret — one flag cannot
        // override the other.
        $this->assertSame(['organization.name'], $keys);
        $this->assertNotContains('payments.paystack_secret_key', $keys);
        $this->assertNotContains('platform.maintenance_notice', $keys, 'unset is not public');
    }

    public function test_the_columns_that_describe_how_a_setting_behaves_cannot_be_posted(): void
    {
        // Flipping `is_public` on a secret publishes it; widening `allowed_values`
        // removes the constraint the value is validated against. Both describe the
        // setting rather than its contents, so both are outside the fillable list.
        $this->expectException(MassAssignmentException::class);

        (new Setting)->fill([
            'group' => 'payments',
            'key' => 'payments.paystack_secret_key',
            'value' => 'sk_test',
            'is_secret' => false,
            'is_public' => true,
            'allowed_values' => ['anything'],
        ]);
    }

    public function test_a_value_comes_back_from_the_database_in_its_declared_type(): void
    {
        // The JSON column stores whatever was written, so the declared type is the
        // only thing that makes a comparison meaningful later.
        $this->assertSame('NGN', $this->makeSetting('platform.currency', 'NGN')->fresh()->typedValue());
        $this->assertSame(3, $this->makeSetting('academic.terms_per_year', '3', SettingType::Integer)->fresh()->typedValue());
        $this->assertTrue($this->makeSetting('platform.registration_open', true, SettingType::Boolean)->fresh()->typedValue());
        $this->assertSame(7.5, $this->makeSetting('commerce.tax_rate', '7.50', SettingType::Decimal)->fresh()->typedValue());

        $scale = [['code' => 'A', 'min' => 70], ['code' => 'B', 'min' => 60]];
        $this->assertSame(
            $scale,
            $this->makeSetting('academic.grade_scale', $scale, SettingType::Json)->fresh()->typedValue()
        );
    }

    public function test_a_choice_setting_validates_against_the_list_it_stored(): void
    {
        $setting = $this->makeSetting('platform.default_video_provider', 'manual', SettingType::Choice, [
            'allowed_values' => ['manual', 'google_meet', 'zoom'],
        ])->fresh();

        // The list drives the admin form and the server-side refusal, so it has to
        // survive the round trip through the JSON column intact.
        $this->assertTrue($setting->allowsValue('google_meet'));
        $this->assertFalse($setting->allowsValue('teams'));
    }

    // ── Audit log ───────────────────────────────────────────────────────────

    public function test_an_entry_is_written_with_nothing_to_update(): void
    {
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'));

        $log = $this->makeAuditLog('settings.updated', actor: $this->makeUser())->fresh();

        $this->assertInstanceOf(Carbon::class, $log->created_at);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }

    public function test_a_system_event_is_recorded_even_though_nobody_caused_it(): void
    {
        // A scheduled job expiring a subscription, or a webhook completing an
        // order, is exactly the event most worth having a record of — and it has no
        // actor, so `user_id` has to be nullable rather than a required field that
        // gets filled with a convenient administrator.
        $log = $this->makeAuditLog('subscriptions.expired');

        $this->assertNull($log->user_id);
        $this->assertNull($log->fresh()->user);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'user_id' => null]);
    }

    public function test_an_entry_reaches_the_record_it_refers_to_whatever_that_record_is(): void
    {
        $subject = $this->makeUser();

        $log = $this->makeAuditLog('users.status_changed', subject: $subject)->fresh();

        $this->assertTrue($log->auditable->is($subject));
    }

    public function test_events_can_be_listed_by_domain_using_their_dotted_names(): void
    {
        $this->makeAuditLog('orders.created');
        $this->makeAuditLog('orders.refunded');
        $this->makeAuditLog('tutors.approved');

        $this->assertSame(2, AuditLog::ofEventPrefix('orders.')->count());
        $this->assertSame(1, AuditLog::ofEvent('tutors.approved')->count());
        $this->assertNotContains('tutors.approved', AuditLog::ofEventPrefix('orders.')->pluck('event')->all());
    }

    public function test_an_entry_cannot_be_edited_once_it_is_written(): void
    {
        $log = $this->makeAuditLog('users.status_changed', actor: $this->makeUser());

        $log->event = 'users.nothing_happened';

        try {
            $log->save();
            $this->fail('an audit entry must not be editable');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame('users.status_changed', AuditLog::find($log->id)->event);
    }

    public function test_an_entry_cannot_be_deleted_one_at_a_time(): void
    {
        $log = $this->makeAuditLog('users.status_changed');

        try {
            $log->delete();
            $this->fail('removing one audit entry is tampering');
        } catch (LogicException $e) {
            $this->assertStringContainsString('tampering', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_retention_pruning_still_removes_an_old_range_in_bulk(): void
    {
        $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subYears(2)]);
        $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subYear()]);
        $this->makeAuditLog('orders.created');

        // The distinction the design turns on: a bulk query-builder delete never
        // instantiates a model, so it never reaches the guard. Retention is a
        // policy about a range of time; tampering is about one record. Only the
        // first is permitted, and it has to keep working or the table grows
        // forever on shared hosting.
        $removed = AuditLog::where('created_at', '<', Carbon::now()->subMonths(18))->delete();

        $this->assertSame(1, $removed);
        $this->assertSame(2, AuditLog::count());
    }
}
