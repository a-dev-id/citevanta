<?php

namespace App\Services\Billing;

/**
 * Amounts in minor units: rupiah for IDR (no decimals), cents for USD.
 */
final class Money
{
    public const CURRENCIES = ['usd', 'idr'];

    /** Minor units per major unit. */
    public static function factor(string $currency): int
    {
        return $currency === 'idr' ? 1 : 100;
    }

    public static function fromMajor(int|float $amount, string $currency): int
    {
        return (int) round($amount * self::factor($currency));
    }

    public static function toMajor(int $amount, string $currency): float|int
    {
        if ($currency === 'idr' || $amount % 100 === 0) {
            return intdiv($amount, self::factor($currency));
        }

        return round($amount / 100, 2);
    }

    /**
     * "Rp 790.123" or "$49.37" ("$49" when there are no cents).
     */
    public static function format(int $amount, string $currency): string
    {
        if ($currency === 'idr') {
            return 'Rp '.number_format($amount, 0, ',', '.');
        }

        $decimals = $amount % 100 === 0 ? 0 : 2;

        return '$'.number_format($amount / 100, $decimals);
    }
}
