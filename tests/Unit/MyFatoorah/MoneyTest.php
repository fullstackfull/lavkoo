<?php

namespace Tests\Unit\MyFatoorah;

use App\Services\MyFatoorah\Money;
use PHPUnit\Framework\TestCase;

/**
 * Comparing two amounts.
 *
 * This decides whether a payment is accepted, so "close enough" is not good enough in either direction:
 * a false mismatch flags a correct payment for a human, and a false match releases an order that was
 * underpaid.
 */
class MoneyTest extends TestCase
{
    public function test_amounts_that_are_the_same_compare_equal_whatever_their_form(): void
    {
        $this->assertTrue(Money::equal('150.00', 150.0));
        $this->assertTrue(Money::equal('150', '150.00'));
        $this->assertTrue(Money::equal(150, '150.000'));
        $this->assertTrue(Money::equal('0.00', 0));
    }

    public function test_a_difference_of_one_halala_is_not_equal(): void
    {
        $this->assertFalse(Money::equal('150.00', '150.01'));
        $this->assertFalse(Money::equal('150.00', '149.99'));
    }

    /**
     * The float trap this class exists for: 1.15 is stored slightly below 1.15, so (int) (1.15 * 100) is
     * 114. Casting without rounding would read a correct payment as a halala short.
     */
    public function test_amounts_that_are_not_exact_in_binary_still_compare_correctly(): void
    {
        foreach (['1.15', '2.675', '0.07', '8.20', '1234.35', '19.99', '0.29'] as $amount) {
            $this->assertTrue(Money::equal($amount, (float) $amount), "{$amount} should equal itself");
        }

        $this->assertTrue(Money::equal('1.15', 1.15));
        $this->assertTrue(Money::equal(0.1 + 0.2, '0.30'));
    }

    public function test_greater_than_is_exact_and_not_true_for_equal_amounts(): void
    {
        $this->assertTrue(Money::greaterThan('150.01', '150.00'));
        $this->assertFalse(Money::greaterThan('150.00', '150.00'));
        $this->assertFalse(Money::greaterThan('149.99', '150.00'));

        // A refund of exactly the remaining balance must be allowed, not refused by a rounding fudge.
        $this->assertFalse(Money::greaterThan(30.0, 150.0 - 120.0));
    }

    public function test_format_always_gives_two_decimals_with_no_separators(): void
    {
        $this->assertSame('150.00', Money::format('150'));
        $this->assertSame('150.00', Money::format(150.0));
        $this->assertSame('1234.35', Money::format('1234.345'));
        $this->assertSame('0.00', Money::format(null));
        $this->assertSame('0.00', Money::format(''));
        // No thousands separator: the value goes into a decimal column and into an API body.
        $this->assertStringNotContainsString(',', Money::format(1234567.89));
    }

    public function test_a_null_amount_is_zero_rather_than_an_error(): void
    {
        $this->assertTrue(Money::equal(null, 0));
        $this->assertTrue(Money::equal(null, '0.00'));
        $this->assertFalse(Money::equal(null, '0.01'));
    }
}
