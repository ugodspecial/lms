<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Models\File;
use App\Domain\Identity\Models\User;
use App\Exceptions\PlatformException;
use App\Support\Files\SafeFilename;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * One place where a file becomes a registered file (§40, §57, §58, §59, ADR-06).
 *
 * The registry row is the access decision's foundation: it asserts a visibility, a
 * disk, a checksum and an owner, and FilePolicy answers from those assertions. So
 * every one of them is written here, after the checks below, and never from a
 * request array — which is why nothing on File is mass assignable.
 *
 * THE RULES, and what each one is for:
 *
 * • A category declares the least protective tier it may live under
 *   (FileCategory::minimumVisibility()). A paid product file, a minor's document
 *   and graded work cannot be stored as public no matter who is uploading or what
 *   the form offered.
 *
 * • The tier decides the disk, and the disk is checked against the tier. A tier
 *   the web server does not serve may not sit on the disk that is symlinked into
 *   the document root — that misconfiguration would expose every protected file on
 *   the platform while every PHP-level check kept passing.
 *
 * • The stored path is generated, never derived from the name the client sent. The
 *   original name is kept in its own column, sanitised, for display and for
 *   Content-Disposition — and it appears in no path, on any disk.
 *
 * • The extension comes from the CONTENTS, not the claim. A `.php` holding a JPEG
 *   is stored as `.jpg`: nothing outside the set Symfony associates with the
 *   detected MIME type is ever written, so the blocked-extension list in config is
 *   a second gate rather than the only one. That matters most on the public tier,
 *   which is the one directory a web server can reach.
 *
 * • The MIME type checked is the detected one (`finfo`), not the client's. A
 *   request can claim anything; the bytes cannot.
 *
 * Nothing here authorizes. That is FilePolicy's job, asked by DownloadAuthorizer —
 * a service that also checked permissions could not be reused by the console
 * commands and importers that have already established the right to act.
 */
