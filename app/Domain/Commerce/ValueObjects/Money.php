<?php

declare(strict_types=1);

namespace App\Domain\Commerce\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * An immutable monetary amount: integer minor units + a currency.
 *
 * This is the single representation of money in the platform. Floats are never
 * used, so 0.1 + 0.2 can never become 0.30000000000000004 on an invoice
 * (§61, ADR-02).
 *
 * Cross-currency arithmetic throws rather than silently converting: an exchange
 * rate is a business decision that must be explicit, versioned and auditable,
 * never an accident of two objects meeting.
 *
 * @immutable
 */
final readonly class Money implements Stringable
{
    private function __construct(
        public int $minorUnits,
        public Currency $currency,
    ) {}

    public static function of(int $minorUnits, Currency|string $currency): self
    {
        return new self(
            $minorUnits,
            $currency instanceof Currency ? $currency : Currency::of($currency)
        );
    }

    /** Build from a major-unit string: Money::fromString('45000.00', 'NGN'). */
    public static function fromString(string $major, Currency|string $currency): self
    {
        $c = $currency instanceof Currency ? $currency : Currency::of($currency);

        return new self($c->toMinor($major), $c);
    }

    /** The organisation's configured base currency. */
    public static function base(int $minorUnits = 0): self
    {
        return self::of($minorUnits, (string) config('platform.currency', 'NGN'));
    }

    public static function zero(Currency|string $currency): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->minorUnits * $quantity, $this->currency);
    }

    /**
     * Allocate a percentage of the amount.
     *
     * Used for commission (ADR-07) and tax. Truncates toward zero: a platform
     * must never take more commission than the rate states, and the caller
     * decides what to do with the remainder.
     *
     * The rate is converted to basis points once, so the arithmetic that follows
     * is integer-only. Rounding a float percentage against a large minor-unit
     * balance is how a commission ends up a kobo off on every transaction.
     */
    public function percentage(float $percent): self
    {
        if ($percent < 0) {
            throw new InvalidArgumentException('Percentage cannot be negative.');
        }

        // 10% => 1000bp, 7.5% => 750bp.
        $basisPoints = (int) round($percent * 100);

        if ($basisPoints === 0) {
            return new self(0, $this->currency);
        }

        // bcmath where available: a ₦9 quadrillion balance multiplied by 10,000
        // basis points exceeds PHP_INT_MAX, and silently wrapping to a negative
        // commission would be catastrophic rather than merely wrong.
        if (function_exists('bcmul')) {
            $product = bcmul((string) $this->minorUnits, (string) $basisPoints);

            return new self((int) bcdiv($product, '10000', 0), $this->currency);
        }

        return new self(intdiv($this->minorUnits * $basisPoints, 10_000), $this->currency);
    }

    /**
     * Split the amount into N parts whose sum is exactly the original.
     *
     * The remainder minor units are distributed one each to the leading parts,
     * so a ₦100.01 split three ways is 3334/3334/3333 — never 3333.67.
     *
     * @return list<self>
     */
    public function allocateTo(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot allocate to fewer than one part.');
        }

        $share = intdiv($this->minorUnits, $parts);
        $remainder = $this->minorUnits - ($share * $parts);

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $result[] = new self($share + ($i < abs($remainder) ? ($remainder > 0 ? 1 : -1) : 0), $this->currency);
        }

        return $result;
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency)
            && $this->minorUnits === $other->minorUnits;
    }

    /** The major-unit decimal string, e.g. "45000.00". Safe for APIs and ledgers. */
    public function toMajor(): string
    {
        return $this->currency->toMajor($this->minorUnits);
    }

    public function currencyCode(): string
    {
        return $this->currency->code;
    }

    public function __toString(): string
    {
        return $this->toMajor();
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency->code} with {$other->currency->code}. ".
                'Convert explicitly through a recorded exchange rate.'
            );
        }
    }
}
