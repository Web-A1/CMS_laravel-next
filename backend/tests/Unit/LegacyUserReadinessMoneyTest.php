<?php

namespace Tests\Unit;

use App\Services\LegacyUsers\Readiness\ExactMoney;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LegacyUserReadinessMoneyTest extends TestCase
{
    public function test_exact_minor_units_and_integer_boundaries(): void
    {
        $this->assertSame(29, ExactMoney::minor('0.29'));
        $this->assertSame(-1, ExactMoney::minor('-0.01'));
        $this->assertSame(999999999999999999, ExactMoney::minor('9999999999999999.99'));
        $this->assertSame(PHP_INT_MAX, ExactMoney::add(PHP_INT_MAX - 1, 1));
        $this->assertSame(PHP_INT_MIN, ExactMoney::add(PHP_INT_MIN + 1, -1));
    }

    public function test_float_money_cannot_be_recovered_by_coercion(): void
    {
        $this->expectException(RuntimeException::class);
        ExactMoney::minor(0.29);
    }

    public function test_positive_total_overflow_is_rejected_before_php_converts_to_float(): void
    {
        $this->expectException(RuntimeException::class);
        ExactMoney::add(PHP_INT_MAX, 1);
    }

    public function test_negative_total_overflow_is_rejected_before_php_converts_to_float(): void
    {
        $this->expectException(RuntimeException::class);
        ExactMoney::add(PHP_INT_MIN, -1);
    }
}