final class FileService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Validate an upload, store it on the disk its tier requires, register it.
     *
     * @param  Model|null  $owner  the record the file belongs to, which is often not
     *                             the person uploading it — an administrator
     *                             attaching a guardian's proof of address is the
     *                             normal case, not the exception
     *
     * @throws PlatformException if the upload is unacceptable or the visibility is
     *                           too open for the category
     * @throws RuntimeException  if the storage tiers are misconfigured
     */
    public function store(
        UploadedFile $upload,
        FileCategory $category,
        FileVisibility $visibility,
        ?User $uploader = null,
        ?Model $owner = null,
    ): File {
        $this->assertVisibilityIsAllowedFor($category, $visibility);
        $this->assertUploadIsAcceptable($upload);

        $disk = $this->diskFor($visibility);

        // Everything read off the temporary file is read before it is moved.
        $detectedMime = (string) $upload->getMimeType();
        $originalName = SafeFilename::forStorage($upload->getClientOriginalName());
        $extension = $this->extensionFor($detectedMime, $originalName);
        $checksum = hash_file('sha256', (string) $upload->getRealPath());

        if (! is_string($checksum)) {
            throw new RuntimeException('The uploaded file could not be hashed, so it cannot be registered.');
        }

        $path = $this->pathFor($category, $extension);

        // Resolved before anything is written: a file whose owner cannot be
        // recorded would be bytes with no subject, which is worse than no file.
        $ownerKey = $owner === null ? null : $this->ownerKey($owner);

        // The three protected tiers are configured with `throw => true`, so a
        // failed write arrives as an exception. The public tier is not, and there
        // `putFileAs` answers `false` — registering a row whose bytes are missing
        // would be data loss that stays invisible until somebody needs the file.
        if (Storage::disk($disk)->putFileAs(dirname($path), $upload, basename($path)) === false) {
            throw new RuntimeException(sprintf(
                'The file could not be written to the "%s" disk.',
                $disk,
            ));
        }

        // File is wholly non-mass-assignable, so each assertion is written out and
        // saved once — an INSERT, never an UPDATE of a row that was already
        // readable under different terms.
        $file = new File;

        $file->category = $category;
        $file->visibility = $visibility;
        $file->disk = $disk;
        $file->path = $path;
        $file->original_name = $originalName;
        $file->mime_type = $detectedMime;
        $file->size_bytes = (int) $upload->getSize();
        $file->checksum_sha256 = $checksum;
        $file->uploaded_by = $uploader === null ? null : (int) $uploader->getKey();

        if ($owner !== null) {
            $file->fileable_type = $owner::class;
            $file->fileable_id = $ownerKey;
        }

        $file->save();

        $this->audit->record(
            event: 'files.uploaded',
            subject: $file,
            newValues: [
                'category' => $category->value,
                'visibility' => $visibility->value,
                'original_name' => $originalName,
                'mime_type' => $detectedMime,
                'size_bytes' => $file->size_bytes,
                'checksum_sha256' => $checksum,
            ],
            tags: ['files', $category->value, $visibility->value],
            actor: $uploader,
        );

        return $file;
    }

    /**
     * The download response for an already-authorized file.
     *
     * `attachment` rather than `inline` in every case, including the public tier:
     * a file rendered inside this origin can script against this origin, and the
     * only way to be sure of that is never to render one. `no-store` because a
     * protected file must not end up in a shared proxy's cache or on a browser's
     * disk after the session that was allowed to see it has ended.
     */
    public function downloadResponse(File $file): StreamedResponse
    {
        return Storage::disk($file->disk)->download(
            $file->path,
            SafeFilename::forDownload($file->original_name),
            [
                'Content-Type' => $file->mime_type,
                'Cache-Control' => 'no-store, private, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Remove a file from the registry.
     *
     * Soft, and the bytes stay put. A file can be evidence — a submission, an
     * invoice, a signed certificate — so the row survives to answer "was anything
     * here, and what was it?", and a mistaken delete can be undone. Erasure is a
     * retention process that has to decide what to do about the record that refers
     * to the file, in that order, and it is not this method (§58).
     *
     * It does not authorize, and does not throw. FilePolicy answers whether this
     * caller may delete the file; a service that re-checked would be a second place
     * for the rule to be wrong, and would refuse the console jobs that have already
     * established the right to act.
     */
    public function delete(File $file, ?User $actor = null): void
    {
        $file->delete();

        $this->audit->record(
            event: 'files.deleted',
            subject: $file,
            oldValues: [
                'category' => $file->category->value,
                'visibility' => $file->visibility->value,
                'path' => $file->path,
                'checksum_sha256' => $file->checksum_sha256,
            ],
            tags: ['files', $file->category->value],
            actor: $actor,
        );
    }

    /**
     * The owner's key, as the registry column can hold it.
     *
     * `fileable_id` is a big integer. A key read back from MySQL arrives as a
     * numeric string unless the driver returns native types, so both shapes are
     * the same key; a key that is not a number at all is not, and coercing it
     * would point the registry at somebody else's record — the one mistake a file
     * registry cannot be allowed to make quietly.
     *
     * @throws RuntimeException if the owner's key is not an integer
     */
    private function ownerKey(Model $owner): int
    {
        $key = $owner->getKey();

        if (is_int($key) || (is_string($key) && ctype_digit($key))) {
            return (int) $key;
        }

        throw new RuntimeException(sprintf(
            '%s cannot own a file: fileable_id is a big integer and its key is not one.',
            $owner::class,
        ));
    }

    /**
     * The disk a visibility tier must live on.
     *
     * @throws RuntimeException if config/platform.php and config/filesystems.php
     *                          disagree — a misconfiguration, not a user error, and
     *                          one that has to be loud
     */
    public function diskFor(FileVisibility $visibility): string
    {
        $tier = config('platform.files.tiers.'.$visibility->value);
        $disk = is_array($tier) ? ($tier['disk'] ?? null) : null;

        if (! is_string($disk) || $disk === '') {
            throw new RuntimeException(sprintf(
                'config/platform.php declares no storage tier for the "%s" visibility.',
                $visibility->value,
            ));
        }

        $servedByWebserver = is_array($tier) && (bool) ($tier['served_by_webserver'] ?? false);

        // The `public` disk is the one directory symlinked into the document root,
        // so a tier that claims the web server does not serve its bytes must not be
        // stored there. Every PHP-level check would keep passing while Apache
        // served the file to anybody who could guess the path.
        if (! $servedByWebserver && $disk === 'public') {
            throw new RuntimeException(sprintf(
                'The "%s" tier is not served by the web server, so it cannot live on the public disk.',
                $visibility->value,
            ));
        }

        return $disk;
    }

    /**
     * @throws PlatformException
     */
    private function assertVisibilityIsAllowedFor(FileCategory $category, FileVisibility $visibility): void
    {
        $minimum = $category->minimumVisibility();

        if ($visibility->rank() >= $minimum->rank()) {
            return;
        }

        throw new PlatformException(
            message: sprintf('A %s cannot be stored as %s.', $category->label(), $visibility->label()),
            errorCode: 'files.visibility_too_open',
            statusCode: 422,
            errors: [
                'visibility' => [sprintf(
                    '%s must be stored as %s or something more protective.',
                    $category->label(),
                    $minimum->label(),
                )],
            ],
            context: [
                'category' => $category->value,
                'requested' => $visibility->value,
                'minimum' => $minimum->value,
            ],
        );
    }

    /**
     * @throws PlatformException
     */
    private function assertUploadIsAcceptable(UploadedFile $upload): void
    {
        if (! $upload->isValid()) {
            // Includes the PHP upload errors — a file larger than post_max_size
            // arrives as no file at all, and "we did not receive it" is the honest
            // answer rather than a validation failure on a field.
            throw new PlatformException(
                message: 'The upload did not reach the server.',
                errorCode: 'files.upload_failed',
                statusCode: 422,
                errors: ['file' => [$upload->getErrorMessage()]],
            );
        }

        // Cast because Symfony reports a size it could not measure as `false`, and
        // a file whose length is unknown is refused rather than trusted.
        $size = (int) $upload->getSize();

        if ($size === 0) {
            throw new PlatformException(
                message: 'The uploaded file is empty.',
                errorCode: 'files.empty',
                errors: ['file' => ['Choose a file that has something in it.']],
            );
        }

        $maxBytes = (int) config('platform.files.max_upload_kb', 0) * 1024;

        // The limit is config, not a constant here: a host raises post_max_size and
        // an operator raises this, without a deploy of application code (§82).
        if ($maxBytes > 0 && $size > $maxBytes) {
            throw new PlatformException(
                message: sprintf('That file is larger than the %s this platform accepts.', Number::fileSize($maxBytes)),
                errorCode: 'files.too_large',
                errors: ['file' => [sprintf(
                    'The largest accepted file is %s; this one is %s.',
                    Number::fileSize($maxBytes),
                    Number::fileSize($size),
                )]],
                context: ['size_bytes' => $size, 'max_bytes' => $maxBytes],
            );
        }

        $originalName = SafeFilename::forStorage($upload->getClientOriginalName());
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $blocked = (array) config('platform.files.blocked_extensions', []);

        // SVG first among equals: it is an image format that can carry script, so
        // on the public tier it is stored cross-site scripting (§57).
        if ($extension !== '' && in_array($extension, $blocked, true)) {
            throw new PlatformException(
                message: sprintf('A .%s file cannot be uploaded.', $extension),
                errorCode: 'files.extension_blocked',
                errors: ['file' => [sprintf('This platform does not accept .%s files.', $extension)]],
                context: ['extension' => $extension],
            );
        }

        $detected = (string) $upload->getMimeType();
        $allowed = (array) config('platform.files.allowed_mime_types', []);

        // Detected, not claimed. A request can say anything about itself; finfo
        // reads the bytes.
        if (! in_array($detected, $allowed, true)) {
            throw new PlatformException(
                message: 'That kind of file cannot be uploaded.',
                errorCode: 'files.mime_not_allowed',
                errors: ['file' => [sprintf(
                    'This file was detected as %s, which is not one of the accepted types.',
                    $detected,
                )]],
                context: ['detected' => $detected, 'claimed' => $upload->getClientMimeType()],
            );
        }
    }

    /**
     * The extension to store under, decided by the contents.
     *
     * The client's extension is used only when the bytes agree with it, so
     * `invoice.pdf` stays `.pdf` and `payload.php` holding a JPEG becomes `.jpg`.
     * When they disagree the first extension Symfony associates with the detected
     * type wins, which is an allowlist derived from the type that was just checked
     * rather than a second list to keep in step.
     */
    private function extensionFor(string $detectedMime, string $originalName): string
    {
        $known = MimeTypes::getDefault()->getExtensions($detectedMime);
        $claimed = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($claimed !== '' && in_array($claimed, $known, true)) {
            return $claimed;
        }

        return $known[0] ?? '';
    }

    /**
     * A generated path: category, year, month, and a random name.
     *
     * The original name appears nowhere in it, so a file cannot be fetched by
     * guessing what somebody called it. The row's uuid is not in it either — the
     * uuid is public (it is the download route's key), and a path built from a
     * public value would be guessable by anybody who had once seen a link.
     */
    private function pathFor(FileCategory $category, string $extension): string
    {
        $name = Str::lower(Str::random(40));

        return sprintf('%s/%s/%s%s', $category->value, now()->format('Y/m'), $name, $extension === '' ? '' : '.'.$extension);
    }
}
