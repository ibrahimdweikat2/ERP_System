<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Decimal
{
    public const MONEY_RULE = 'regex:/^(0|[1-9][0-9]{0,13})(\\.[0-9]{1,4})?$/';

    public const RATE_RULE = 'regex:/^(0|[1-9][0-9]{0,9})(\\.[0-9]{1,8})?$/';

    public static function of(string|int $value): BigDecimal
    {
        return BigDecimal::of($value);
    }

    public static function money(string|int|BigDecimal $value): string
    {
        return (string) BigDecimal::of($value)->toScale(4, RoundingMode::HALF_UP);
    }

    public static function add(string $a, string $b): string
    {
        return self::money(self::of($a)->plus($b));
    }

    public static function sub(string $a, string $b): string
    {
        return self::money(self::of($a)->minus($b));
    }

    public static function mul(string $a, string $b): string
    {
        return self::money(self::of($a)->multipliedBy($b));
    }

    public static function cmp(string $a, string $b): int
    {
        return self::of($a)->compareTo($b);
    }
}
