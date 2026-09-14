<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Models\File;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * The download chain, end to end over HTTP (§40, §59, docs/01 §5.4, docs/06 row 50).
 *
 * `FilePolicyTest` answers who may read a file. This answers what happens when
 * somebody asks, through a browser, for a file they may and may not have: the
 * bytes, the headers, the status, the rate limit and the trail.
 *
 * Four claims:
 *
 * • THE RESPONSE IS AN ATTENTION, NEVER INLINE — including on the public tier. A
 *   file rendered inside this origin can script against this origin, and the only
 *   way to be sure is never to render one.
 *
 * • THE ANSWER DISTINGUISHES THE FAILURES. 403 for a file that exists and is not
 *   yours, 404 for one that does not, and 404 for one whose row exists but whose
 *   bytes are gone — because an empty 200 hands somebody a corrupt PDF and a 500
 *   sends them to look at the application instead of the disk.
 *
 * • A RATE LIMIT SITS BEHIND AUTHORIZATION, not in front of it, and says when to
 *   come back.
 *
 * • ONLY THE RESTRICTED TIER IS RECORDED. §58 asks that access to a minor's data
 *   be auditable; recording every avatar download would bury those entries in the
 *   trail and fill a table nobody can search.
 */
final class SecureDownloadTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    private const CONTENT = 'the bytes that were stored';

    public function test_an_authorized_download_streams_the_bytes_as_an_attachment(): void
    {
        Storage::fake('private');

        $tutor = $this->user(Roles::TUTOR);
        $file = $this->withBytes($this->makeFile($tutor, $tutor, [
            'category' => FileCategory::Cv,
            'visibility' => FileVisibility::IsPrivate,
            'disk' => 'private',
            'original_name' => 'curriculum-vitae.pdf',
            'mime_type' => 'application/pdf',
        ]));

        $response = $this->actingAs($tutor)->get(route('files.download', $file->uuid));

        $response->assertOk();

        // `attachment`, never `inline`: a file rendered inside this origin can
        // script against this origin.
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('curriculum-vitae.pdf', (string) $response->headers->get('Content-Disposition'));

        // The registered MIME type, not whatever the disk sniffs from the bytes:
        // the row is the assertion the platform made at upload time.
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        // A protected file must not end up in a shared proxy's cache or on a
        // browser's disk after the session that was allowed to see it has ended.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    public function test_a_public_file_downloads_for_a_guest(): void
    {
        Storage::fake('public');

        $file = $this->withBytes($this->makeFile(null, null, [
            'category' => FileCategory::ProfilePhoto,
            'visibility' => FileVisibility::IsPublic,
            'disk' => 'public',
            'original_name' => 'avatar.jpg',
            'mime_type' => 'image/jpeg',
        ]));

        // Not actingAs() anybody. The public tier has to work without an account,
        // which is why FilePolicy's read abilities accept a nullable user — and why
        // this route carries no `auth` middleware, asserted below.
        $response = $this->get(route('files.download', $file->uuid));

        $response->assertOk();
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    public function test_a_signed_in_user_may_download_the_authenticated_tier(): void
    {
        Storage::fake('authenticated');

        $file = $this->withBytes($this->makeFile(null, null, [
            'category' => FileCategory::CourseResource,
            'visibility' => FileVisibility::IsAuthenticated,
            'disk' => 'authenticated',
            'original_name' => 'week-3-slides.pdf',
            'mime_type' => 'application/pdf',
        ]));

        foreach ([Roles::STUDENT, Roles::PARENT, Roles::TUTOR, Roles::EVALUATOR] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('files.download', $file->uuid))
                ->assertOk();
        }
    }

    public function test_a_download_somebody_is_not_allowed_is_a_403_that_leaks_nothing(): void
    {
        Storage::fake('private');

        $owner = $this->user(Roles::STUDENT);
        $stranger = $this->user(Roles::STUDENT);

        $file = $this->withBytes($this->makeFile($owner, $owner, [
            'category' => FileCategory::StudentDocument,
            'visibility' => FileVisibility::IsPrivate,
            'disk' => 'private',
            'original_name' => 'report-card.pdf',
            'mime_type' => 'application/pdf',
        ]));

        $response = $this->actingAs($stranger)->get(route('files.download', $file->uuid));

        // 403 rather than 404 because the identifier is a v4 uuid: it is not
        // enumerable, so the answer tells a signed-in user something they were
        // entitled to learn — that the file is not theirs — and nothing to anybody
        // who cannot already reach a uuid.
        $response->assertStatus(403);
        $response->assertDontSee(self::CONTENT, false);
        $this->assertStringNotContainsString('report-card.pdf', $response->getContent());
    }

    public function test_a_restricted_file_is_refused_to_a_scoped_staff_holder(): void
    {
        Storage::fake('restricted');

        $student = $this->user(Roles::STUDENT);
        $academic = $this->user(Roles::ACADEMIC_ADMIN);

        $file = $this->withBytes($this->makeFile($student, $student, [
            'category' => FileCategory::AssignmentSubmission,
            'visibility' => FileVisibility::IsRestricted,
            'disk' => 'restricted',
        ]));

        // docs/05 §3.9 scopes files.view_restricted for Academic Admin and Finance
        // Officer. Until a domain says which records they cover, they reach only
        // their own — over HTTP, not just in the policy.
        $this->actingAs($academic)->get(route('files.download', $file->uuid))->assertStatus(403);
        $this->actingAs($student)->get(route('files.download', $file->uuid))->assertOk();
    }

    public function test_an_unknown_identifier_is_a_404(): void
    {
        $this->get(route('files.download', (string) Str::uuid()))->assertNotFound();
    }

    public function test_a_soft_deleted_file_is_a_404_like_one_that_never_existed(): void
    {
        Storage::fake('private');

        $owner = $this->user(Roles::TUTOR);
        $file = $this->withBytes($this->makeFile($owner, $owner, [
            'category' => FileCategory::Cv,
            'visibility' => FileVisibility::IsPrivate,
            'disk' => 'private',
        ]));
        $uuid = $file->uuid;

        $file->delete();

        // A distinct 410 would tell a probe that the platform once held something at
        // that address, and the bytes still being on disk must not change the answer
        // a request gets.
        $this->actingAs($owner)->get(route('files.download', $uuid))->assertNotFound();
    }

    public function test_a_row_whose_bytes_are_gone_is_a_404_not_an_empty_download(): void
    {
        Storage::fake('private');

        $owner = $this->user(Roles::TUTOR);

        // A registered row with nothing behind it: a storage volume that was not
        // carried over to the new host, or an erasure that ran ahead of the registry.
        $file = $this->makeFile($owner, $owner, [
            'category' => FileCategory::Cv,
            'visibility' => FileVisibility::IsPrivate,
            'disk' => 'private',
            'path' => 'cv/'.Str::lower(Str::random(40)).'.pdf',
        ]);

        $this->assertFalse(Storage::disk('private')->exists($file->path));

        $this->actingAs($owner)->get(route('files.download', $file->uuid))->assertNotFound();
    }

    public function test_repeated_downloads_are_rate_limited_and_say_when_to_return(): void
    {
        Storage::fake('restricted');

        // Three attempts would be the honest test of the shipped limit; the limit is
        // config, so the test lowers it and keeps the run fast.
        config([
            'platform.files.download_rate_limit.max_attempts' => 2,
            'platform.files.download_rate_limit.decay_minutes' => 1,
        ]);

        $administrator = $this->user(Roles::ADMINISTRATOR);
        $file = $this->withBytes($this->makeFile(null, null, [
            'category' => FileCategory::StudentDocument,
            'visibility' => FileVisibility::IsRestricted,
            'disk' => 'restricted',
        ]));

        $url = route('files.download', $file->uuid);

        $this->actingAs($administrator);
        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        $response = $this->get($url);

        $response->assertStatus(429);

        // A 429 with no Retry-After is a rate limit the client cannot respect, so it
        // retries immediately and stays limited.
        $retryAfter = (int) (string) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function test_the_shipped_limit_refuses_the_31st_download_inside_five_minutes(): void
    {
        Storage::fake('restricted');

        // docs/07 W8 says "31st download in 5 minutes → 429". The numbers are
        // config, and this is the test that keeps the config, the documentation and
        // the behaviour agreeing — with the shipped values rather than a convenient
        // small one, because a limit that is only ever tested at two attempts can
        // be raised to a million and every test still passes.
        $this->assertSame(30, (int) config('platform.files.download_rate_limit.max_attempts'));
        $this->assertSame(5, (int) config('platform.files.download_rate_limit.decay_minutes'));

        $administrator = $this->user(Roles::ADMINISTRATOR);
        $file = $this->withBytes($this->makeFile(null, null, [
            'category' => FileCategory::Report,
            'visibility' => FileVisibility::IsRestricted,
            'disk' => 'restricted',
        ]));

        $url = route('files.download', $file->uuid);

        $this->actingAs($administrator);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->get($url)->assertOk();
        }

        $this->get($url)->assertStatus(429);
    }

    public function test_the_limit_is_per_account_so_changing_address_does_not_reset_it(): void
    {
        Storage::fake('restricted');
        config(['platform.files.download_rate_limit.max_attempts' => 1]);

        $administrator = $this->user(Roles::ADMINISTRATOR);
        $file = $this->withBytes($this->makeFile(null, null, [
            'category' => FileCategory::Report,
            'visibility' => FileVisibility::IsRestricted,
            'disk' => 'restricted',
        ]));
        $url = route('files.download', $file->uuid);

        $this->actingAs($administrator);
        $this->get($url)->assertOk();

        // A different source address, the same account. Keying a signed-in user on
        // their address would let anybody rotate it — a proxy, a mobile network, a
        // second browser tab — and keep an unlimited allowance.
        $this->get($url, ['REMOTE_ADDR' => '203.0.113.9'])->assertStatus(429);
    }

    public function test_a_restricted_download_is_recorded_in_the_trail(): void
    {
        Storage::fake('restricted');

        $administrator = $this->user(Roles::ADMINISTRATOR);
        $student = $this->user(Roles::STUDENT);

        $file = $this->withBytes($this->makeFile($student, $student, [
            'category' => FileCategory::StudentDocument,
            'visibility' => FileVisibility::IsRestricted,
            'disk' => 'restricted',
        ]));

        $before = AuditLog::ofEvent('files.downloaded')->count();

        $this->actingAs($administrator)->get(route('files.download', $file->uuid))->assertOk();

        $entry = AuditLog::ofEvent('files.downloaded')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($before + 1, AuditLog::ofEvent('files.downloaded')->count());
        $this->assertSame($administrator->id, $entry->user_id);
        $this->assertSame(File::class, $entry->auditable_type);
        $this->assertSame($file->getKey(), $entry->auditable_id);
        $this->assertContains('restricted', $entry->tags);
        $this->assertContains('student_document', $entry->tags);
    }

    public function test_a_public_or_authenticated_download_is_not_recorded(): void
    {
        Storage::fake('public');
        Storage::fake('authenticated');

        $public = $this->withBytes($this->makeFile(null, null, [
            'category' => FileCategory::ProfilePhoto,
            'visibility' => FileVisibility::IsPublic,
            'disk' => 'public',
        ]));
        $authenticated = $this->withBytes($this->makeFile(null, null, [
            'category' => FileCategory::CourseResource,
            'visibility' => FileVisibility::IsAuthenticated,
            'disk' => 'authenticated',
        ]));

        $before = AuditLog::ofEvent('files.downloaded')->count();

        $this->get(route('files.download', $public->uuid))->assertOk();
        $this->actingAs($this->user(Roles::STUDENT))
            ->get(route('files.download', $authenticated->uuid))
            ->assertOk();

        // Recording these would fill the trail with rows nobody reads and bury the
        // entries about a minor's records, which is its own kind of loss.
        $this->assertSame($before, AuditLog::ofEvent('files.downloaded')->count());
    }

    public function test_a_refused_download_writes_no_audit_entry(): void
    {
        Storage::fake('restricted');

        $owner = $this->user(Roles::STUDENT);
        $stranger = $this->user(Roles::STUDENT);

        $file = $this->withBytes($this->makeFile($owner, $owner, [
            'category' => FileCategory::StudentDocument,
            'visibility' => FileVisibility::IsRestricted,
            'disk' => 'restricted',
        ]));

        $before = AuditLog::ofEvent('files.downloaded')->count();

        $this->actingAs($stranger)->get(route('files.download', $file->uuid))->assertStatus(403);

        // A refusal is answered by the policy; the entry says somebody READ a
        // restricted file, and nobody did.
        $this->assertSame($before, AuditLog::ofEvent('files.downloaded')->count());
    }

    public function test_the_filename_in_the_response_header_is_sanitised(): void
    {
        Storage::fake('private');

        $owner = $this->user(Roles::TUTOR);

        // The column can hold whatever an import or a legacy row left there. The
        // header is built from the sanitised form regardless, because a quote ends
        // the parameter early and a semicolon starts a new one.
        $file = $this->withBytes($this->makeFile($owner, $owner, [
            'category' => FileCategory::Cv,
            'visibility' => FileVisibility::IsPrivate,
            'disk' => 'private',
            'original_name' => 'report"; filename=evil.exe',
            'mime_type' => 'application/pdf',
        ]));

        $response = $this->actingAs($owner)->get(route('files.download', $file->uuid));

        $disposition = (string) $response->headers->get('Content-Disposition');

        $response->assertOk();
        $this->assertStringStartsWith('attachment', $disposition);

        // Exactly one parameter separator: `attachment; filename=…`. A second would
        // mean the uploaded name succeeded in adding a parameter of its own.
        $this->assertSame(1, substr_count($disposition, ';'));
        $this->assertStringContainsString('report filename=evil.exe', $disposition);
    }

    public function test_a_non_ascii_filename_reaches_the_browser_in_a_form_it_can_use(): void
    {
        Storage::fake('private');

        $owner = $this->user(Roles::TUTOR);
        $file = $this->withBytes($this->makeFile($owner, $owner, [
            'category' => FileCategory::Cv,
            'visibility' => FileVisibility::IsPrivate,
            'disk' => 'private',
            'original_name' => 'Résultats.pdf',
            'mime_type' => 'application/pdf',
        ]));

        $response = $this->actingAs($owner)->get(route('files.download', $file->uuid));

        $disposition = (string) $response->headers->get('Content-Disposition');

        // Symfony supplies an ASCII fallback and the RFC 6266 extended form, so a
        // name in somebody's own language survives the trip instead of arriving as
        // a run of question marks.
        $response->assertOk();
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertStringContainsString(rawurlencode('Résultats.pdf'), $disposition);
    }

    public function test_the_route_carries_no_auth_middleware_so_the_public_tier_reaches_a_guest(): void
    {
        $route = Route::getRoutes()->getByName('files.download');

        $this->assertNotNull($route);
        $this->assertNotContains(Authenticate::class, $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());

        // The route decides for itself, through DownloadAuthorizer, which is what
        // lets one route serve four tiers instead of four routes each remembering to
        // check three of them.
        $this->assertContains('web', $route->gatherMiddleware());
    }

    /**
     * Write the bytes a registered row points at, so a download has something to
     * stream. `makeFile` builds the row; the disk is separate from it, which is
     * exactly the situation a failed deploy produces.
     */
    private function withBytes(File $file, string $content = self::CONTENT): File
    {
        Storage::disk($file->disk)->put($file->path, $content);

        return $file;
    }

    private function user(string ...$roles): User
    {
        $user = $this->makeUser();

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user;
    }
}
