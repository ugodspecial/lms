<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Models\File;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * §59 in one sentence: protected files must not be reachable through a URL anybody
 * could arrive at by guessing.
 *
 * Guessing takes three forms, and each is closed here rather than trusted to a
 * convention:
 *
 * • ENUMERATING AN ID. Row ids are sequential, so `{id}` in a route turns the files
 *   table into a public listing one request at a time. The route parameter is a v4
 *   uuid and `whereUuid` refuses anything that is not one, which makes the
 *   difference between "1, 2, 3" and 122 bits of entropy the whole protection.
 *
 * • GUESSING A PATH. If a stored path were the original filename, or contained the
 *   uuid that appears in every link ever shared, the path would be derivable. It is
 *   neither: it is a category, a month and 40 random characters. And even a path
 *   somebody did know is not fetchable, because the disks holding protected bytes
 *   are not under the document root and no route serves a path.
 *
 * • REACHING AROUND THE AUTHORIZED ROUTE. One route serves every file on the
 *   platform. Asserting that it is the ONLY one, and that nothing writes to the
 *   registry over HTTP, is what keeps a later feature from adding a second door
 *   that checks two of the four things.
 *
 * The 403/404 split is asserted too, because it is a deliberate choice and not an
 * accident: an existing file that is not yours answers 403 and a missing one 404,
 * which is only safe because the identifier cannot be enumerated. Somebody who
 * later changes `{uuid}` to `{id}` breaks the assumption this test rests on, and
 * this test is where they find out.
 */
