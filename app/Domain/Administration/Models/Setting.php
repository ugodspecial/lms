<?php

declare(strict_types=1);

namespace App\Domain\Administration\Models;

use App\Domain\Administration\Enums\SettingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An administrator-editable platform value (§30, §82).
 *
 * This table exists so that nothing business-shaped is hard-coded: academic years,
 * grade scales, cancellation windows, tax rates, currency, the video provider.
 * Code reads a setting; it does not contain the number.
 *
 * The fillable list is the point of the security boundary here. `group`, `key`,
 * `value`, `type` and `description` are what an administrator edits. `is_secret`,
 * `is_public` and `allowed_values` are NOT mass assignable, because they describe
 * how a setting behaves rather than what it holds: a request that could flip
 * `is_public` on a secret would publish it, and one that could widen
 * `allowed_values` would remove the constraint the value is validated against.
 * Those three are written by code that has already decided the caller may change
 * the shape of the setting, not merely its contents.
 *
 * `is_secret` means the value must never be rendered into a page, a JSON response
 * or an export — the payment secret keys live here (§30.7), and the rule is
 * deny-by-default at the presentation boundary.
 *
 * @property SettingType $type
 * @property bool $is_secret
 * @property bool $is_public
 */
#[Fillable(['group', 'key', 'value', 'type', 'description'])]
class Setting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'allowed_values' => 'array',
            'type' => SettingType::class,
            'is_secret' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopeInGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    /**
     * Settings that may be sent to a browser.
     *
     * Both flags have to agree: `is_public` is an opt-in and `is_secret` is a veto,
     * so a row that was somehow marked both stays out. The payment secret keys live
     * in this table (§30.7) and the rule is deny-by-default at the presentation
     * boundary — one flag must never be able to override the other.
     *
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopeExposedToClient(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_secret', false);
    }

    /**
     * The stored value as the type it was declared to be.
     *
     * Everything arrives as decoded JSON, so a setting declared `int` is a string
     * or a number depending on who last wrote it, and callers comparing it with
     * `===` would get different answers for the same setting. Coercing in one
     * place means a reader never has to guess.
     *
     * Note that a decimal setting is never an amount of money. Money is stored as
     * integer minor units with a currency (ADR-03), so it arrives as
     * SettingType::Integer; a decimal here is a rate or a percentage.
     */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            SettingType::Boolean => (bool) $this->value,
            SettingType::Integer => (int) $this->value,
            SettingType::Decimal => (float) $this->value,
            SettingType::Json => $this->value,
            SettingType::Choice, SettingType::Text => (string) $this->value,
        };
    }

    /**
     * Whether a candidate value is one this setting accepts.
     *
     * Only meaningful for SettingType::Choice; every other type is validated by
     * its shape.
     */
    public function allowsValue(string $candidate): bool
    {
        // Read once into a local so the type narrows for the check below. A NULL
        // or malformed list means nobody enumerated the options, not that every
        // option is forbidden — otherwise a choice setting could not exist before
        // its list was filled in.
        $allowed = $this->allowed_values;

        if ($this->type !== SettingType::Choice || ! is_array($allowed)) {
            return true;
        }

        return in_array($candidate, $allowed, true);
    }
}
