<?php

namespace App\Payment;

final class FiatAmount
{
    private const MINOR_UNITS = [
        'JPY' => 0,
    ];

    public static function convertFromBase(int $baseAmount, mixed $rate, string $currency): int
    {
        $rate = self::validRate($rate);
        $factor = bcpow('10', (string) self::minorUnits($currency), 0);
        $targetMinor = bcdiv(
            bcmul(bcmul((string) $baseAmount, $rate, 12), $factor, 12),
            '100',
            12
        );

        return (int) bcadd($targetMinor, '0.5', 0);
    }

    public static function fromMajor(mixed $amount, string $currency): ?int
    {
        if (! is_scalar($amount) || ! is_numeric((string) $amount) || bccomp((string) $amount, '0', 12) < 0) {
            return null;
        }

        $factor = bcpow('10', (string) self::minorUnits($currency), 0);
        $minor = bcmul((string) $amount, $factor, 12);

        return (int) bcadd($minor, '0.5', 0);
    }

    public static function formatMinor(int $amount, string $currency): string
    {
        $decimals = self::minorUnits($currency);

        return bcdiv((string) $amount, bcpow('10', (string) $decimals, 0), $decimals);
    }

    private static function validRate(mixed $rate): string
    {
        if (! is_scalar($rate) || ! is_numeric((string) $rate) || bccomp((string) $rate, '0', 12) <= 0) {
            return '1';
        }

        return (string) $rate;
    }

    private static function minorUnits(string $currency): int
    {
        return self::MINOR_UNITS[strtoupper($currency)] ?? 2;
    }
}
