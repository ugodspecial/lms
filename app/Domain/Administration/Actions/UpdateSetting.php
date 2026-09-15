<?php

declare(strict_types=1);

namespace App\Domain\Administration\Actions;

use App\Domain\Administration\Models\Setting;
use App\Domain\Administration\Services\SettingsService;
use App\Domain\Identity\Models\User;
use App\Exceptions\PlatformException;

/**
 * The use case behind "an administrator changed a setting" (§60, docs/01 §5.3).
 *
 * Thin on purpose, and thin in a specific way. Authorization is NOT here: it is
 * SettingPolicy's job, invoked by the controller or Form Request before this
 * runs. A use case that re-checked a permission could not be reused from a
 * console command, a seeder or an installer that has already established the
 * right to act — and "the action also checks" tends to become the only check
 * anybody writes a test for.
 *
 * What IS here are the two things only a use case knows: which setting a key
 * refers to, and how to tell a human that it does not exist.
 *
 * Creating a setting is deliberately not part of this action. `is_secret`,
 * `is_public` and `allowed_values` are not mass assignable (see Setting) because
 * they describe how a setting behaves rather than what it holds, and the same
 * reasoning applies to the row itself: the registry of settings is code —
 * shipped, reviewed and versioned — not something invented through a form.
 * Administrators change values; a deploy changes the shape. That is also why
 * docs/06's settings screen is a list-and-edit screen rather than a CRUD one.
 *
 * @throws PlatformException
 */
final class UpdateSetting
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Resolve a key and write its new value.
     *
     * The value must already be cast to the type the setting declares — an int
     * for an `int` setting, not the string `"5"` a form submitted. HTML forms
     * only ever produce strings, so the HTTP layer owns that conversion;
     * SettingsService refuses to do it silently, which is what makes the
     * requirement impossible to miss rather than a convention.
     *
     * @param  User|null  $actor  who is responsible; defaults to the authenticated user
     *
     * @throws PlatformException 404 `settings.unknown_key` if no setting has that
     *                           key; 422 `settings.type_mismatch` or
     *                           `settings.value_not_allowed` if the value is not
     *                           one the setting can hold
     */
    public function execute(string $key, mixed $value, ?User $actor = null): Setting
    {
        // `key` is a reserved word in MySQL. Eloquent quotes it, which is why
        // this reads like any other column — and why raw SQL against this table
        // must not be written by hand without backticks.
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null) {
            // 404 rather than 422: the request was well formed and the target
            // does not exist. A form that posts to a key nobody registered is
            // either a stale deploy or a probe, and both want "not found".
            throw new PlatformException(
                message: 'That setting does not exist.',
                errorCode: 'settings.unknown_key',
                statusCode: 404,
                errors: ['key' => [sprintf('"%s" is not a registered setting.', $key)]],
            );
        }

        return $this->settings->set($setting, $value, $actor);
    }
}
