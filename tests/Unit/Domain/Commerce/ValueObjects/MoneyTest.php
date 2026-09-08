<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Commerce\ValueObjects;

use App\Domain\Commerce\ValueObjects\Money;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Money arithmetic — the rules that keep a ledger balanced.
 *
 * These are unit tests with no database and no HTTP, which is exactly where the
 * financial correctness of the platform is decided (§71). A bug here is not
 * cosmetic: it is a wrong invoice, a wrong refund or a commission that does not
 * sum to the price it was taken from.
 */
final class MoneyTest extends TestCase
{
    public function test_it_carries_minor_units_and_currency_together(): void
    {
        $amount = Money::of(4_500_000, 'NGN');

        $this->assertSame(4_500_000, $amount->minorUnits);
        $this->assertSame('NGN', $amount->currencyCode());
        $this->assertSame('45000.00', $amount->toMajor());
    }

    public function test_it_builds_from_a_major_unit_string(): void
    {
        $this->assertSame(4_500_000, Money::fromString('45000.00', 'NGN')->minorUnits);
        $this->assertSame(50, Money::fromString('0.50', 'NGN')->minorUnits);
    }

    public function test_it_uses_the_configured_base_currency(): void
    {
        $this->assertSame(
            (string) config('platform.currency'),
            Money::base(100)->currencyCode()
        );
    }

    public function test_addition_and_subtraction_are_exact(): void
    {
        $a = Money::of(10, 'NGN');
        $b = Money::of(20, 'NGN');

        $this->assertSame(30, $a->add($b)->minorUnits);
        $this->assertSame(-10, $a->subtract($b)->minorUnits);

        // The value object is immutable: an operation returns a new instance and
        // leaves the operands untouched, so a total cannot be corrupted by a
        // loop that reuses the same Money.
        $this->assertSame(10, $a->minorUnits);
        $this->assertSame(20, $b->minorUnits);
    }

    public function test_the_classic_float_trap_does_not_apply(): void
    {
        // 0.1 + 0.2 in binary floating point is 0.30000000000000004. Three line
        // items of ₦0.10 must total exactly ₦0.30 on an invoice.
        $total = Money::of(10, 'NGN')->add(Money::of(10, 'NGN'))->add(Money::of(10, 'NGN'));

        $this->assertSame(30, $total->minorUnits);
        $this->assertSame('0.30', $total->toMajor());
    }

    public function test_mixing_currencies_throws_instead_of_converting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Convert explicitly through a recorded exchange rate');

        Money::of(100, 'NGN')->add(Money::of(100, 'USD'));
    }

    public function test_multiplication_by_quantity(): void
    {
        $this->assertSame(450_000, Money::of(150_000, 'NGN')->multiply(3)->minorUnits);
        $this->assertSame(0, Money::of(150_000, 'NGN')->multiply(0)->minorUnits);
    }

    public function test_percentage_is_integer_safe(): void
    {
        $amount = Money::of(10_000, 'NGN'); // ₦100.00

        $this->assertSame(1_000, $amount->percentage(10)->minorUnits);   // ₦10.00
        $this->assertSame(750, $amount->percentage(7.5)->minorUnits);     // ₦7.50
        $this->assertSame(0, $amount->percentage(0)->minorUnits);

        // A commission on an odd amount must not drift by a kobo.
        $this->assertSame(333, Money::of(3_333, 'NGN')->percentage(10)->minorUnits);
    }

    public function test_a_negative_percentage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(10_000, 'NGN')->percentage(-5);
    }

    public function test_allocation_sums_exactly_to_the_original(): void
    {
        // ₦100.01 split three ways cannot be three equal integer amounts. The
        // remainder goes to the leading parts, and — critically — the parts still
        // add up to the original, so a split invoice balances.
        $amount = Money::of(10_001, 'NGN');
        $parts = $amount->allocateTo(3);

        $this->assertCount(3, $parts);
        $this->assertSame([3334, 3334, 3333], array_map(fn (Money $m): int => $m->minorUnits, $parts));
        $this->assertSame(10_001, array_sum(array_map(fn (Money $m): int => $m->minorUnits, $parts)));
    }

    public function test_allocation_is_exact_for_every_divisor(): void
    {
        foreach ([1, 2, 3, 7, 12, 100] as $parts) {
            foreach ([1, 99, 100, 10_001, 999_999] as $minor) {
                $amount = Money::of($minor, 'NGN');
                $allocated = $amount->allocateTo($parts);

                $this->assertCount($parts, $allocated);
                $this->assertSame(
                    $minor,
                    array_sum(array_map(fn (Money $m): int => $m->minorUnits, $allocated)),
                    "Allocating {$minor} into {$parts} parts did not sum back to the original"
                );
            }
        }
    }

    public function test_allocating_to_fewer_than_one_part_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, 'NGN')->allocateTo(0);
    }

    public function test_sign_predicates(): void
    {
        $this->assertTrue(Money::of(0, 'NGN')->isZero());
        $this->assertTrue(Money::of(1, 'NGN')->isPositive());
        $this->assertTrue(Money::of(-1, 'NGN')->isNegative());
        $this->assertSame(-100, Money::of(100, 'NGN')->negate()->minorUnits);
    }

    public function test_comparison_is_currency_aware(): void
    {
        $small = Money::of(100, 'NGN');
        $large = Money::of(200, 'NGN');

        $this->assertTrue($small->lessThan($large));
        $this->assertTrue($large->greaterThan($small));
        $this->assertFalse($small->greaterThan($large));
        $this->assertTrue($small->equals(Money::of(100, 'NGN')));
        $this->assertFalse($small->equals(Money::of(100, 'USD')));
    }

    public function test_zero_construction(): void
    {
        $this->assertTrue(Money::zero('NGN')->isZero());
        $this->assertSame('NGN', Money::zero('NGN')->currencyCode());
    }
}
