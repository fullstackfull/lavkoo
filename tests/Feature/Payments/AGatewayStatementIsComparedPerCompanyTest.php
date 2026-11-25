<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\GatewayStatementCheck;
use App\Services\Payments\PaymentAccountBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsWalletTopUpWorld;
use Tests\TestCase;

/**
 * A gateway's settlement statement compared with what this shop recorded, per company and per currency,
 * and never across currencies (D2-16).
 *
 * The gateway's statement is the only record of these payments that is not this platform's, which is what
 * makes it the one worth comparing: a payment row says what the shop THINKS happened and a settlement
 * line says what the money did. The four ways they can disagree are each somebody's problem -- money the
 * gateway took and the shop never recorded, money the shop recorded and the gateway has not paid out, one
 * reference for two amounts, and one reference in two currencies -- and all four are reported here rather
 * than summed into a single "difference" nobody can act on.
 *
 * TWO CURRENCIES ARE NEVER ADDED, and the dinar is why it has to be a rule rather than a habit: on a
 * platform of six markets a total over riyals and dinars is a number that means nothing, and comparing a
 * dinar at two decimals calls 12.345 and 12.35 equal -- four fils apart.
 */
class AGatewayStatementIsComparedPerCompanyTest extends TestCase
{
    use BuildsWalletTopUpWorld;

    private const GATEWAY = 'paymob_accept';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->thePaymentAmountColumnThePlatformActuallyHas();

