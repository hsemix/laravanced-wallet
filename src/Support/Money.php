<?php

declare(strict_types=1);

namespace Hsemix\AdvancedWallet\Support;

use Hsemix\AdvancedWallet\Exceptions\InvalidParameterException;

/**
 * Decimal-string arithmetic on top of bcmath. Amounts are never handled as
 * floats internally, so a ledger never drifts by fractions of a cent.
 */
final class Money
{
    public const SCALE = 4;

    private const WORK_SCALE = 10;

    /** Normalise int|float|string to a plain decimal string (no exponent). */
    public static function normalize(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, self::WORK_SCALE, '.', ''), '0'), '.');
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidParameterException("Invalid monetary amount: {$value}");
        }

        return $value;
    }

    public static function add(int|float|string $a, int|float|string $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::WORK_SCALE);
    }

    public static function sub(int|float|string $a, int|float|string $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::WORK_SCALE);
    }

    public static function mul(int|float|string $a, int|float|string $b): string
    {
        return bcmul(self::normalize($a), self::normalize($b), self::WORK_SCALE);
    }

    public static function div(int|float|string $a, int|float|string $b): string
    {
        return bcdiv(self::normalize($a), self::normalize($b), self::WORK_SCALE);
    }

    public static function comp(int|float|string $a, int|float|string $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::WORK_SCALE);
    }

    public static function gt(int|float|string $a, int|float|string $b): bool
    {
        return self::comp($a, $b) > 0;
    }

    public static function gte(int|float|string $a, int|float|string $b): bool
    {
        return self::comp($a, $b) >= 0;
    }

    public static function lt(int|float|string $a, int|float|string $b): bool
    {
        return self::comp($a, $b) < 0;
    }

    public static function lte(int|float|string $a, int|float|string $b): bool
    {
        return self::comp($a, $b) <= 0;
    }

    public static function isZero(int|float|string $a): bool
    {
        return self::comp($a, '0') === 0;
    }

    public static function isPositive(int|float|string $a): bool
    {
        return self::comp($a, '0') > 0;
    }

    /** Round half away from zero to the ledger scale (default 4 places). */
    public static function round(int|float|string $value, int $scale = self::SCALE): string
    {
        $value = self::normalize($value);
        $half = '0.'.str_repeat('0', $scale).'5';

        $rounded = str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);

        return $rounded;
    }
}
