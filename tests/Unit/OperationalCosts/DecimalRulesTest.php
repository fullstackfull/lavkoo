<?php

namespace Tests\Unit\OperationalCosts;

use App\Http\Controllers\Admin\OperationalCosts\DecimalRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalRulesTest extends TestCase
{
    /** @return array<string, array{string, bool, bool, bool, bool}> value, amount, positive, signed, percent */
    public static function values(): array
    {
        return [
            'zero' => ['0', true, false, true, true],
            'zeros with decimals' => ['0.00', true, false, true, true],
            'a smallest unit' => ['0.000001', true, true, true, false],
            'an integer' => ['12', true, true, true, true],
            'two decimals' => ['12.50', true, true, true, true],
            'six decimals' => ['12.123456', true, true, true, false],
            'seven decimals' => ['12.1234567', false, false, false, false],
            'leading zeros' => ['007.5', true, true, true, true],
            'a negative' => ['-5', false, false, true, false],
            'a negative decimal' => ['-5.25', false, false, true, false],
            'a decimal comma' => ['12,5', false, false, false, false],
            'a thousands separator' => ['1,200.50', false, false, false, false],
            'scientific notation' => ['1e3', false, false, false, false],
            'a plus sign' => ['+5', false, false, false, false],
            'a trailing dot' => ['5.', false, false, false, false],
            'a leading dot' => ['.5', false, false, false, false],
            'a unit' => ['5 SAR', false, false, false, false],
            'words' => ['abc', false, false, false, false],
            'empty' => ['', false, false, false, false],
            'arabic digits' => ['٣', false, false, false, false],
            'a trailing line break' => ["12\n", false, false, false, false],
            'fifteen digits' => ['123456789012345', false, false, false, false],
            'a hundred percent' => ['100', true, true, true, true],
            'four decimals of a percent' => ['2.9999', true, true, true, true],
            'five decimals of a percent' => ['2.99999', true, true, true, false],
            'a percent of four digits' => ['1000', true, true, true, false],
        ];
    }

    #[DataProvider('values')]
    public function test_each_kind_of_figure_accepts_exactly_the_decimals_it_means(string $value, bool $amount, bool $positive, bool $signed, bool $percent): void
    {
        $this->assertSame($amount, preg_match(DecimalRules::AMOUNT, $value) === 1, 'amount');
        $this->assertSame($positive, preg_match(DecimalRules::POSITIVE_AMOUNT, $value) === 1, 'positive amount');
        $this->assertSame($signed, preg_match(DecimalRules::SIGNED_AMOUNT, $value) === 1, 'signed amount');
        $this->assertSame($percent, preg_match(DecimalRules::PERCENT, $value) === 1, 'percent');
    }

    public function test_the_rule_lists_say_whether_the_figure_is_required(): void
    {
        $this->assertSame(['nullable', 'regex:' . DecimalRules::AMOUNT], DecimalRules::amount());
        $this->assertSame(['required', 'regex:' . DecimalRules::AMOUNT], DecimalRules::amount(required: true));
        $this->assertSame(['required', 'regex:' . DecimalRules::POSITIVE_AMOUNT], DecimalRules::positiveAmount());
        $this->assertSame(['required', 'regex:' . DecimalRules::SIGNED_AMOUNT], DecimalRules::signedAmount(required: true));
        $this->assertSame(['nullable', 'regex:' . DecimalRules::PERCENT], DecimalRules::percent());
    }
}
