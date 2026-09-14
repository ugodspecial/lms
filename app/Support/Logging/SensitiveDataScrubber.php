<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Stringable;

/**
 * Strips secrets out of a payload before it is written to a log, an audit row or
 * an error report (§77, ADR-11 §8, ADR-12).
 *
 * Why a class rather than a rule in the logging config: redaction has to be
 * structural. A log line that is redacted only when somebody remembers to
 * redact it is a leak waiting for one new field name, and the cost of missing
 * one here is a plaintext password, 2FA secret or card number sitting in a table
 * an administrator can browse — which makes `audit_logs` the most valuable table
 * in the database (ADR-12).
 *
 * Two independent passes, because secrets arrive two ways:
 *
 * 1. By KEY — `config('platform.logging.redact_keys')`. A key matches when it is
 *    the entry, starts with it or ends with it, so `token` catches `token`,
 *    `access_token` and `token_hash` in one entry; an entry containing `*` is
 *    matched as a wildcard. The VALUE is replaced and the key is kept, because
 *    which field was present is itself part of the record — an auditor needs to
 *    see that a password was changed, not what it was changed to.
 *
 * 2. By VALUE — `config('platform.logging.redact_value_patterns')`. These catch
 *    secrets inside a free-text field, where there is no sensitive key name to
 *    match on: a Paystack key pasted into a support note, an
 *    `Authorization: Bearer …` header captured into an exception message.
 *
 * The matcher deliberately errs toward redaction. `card` catches `report_card`
 * as well as `card_number`, and losing the value of one report-card reference in
 * a log line is a far smaller cost than one card number. The list is config, so
 * an operator who needs a specific field back can narrow the entry rather than
 * disable the scrubber.
 *
 * Recursion is depth-limited. Payloads are built from application data, and a
 * self-referencing array would otherwise exhaust the stack in the middle of
 * writing a log entry — turning a redaction routine into an outage.
 */
final class SensitiveDataScrubber
{
    /** Replaces a value whose key or content is sensitive. */
    public const REDACTED = '[REDACTED]';

    /**
     * Replaces a value nested deeper than {@see MAX_DEPTH}.
     *
     * A distinct marker, because "[REDACTED]" would tell an auditor that a
     * secret was present when the truth is that the payload was too deep to
     * inspect — two very different things to go looking for.
     */
    public const TRUNCATED = '[TRUNCATED]';

    private const MAX_DEPTH = 12;

    /** @var list<string> lower-cased key entries, empties removed */
    private readonly array $keys;

    /** @var list<string> regular expressions applied to string values */
    private readonly array $patterns;

    public function __construct()
    {
        // Read once, in the constructor. An audit write happens inside a request
        // that is already doing work, and calling config() for every node of a
        // deep payload is measurable.
        $keys = [];

        foreach ((array) config('platform.logging.redact_keys', []) as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = strtolower(trim($entry));

            // An empty entry would match every key: str_starts_with($k, '') is
            // true for all $k, so a stray comma in the config array would
            // redact the entire payload and look like a bug in the audited code.
            if ($entry === '') {
                continue;
            }

            $keys[] = $entry;
        }

        $patterns = [];

        foreach ((array) config('platform.logging.redact_value_patterns', []) as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            // Compiled once, here, rather than on every string that passes through.
            // The suppression is deliberate and narrow: a pattern that does not
            // compile is dropped, and a malformed entry in config cannot emit a
            // warning on every log write for the rest of the request. preg_match is
            // the only way to ask, and it warns when the answer is no.
            if (@preg_match($pattern, '') === false) {
                continue;
            }

            $patterns[] = $pattern;
        }

        $this->keys = $keys;
        $this->patterns = $patterns;
    }

    /** Whether a field name is one whose value must never be recorded. */
    public function isSensitiveKey(string $key): bool
    {
        $key = strtolower(trim($key));

        if ($key === '') {
            return false;
        }

        foreach ($this->keys as $entry) {
            if (str_contains($entry, '*')) {
                if ($this->matchesWildcard($entry, $key)) {
                    return true;
                }

                continue;
            }

            if ($key === $entry || str_starts_with($key, $entry) || str_ends_with($key, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redact every sensitive value in an arbitrary payload.
     *
     * Arrays are traversed and their string keys tested; strings are passed
     * through the value patterns; objects are refused unless they can be turned
     * into a string, because guessing at the contents of an unknown object in a
     * logging path is how a secret gets through.
     */
    public function scrub(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::TRUNCATED;
        }

        if (is_array($value)) {
            $scrubbed = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $scrubbed[$key] = self::REDACTED;

                    continue;
                }

                $scrubbed[$key] = $this->scrub($item, $depth + 1);
            }

            return $scrubbed;
        }

        if (is_string($value)) {
            return $this->scrubString($value);
        }

        // A date, an enum or another value object stringifies to something worth
        // keeping. Anything else — an Eloquent model above all, which would
        // serialise its own attributes — is refused rather than guessed at.
        if ($value instanceof Stringable) {
            return $this->scrubString((string) $value);
        }

        if (is_object($value)) {
            return self::REDACTED;
        }

        // int, float, bool and null carry no secret of their own.
        return $value;
    }

    /**
     * The typed entry point used for audit payloads.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function scrubArray(array $values): array
    {
        $scrubbed = $this->scrub($values);

        return is_array($scrubbed) ? $scrubbed : [];
    }

    /**
     * Apply the value patterns to one string.
     *
     * Patterns that do not compile were dropped in the constructor, so the null
     * guard below is a second line of defence rather than the first: preg_replace
     * returns null and leaves the value untouched, and the remaining patterns
     * still get their turn.
     */
    public function scrubString(string $value): string
    {
        foreach ($this->patterns as $pattern) {
            $result = preg_replace($pattern, self::REDACTED, $value);

            if ($result !== null) {
                $value = $result;
            }
        }

        return $value;
    }

    /** Whether a key matches a wildcard entry such as `*_secret` or `card*`. */
    private function matchesWildcard(string $entry, string $key): bool
    {
        // preg_quote first so a literal dot or bracket in an entry cannot become
        // a metacharacter, then turn the escaped `\*` back into `.*`.
        $regex = '/^'.str_replace('\*', '.*', preg_quote($entry, '/')).'$/';

        return preg_match($regex, $key) === 1;
    }
}