final class GuessableUrlTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    public function test_the_download_route_accepts_only_a_uuid(): void
    {
        $file = $this->file();

        $this->actingAs($this->user(Roles::ADMINISTRATOR))
            ->get(route('files.download', $file->uuid))
            ->assertOk();

        // Anything that is not a uuid never reaches the controller, so a sequential
        // id, a slug and a traversal attempt are all one answer: not found.
        foreach ([
            '/files/'.$file->id.'/download',
            '/files/1/download',
            '/files/not-a-uuid/download',
            '/files/00000000-0000-0000-0000-000000000000X/download',
            '/files/'.rawurlencode('../'.$file->uuid).'/download',
        ] as $uri) {
            $this->actingAs($this->user(Roles::ADMINISTRATOR))->get($uri)->assertNotFound();
        }
    }

    public function test_the_row_id_never_appears_in_a_url_even_though_the_uuid_does(): void
    {
        $file = $this->file();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $file->uuid,
            'the identifier in the URL has to be a v4 uuid, or it is enumerable',
        );

        $this->assertStringNotContainsString('/'.$file->id.'/', route('files.download', $file->uuid));

        // The id is still there for foreign keys and for ordering. It is the URL
        // that must not carry it.
        $this->assertNotNull(File::query()->whereKey($file->getKey())->first());
    }

    public function test_the_only_route_that_touches_files_is_the_authorized_download(): void
    {
        $fileRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains($route->uri(), 'files'))
            ->map(static fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(['GET|HEAD files/{uuid}/download'], $fileRoutes);
    }

    public function test_nothing_writes_to_the_file_registry_over_http(): void
    {
        // A file's visibility, disk and path are the assertions the access decision
        // is built on. There is no route that changes them, so the only way a file
        // becomes public is a change to the code that checks whether it may.
        $writeVerbs = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains($route->uri(), 'files'))
            ->flatMap(static fn ($route): array => $route->methods())
            ->unique()
            ->values()
            ->all();

        $this->assertSame([], array_values(array_intersect($writeVerbs, ['POST', 'PUT', 'PATCH', 'DELETE'])));
    }

    public function test_no_disk_is_served_by_the_frameworks_own_storage_route(): void
    {
        // A local disk with `serve => true` makes Laravel register two routes of
        // its own for it — GET /storage/{path} AND PUT /storage/{path}
        // (Illuminate\Filesystem\FilesystemServiceProvider::serveFiles). Both go
        // straight to the disk: past FileService's MIME and extension checks, past
        // the category's visibility floor, past the registry row, past the audit
        // entry. The second one is a WRITE, so it would also be a second source of
        // truth about what the platform holds.
        //
        // They are signature-gated rather than open — ServeFile refuses an unsigned
        // request with 403 outside production and 404 inside it — which is why this
        // is a decision and not an incident. But the platform has one door on
        // purpose, and config/filesystems.php keeps it that way.
        $disks = config('filesystems.disks');

        $this->assertIsArray($disks);

        foreach ($disks as $disk => $config) {
            $served = is_array($config) ? (bool) ($config['serve'] ?? false) : false;

            $this->assertFalse(
                $served,
                'the ['.$disk.'] disk is served by the framework, which registers routes past FilePolicy',
            );
        }

        $storageRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with($route->uri(), 'storage'))
            ->map(static fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $storageRoutes);
    }

    public function test_a_known_storage_path_is_not_fetchable_over_http(): void
    {
        Storage::fake('restricted');

        $administrator = $this->user(Roles::ADMINISTRATOR);
        $file = $this->file(FileVisibility::IsRestricted, 'restricted');

        Storage::disk('restricted')->put($file->path, 'protected bytes');

        $this->actingAs($administrator);

        // Even holding the exact path — an administrator, a leaked log line, a
        // backup listing — there is no URL that returns it. The only door asks first.
        //
        // `/storage/...` is in the list because it is the URI Laravel would have
        // used had any disk been served; with `serve => false` on every disk there
        // is no such route, so the answer is 404 rather than the 403 ServeFile
        // gives an unsigned request. Both are refusals, and the one that is not a
        // door at all is the one being asserted here.
        foreach ([
            '/storage/'.$file->path,
            '/'.$file->path,
            '/files/'.$file->path,
            '/restricted/'.$file->path,
        ] as $uri) {
            $this->get($uri)->assertNotFound();
        }
    }

    public function test_protected_bytes_do_not_live_under_the_document_root(): void
    {
        $publicRoot = (string) config('filesystems.disks.public.root');
        $documentRoot = public_path();

        foreach ([FileVisibility::IsAuthenticated, FileVisibility::IsPrivate, FileVisibility::IsRestricted] as $tier) {
            $disk = (string) config('platform.files.tiers.'.$tier->value.'.disk');
            $root = (string) config('filesystems.disks.'.$disk.'.root');

            // The protection is not only authorization: a file the web server can
            // reach is served by the web server, and no PHP-level check runs at all.
            $this->assertFalse(
                str_starts_with($root, $publicRoot),
                $disk.' ('.$root.') is inside the public disk ('.$publicRoot.')',
            );
            $this->assertFalse(
                str_starts_with($root, $documentRoot),
                $disk.' ('.$root.') is inside the document root ('.$documentRoot.')',
            );
            $this->assertNotSame($publicRoot, $root, $disk.' is the public disk');
        }
    }

    public function test_an_existing_file_answers_403_and_a_missing_one_answers_404(): void
    {
        Storage::fake('private');

        $owner = $this->user(Roles::STUDENT);
        $file = $this->makeFile($owner, $owner, [
            'category' => FileCategory::StudentDocument,
            'visibility' => FileVisibility::IsPrivate,
            'disk' => 'private',
            'path' => 'student_document/'.Str::lower(Str::random(40)).'.pdf',
        ]);
        Storage::disk('private')->put($file->path, 'bytes');

        $guest = $this->get(route('files.download', $file->uuid));
        $missing = $this->get(route('files.download', (string) Str::uuid()));

        $guest->assertStatus(403);
        $missing->assertStatus(404);

        // Deliberate, and only safe because the identifier is a v4 uuid: there is
        // nothing to enumerate, so "exists but not yours" tells a signed-in user
        // something they were entitled to learn and a probe nothing it could use.
        // If `{uuid}` ever became `{id}`, this asymmetry would be an oracle and both
        // would have to answer 404.
        $this->assertNotSame($guest->getStatusCode(), $missing->getStatusCode());
    }

    public function test_two_links_to_the_same_file_share_nothing_with_its_stored_location(): void
    {
        Storage::fake('restricted');

        $file = $this->file(FileVisibility::IsRestricted, 'restricted');
        $url = route('files.download', $file->uuid);

        $this->assertStringContainsString($file->uuid, $url);
        $this->assertStringNotContainsString($file->path, $url);
        $this->assertStringNotContainsString($file->uuid, $file->path);
        $this->assertStringNotContainsString($file->original_name, $file->path);
    }

    /**
     * A registered row on the given tier, with bytes behind it unless the test is
     * about them being absent.
     */
    private function file(FileVisibility $visibility = FileVisibility::IsPrivate, string $disk = 'private'): File
    {
        Storage::fake($disk);

        $file = $this->makeFile(null, null, [
            'category' => FileCategory::Cv,
            'visibility' => $visibility,
            'disk' => $disk,
            'path' => $visibility->value.'/'.now()->format('Y/m').'/'.Str::lower(Str::random(40)).'.pdf',
        ]);

        Storage::disk($disk)->put($file->path, 'bytes');

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
