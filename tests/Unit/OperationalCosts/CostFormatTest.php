<?php

namespace Tests\Unit\OperationalCosts;

use App\Http\Controllers\Admin\OperationalCosts\CostFormat;
use App\Services\OperationalCosts\Support\Money;
use PHPUnit\Framework\TestCase;

class CostFormatTest extends TestCase
{
    public function test_an_amount_is_shown_with_two_decimals_and_its_currency(): void
    {
        $this->assertSame('12.50 SAR', CostFormat::amount('12.5', 'SAR'));
        $this->assertSame('12.50 SAR', CostFormat::amount(Money::of('12.499999')->plus('0.000001'), 'SAR'));
        $this->assertSame('1.01', CostFormat::amount('1.005'));
        $this->assertSame('-3.20 USD', CostFormat::amount('-3.2', 'USD'));
        $this->assertSame('0.00 SAR', CostFormat::amount(Money::zero(), 'SAR'), 'A zero that really is one is shown.');
    }

    public function test_an_amount_that_does_not_exist_is_a_dash_never_a_zero(): void
    {
        $this->assertSame('—', CostFormat::amount(null, 'SAR'));
        $this->assertSame('—', CostFormat::amount('', 'SAR'));
        $this->assertSame('—', CostFormat::percent(null));
        $this->assertSame('—', CostFormat::figure(null));
        $this->assertSame('—', CostFormat::figure(''));
    }

    public function test_a_percentage_and_a_rule_figure_are_shown_without_the_padding_of_storage(): void
    {
        $this->assertSame('45.45%', CostFormat::percent(Money::of('45.4545')));
        $this->assertSame('2.5', CostFormat::figure('2.500000'));
        $this->assertSame('100', CostFormat::figure('100.000000'));
        $this->assertSame('0.35', CostFormat::figure('0.350000'));
        $this->assertSame('0', CostFormat::figure('0.000000'));
        $this->assertSame('0.000001', CostFormat::figure('0.000001'));
    }
}
