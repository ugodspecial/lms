<?php

declare(strict_types=1);

namespace App\Domain\Administration\Services;

use App\Domain\Administration\Enums\SettingType;
use App\Domain\Administration\Models\Setting;
use App\Domain\Identity\Models\User;
use App\Exceptions\PlatformException;
use App\Support\Logging\SensitiveDataScrubber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Reads and writes the `settings` table — the reason nothing business-shaped is
 * hard-coded (§60, §61, §82, docs/01 §5.3).
 *
 * Academic years, grade scales, the age of majority, cancellation windows, tax
 * rates, currency, the video provider: code asks for a setting, it does not
 * contain the number. This class is the asking.
 *
 * THREE RULES, each of which exists because the alternative fails silently:
 *
 * 1. SECRETS NEVER LEAVE. `all()` and `get()` filter `is_secret` rows out before
 *    anything else happens, so there is no code path — not one — from this
 *    service to a secret value. That is deliberate: §30.7 says secrets live in
 *    `.env` only, so a secret in this table is an operator's emergency override,
 *    and the way to keep it emergency-only is to make it unreadable through the
 *    API every screen and helper uses. Code that genuinely needs one reads the
 *    `Setting` model directly, which is a visible, reviewable choice rather than
 *    an accident of calling `setting()`.
 *
 * 2. ONE CACHE ENTRY. The whole set is cached under one key rather than per
 *    setting: a page reads a dozen settings, and a dozen cache lookups on shared
 *    hosting (where the cache is the filesystem or the database, ADR-06) is a
 *    dozen round trips. Every write busts it, so the TTL only bounds the damage
 *    from a change made outside this service — a seeder, or a hand-run UPDATE.
 *
 * 3. A MISSING TABLE IS NOT AN OUTAGE. Before the first migration, and on any
 *    connection where the table cannot be read, callers get their default and
 *    nothing is cached. `platform:doctor` and the installer run against a
 *    database with no schema yet, and a settings read that threw would take the
 *    landing page down with it — the exact opposite of a graceful install.
 *
 * Keys are dotted lowercase namespaces (`platform.currency`,
 * `tutoring.cancellation_window_hours`). The group a key belongs to is a column,
 * not a prefix, so the two are independent: grouping drives the admin tabs and
 * the permission check, the key drives the lookup.
 */
final class SettingsService
{
    /** One entry for the whole set. Public so a write elsewhere can bust it. */
    public const CACHE_KEY = 'platform.settings';

    private const CACHE_TTL = 3600;

    private bool $readable = true;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Every readable setting as `key => typed value`, secrets excluded.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            // Only this class writes the entry, so what comes back is what went in:
            // keyed by setting key, values already typed.
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $values = [];

        try {
            foreach (Setting::query()->orderBy('key')->get() as $setting) {
                if ($setting->is_secret) {
                    continue;
                }

                // A NULL value is how an operator clears an override, and it has to
                // behave like "not set" rather than like a value — otherwise
                // typedValue() would hand a Text setting back as "" and the
                // caller's default would never be reached. Note that this is
                // `=== null`, not falsy: a stored `false` or `0` is a real answer
                // and must survive.
                if ($setting->value === null) {
                    continue;
                }

                $values[(string) $setting->key] = $setting->typedValue();
            }
        } catch (QueryException) {
            // Not cached: a failure that were cached would keep serving defaults
            // for an hour after the migration that fixes it has already run.
            $this->readable = false;

            return [];
        }

        Cache::put(self::CACHE_KEY, $values, self::CACHE_TTL);

