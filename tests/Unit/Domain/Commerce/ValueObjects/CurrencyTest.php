<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Commerce\ValueObjects;

use App\Domain\Commerce\ValueObjects\Currency;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Currency conversion is the foundation every price, invoice and refund rests on.
 *
 * The cases that matter are the ones a "multiply by 100" implementation gets
 * silently wrong: a zero-decimal currency, a negative amount, a fraction longer
 * than the exponent, and an empty string. Each of those is a real money bug —
 * a ₦1 discrepancy on one invoice is a rounding error, the same discrepancy on
 * a recurring subscription is an accounting problem.
 */
final class CurrencyTest extends TestCase
{
    public function test_it_reads_the_exponent_from_configuration_rather_than_assuming_two(): void
    {
        $this->assertSame(2, Currency::of('NGN')->exponent);
        $this->assertSame(100, Currency::of('NGN')->minorUnitFactor());

        // West African CFA franc has no minor units at all.
        $this->assertSame(0, Currency::of('XOF')->exponent);
        $this->assertSame(1, Currency::of('XOF')->minorUnitFactor());
    }

    public function test_currency_codes_are_case_insensitive(): void
    {
        $this->assertSame('NGN', Currency::of('ngn')->code);
        $this->assertSame('NGN', Currency::of(' NGN ')->code);
    }

    public function test_an_unconfigured_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported currency [ZZZ]');

        Currency::of('ZZZ');
    }

    public function test_it_converts_major_units_to_minor_units(): void
    {
        $ngn = Currency::of('NGN');

        $this->assertSame(4_500_000, $ngn->toMinor('45000'));
        $this->assertSame(4_500_000, $ngn->toMinor('45000.00'));
        $this->assertSame(4_500_050, $ngn->toMinor('45000.50'));
        $this->assertSame(1, $ngn->toMinor('0.01'));
        $this->assertSame(0, $ngn->toMinor('0'));
        $this->assertSame(0, $ngn->toMinor('0.00'));
    }

    public function test_it_converts_negative_amounts(): void
    {
        // Refunds and credit notes are stored as negative minor units, so the
        // sign must survive the round trip rather than being dropped by abs().
        $ngn = Currency::of('NGN');

        $this->assertSame(-4_500_000, $ngn->toMinor('-45000.00'));
        $this->assertSame('-45000.00', $ngn->toMajor(-4_500_000));
    }

    public function test_it_truncates_a_fraction_longer_than_the_exponent(): void
    {
        // A sub-kobo amount cannot exist. Truncating is a deliberate, documented
        // choice; rounding would let a caller inject money that was never charged.
        $this->assertSame(4_500_000, Currency::of('NGN')->toMinor('45000.007'));
    }

    public function test_a_leading_zero_pads_correctly(): void
    {
        // "0.5" is 50 kobo, not 5. Getting this wrong is off by a factor of ten
        // and is easy to miss because the amount still looks plausible.
        $this->assertSame(50, Currency::of('NGN')->toMinor('0.5'));
        $this->assertSame(5, Currency::of('NGN')->toMinor('0.05'));
        $this->assertSame('0.50', Currency::of('NGN')->toMajor(50));
        $this->assertSame('0.05', Currency::of('NGN')->toMajor(5));
    }

    public function test_zero_decimal_currencies_take_no_fraction(): void
    {
        $xof = Currency::of('XOF');

        $this->assertSame(1000, $xof->toMinor('1000'));
        $this->assertSame('1000', $xof->toMajor(1000));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no minor units');

        $xof->toMinor('1000.50');
    }

    public function test_it_round_trips_without_losing_precision(): void
    {
        foreach (['NGN', 'USD', 'GBP', 'EUR', 'XOF'] as $code) {
            $currency = Currency::of($code);

            // An amount large enough to overflow a naive float: ₦9 quadrillion in
            // kobo exceeds PHP's float precision, which is exactly why minor
            // units are integers (ADR-02).
            foreach ([0, 1, 99, 100, 123456789, 9_007_199_254_740_992] as $minor) {
                $this->assertSame(
                    $minor,
                    $currency->toMinor($currency->toMajor($minor)),
                    "{$code} failed to round trip {$minor} minor units"
                );
            }
        }
    }

    public function test_it_rejects_malformed_input(): void
    {
        $ngn = Currency::of('NGN');

        foreach (['', '  ', 'abc', '1,000', '12.34.56', '--5', '1e3'] as $input) {
            try {
                $ngn->toMinor($input);
                $this->fail("Expected [{$input}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_configured_set_is_discoverable(): void
    {
        $available = Currency::available();

        $this->assertContains('NGN', $available);

        // The base currency must be one the platform actually knows how to
        // format, or every price on every page fails at render time.
        $base = (string) config('platform.currency');
        $this->assertContains($base, $available, "Base currency [{$base}] is not defined in config/platform.php");
        $this->assertInstanceOf(Currency::class, Currency::of($base));
    }
}
