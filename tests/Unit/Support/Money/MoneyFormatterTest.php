<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Money;

use App\Domain\Commerce\ValueObjects\Money;
use App\Support\Money\MoneyFormatter;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Money presentation.
 *
 * The rule being enforced here is §61: no currency symbol is ever hard-coded in
 * business logic or markup. If formatting is wrong, an invoice shows the wrong
 * symbol to a customer in another country — and the fix must be possible without
 * editing a single Blade file.
 */
final class MoneyFormatterTest extends TestCase
{
    private MoneyFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new MoneyFormatter;
    }

    public function test_it_uses_the_symbol_from_configuration_not_a_literal(): void
    {
        $symbol = (string) config('platform.currencies.NGN.symbol');

        $this->assertStringStartsWith($symbol, $this->formatter->format(4_500_000, 'NGN'));
        $this->assertStringNotContainsString('₦', $this->formatter->format(100, 'USD'));
    }

    public function test_it_formats_with_thousands_separators_and_two_decimals(): void
    {
        $this->assertSame('₦45,000.00', $this->formatter->format(4_500_000, 'NGN'));
        $this->assertSame('₦1,234,567.89', $this->formatter->format(123_456_789, 'NGN'));
        $this->assertSame('₦0.01', $this->formatter->format(1, 'NGN'));
        $this->assertSame('₦0.00', $this->formatter->format(0, 'NGN'));
    }

    public function test_it_formats_negative_amounts_with_a_leading_minus(): void
    {
        // A refund line must read as negative at a glance on a printed invoice.
        $this->assertSame('-₦45,000.00', $this->formatter->format(-4_500_000, 'NGN'));
    }

    public function test_it_respects_per_locale_separators(): void
    {
        // German-style: dot for thousands, comma for decimals.
        $this->assertSame('€1.234,56', $this->formatter->format(123_456, 'EUR'));
    }

    public function test_it_respects_symbol_position(): void
    {
        config(['platform.currencies.NGN.symbol_first' => false]);

        $this->assertSame('45,000.00 ₦', $this->formatter->format(4_500_000, 'NGN'));
    }

    public function test_zero_decimal_currencies_show_no_fraction(): void
    {
        $this->assertSame('CFA1 000', $this->formatter->format(1_000, 'XOF'));
        $this->assertSame('CFA0', $this->formatter->format(0, 'XOF'));
    }

    public function test_it_accepts_a_money_value_object(): void
    {
        $this->assertSame('₦45,000.00', $this->formatter->format(Money::of(4_500_000, 'NGN')));
    }

    public function test_it_falls_back_to_the_configured_base_currency(): void
    {
        config(['platform.currency' => 'NGN']);

        $this->assertSame('₦100.00', $this->formatter->format(10_000));
    }

    public function test_plain_output_omits_the_symbol_for_inputs_and_exports(): void
    {
        $this->assertSame('45000.00', $this->formatter->plain(4_500_000, 'NGN'));
        $this->assertSame('-45000.00', $this->formatter->plain(-4_500_000, 'NGN'));
        $this->assertSame('1000', $this->formatter->plain(1_000, 'XOF'));
    }

    public function test_compact_output_stays_readable_on_a_dense_dashboard(): void
    {
        // Below 1,000 major units the exact amount is shown; above it, an
        // abbreviation. Both are deliberate: "₦999.00" is already short, while
        // "₦45,000.00" is too wide for a dashboard tile.
        $this->assertSame('₦999.00', $this->formatter->compact(99_900, 'NGN'));
        $this->assertSame('₦45K', $this->formatter->compact(4_500_000, 'NGN'));
        $this->assertSame('₦1.5K', $this->formatter->compact(150_000, 'NGN'));
        $this->assertSame('₦1.2M', $this->formatter->compact(120_000_000, 'NGN'));
        $this->assertSame('₦3.4B', $this->formatter->compact(340_000_000_000, 'NGN'));
        $this->assertSame('-₦45K', $this->formatter->compact(-4_500_000, 'NGN'));
    }

    public function test_it_parses_user_input_back_into_minor_units(): void
    {
        $this->assertSame(4_500_000, $this->formatter->parse('45000', 'NGN'));
        $this->assertSame(4_500_000, $this->formatter->parse('45000.00', 'NGN'));

        // An amount pasted from a spreadsheet or an invoice arrives with
        // separators and surrounding space; rejecting it would push a tutor to
        // retype a price and risk a typo.
        $this->assertSame(4_500_000, $this->formatter->parse(' 45,000.00 ', 'NGN'));
        $this->assertSame(-4_500_000, $this->formatter->parse('-45000.00', 'NGN'));
        $this->assertSame(50, $this->formatter->parse('0.5', 'NGN'));
    }

    public function test_empty_input_is_null_rather_than_zero(): void
    {
        // "No amount entered" and "an amount of zero" are different facts: a
        // free product costs zero, an unfilled refund field means no refund.
        // Collapsing them would issue refunds of ₦0.00 or price things wrongly.
        $this->assertNull($this->formatter->parse(null, 'NGN'));
        $this->assertNull($this->formatter->parse('', 'NGN'));
        $this->assertNull($this->formatter->parse('   ', 'NGN'));
        $this->assertSame(0, $this->formatter->parse('0', 'NGN'));
    }

    public function test_malformed_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->formatter->parse('45,000 Naira', 'NGN');
    }

    public function test_an_unknown_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->formatter->format(100, 'ZZZ');
    }

    public function test_the_global_helper_matches_the_formatter(): void
    {
        // money() is the only symbol-producing call available to Blade; if it
        // ever diverged from the formatter, the same amount would render
        // differently on two pages.
        $this->assertSame(
            $this->formatter->format(4_500_000, 'NGN'),
            money(4_500_000, 'NGN')
        );
    }
}
