<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\Odoo\OdooMoney;
use App\Services\Accounting\Odoo\OdooXmlRpc;
use App\Services\OperationalCosts\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Money on its way into an Odoo monetary field: the one conversion here where a slip is a wrong number in somebody's books.
 *
 * `Money` is an exact decimal at scale 6; an Odoo monetary field is a Python float carried as XML-RPC `<double>`. The conversion
 * is unavoidable, and these tests pin the four ways it could go wrong: becoming an int, becoming a string, carrying more
 * precision than the currency has, and — the accounting one — turning a negative amount into a negative debit instead of a
 * positive credit.
 */
class OdooMoneyTest extends TestCase
{
    // ---- halalas, fractions and rounding ------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: float}> */
    public static function amountsAndTheirMonetaryValues(): array
    {
        return [
            'a whole riyal' => ['49', 49.0],
            'halalas' => ['49.50', 49.5],
            'one halala' => ['0.01', 0.01],
            'ninety-nine halalas' => ['12.99', 12.99],
            'a third of a riyal rounds half-up' => ['0.335', 0.34],
            'and down below the half' => ['0.334', 0.33],
            'an exact half rounds up' => ['2.345', 2.35],
            // Money keeps six decimals for allocation; the field holds two, so the rounding happens HERE, once, visibly.
            'six decimals become two' => ['1.004999', 1.0],
            'six decimals that round up' => ['1.005001', 1.01],
            'a large figure keeps its halalas' => ['1234567.89', 1234567.89],
            'zero' => ['0', 0.0],
            'negative keeps its magnitude' => ['-49.50', -49.5],
        ];
    }

    #[DataProvider('amountsAndTheirMonetaryValues')]
    public function test_an_amount_reaches_the_field_at_the_currencys_precision(string $amount, float $expected): void
    {
        $this->assertSame($expected, OdooMoney::forMonetaryField(Money::of($amount)));
    }

    public function test_the_value_is_a_float_and_never_an_int_or_a_string(): void
    {
        // The three wrong types, named. A whole amount is the dangerous one: PHP hands you 49 for 49.0 and nothing downstream
        // would notice until a figure had halalas in it.
        foreach (['49', '49.00', '0', '100'] as $whole) {
            $value = OdooMoney::forMonetaryField(Money::of($whole));

            $this->assertIsFloat($value, "{$whole} must be a float");
            $this->assertNotSame((int) $value, $value);
        }
    }

    public function test_a_whole_amount_is_encoded_as_a_double_in_the_request(): void
    {
        // End to end through the codec, because the type only matters once it is on the wire.
        $xml = OdooXmlRpc::request('execute_kw', [['debit' => OdooMoney::forMonetaryField(Money::of('49.00'))]]);

        $this->assertStringContainsString('<double>49</double>', $xml);
        $this->assertStringNotContainsString('<int>49</int>', $xml);
        $this->assertStringNotContainsString('<string>49', $xml);
    }

    public function test_halalas_survive_the_whole_way_to_the_wire(): void
    {
        $xml = OdooXmlRpc::request('execute_kw', [['credit' => OdooMoney::forMonetaryField(Money::of('49.50'))]]);

        $this->assertStringContainsString('<double>49.5</double>', $xml);
    }

    public function test_a_currency_with_other_decimals_is_rounded_to_its_own(): void
    {
        $this->assertSame(49.0, OdooMoney::forMonetaryField(Money::of('49.49'), decimals: 0));
        $this->assertSame(49.456, OdooMoney::forMonetaryField(Money::of('49.4561'), decimals: 3));
    }

