<?php

declare(strict_types=1);

namespace App\Domain\Commerce\ValueObjects;

use InvalidArgumentException;

/**
 * An ISO-4217 currency.
 *
 * The critical property is the **decimal exponent**: the number of minor units
 * per major unit is 10^exponent, and it is NOT always 2. NGN and USD are 2;
 * XOF (West African CFA franc) is 0. Hard-coding "× 100" would silently corrupt
 * every XOF amount (§61, ADR-02).
 *
 * Definitions come from config('platform.currencies') so adding a currency is a
 * configuration change, never a migration.
 *
 * @immutable
 */
final readonly class Currency
{
    /** @param array<string, mixed> $attributes */
    private function __construct(
        public string $code,
        public string $name,
        public int $exponent,
        public string $symbol,
        public bool $symbolFirst,
        public string $decimalSeparator,
        public string $thousandsSeparator,
        public array $attributes = [],
    ) {}

    /**
     * @throws InvalidArgumentException when the code is not in the configured set
     */
    public static function of(string $code): self
    {
        $code = strtoupper(trim($code));

        /** @var array<string, array<string, mixed>>|null $configured */
        $configured = config('platform.currencies');

        if (! is_array($configured) || ! isset($configured[$code])) {
            throw new InvalidArgumentException(
                "Unsupported currency [{$code}]. Add it to config/platform.php 'currencies'."
            );
        }

        $c = $configured[$code];

        return new self(
            code: $code,
            name: (string) ($c['name'] ?? $code),
            exponent: (int) ($c['exponent'] ?? 2),
            symbol: (string) ($c['symbol'] ?? $code),
            symbolFirst: (bool) ($c['symbol_first'] ?? true),
            decimalSeparator: (string) ($c['decimal'] ?? '.'),
            thousandsSeparator: (string) ($c['thousands'] ?? ','),
            attributes: $c,
        );
    }

    /** The multiplier between major and minor units (100 for exponent 2, 1 for exponent 0). */
    public function minorUnitFactor(): int
    {
        return 10 ** $this->exponent;
    }

    /**
     * Convert a major-unit string ("45000.00") to integer minor units.
     *
     * String arithmetic via bcmath where available, otherwise a scaled integer
     * parse. Floats are never involved — that is the whole point (ADR-02).
     */
    public function toMinor(string $major): int
    {
        $major = trim($major);

        if ($major === '') {
            throw new InvalidArgumentException('Cannot convert an empty amount to minor units.');
        }

        if (! preg_match('/^-?\d*(\.\d+)?$/', $major)) {
            throw new InvalidArgumentException("Amount [{$major}] is not a valid decimal string.");
        }

        $negative = str_starts_with($major, '-');
        $major = ltrim($major, '-');

        [$whole, $fraction] = array_pad(explode('.', $major, 2), 2, '');

        if ($this->exponent === 0) {
            // Zero-decimal currency: any fractional part is a caller error
            // rather than something to silently round away.
            if ($fraction !== '' && (int) $fraction !== 0) {
                throw new InvalidArgumentException(
                    "Currency [{$this->code}] has no minor units; got [{$major}] with a fraction."
                );
            }

            $minor = (int) ($whole === '' ? '0' : $whole);
        } else {
            $fraction = str_pad(substr($fraction, 0, $this->exponent), $this->exponent, '0');

            if (function_exists('bcadd')) {
                $minor = (int) bcmul($whole === '' ? '0' : $whole, (string) $this->minorUnitFactor());
                $minor += (int) $fraction;
            } else {
                $minor = (int) (($whole === '' ? '0' : $whole).$fraction);
            }
        }

        return $negative ? -$minor : $minor;
    }

    /** Convert integer minor units to a major-unit decimal string ("45000.00"). */
    public function toMajor(int $minor): string
    {
        $negative = $minor < 0;
        $abs = (string) abs($minor);

        if ($this->exponent === 0) {
            return ($negative ? '-' : '').$abs;
        }

        $abs = str_pad($abs, $this->exponent + 1, '0', STR_PAD_LEFT);
        $whole = substr($abs, 0, -$this->exponent);
        $fraction = substr($abs, -$this->exponent);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    /** All configured currency codes. */
    public static function available(): array
    {
        /** @var array<string, mixed>|null $configured */
        $configured = config('platform.currencies');

        return is_array($configured) ? array_keys($configured) : [];
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
