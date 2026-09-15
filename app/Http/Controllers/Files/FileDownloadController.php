<?php

declare(strict_types=1);

namespace App\Http\Controllers\Files;

use App\Domain\Administration\Models\File;
use App\Domain\Administration\Services\DownloadAuthorizer;
use App\Domain\Administration\Services\FileService;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only route that serves a registered file (§40, §59, ADR-10).
 *
 * Three lines of work, and the reason it stays at three: resolving a uuid, asking
 * DownloadAuthorizer, and streaming. No visibility rule, no ownership comparison
 * and no rate limit appears here, because a controller that re-implements any of
 * them is a second place for one of them to be wrong — and the second place is
 * always the one that gets edited.
 *
 * Unknown, malformed and soft-deleted all answer 404. A uuid is v4 and the route
 * constrains the parameter to one, so there is no id to enumerate; a distinct
 * answer for a deleted file would tell a probe that the platform once held
 * something at that address.
 */
final class FileDownloadController extends Controller
{
    public function __construct(
        private readonly DownloadAuthorizer $authorizer,
        private readonly FileService $files,
    ) {}

    public function __invoke(string $uuid): StreamedResponse
    {
        $file = File::query()->where('uuid', $uuid)->first();

        if ($file === null) {
            abort(404);
        }

        // Narrowed rather than assumed: the guard is typed Authenticatable, and the
        // authorization chain takes a platform user or nobody.
        $user = Auth::user();

        $this->authorizer->authorize($user instanceof User ? $user : null, $file);

        return $this->files->downloadResponse($file);
    }
}
