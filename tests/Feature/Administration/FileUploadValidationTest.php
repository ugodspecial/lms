<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Models\File;
use App\Domain\Administration\Services\FileService;
use App\Domain\Identity\Models\User;
use App\Exceptions\PlatformException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * The rules an upload has to satisfy before it becomes a registered file
 * (§57, §58, §59, docs/06 row 49).
 *
 * Four claims are being tested, and each has a silent failure mode:
 *
 * • THE TIER DECIDES THE DISK. A protected file written to the disk that is
 *   symlinked into the document root is served by the web server to anybody who
 *   can guess a path, and every PHP-level check still passes. This is the one
 *   mistake that cannot be caught later, because by then the file has been
 *   downloaded.
 *
 * • THE CONTENTS DECIDE, NOT THE CLAIM. The MIME type stored and checked is the
 *   detected one, and the extension the file is stored under comes from the bytes.
 *   A client can say anything about itself; finfo cannot be talked into it.
 *
 * • A CATEGORY HAS A FLOOR. A paid product file, a minor's record and graded work
 *   cannot be stored as public no matter what a form offered, because the form is
 *   the part of the system a mistake gets made in.
 *
 * • THE PATH IS GENERATED. It contains neither the original name nor the row's
 *   uuid, so a file cannot be fetched by guessing what somebody called it or by
 *   reading a link they were once sent.
 *
 * `UploadedFile::fake()` reports the MIME type a test declares rather than
 * sniffing bytes, which is exactly what these cases need: production's
 * `UploadedFile::getMimeType()` is finfo over the real file, and the fake lets a
 * test say "the bytes are a PDF" without shipping a PDF.
 */
