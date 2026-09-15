<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * Makes an uploaded filename safe to store, and safe to hand back (§57, §59).
 *
 * The name a client sends is attacker-controlled input in a field everybody
 * treats as cosmetic. Three things go wrong with it, and none of them are visible
 * in the code that uses the name:
 *
 * • PATH. `../../config/app.php` or `C:\boot.ini` as a "filename" becomes a path
 *   the moment anything joins it to a directory. The stored path here is a hash
 *   and never contains the original name at all, but the name is still stored in
 *   `files.original_name` and read back by exports, logs and admin tables — so it
 *   is reduced to a basename first and separators are removed rather than trusted.
 *
 * • HEADERS. A download sends the name in `Content-Disposition`. A CR or LF in it
 *   is a response-splitting vector, and a quote ends the parameter early. Control
 *   characters and quotes are stripped, not escaped, because an escaped control
 *   character is still a control character to whatever reads it next.
 *
 * • LENGTH. The column is VARCHAR(255); a 4,000-character name fails the INSERT
 *   after the bytes are already on disk, leaving an orphaned file and an error
 *   that points at the database rather than at the upload. Truncation keeps the
 *   extension, because `report.pdf` truncated to `repo` is a worse answer than
 *   `rep….pdf` — the extension is the part a person acts on.
 *
 * Non-ASCII is preserved. A parent uploading `Résultats scolaires.pdf` should get
 * `Résultats scolaires.pdf` back, and Symfony's header handling emits the
 * `filename*=utf-8''…` form for clients that understand it.
 */
final class SafeFilename
{
    /**
     * Under the 255-character column, with room for the truncation marker and
     * for a prefix an export might add.
     */
    public const MAX_LENGTH = 200;

    /** Used when sanitising leaves nothing behind, so a name is never empty. */
    public const FALLBACK = 'file';

    /**
     * Reduce a client-supplied name to something safe to store.
     *
     * Idempotent: sanitising an already-sanitised name returns it unchanged, so a
     * name that passes through both the upload path and an import path is not
     * mangled twice.
     */
    public static function forStorage(string $name): string
    {
        // Normalise separators first, because basename() only knows about the one
        // this platform runs on and a Windows-style path would survive it.
        $name = str_replace(['\\', '/'], ' ', $name);

        // Control characters, including NUL, CR and LF.
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        // Quotes and backslashes: both are meaningful in a header parameter and in
        // a shell command somebody will eventually paste this into.
        $name = str_replace(['"', "'", '`'], '', $name);

        $name = basename($name);

        // Collapse the whitespace the separator replacement introduced, then drop
        // the dots and spaces that lead the result — in that order, and in one
        // pass each. Stripping leading dots before collapsing whitespace lets a
        // trim expose a new leading dot (`.. .. config` becomes `.. config`), and a
        // name that changes when it is sanitised twice is a name two parts of the
        // system disagree about.
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = (string) preg_replace('/^[\s.]+/u', '', $name);
        $name = trim($name);

        // Leading dots make a hidden file on Unix and are how `.htaccess` and `..`
        // arrive; a name that is only dots has nothing to say, and the fallback
        // keeps the stored value from being empty.
        if ($name === '') {
            return self::FALLBACK;
        }

        return self::truncateKeepingExtension($name);
    }

    /**
     * A name safe to put in a `Content-Disposition` header.
     *
     * Separate from {@see forStorage()} because the two answers differ: a stored
     * name is data, and a downloaded name is part of a response. This applies the
     * storage rules and then removes anything with no business inside a header
     * parameter, so the controller cannot get it wrong by reaching for the wrong
     * one.
     */
    public static function forDownload(string $name): string
    {
        $name = self::forStorage($name);

        // Semicolon separates header parameters; a client must not be able to add
        // one of its own.
        $name = str_replace(';', '', $name);

        return $name === '' ? self::FALLBACK : $name;
    }

    /**
     * Shorten a name to the limit without losing its extension.
     *
     * Multibyte-safe: cutting a UTF-8 name at a byte boundary produces a broken
     * character, which some browsers render as a replacement character in the
     * saved filename.
     */
    private static function truncateKeepingExtension(string $name): string
    {
        if (mb_strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);

        // A "dotfile" with no stem, or an extension longer than the limit, is not
        // an extension worth preserving — truncate the whole name instead.
        if ($extension === '' || mb_strlen($extension) > 20) {
            return mb_substr($name, 0, self::MAX_LENGTH);
        }

        $stem = mb_substr($name, 0, -(mb_strlen($extension) + 1));
        $budget = self::MAX_LENGTH - mb_strlen($extension) - 2; // dot, and the marker

        return mb_substr($stem, 0, max($budget, 1)).'….'.$extension;
    }
}
