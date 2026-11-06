<?php

namespace Tests\Unit\Fulfillment;

use App\Services\Fulfillment\Support\ExchangeRate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A rate from the store's text column is accepted only as a plain positive decimal. */
class ExchangeRateTest extends TestCase
{
    /** @return iterable<string, array{mixed, float}> */
    public static function usable(): iterable
    {
        yield 'whole number' => ['1', 1.0];
        yield 'two decimals' => ['3.75', 3.75];
        yield 'spaces around it' => [' 3.75 ', 3.75];
        yield 'tab and newline around it' => ["\t3.75\n", 3.75];
        yield 'leading zero' => ['0.2666', 0.2666];
        yield 'the smallest believable rate' => ['0.000001', 0.000001];
        yield 'trailing zeros' => ['3.7500000000', 3.75];
        yield 'twenty decimals' => ['1.12345678901234567890', 1.1234567890123457];
        yield 'the largest believable rate' => ['1000000000', 1000000000.0];
        yield 'nine digits' => ['999999999', 999999999.0];
        yield 'an integer from a number column' => [4, 4.0];
        yield 'a float from a number column' => [3.75, 3.75];
    }

    #[DataProvider('usable')]
    public function test_a_plain_positive_decimal_is_a_rate(mixed $value, float $expected): void
    {
        $this->assertSame($expected, ExchangeRate::parse($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusable(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'letters' => ['abc'];
        yield 'comma as the decimal mark' => ['3,75'];
        yield 'thousands separator' => ['1,000.5'];
        yield 'exponent' => ['1e3'];
        yield 'plus sign' => ['+2'];
        yield 'minus sign' => ['-2'];
        yield 'zero' => ['0'];
        yield 'zero with decimals' => ['0.0'];
        yield 'zeros' => ['0000'];
        yield 'no digit before the point' => ['.5'];
        yield 'no digit after the point' => ['5.'];
        yield 'two points' => ['3.7.5'];
        yield 'not a number' => ['NaN'];
        yield 'infinity' => ['INF'];
        yield 'arabic-indic digits' => ['٣٫٧٥'];
        yield 'full-width digits' => ['１２'];
        yield 'a unit after it' => ['3.75 USD'];
        yield 'hexadecimal' => ['0x1A'];
        yield 'sixteen digits' => ['1234567890123456'];
        yield 'fifteen digits, a billion times too large' => ['999999999999999'];
        yield 'just above the largest believable rate' => ['1000000001'];
        yield 'smaller than any real currency' => ['0.0000001'];
        yield 'twenty zeros and a one' => ['0.00000000000000000001'];
        yield 'twenty-one decimals' => ['1.123456789012345678901'];
        yield 'null' => [null];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'an array' => [[3.75]];
        yield 'integer zero' => [0];
        yield 'negative integer' => [-1];
        yield 'float zero' => [0.0];
        yield 'negative float' => [-3.75];
        yield 'float not a number' => [NAN];
        yield 'float smaller than any real currency' => [1.0E-7];
        yield 'float larger than any real currency' => [1.0E+10];
        yield 'float infinity' => [INF];
    }

    #[DataProvider('unusable')]
    public function test_anything_else_is_no_rate_at_all(mixed $value): void
    {
        $this->assertNull(ExchangeRate::parse($value));
    }
}
