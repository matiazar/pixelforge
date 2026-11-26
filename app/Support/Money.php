<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is integer nano-USD everywhere (1 USD = 1_000_000_000).
 * Conversions to and from decimal strings never go through floats.
 */
final class Money
{
    public const NANO_PER_USD = 1_000_000_000;

    private const DECIMALS = 9;

    /**
     * "0.373875000" -> 373875000. Rejects anything that is not an exact decimal
     * with at most 9 decimals and 9 integer digits (keeps the result inside a 64-bit int).
     */
    public static function fromDecimalString(string $amount): int
    {
        if (preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,9}))?$/', trim($amount), $m) !== 1) {
            throw new InvalidArgumentException("Not an exact USD amount: [{$amount}]");
        }

        $whole = (int) $m[2];
        $fraction = (int) str_pad($m[3] ?? '', self::DECIMALS, '0');
        $nano = $whole * self::NANO_PER_USD + $fraction;

        return $m[1] === '-' ? -$nano : $nano;
    }

    /**
     * 467343750 -> "0.46734375": up to 9 decimals, at least $minDecimals, never rounded.
     */
    public static function format(int $nano, int $minDecimals = 2): string
    {
        $sign = $nano < 0 ? '-' : '';
        $abs = abs($nano);

        $whole = intdiv($abs, self::NANO_PER_USD);
        $fraction = rtrim(str_pad((string) ($abs % self::NANO_PER_USD), self::DECIMALS, '0', STR_PAD_LEFT), '0');
        $fraction = str_pad($fraction, $minDecimals, '0');

        return $sign.$whole.($fraction === '' ? '' : '.'.$fraction);
    }

    /**
     * Applies a percent markup, rounding up to the next nano when it is not exact.
     */
    public static function withMarkup(int $nano, int $percent): int
    {
        $numerator = $nano * (100 + $percent);

        return intdiv($numerator, 100) + ($numerator % 100 > 0 ? 1 : 0);
    }
}
