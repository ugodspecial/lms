<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Administration;

use App\Domain\Administration\Enums\SettingType;
use App\Domain\Administration\Models\Setting;
use Tests\TestCase;

/**
 * Settings are how the platform avoids hard-coding business values (§30, §82):
 * academic years, grade scales, cancellation windows, tax rates, currency, video
 * provider. Two things can go wrong in the model that carries them, and both are
 * silent.
 *
 * The first is type. Everything arrives from a JSON column, so a setting declared
 * `int` may come back as the string "5" or the number 5 depending on who last
 * wrote it, and a caller comparing with `===` gets different answers for the same
 * setting on different days.
 *
 * The second is that the columns describing how a setting BEHAVES are editable by
 * the same form that edits its value. `is_secret` decides whether the value may
 * ever be rendered; `is_public` decides whether it may be exposed to the client;
 * `allowed_values` is the list the value is validated against. A request that can
 * set them can publish a secret or delete its own constraint.
 */
final class SettingModelTest extends TestCase
{
    private function setting(SettingType $type, mixed $value): Setting
    {
        return new Setting([
            'group' => 'platform',
            'key' => 'test.key',
            'value' => $value,
            'type' => $type,
        ]);
    }

    public function test_a_value_declared_integer_reads_as_an_integer(): void
    {
        // Both spellings occur in practice: a seeder writes 5, an admin form posts
        // "5". The reader must not have to know which happened.
        $this->assertSame(7, $this->setting(SettingType::Integer, 7)->typedValue());
        $this->assertSame(7, $this->setting(SettingType::Integer, '7')->typedValue());
    }

    public function test_a_value_declared_boolean_reads_as_a_boolean(): void
    {
        $this->assertTrue($this->setting(SettingType::Boolean, true)->typedValue());
        $this->assertFalse($this->setting(SettingType::Boolean, false)->typedValue());
        $this->assertFalse($this->setting(SettingType::Boolean, 0)->typedValue());
    }

    public function test_a_value_declared_decimal_reads_as_a_number(): void
    {
        // A decimal setting is a rate or a percentage, never an amount. Money is
        // integer minor units with a currency (ADR-03), so it arrives as
        // SettingType::Integer and cannot be confused with this.
        $this->assertSame(7.5, $this->setting(SettingType::Decimal, '7.50')->typedValue());
        $this->assertSame(7.5, $this->setting(SettingType::Decimal, 7.5)->typedValue());
    }

    public function test_a_value_declared_text_reads_as_a_string(): void
    {
        $this->assertSame('NGN', $this->setting(SettingType::Text, 'NGN')->typedValue());

        // A number stored under a text key stays text: an academic year is an
        // identifier, not a quantity, and "2026" must not become 2026.
        $this->assertSame('2026', $this->setting(SettingType::Text, 2026)->typedValue());
    }

    public function test_a_value_declared_json_reads_back_as_the_structure_that_was_written(): void
    {
        $value = ['grades' => [['code' => 'A', 'min' => 70]], 'pass' => 50];

        $this->assertSame($value, $this->setting(SettingType::Json, $value)->typedValue());
    }

    public function test_a_choice_setting_only_accepts_the_values_it_declares(): void
    {
        $setting = $this->setting(SettingType::Choice, 'NGN');
        $setting->allowed_values = ['NGN', 'USD', 'GBP'];

        $this->assertTrue($setting->allowsValue('NGN'));
        $this->assertTrue($setting->allowsValue('USD'));
        $this->assertFalse($setting->allowsValue('EUR'), 'an undeclared currency must be refused');
        $this->assertFalse($setting->allowsValue('ngn'), 'the match is exact, not case-folded');
    }

    public function test_a_choice_setting_with_no_declared_list_accepts_any_value(): void
    {
        // NULL means nobody enumerated the options, not that every option is
        // forbidden — otherwise a choice setting could never be created before its
        // list was filled in.
        $this->assertTrue($this->setting(SettingType::Choice, 'NGN')->allowsValue('anything'));
    }

    public function test_a_non_choice_setting_is_validated_by_its_shape_not_by_a_list(): void
    {
        $setting = $this->setting(SettingType::Text, 'NGN');
        $setting->allowed_values = ['USD'];

        $this->assertTrue($setting->allowsValue('NGN'));
    }

    public function test_the_columns_that_describe_how_a_setting_behaves_are_not_mass_assignable(): void
    {
        $this->assertSame(
            ['group', 'key', 'value', 'type', 'description'],
            (new Setting)->getFillable()
        );
    }
}