        // The decimals each currency has, as the platform's configuration names them: ISO 4217 gives the
        // dinar and the two rials three.
        config(['gcc.storefronts.currency.decimals' => ['SAR' => 2, 'AED' => 2, 'KWD' => 3, 'BHD' => 3, 'OMR' => 3]]);
    }

    /**
     * The shared harness declares `payment_amount` as `decimal(24,2)`, which is the shape an OLD migration
     * left; the platform's column is `DECIMAL(40,20)` (2026_05_07_000001_update_all_decimal_columns_to_40_20).
     * Two decimals cannot hold a dinar's third, so without this a 12.345 dinar payment is stored as 12.35
     * on MariaDB -- and stored faithfully on SQLite, whose NUMERIC affinity ignores the declared scale, so
     * the same test would measure two different platforms on the two engines. Found by writing the dinar
     * case below and reading why it failed.
     */
    private function thePaymentAmountColumnThePlatformActuallyHas(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `payment_requests` MODIFY COLUMN `payment_amount` DECIMAL(40,20) NOT NULL DEFAULT 0');
        }
    }

    /** A payment this shop settled, with the company and market it was taken for frozen on it as the binding writes them. */
    private function aSettledPayment(string $reference, float $amount, string $currency = 'SAR', ?int $companyId = null, ?string $market = null): string
    {
        $id = (string) Str::uuid();
        $additionalData = ['payment_mode' => 'web'];

        if ($companyId !== null) {
            $additionalData[PaymentAccountBinding::KEY] = ['company_id' => $companyId, 'market' => $market, 'currency' => $currency];
        }

        DB::table('payment_requests')->insert([
            'id' => $id, 'payer_id' => '1', 'payment_amount' => $amount, 'currency_code' => $currency,
            'payment_method' => self::GATEWAY, 'success_hook' => 'add_fund_to_wallet_success',
            'additional_data' => json_encode($additionalData), 'attribute' => 'add_fund',
            'is_paid' => true, 'transaction_id' => $reference,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param list<array{reference: string, amount: int|float|string, currency: string}> $lines */
    private function comparedWith(array $lines): array
    {
        return app(GatewayStatementCheck::class)->compare(gateway: self::GATEWAY, statement: $lines);
    }

    private function aLine(string $reference, float|string $amount, string $currency = 'SAR'): array
    {
        return ['reference' => $reference, 'amount' => $amount, 'currency' => $currency];
    }

    // ---------------------------------------------------------------- the four disagreements

    public function test_a_line_and_a_payment_that_agree_are_matched_and_the_difference_is_zero(): void
    {
        $this->aSettledPayment(reference: 'TX-1', amount: 100.0);

        $found = $this->comparedWith([$this->aLine('TX-1', 100.0)]);

        $this->assertSame([], $found['in_the_statement_only']);
        $this->assertSame([], $found['recorded_only']);
        $this->assertSame([], $found['amount_differs']);
        $this->assertSame(1, $found['per_market']['platform|SAR']['matched']);
        $this->assertSame(10000, $found['per_market']['platform|SAR']['statement_minor']);
        $this->assertSame(10000, $found['per_market']['platform|SAR']['recorded_minor']);
    }

    public function test_money_the_gateway_took_and_this_shop_never_recorded_is_reported(): void
    {
        $found = $this->comparedWith([$this->aLine('TX-NOBODY-SAW', 250.0)]);

        $this->assertSame([['reference' => 'TX-NOBODY-SAW', 'amount' => '250', 'currency' => 'SAR']], $found['in_the_statement_only']);
        $this->assertSame('platform', $found['per_market']['platform|SAR']['company']);
        $this->assertSame(0, $found['per_market']['platform|SAR']['recorded_minor']);
        $this->assertSame(25000, $found['per_market']['platform|SAR']['statement_minor']);
    }

    public function test_money_settled_here_and_in_no_line_of_the_statement_is_reported(): void
    {
        $payment = $this->aSettledPayment(reference: 'TX-2', amount: 40.0);

        $found = $this->comparedWith([]);

        $this->assertCount(1, $found['recorded_only']);
        $this->assertSame($payment, $found['recorded_only'][0]['payment']);
        $this->assertSame('TX-2', $found['recorded_only'][0]['reference']);
        $this->assertSame(4000, $found['per_market']['platform|SAR']['recorded_minor']);
        $this->assertSame(0, $found['per_market']['platform|SAR']['statement_minor']);
    }

    public function test_one_reference_for_two_different_amounts_is_reported_with_both_figures(): void
    {
        $this->aSettledPayment(reference: 'TX-3', amount: 100.0);

        $found = $this->comparedWith([$this->aLine('TX-3', 99.0)]);

        $this->assertCount(1, $found['amount_differs']);
        $this->assertSame(99.0, (float) $found['amount_differs'][0]['statement']);
        // Read as stored, which on the platform's own DECIMAL(40,20) column is 100 with twenty decimals.
        $this->assertSame(100.0, (float) $found['amount_differs'][0]['recorded']);
        $this->assertSame(0, $found['per_market']['platform|SAR']['matched']);
        $this->assertSame(-100, $found['per_market']['platform|SAR']['statement_minor'] - $found['per_market']['platform|SAR']['recorded_minor']);
    }

    public function test_one_reference_in_two_different_currencies_is_reported(): void
    {
        $this->aSettledPayment(reference: 'TX-4', amount: 100.0, currency: 'SAR');

        $found = $this->comparedWith([$this->aLine('TX-4', 100.0, 'AED')]);

        $this->assertSame([['reference' => 'TX-4', 'statement' => 'AED', 'recorded' => 'SAR']], $found['currency_differs']);
        $this->assertSame([], $found['amount_differs'], 'an amount in another currency was compared as though the currencies agreed');
    }

    // ---------------------------------------------------------------- per company and per currency

    public function test_two_companies_are_never_added_together(): void
    {
        $this->aSettledPayment(reference: 'SA-1', amount: 100.0, currency: 'SAR', companyId: 1, market: 'SA');
        $this->aSettledPayment(reference: 'AE-1', amount: 200.0, currency: 'AED', companyId: 2, market: 'AE');

        $found = $this->comparedWith([$this->aLine('SA-1', 100.0, 'SAR'), $this->aLine('AE-1', 200.0, 'AED')]);

        $this->assertSame(['1|SAR', '2|AED'], array_keys($found['per_market']));
        $this->assertSame('SA', $found['per_market']['1|SAR']['market']);
        $this->assertSame(10000, $found['per_market']['1|SAR']['statement_minor']);
        $this->assertSame(20000, $found['per_market']['2|AED']['statement_minor']);
    }

    public function test_one_company_trading_in_two_currencies_is_kept_apart_by_currency(): void
    {
        $this->aSettledPayment(reference: 'C1-SAR', amount: 100.0, currency: 'SAR', companyId: 1, market: 'SA');
        $this->aSettledPayment(reference: 'C1-KWD', amount: 10.0, currency: 'KWD', companyId: 1, market: 'KW');

        $found = $this->comparedWith([$this->aLine('C1-SAR', 100.0, 'SAR'), $this->aLine('C1-KWD', 10.0, 'KWD')]);

        $this->assertSame(['1|KWD', '1|SAR'], array_keys($found['per_market']));
        $this->assertSame(10000, $found['per_market']['1|KWD']['statement_minor'], 'a dinar was not counted in thousandths');
        $this->assertSame(10000, $found['per_market']['1|SAR']['statement_minor']);
    }

    /** Every payment on the platform today carries no company stamp, and belongs to the platform rather than to nothing. */
    public function test_a_payment_with_no_company_stamp_is_reported_under_the_platform(): void
    {
        $this->aSettledPayment(reference: 'TX-5', amount: 100.0);

        $found = $this->comparedWith([$this->aLine('TX-5', 100.0)]);

        $this->assertSame(['platform|SAR'], array_keys($found['per_market']));
    }

    // ---------------------------------------------------------------- the dinar's third decimal

    /**
     * THE CASE THAT NEEDS THE MINOR UNIT. A dinar's smallest coin is a thousandth, so 12.345 dinars is
     * 12,345 fils -- and a comparison in hundredths would both call it 1,234 and call 12.345 and 12.350
     * equal, four fils apart. The totals are asserted as well as the match, because a test that only
     * showed the match would pass with the unit wrong.
     */
    public function test_a_dinar_is_counted_and_compared_in_fils_and_not_in_halalas(): void
    {
        $this->aSettledPayment(reference: 'KW-1', amount: 12.345, currency: 'KWD', companyId: 3, market: 'KW');
        $this->aSettledPayment(reference: 'KW-2', amount: 12.345, currency: 'KWD', companyId: 3, market: 'KW');

        $found = $this->comparedWith([$this->aLine('KW-1', '12.345', 'KWD'), $this->aLine('KW-2', '12.350', 'KWD')]);

        $this->assertSame(1, $found['per_market']['3|KWD']['matched'], 'a dinar that agrees to the fils was not matched');
        $this->assertCount(1, $found['amount_differs'], 'four fils of difference was rounded away');
        $this->assertSame('KW-2', $found['amount_differs'][0]['reference']);
        $this->assertSame(24695, $found['per_market']['3|KWD']['statement_minor'], 'the dinar was not counted in thousandths');
        $this->assertSame(24690, $found['per_market']['3|KWD']['recorded_minor']);
    }

    // ---------------------------------------------------------------- what it will not guess

    public function test_a_line_naming_no_transaction_is_reported_and_never_matched_by_position(): void
    {
        $this->aSettledPayment(reference: 'TX-6', amount: 100.0);

        $found = $this->comparedWith([$this->aLine('', 100.0)]);

        $this->assertCount(1, $found['in_the_statement_only']);
        $this->assertCount(1, $found['recorded_only'], 'a line with no reference was matched to a payment by its position');
    }

    public function test_an_unsettled_payment_is_not_in_the_comparison_at_all(): void
    {
        $id = $this->aSettledPayment(reference: 'TX-7', amount: 100.0);
        DB::table('payment_requests')->where('id', $id)->update(['is_paid' => false]);

        $found = $this->comparedWith([]);

        $this->assertSame([], $found['recorded_only'], 'a payment nobody has settled was reported as money owed');
    }

    public function test_another_gateways_settled_payment_is_not_in_the_comparison(): void
    {
        $id = $this->aSettledPayment(reference: 'TX-8', amount: 100.0);
        DB::table('payment_requests')->where('id', $id)->update(['payment_method' => 'fatoorah']);

        $this->assertSame([], $this->comparedWith([])['recorded_only']);
    }

    // ---------------------------------------------------------------- the command

    public function test_the_command_reads_a_csv_and_writes_nothing(): void
    {
        $this->aSettledPayment(reference: 'TX-9', amount: 100.0);
        $file = tempnam(sys_get_temp_dir(), 'statement') . '.csv';
        file_put_contents($file, "Transaction ID,Amount,Currency\nTX-9,100.00,SAR\nTX-UNKNOWN,55.00,SAR\n");

        $this->artisan('payments:check-statement', ['gateway' => self::GATEWAY, 'file' => $file])
            ->expectsOutputToContain('2 statement line(s) read')
            ->expectsOutputToContain('Nothing was written')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('payment_requests')->where('is_paid', 1)->count());
        unlink($file);
    }

    /** A header naming none of the columns is refused rather than read by position, which would produce confident nonsense. */
    public function test_the_command_refuses_a_file_whose_header_names_nothing_it_knows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'statement') . '.csv';
        file_put_contents($file, "col_a,col_b,col_c\nTX-9,100.00,SAR\n");

        $this->artisan('payments:check-statement', ['gateway' => self::GATEWAY, 'file' => $file])->assertExitCode(1);

        unlink($file);
    }

    public function test_the_command_refuses_a_file_that_is_not_there(): void
    {
        $this->artisan('payments:check-statement', ['gateway' => self::GATEWAY, 'file' => '/nowhere/statement.csv'])->assertExitCode(1);
    }
}
