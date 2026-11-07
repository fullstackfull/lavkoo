<?php

namespace Tests\Unit\OperationalCosts;

use App\Services\OperationalCosts\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_floats_are_not_added_as_floats(): void
    {
        $this->assertSame('0.300000', Money::of(0.1)->plus(0.2)->toStorage());
        $this->assertSame('1.100000', Money::of('0.7')->plus('0.4')->toStorage());
    }

    public function test_a_split_adds_up_to_exactly_the_whole(): void
    {
        $parts = Money::of('100')->allocate(['a' => 1, 'b' => 1, 'c' => 1]);

        $this->assertSame('100.000000', Money::sum($parts)->toStorage());
        $this->assertSame(['a', 'b', 'c'], array_keys($parts));
    }

    public function test_a_weighted_split_follows_the_weights_and_still_adds_up(): void
    {
        $parts = Money::of('10.00')->allocate([1, 2, 3, 4]);

        $this->assertSame('10.000000', Money::sum($parts)->toStorage());
        $this->assertSame('1.000000', $parts[0]->toStorage());
        $this->assertSame('4.000000', $parts[3]->toStorage());
    }

    public function test_a_split_with_no_usable_weights_is_even(): void
    {
        $parts = Money::of('9')->allocate([0, 0, 0]);

        $this->assertSame(['3.000000', '3.000000', '3.000000'], array_map(static fn (Money $part): string => $part->toStorage(), $parts));
    }

    public function test_a_split_of_a_negative_amount_keeps_its_sign_and_total(): void
    {
        $parts = Money::of('-10')->allocate([1, 1, 1]);

        $this->assertSame('-10.000000', Money::sum($parts)->toStorage());
    }

    public function test_a_percentage_is_exact(): void
    {
        $this->assertSame('1.500000', Money::of('10')->percent('15')->toStorage());
        $this->assertSame('2.900000', Money::of('100')->percent('2.9')->toStorage());
    }

    public function test_storage_rounds_half_up_to_six_decimals_and_display_to_two(): void
    {
        $this->assertSame('0.123457', Money::of('0.1234565')->toStorage());
        $this->assertSame('1.01', Money::of('1.005')->toDisplay());
    }

    public function test_division_by_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(1)->dividedBy(0);
    }

    public function test_text_that_is_not_a_number_is_refused_instead_of_becoming_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('twelve');
    }

    public function test_sum_skips_unknowns_but_the_caller_still_sees_them_as_null(): void
    {
        $this->assertSame('3.000000', Money::sum([1, null, 2])->toStorage());
        $this->assertNull(Money::ofNullable(null));
        $this->assertNull(Money::ofNullable(''));
    }
}
