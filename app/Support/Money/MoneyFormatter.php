<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Domain\Commerce\ValueObjects\Currency;
use App\Domain\Commerce\ValueObjects\Money;

/**
 * Presentation-layer formatting for Money.
 *
 * Deliberately NOT part of the domain value object: how an amount is *rendered*
 * (symbol position, separators, whether to show decimals on a receipt line) is
 * a view concern. The domain object carries only exact integer minor units.
 *
 * This is the only place a currency symbol appears (§61) — no ₦ is ever
 * hard-coded in a service, policy, migration or Blade file.
 */
final class MoneyFormatter
{
    /** Human-facing amount with symbol: "₦45,000.00". */
    public function format(int|Money $amount, ?string $currencyCode = null): string
    {
        [$minor, $currency] = $this->resolve($amount, $currencyCode);

        $major = $currency->toMajor($minor);
        [$whole, $fraction] = array_pad(explode('.', $major, 2), 2, '');

        $whole = number_format((int) $whole, 0, '', $currency->thousandsSeparator);
        $body = $currency->exponent > 0 ? $whole.$currency->decimalSeparator.$fraction : $whole;

        if ($minor < 0) {
            $body = '-'.$body;
        }

        return $currency->symbolFirst
            ? $currency->symbol.$body
            : $body.' '.$currency->symbol;
    }

    /**
     * Amount without a symbol, for input fields and CSV exports where a symbol
     * would be noise or would break parsing.
     */
    public function plain(int|Money $amount, ?string $currencyCode = null): string
    {
        [$minor, $currency] = $this->resolve($amount, $currencyCode);

        return $currency->toMajor($minor);
    }

    /**
     * Compact form for dense dashboards: "₦45K", "₦1.2M".
     */
    public function compact(int|Money $amount, ?string $currencyCode = null): string
    {
        [$minor, $currency] = $this->resolve($amount, $currencyCode);

        $value = $minor / $currency->minorUnitFactor();
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        $formatted = match (true) {
            $abs >= 1_000_000_000 => rtrim(rtrim(number_format($abs / 1_000_000_000, 2), '0'), '.').'B',
            $abs >= 1_000_000 => rtrim(rtrim(number_format($abs / 1_000_000, 2), '0'), '.').'M',
            $abs >= 1_000 => rtrim(rtrim(number_format($abs / 1_000, 1), '0'), '.').'K',
            default => $currency->exponent > 0 ? number_format($abs, 2) : (string) (int) $abs,
        };

        return $currency->symbolFirst
            ? $sign.$currency->symbol.$formatted
            : $sign.$formatted.' '.$currency->symbol;
    }

    /**
     * Parse user input into minor units.
     *
     * Accepts "45000", "45000.00" and " 45,000.00 " (commas stripped). Returns
     * null for empty input so a Form Request can distinguish "not provided"
     * from "zero" — a refund of zero is not the same as no refund.
     */
    public function parse(?string $input, ?string $currencyCode = null): ?int
    {
        if ($input === null) {
            return null;
        }

        $clean = str_replace([',', ' ', "\u{00A0}"], '', $input);

        if ($clean === '') {
            return null;
        }

        $currency = Currency::of($currencyCode ?? (string) config('platform.currency', 'NGN'));

        return $currency->toMinor($clean);
    }

    /**
     * @return array{0: int, 1: Currency}
     */
    private function resolve(int|Money $amount, ?string $currencyCode): array
    {
        if ($amount instanceof Money) {
            return [$amount->minorUnits, $amount->currency];
        }

        return [$amount, Currency::of($currencyCode ?? (string) config('platform.currency', 'NGN'))];
    }
}