        return $values;
    }

    /**
     * One setting, typed, or the caller's default.
     *
     * A secret returns the default as well — see rule 1. Callers should pass
     * their fallback from config rather than a literal, so the value lives in
     * exactly one place when the table has no row for it yet:
     * `setting('platform.currency', config('platform.currency'))`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // `??` rather than array_key_exists: a setting whose stored value is
        // NULL is indistinguishable from an absent one, and both mean "use the
        // default". Storing NULL is how an operator clears an override.
        return $this->all()[$key] ?? $default;
    }

    /**
     * Write a value, bust the cache, and record old→new in the audit trail.
     *
     * This is the only write path, which is what makes the audit guarantee real:
     * a change that bypassed it would have to bypass the cache bust as well, and
     * would then be visible as a stale value rather than as a missing record.
     *
     * A write of the value the setting already holds does nothing — no UPDATE, no
     * cache bust, no audit entry. Recording "changed X to what X already was"
     * would bury the entries that matter in a trail an administrator has to read.
     *
     * @throws PlatformException if the value does not match the declared type, or
     *                           is not one of a Choice setting's allowed values
     */
    public function set(Setting $setting, mixed $value, ?User $actor = null): Setting
    {
        $this->assertAccepts($setting, $value);

        $old = $setting->typedValue();

        if ($old === $value) {
            return $setting;
        }

        $setting->value = $value;
        $setting->save();

        $this->forget();

        $key = (string) $setting->key;

        // A secret's value never reaches the trail. The scrubber matches on field
        // NAMES and the field name here is the setting's own key, so the explicit
        // check is the guarantee; using the key as the field name gives the
        // scrubber a second chance on keys that look like what they hold
        // (`payments.paystack_secret_key`), and makes the entry self-describing.
        $this->audit->record(
            event: 'settings.updated',
            subject: $setting,
            oldValues: [$key => $setting->is_secret ? SensitiveDataScrubber::REDACTED : $old],
            newValues: [$key => $setting->is_secret ? SensitiveDataScrubber::REDACTED : $value],
            tags: ['settings', $setting->group->value],
            actor: $actor,
        );

        return $setting;
    }

    /** Drop the cached set. Called on every write, and safe to call any time. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Whether the settings table could be read on the last attempt.
     *
     * False means the platform is running on defaults because the table is
     * missing or unreachable — worth a line in `platform:doctor` output, and the
     * difference between "nobody set this" and "we could not ask".
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * Refuse a value the setting cannot hold.
     *
     * The type is stored rather than inferred so that `"0"`, `0` and `false` stay
     * distinguishable (SettingType). That only works if a write cannot smuggle a
     * string into an `int` setting: `typedValue()` would coerce it back, but the
     * stored JSON would then disagree with the declared type, and the next reader
     * — or the next administrator, in the UI — would get a different answer than
     * the last one.
     *
     * Numeric strings are refused, not coerced. HTML forms submit strings, so the
     * HTTP layer has to cast before calling this; refusing here is what makes
     * that requirement impossible to miss.
     *
     * @throws PlatformException
     */
    private function assertAccepts(Setting $setting, mixed $value): void
    {
        $expected = match ($setting->type) {
            SettingType::Text, SettingType::Choice => is_string($value) ? null : 'a string',
            SettingType::Integer => is_int($value) ? null : 'a whole number',
            SettingType::Boolean => is_bool($value) ? null : 'true or false',
            // int is accepted for a decimal: a rate of exactly 0 or exactly 10 is
            // a whole number, and refusing it would push callers into 0.0 noise.
            SettingType::Decimal => is_int($value) || is_float($value) ? null : 'a number',
            SettingType::Json => is_array($value) ? null : 'a structured value',
        };

        if ($expected !== null) {
            throw new PlatformException(
                message: sprintf('The value for %s must be %s.', $setting->key, $expected),
                errorCode: 'settings.type_mismatch',
                errors: [
                    'value' => [sprintf(
                        'This setting is declared "%s" and must be %s.',
                        $setting->type->label(),
                        $expected,
                    )],
                ],
                context: ['key' => (string) $setting->key, 'declared' => $setting->type->value],
            );
        }

        // The allow-list is the reason a Choice setting exists: it turns a
        // free-text box that can hold anything into a select that cannot.
        if (is_string($value) && ! $setting->allowsValue($value)) {
            throw new PlatformException(
                message: sprintf('"%s" is not one of the values allowed for %s.', $value, $setting->key),
                errorCode: 'settings.value_not_allowed',
                errors: ['value' => ['Choose one of the listed options.']],
                context: [
                    'key' => (string) $setting->key,
                    'allowed' => is_array($setting->allowed_values) ? $setting->allowed_values : [],
                ],
            );
        }
    }
}