    public function test_an_impossible_precision_is_refused_rather_than_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OdooMoney::forMonetaryField(Money::of('1.00'), decimals: -1);
    }

    public function test_a_precision_beyond_what_money_stores_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OdooMoney::forMonetaryField(Money::of('1.00'), decimals: Money::STORAGE_SCALE + 1);
    }

    // ---- a negative amount is not a negative debit ---------------------------------------------------------------

    public function test_a_positive_amount_is_a_debit_and_the_credit_is_zero(): void
    {
        $this->assertSame(['debit' => 49.5, 'credit' => 0.0], OdooMoney::debitAndCredit(Money::of('49.50')));
    }

    public function test_a_negative_amount_becomes_a_positive_credit_and_never_a_negative_debit(): void
    {
        // The accounting rule, and the whole reason this method exists. `debit: -49.50` is a different statement from
        // `credit: 49.50`: the trial balance reads strangely and a reversal becomes impossible to tell from a mistake.
        $side = OdooMoney::debitAndCredit(Money::of('-49.50'));

        $this->assertSame(['debit' => 0.0, 'credit' => 49.5], $side);
        $this->assertGreaterThanOrEqual(0.0, $side['debit']);
        $this->assertGreaterThanOrEqual(0.0, $side['credit']);
    }

    /** @return array<string, array{0: string}> */
    public static function signedAmounts(): array
    {
        return [
            'a sale' => ['1200.00'],
            'a refund' => ['-1200.00'],
            'a fee' => ['37.45'],
            'a fee reversed' => ['-37.45'],
            'one halala' => ['0.01'],
            'one halala back' => ['-0.01'],
            'nothing' => ['0.00'],
            'a rounding edge' => ['-0.005'],
        ];
    }

    #[DataProvider('signedAmounts')]
    public function test_neither_column_is_ever_negative_and_only_one_carries_the_figure(string $amount): void
    {
        $side = OdooMoney::debitAndCredit(Money::of($amount));

        $this->assertGreaterThanOrEqual(0.0, $side['debit'], 'a debit is never negative');
        $this->assertGreaterThanOrEqual(0.0, $side['credit'], 'a credit is never negative');
        $this->assertTrue(
            $side['debit'] === 0.0 || $side['credit'] === 0.0,
            'the direction is which column holds the figure, so only one of them ever does',
        );
    }

    #[DataProvider('signedAmounts')]
    public function test_a_line_and_its_counter_balance_exactly(string $amount): void
    {
        // An unbalanced `account.move` is refused by Odoo, and rightly. The counter-line is derived from the same rounded figure
        // so the two cannot drift apart by a rounding step.
        $line = OdooMoney::debitAndCredit(Money::of($amount));
        $counter = OdooMoney::counterOf(Money::of($amount));

        $this->assertSame($line['debit'] + $counter['debit'], $line['credit'] + $counter['credit']);
        $this->assertSame($line['debit'], $counter['credit']);
        $this->assertSame($line['credit'], $counter['debit']);
    }

    public function test_zero_is_zero_on_both_sides_rather_than_a_refusal(): void
    {
        // A line that nets to nothing is a real thing -- a fully refunded fee, say -- and Odoo accepts it.
        $this->assertSame(['debit' => 0.0, 'credit' => 0.0], OdooMoney::debitAndCredit(Money::zero()));
    }

    public function test_an_amount_that_rounds_away_to_nothing_keeps_its_side_at_zero(): void
    {
        // Rounding happens before the side is chosen, so a figure below half a halala does not become a one-halala credit.
        $this->assertSame(['debit' => 0.0, 'credit' => 0.0], OdooMoney::debitAndCredit(Money::of('-0.004')));
        $this->assertSame(['debit' => 0.0, 'credit' => 0.0], OdooMoney::debitAndCredit(Money::of('0.004')));
    }

    public function test_the_two_columns_are_encoded_as_doubles_together(): void
    {
        $xml = OdooXmlRpc::request('execute_kw', [OdooMoney::debitAndCredit(Money::of('-37.45'))]);

        $this->assertStringContainsString('<name>debit</name><value><double>0</double>', $xml);
        $this->assertStringContainsString('<name>credit</name><value><double>37.45</double>', $xml);
    }
}
