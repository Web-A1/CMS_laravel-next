<?php

namespace App\Services\LegacyUsers\Readiness;

use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use RuntimeException;

final class ExactMoney
{
    public static function minor(mixed $decimal): int
    {
        if (! is_string($decimal)) {
            throw new RuntimeException('Money requires an exact decimal string.');
        }

        return LegacyUserSourcePolicy::minorUnits($decimal);
    }

    public static function add(mixed $total, mixed $value): int
    {
        if (! is_int($total) || ! is_int($value)
            || ($value > 0 && $total > PHP_INT_MAX - $value)
            || ($value < 0 && $total < PHP_INT_MIN - $value)) {
            throw new RuntimeException('Exact integer total is outside the supported range.');
        }

        return $total + $value;
    }
}