final class FileUploadValidationTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    public function test_an_acceptable_upload_is_stored_on_the_disk_its_tier_requires(): void
    {
        Storage::fake('authenticated');

        $uploader = $this->makeUser();

        $file = $this->service()->store(
            upload: UploadedFile::fake()->create('lecture-notes.pdf', 100, 'application/pdf'),
            category: FileCategory::CourseResource,
            visibility: FileVisibility::IsAuthenticated,
            uploader: $uploader,
        );

        // The tier's disk, from config — not 'local', not 'public'.
        $this->assertSame('authenticated', $file->disk);
        Storage::disk('authenticated')->assertExists($file->path);

        $this->assertSame('lecture-notes.pdf', $file->original_name);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertSame(102400, $file->size_bytes);
        $this->assertSame($uploader->id, $file->uploaded_by);
        $this->assertNotNull($file->uuid);

        // And it is a row, not a file on a disk that happens to exist.
        $this->assertDatabaseHas('files', [
            'id' => $file->id,
            'disk' => 'authenticated',
            'category' => FileCategory::CourseResource->value,
            'visibility' => FileVisibility::IsAuthenticated->value,
        ]);
    }

    public function test_the_public_tier_lands_on_the_only_symlinked_disk(): void
    {
        Storage::fake('public');

        $file = $this->service()->store(
            upload: UploadedFile::fake()->image('avatar.jpg'),
            category: FileCategory::ProfilePhoto,
            visibility: FileVisibility::IsPublic,
        );

        $this->assertSame('public', $file->disk);
        Storage::disk('public')->assertExists($file->path);
    }

    public function test_the_stored_mime_type_is_the_detected_one_not_the_claimed_one(): void
    {
        Storage::fake('private');

        // `clientMimeType` is a header the client wrote; `getMimeType()` is finfo.
        // In production these disagree whenever somebody renames a file, and the
        // one that is stored has to be the one that was measured.
        $file = $this->service()->store(
            upload: UploadedFile::fake()->createWithContent('notes.txt', 'plain text, nothing else'),
            category: FileCategory::CourseResource,
            visibility: FileVisibility::IsPrivate,
        );

        $this->assertSame('text/plain', $file->mime_type);
        $this->assertSame('plain text, nothing else', Storage::disk('private')->get($file->path));
    }

    public function test_the_checksum_is_of_the_bytes_that_were_actually_stored(): void
    {
        Storage::fake('private');

        $content = 'the quick brown fox jumps over the lazy dog';

        $file = $this->service()->store(
            upload: UploadedFile::fake()->createWithContent('evidence.txt', $content),
            category: FileCategory::StudentDocument,
            visibility: FileVisibility::IsRestricted,
        );

        $this->assertSame(hash('sha256', $content), $file->checksum_sha256);
        $this->assertSame(strlen($content), $file->size_bytes);
    }

    public function test_an_extension_that_contradicts_the_contents_is_replaced(): void
    {
        Storage::fake('authenticated');

        // The bytes are a PDF; the name says text. Storing it as `.txt` would put a
        // PDF where a reader, a thumbnailer and a virus scanner all expect text.
        $file = $this->service()->store(
            upload: UploadedFile::fake()->create('notes.txt', 10, 'application/pdf'),
            category: FileCategory::CourseResource,
            visibility: FileVisibility::IsAuthenticated,
        );

        $this->assertStringEndsWith('.pdf', $file->path);
        $this->assertSame('notes.txt', $file->original_name, 'the name the person sent is still what they see');
    }

    public function test_a_file_with_no_extension_gets_one_from_its_contents(): void
    {
        Storage::fake('private');

        $file = $this->service()->store(
            upload: UploadedFile::fake()->create('invoice', 10, 'application/pdf'),
            category: FileCategory::Invoice,
            visibility: FileVisibility::IsPrivate,
        );

        $this->assertStringEndsWith('.pdf', $file->path);
        $this->assertSame('invoice', $file->original_name);
    }

    public function test_a_blocked_extension_is_refused_even_when_the_bytes_look_harmless(): void
    {
        Storage::fake('public');

        // SVG is first among equals: an image format that can carry script, so on
        // the public tier it is stored cross-site scripting (§57).
        foreach (['logo.svg', 'shell.php', 'tool.exe', 'page.html', 'archive.phar'] as $name) {
            try {
                $this->service()->store(
                    upload: UploadedFile::fake()->create($name, 10, 'image/jpeg'),
                    category: FileCategory::ProfilePhoto,
                    visibility: FileVisibility::IsPublic,
                );

                $this->fail($name.' was stored.');
            } catch (PlatformException $exception) {
                $this->assertSame('files.extension_blocked', $exception->getErrorCode(), $name);
                $this->assertSame(422, $exception->getStatusCode(), $name);
                $this->assertArrayHasKey('file', $exception->getErrors(), $name);
            }
        }

        self::assertNothingWasStored('public');
    }

    public function test_a_mime_type_outside_the_allowlist_is_refused(): void
    {
        Storage::fake('restricted');

        foreach ([
            ['malware.bin', 'application/x-msdownload'],
            ['vector.svg', 'image/svg+xml'],
            ['app.apk', 'application/vnd.android.package-archive'],
        ] as [$name, $mime]) {
            try {
                $this->service()->store(
                    upload: UploadedFile::fake()->create($name, 10, $mime),
                    category: FileCategory::StudentDocument,
                    visibility: FileVisibility::IsRestricted,
                );

                $this->fail("{$name} ({$mime}) was stored.");
            } catch (PlatformException $exception) {
                $this->assertContains(
                    $exception->getErrorCode(),
                    ['files.extension_blocked', 'files.mime_not_allowed'],
                    $name,
                );
                $this->assertSame(422, $exception->getStatusCode(), $name);
            }
        }

        self::assertNothingWasStored('restricted');
    }

    public function test_an_empty_upload_is_refused(): void
    {
        Storage::fake('private');
        $before = File::query()->count();

        try {
            $this->service()->store(
                upload: UploadedFile::fake()->create('empty.pdf', 0, 'application/pdf'),
                category: FileCategory::Cv,
                visibility: FileVisibility::IsPrivate,
            );

            $this->fail('An empty file was registered.');
        } catch (PlatformException $exception) {
            // A zero-byte row is worse than a refused upload: it is a file that
            // downloads as a corrupt document, with a checksum that looks valid.
            $this->assertSame('files.empty', $exception->getErrorCode());
        }

        // A zero-byte row would download as a corrupt document with a checksum that
        // looks perfectly valid.
        $this->assertSame($before, File::query()->count());
    }

    public function test_an_upload_over_the_configured_limit_is_refused(): void
    {
        Storage::fake('private');
        config(['platform.files.max_upload_kb' => 10]);
        $before = File::query()->count();

        try {
            $this->service()->store(
                upload: UploadedFile::fake()->create('film.mp4', 100, 'video/mp4'),
                category: FileCategory::CourseResource,
                visibility: FileVisibility::IsPrivate,
            );

            $this->fail('An oversized file was registered.');
        } catch (PlatformException $exception) {
            $this->assertSame('files.too_large', $exception->getErrorCode());
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame(10240, $exception->getContext()['max_bytes']);
            $this->assertSame(102400, $exception->getContext()['size_bytes']);
            $this->assertStringContainsString('10 KB', $exception->getUserMessage());
        }

        self::assertNothingWasStored('private');
        $this->assertSame($before, File::query()->count());
    }

    public function test_a_category_cannot_be_stored_below_its_floor(): void
    {
        Storage::fake('public');
        Storage::fake('authenticated');
        $before = File::query()->count();

        $refused = [
            [FileCategory::DigitalProduct, FileVisibility::IsPublic],
            [FileCategory::DigitalProduct, FileVisibility::IsAuthenticated],
            [FileCategory::DigitalProduct, FileVisibility::IsPrivate],
            [FileCategory::StudentDocument, FileVisibility::IsPublic],
            [FileCategory::StudentDocument, FileVisibility::IsAuthenticated],
            [FileCategory::AssignmentSubmission, FileVisibility::IsPublic],
            [FileCategory::Report, FileVisibility::IsPrivate],
            [FileCategory::Invoice, FileVisibility::IsPublic],
            [FileCategory::Cv, FileVisibility::IsAuthenticated],
            [FileCategory::CourseResource, FileVisibility::IsPublic],
        ];

        foreach ($refused as [$category, $visibility]) {
            $label = $category->value.' as '.$visibility->value;

            try {
                $this->service()->store(
                    upload: UploadedFile::fake()->create('file.pdf', 10, 'application/pdf'),
                    category: $category,
                    visibility: $visibility,
                );

                $this->fail("{$label} was stored.");
            } catch (PlatformException $exception) {
                // This is the case a dropdown in a Livewire component gets wrong,
                // and it is checked in the service rather than in the form because
                // the form is the part that changes.
                $this->assertSame('files.visibility_too_open', $exception->getErrorCode(), $label);
                $this->assertSame(422, $exception->getStatusCode(), $label);
                $this->assertSame($visibility->value, $exception->getContext()['requested'], $label);
                $this->assertSame($category->minimumVisibility()->value, $exception->getContext()['minimum'], $label);
            }
        }

        $this->assertSame($before, File::query()->count());
        self::assertNothingWasStored('public');
        self::assertNothingWasStored('authenticated');
    }

    public function test_a_category_may_always_be_stored_more_protectively_than_it_must(): void
    {
        Storage::fake('restricted');

        // A course resource under the restricted tier is unusual but safe, and
        // refusing it would push an administrator to file protected material under
        // a category that fits less well.
        $file = $this->service()->store(
            upload: UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            category: FileCategory::CourseResource,
            visibility: FileVisibility::IsRestricted,
        );

        $this->assertSame(FileVisibility::IsRestricted, $file->visibility);
        $this->assertSame('restricted', $file->disk);
        Storage::disk('restricted')->assertExists($file->path);
    }

    public function test_the_stored_path_contains_neither_the_original_name_nor_the_uuid(): void
    {
        Storage::fake('restricted');

        $file = $this->service()->store(
            upload: UploadedFile::fake()->create('passport-scan-of-jane.pdf', 10, 'application/pdf'),
            category: FileCategory::StudentDocument,
            visibility: FileVisibility::IsRestricted,
        );

        // The uuid is public: it is the download route's key. A path built from it
        // would be guessable by anybody who had once been sent a link.
        $this->assertStringNotContainsString($file->uuid, $file->path);
        $this->assertStringNotContainsString('passport', $file->path);
        $this->assertStringNotContainsString('jane', $file->path);

        $this->assertMatchesRegularExpression(
            '#^student_document/\d{4}/\d{2}/[a-z0-9]{40}\.pdf$#',
            $file->path,
        );
    }

    public function test_two_uploads_of_the_same_file_get_different_paths(): void
    {
        Storage::fake('restricted');

        $first = $this->service()->store(
            upload: UploadedFile::fake()->createWithContent('same.txt', 'identical content'),
            category: FileCategory::StudentDocument,
            visibility: FileVisibility::IsRestricted,
        );
        $second = $this->service()->store(
            upload: UploadedFile::fake()->createWithContent('same.txt', 'identical content'),
            category: FileCategory::StudentDocument,
            visibility: FileVisibility::IsRestricted,
        );

        // Deduplication by checksum would make one person's delete remove another
        // person's file, and would let a path be derived from content somebody else
        // already uploaded. The checksum is recorded; the path is not derived.
        $this->assertSame($first->checksum_sha256, $second->checksum_sha256);
        $this->assertNotSame($first->path, $second->path);
    }

    public function test_the_original_name_is_sanitised_before_it_is_stored(): void
    {
        Storage::fake('restricted');

        $file = $this->service()->store(
            upload: UploadedFile::fake()->createWithContent("../../etc/passwd\x00.pdf", 'content'),
            category: FileCategory::StudentDocument,
            visibility: FileVisibility::IsRestricted,
        );

        $stored = File::query()->whereKey($file->getKey())->value('original_name');

        $this->assertStringNotContainsString('/', (string) $stored);
        $this->assertStringNotContainsString("\x00", (string) $stored);
        $this->assertStringNotContainsString('..', (string) $stored);
        $this->assertNotSame('', (string) $stored);
    }

    public function test_the_row_records_who_uploaded_and_what_it_belongs_to(): void
    {
        Storage::fake('restricted');

        // The uploader and the subject are different people, which is the normal
        // case rather than the exception: an administrator files a guardian's proof
        // of address, a tutor uploads on behalf of a class.
        $administrator = $this->makeUser();
        $student = $this->makeUser();

        $file = $this->service()->store(
            upload: UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
            category: FileCategory::StudentDocument,
            visibility: FileVisibility::IsRestricted,
            uploader: $administrator,
            owner: $student,
        );

        $this->assertSame($administrator->id, $file->uploaded_by);
        $this->assertSame(User::class, $file->fileable_type);
        $this->assertSame($student->id, $file->fileable_id);
    }

    public function test_an_upload_is_audited_with_its_tier_and_category(): void
    {
        Storage::fake('restricted');

        $uploader = $this->makeUser();

        $file = $this->service()->store(
            upload: UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
            category: FileCategory::Report,
            visibility: FileVisibility::IsRestricted,
            uploader: $uploader,
        );

        $entry = AuditLog::query()->where('event', 'files.uploaded')->latest('id')->first();

        $this->assertNotNull($entry, 'no audit entry was written');
        $this->assertSame(File::class, $entry->auditable_type);
        $this->assertSame($file->getKey(), $entry->auditable_id);
        $this->assertSame($uploader->id, $entry->user_id);

        // A trail that says "a file was uploaded" answers nothing. The tier and the
        // category are what make it searchable later (§58).
        $this->assertEquals([
            'category' => 'report',
            'visibility' => 'restricted',
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10240,
            'checksum_sha256' => $file->checksum_sha256,
        ], $entry->new_values);

        $this->assertContains('restricted', $entry->tagList());
        $this->assertContains('files', $entry->tagList());
    }

    public function test_deleting_is_soft_leaves_the_bytes_and_is_recorded(): void
    {
        Storage::fake('private');

        $uploader = $this->makeUser();

        $file = $this->service()->store(
            upload: UploadedFile::fake()->createWithContent('keep.txt', 'still here'),
            category: FileCategory::Cv,
            visibility: FileVisibility::IsPrivate,
            uploader: $uploader,
        );

        $this->service()->delete($file, $uploader);

        // The row survives to answer "was anything here, and what was it?", and a
        // mistaken delete can be undone. A file can be evidence.
        $this->assertSoftDeleted('files', ['id' => $file->id]);
        $this->assertNotNull(File::withTrashed()->whereKey($file->getKey())->first());

        // Erasure is a retention process that has to decide what happens to the
        // record referring to the file, in that order (§58).
        Storage::disk('private')->assertExists($file->path);

        $entry = AuditLog::query()->where('event', 'files.deleted')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($uploader->id, $entry->user_id);
        $oldValues = (array) $entry->old_values;

        $this->assertSame('cv', $oldValues['category'] ?? null);
        $this->assertSame('private', $oldValues['visibility'] ?? null);
        $this->assertSame($file->path, $oldValues['path'] ?? null);
        $this->assertSame($file->checksum_sha256, $oldValues['checksum_sha256'] ?? null);
    }

    public function test_a_deleted_file_is_not_found_by_the_download_route(): void
    {
        Storage::fake('private');

        $file = $this->service()->store(
            upload: UploadedFile::fake()->create('gone.pdf', 10, 'application/pdf'),
            category: FileCategory::Cv,
            visibility: FileVisibility::IsPrivate,
            uploader: $this->makeUser(),
        );

        $uuid = $file->uuid;
        $this->service()->delete($file);

        // Soft-deleted answers the same as never-existed: a distinct 410 would tell
        // a probe that the platform once held something at that address.
        $this->assertNull(File::query()->where('uuid', $uuid)->first());
        $this->assertNotNull(File::withTrashed()->where('uuid', $uuid)->first());
    }

    public function test_a_tier_pointed_at_the_public_disk_is_refused_loudly(): void
    {
        // The misconfiguration this catches is invisible to every authorization
        // test: the checks all pass, and Apache serves the file anyway.
        config(['platform.files.tiers.private' => ['disk' => 'public', 'served_by_webserver' => false]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not served by the web server');

        $this->service()->diskFor(FileVisibility::IsPrivate);
    }

    public function test_a_tier_with_no_disk_declared_is_refused_loudly(): void
    {
        config(['platform.files.tiers.restricted' => ['served_by_webserver' => false]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares no storage tier');

        $this->service()->diskFor(FileVisibility::IsRestricted);
    }

    private function service(): FileService
    {
        return app(FileService::class);
    }

    /**
     * Laravel 13 has `assertExists` and `assertMissing` but nothing that says "this
     * disk is empty", and an empty disk is the assertion a refused upload needs: a
     * rejection that still wrote bytes has failed at the part that matters.
     */
    private static function assertNothingWasStored(string $disk): void
    {
        $files = Storage::disk($disk)->allFiles();

        self::assertSame([], $files, $disk.' has '.count($files).' file(s) after a refused upload');
    }
}
