<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Models\File;
use App\Domain\Identity\Models\User;
use App\Exceptions\PlatformException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * The chain a download has to survive (§40, §59, docs/01 §5.4, ADR-10).
 *
 * FilePolicy answers *who may read this file*. This class is the rest of the
 * decision, in the order it has to happen, and it exists so that the order is
 * written down once rather than repeated at every route that hands back a file:
 *
 *   1. the policy — asked through the Gate, so `Gate::before` and any future
 *      policy `before()` still apply;
 *   2. the rate limit — after authorization, so a quota belongs to somebody who
 *      was allowed to spend it. Limiting first would let any caller exhaust an
 *      administrator's allowance by hammering a file they were never going to get;
 *   3. the bytes — a row whose file is gone is a 404, not a 500, and not a
 *      download of an empty stream that leaves the recipient with a corrupt PDF;
 *   4. the record — for the restricted tier only.
 *
 * Phase 8 extends this rather than replacing it: a digital product adds
 * "authenticated → purchased → payment confirmed → entitlement active → download
 * limit not exceeded → not expired" between steps 1 and 2, and the ledger row in
 * `digital_product_downloads`. The chain is a class so that the Commerce rules
 * have somewhere to live that is not a controller.
 *
 * It throws rather than returning a boolean because the four outcomes are four
 * different HTTP answers, and a `bool` would collapse "not yours" (403), "too many
 * attempts" (429) and "no longer here" (404) into one message the client cannot act
 * on. PlatformException carries the status and a stable code (§42, §64).
 */
final class DownloadAuthorizer
{
    public function __construct(
        private readonly Gate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws PlatformException 403 `files.forbidden`, 429 `files.rate_limited`,
     *                           or 404 `files.missing`
     */
    public function authorize(?User $user, File $file): void
    {
        $this->assertAllowed($user, $file);
        $this->assertWithinRateLimit($user);
        $this->assertBytesArePresent($file);

        if ($file->visibility === FileVisibility::IsRestricted) {
            $this->recordAccess($user, $file);
        }
    }

    /**
     * @throws PlatformException
     */
    private function assertAllowed(?User $user, File $file): void
    {
        // Through the Gate, and for the given user rather than "whoever is
        // authenticated", so a console command acting on somebody's behalf asks the
        // same question a request does.
        $allowed = $user === null
            ? $this->gate->allows('download', $file)
            : $this->gate->forUser($user)->allows('download', $file);

        if ($allowed) {
            return;
        }

        throw new PlatformException(
            message: 'You do not have access to that file.',
            errorCode: 'files.forbidden',
            statusCode: 403,
            context: ['file' => $file->uuid, 'visibility' => $file->visibility->value],
        );
    }

    /**
     * @throws PlatformException
     */
    private function assertWithinRateLimit(?User $user): void
    {
        $limit = (array) config('platform.files.download_rate_limit', []);
        $maxAttempts = (int) ($limit['max_attempts'] ?? 30);
        $decaySeconds = (int) ($limit['decay_minutes'] ?? 5) * 60;

        // Zero or negative disables the limit, which is an operator's decision on a
        // host with a fast disk and no abuse — not something to guess at here.
        if ($maxAttempts <= 0) {
            return;
        }

        // A guest is limited per address. A signed-in user is limited per account,
        // so rotating IPs does not reset the counter for somebody who is
        // identifiable anyway.
        $subject = $user !== null ? 'user.'.$user->getKey() : 'ip.'.$this->clientIp();
        $key = 'files.download.'.$subject;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new PlatformException(
                message: 'Too many downloads. Wait a moment and try again.',
                errorCode: 'files.rate_limited',
                statusCode: 429,
                // Retry-After is what lets a client back off correctly instead of
                // retrying immediately and staying limited. Sent as a header,
                // because a browser download that gets a bare 429 body has nothing
                // to act on, and repeated for the log.
                headers: ['Retry-After' => (string) $retryAfter],
                context: ['retry_after_seconds' => $retryAfter, 'subject' => $subject],
            );
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /**
     * @throws PlatformException
     */
    private function assertBytesArePresent(File $file): void
    {
        if (Storage::disk($file->disk)->exists($file->path)) {
            return;
        }

        // The row is here and the bytes are not: a failed deploy, a storage volume
        // that was not carried over, or an erasure that ran ahead of the registry.
        // It is a 404 to the caller — an empty 200 would hand them a corrupt file
        // and a 500 would send somebody to look at the application instead of the
        // disk.
        throw new PlatformException(
            message: 'That file is no longer available.',
            errorCode: 'files.missing',
            statusCode: 404,
            context: ['file' => $file->uuid, 'disk' => $file->disk, 'path' => $file->path],
        );
    }

    /**
     * Record that a restricted file was read.
     *
     * Only the restricted tier. §58 asks that access to a minor's data and to
     * protected commercial files be auditable; recording every course-thumbnail
     * download would bury those entries in the trail and fill `audit_logs` with
     * rows nobody will ever read — which is its own kind of loss, because a trail
     * nobody can search is a trail nobody searches.
     */
    private function recordAccess(?User $user, File $file): void
    {
        $this->audit->record(
            event: 'files.downloaded',
            subject: $file,
            tags: ['files', $file->category->value, 'restricted'],
            actor: $user,
        );
    }

    private function clientIp(): string
    {
        // Resolved defensively: a console or queued caller has no request, and the
        // limit still has to key on something.
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? (string) ($request->ip() ?? 'unknown') : 'unknown';
    }
}
