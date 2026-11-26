<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\SeedsPlatformMarketData;

/**
 * What one market TOOK and the TAX it recorded (L-13): the two halves of the drill-down criterion that the settlement summaries and the legal company
 * did not cover.
 *
 * Four money figures of one market already existed or exist here, and the point of this file is that they stay four. What the market SOLD is what was
 * billed; what it TOOK is what arrived and through which method; its TAX is what the authority is owed out of that; what was SETTLED is what then left
 * for its shops. Each is reported under the currency it is in and under the company whose books it belongs in, and no two are ever added.
 *
 * Three claims the figures must refuse to make, each of them the lie that would be easiest to tell:
 *
 *   - that an order which records no payment method was paid by the commonest one (it is a row of its own, with a null method);
 *   - that money billed is money received (`paid` sums only orders whose payment status is paid; the rest are counted and carry nothing);
 *   - that a tax-included sale carries zero tax (a NULL `included_tax_amount` is counted as not recorded, which is what D1-07 wrote the column for).
 *
 * Nothing of the customer is in the answer or in the statements that build it, and each figure is one grouped statement whatever the amount of data.
 */
class MarketPaymentsAndTaxTest extends PlatformAdminTestCase
{
    use SeedsPlatformMarketData;

    private const REPORTS = self::PREFIX . '/reports';

    private const SAUDI_COMPANY = 1;

    private const EMIRATI_COMPANY = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
        $this->runMoneyColumnMigrations();
        DB::table('legal_entities')->insert([
            ['id' => self::SAUDI_COMPANY, 'country' => 'SA', 'legal_name' => 'Lavkoo Trading Company', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => self::EMIRATI_COMPANY, 'country' => 'AE', 'legal_name' => 'Lavkoo Emirates', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_a_report_says_what_the_market_took_by_method_and_currency(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00');
        $this->sale(orderId: 202, market: 'SA', currency: 'SAR', method: 'mada', paid: '25.50');
        $this->sale(orderId: 203, market: 'SA', currency: 'SAR', method: 'cash_on_delivery', paid: '40.00');

        $this->assertSame([
            ['method' => 'cash_on_delivery', 'currency' => 'SAR', 'orders' => 1, 'paid_orders' => 1, 'unpaid_orders' => 0, 'paid' => '40.00'],
            ['method' => 'mada', 'currency' => 'SAR', 'orders' => 2, 'paid_orders' => 2, 'unpaid_orders' => 0, 'paid' => '125.50'],
        ], $this->report(market: 'SA')->json('data.payments.by_method'));
    }

    public function test_an_order_that_records_no_payment_method_is_a_row_of_its_own_and_is_never_folded_into_another(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00');
        $this->sale(orderId: 202, market: 'SA', currency: 'SAR', method: null, paid: '70.00');
        $this->sale(orderId: 203, market: 'SA', currency: 'SAR', method: '  ', paid: '5.00');

        $rows = (array) $this->report(market: 'SA')->json('data.payments.by_method');

        // The blank string is the same absence as the null and is reported as one row with it, not as a method whose name is two spaces.
        $this->assertSame([null, 'mada'], array_column($rows, 'method'));
        $this->assertSame(['75.00', '100.00'], array_column($rows, 'paid'), 'the method nobody recorded keeps its own money and takes none of mada\'s');
    }

    public function test_money_billed_is_not_money_received(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00');
        $this->sale(orderId: 202, market: 'SA', currency: 'SAR', method: 'mada', paid: '0.00', status: 'unpaid', amount: '80.00');
        $this->sale(orderId: 203, market: 'SA', currency: 'SAR', method: 'mada', paid: '30.00', status: 'partially_paid', amount: '90.00');

        $row = (array) $this->report(market: 'SA')->json('data.payments.by_method.0');

        $this->assertSame(['method' => 'mada', 'currency' => 'SAR', 'orders' => 3, 'paid_orders' => 1, 'unpaid_orders' => 2, 'paid' => '100.00'], $row);
        $this->assertStringNotContainsString('130.00', (string) $this->report(market: 'SA')->getContent(), 'a part-payment is not money the market took');
    }

    public function test_an_order_stored_at_a_converted_rate_carries_no_amount_and_is_not_added_to_the_market_s_own_money(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00');
        $this->sale(orderId: 202, market: 'SA', currency: 'USD', method: 'mada', paid: '200.00', rate: '3.75');

        $rows = (array) $this->report(market: 'SA')->json('data.payments.by_method');

        $this->assertSame([
            ['method' => 'mada', 'currency' => null, 'orders' => 1, 'paid_orders' => 1, 'unpaid_orders' => 0, 'paid' => null],
            ['method' => 'mada', 'currency' => 'SAR', 'orders' => 1, 'paid_orders' => 1, 'unpaid_orders' => 0, 'paid' => '100.00'],
        ], $rows, 'the rate is not read as a conversion: the order is unpriced and carries nothing');
    }

    public function test_the_tax_charged_on_top_and_the_tax_inside_a_price_are_reported_apart_and_never_added(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '115.00', tax: '15.00', taxModel: 'exclude');
        $this->sale(orderId: 202, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00', tax: '0.00', includedTax: '13.04', taxModel: 'include');

        $tax = (array) $this->report(market: 'SA')->json('data.tax');

        $this->assertSame([[
            'currency' => 'SAR',
            'legal_entity_id' => self::SAUDI_COMPANY,
            'legal_entity' => 'Lavkoo Trading Company',
            'orders' => 2,
            'charged' => '15.00',
            'included' => '13.04',
            'tax_included_orders' => 1,
            'included_not_recorded' => 0,
        ]], $tax['by_currency']);
        $this->assertNull($tax['reason']);
        $this->assertTrue($tax['included_recorded']);
        $this->assertStringNotContainsString('28.04', (string) $this->report(market: 'SA')->getContent(), 'the two are different money and no row adds them');
    }

    public function test_an_ordinary_tax_on_top_sale_is_not_counted_as_an_included_tax_nobody_recorded(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '115.00', tax: '15.00', taxModel: 'exclude');
        $this->sale(orderId: 202, market: 'SA', currency: 'SAR', method: 'mada', paid: '115.00', tax: '15.00', taxModel: null);

        $row = (array) $this->report(market: 'SA')->json('data.tax.by_currency.0');

        $this->assertSame(0, $row['tax_included_orders'], 'neither sale was sold tax-included');
        $this->assertSame(0, $row['included_not_recorded'], 'a sale with no included tax to record is not a gap in the record');
        $this->assertSame('30.00', $row['charged'], 'both sales charged their tax on top and both are in the charged figure');
    }

    public function test_without_the_tax_model_the_platform_cannot_say_which_sales_were_tax_included_and_claims_nothing(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '115.00', tax: '15.00', taxModel: 'include');
        Schema::table('orders', fn ($table) => $table->dropColumn('tax_model'));

        $row = (array) $this->report(market: 'SA')->json('data.tax.by_currency.0');

        $this->assertNull($row['tax_included_orders'], 'no column, no claim -- and a zero would read as "no tax-included sale here"');
        $this->assertNull($row['included_not_recorded']);
        $this->assertSame('15.00', $row['charged'], 'what was charged on top is still readable');
    }

    public function test_a_tax_included_order_whose_included_amount_was_never_written_is_counted_and_never_read_as_zero(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '115.00', tax: '0.00', includedTax: '13.04', taxModel: 'include');
        $this->sale(orderId: 202, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00', tax: '0.00', includedTax: null, taxModel: 'include');

        $row = (array) $this->report(market: 'SA')->json('data.tax.by_currency.0');

        $this->assertSame(1, $row['included_not_recorded'], 'the tax-included sale that recorded nothing is counted, because zero is the one thing it cannot be');
        $this->assertSame('13.04', $row['included'], 'and the one that did record is not diluted by it');
        $this->assertSame(2, $row['orders']);
    }

    public function test_a_platform_that_does_not_record_the_included_tax_at_all_says_so_rather_than_reporting_zeros(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '115.00', tax: '0.00', taxModel: 'include');
        Schema::table('orders', fn ($table) => $table->dropColumn('included_tax_amount'));

        $tax = (array) $this->report(market: 'SA')->json('data.tax');

        $this->assertFalse($tax['included_recorded'], 'the answer says the column is not there rather than letting a zero stand for it');
        $this->assertSame(1, $tax['by_currency'][0]['tax_included_orders']);
        $this->assertSame(1, $tax['by_currency'][0]['included_not_recorded'], 'a tax-included sale on a platform with nowhere to record its tax is exactly the gap this counts');
    }

    public function test_an_installation_that_records_neither_the_payment_nor_the_tax_answers_a_reason_and_not_a_number(): void
    {
        Schema::table('orders', fn ($table) => $table->dropColumn(['payment_method', 'total_tax_amount']));

        $report = (array) $this->report(market: 'SA')->json('data');

        $this->assertSame(['by_method' => null, 'reason' => 'order_payment_not_recorded'], $report['payments']);
        $this->assertSame(['by_currency' => null, 'reason' => 'order_tax_not_recorded'], $report['tax']);
    }

    public function test_another_market_s_money_is_never_in_this_market_s_figures(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00', tax: '15.00');
        $this->sale(orderId: 202, market: 'AE', currency: 'AED', method: 'mada', paid: '900.00', tax: '45.00');

        $saudi = (string) $this->report(market: 'SA')->getContent();
        $this->assertStringNotContainsString('900.00', $saudi);
        $this->assertStringNotContainsString('AED', $saudi);

        $emirati = (array) $this->report(market: 'AE')->json('data.tax.by_currency.0');
        $this->assertSame(['AED', self::EMIRATI_COMPANY, 'Lavkoo Emirates', '45.00'], [$emirati['currency'], $emirati['legal_entity_id'], $emirati['legal_entity'], $emirati['charged']]);
    }

    public function test_only_what_the_window_holds_is_counted(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00', tax: '15.00');
        $this->sale(orderId: 202, market: 'SA', currency: 'SAR', method: 'mada', paid: '70.00', tax: '9.00', at: '2026-09-01 10:00:00');

        $this->assertSame('100.00', $this->report(market: 'SA')->json('data.payments.by_method.0.paid'));
        $this->assertSame('15.00', $this->report(market: 'SA')->json('data.tax.by_currency.0.charged'));

        $wider = $this->report(market: 'SA', window: '&from=2026-08-19&to=2026-11-17');
        $this->assertSame('170.00', $wider->json('data.payments.by_method.0.paid'));
        $this->assertSame('24.00', $wider->json('data.tax.by_currency.0.charged'));
    }

    public function test_no_customer_is_named_in_the_money_figures_or_read_by_them(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00', tax: '15.00', customerId: 77);
        DB::table('orders')->where('id', 201)->update(['order_note' => 'ring Fatimah on 0500000000']);

        $statements = $this->statementsRunBy(function (): void {
            $body = strtolower((string) $this->report(market: 'SA')->getContent());

            foreach (['fatimah', '0500000000', 'order_note', '"customer'] as $person) {
                $this->assertStringNotContainsString($person, $body, 'the money of a market names no person');
            }
        });

        $money = array_values(array_filter(
            $this->withoutAuditTrail($this->withoutSchemaChecks($statements)),
            static fn (string $sql): bool => str_contains($sql, 'payment_method') || str_contains($sql, 'total_tax_amount'),
        ));
        $this->assertNotEmpty($money, 'the two figures had to run, or this proved nothing');
        foreach ($money as $sql) {
            foreach (['customer_id', 'order_note', 'shipping_address_data', 'is_guest'] as $column) {
                $this->assertStringNotContainsString($column, $sql, 'nothing about a person is even read: ' . $sql);
            }
        }
    }

    public function test_each_figure_is_one_grouped_statement_whatever_the_amount_of_data(): void
    {
        $this->sale(orderId: 201, market: 'SA', currency: 'SAR', method: 'mada', paid: '100.00', tax: '15.00');
        $before = $this->moneyStatements();

        for ($order = 300; $order < 360; $order++) {
            $this->sale(orderId: $order, market: 'SA', currency: 'SAR', method: 'mada', paid: '1.00', tax: '0.15');
        }
        $after = $this->moneyStatements();

        $this->assertSame($before, $after, 'sixty more orders, not one statement more');
        $this->assertCount(1, array_filter($after, static fn (string $sql): bool => str_contains($sql, 'payment_method')), 'what the market took is one statement');
        $this->assertCount(1, array_filter($after, static fn (string $sql): bool => str_contains($sql, 'total_tax_amount')), 'the tax is one statement');
        $this->assertSame('160.00', $this->report(market: 'SA')->json('data.payments.by_method.0.paid'), 'and it is still right');
    }

    /** @return list<string> */
    private function moneyStatements(): array
    {
        $statements = $this->withoutAuditTrail($this->withoutSchemaChecks($this->statementsRunBy(fn () => $this->report(market: 'SA')->assertOk())));

        return array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'payment_method') || str_contains($sql, 'total_tax_amount')));
    }

    private function report(string $market, string $window = ''): TestResponse
    {
        return $this->getJson(self::REPORTS . '?market=' . $market . $window, $this->bearer($this->mintToken($this->platformAdmin(null, grantOverrides: ['capabilities' => json_encode(['reports'])]))));
    }

    private function sale(
        int $orderId,
        string $market,
        string $currency,
        ?string $method,
        string $paid,
        string $status = 'paid',
        ?string $amount = null,
        string $rate = '1',
        string $tax = '0.00',
        ?string $includedTax = null,
        ?string $taxModel = null,
        ?int $customerId = null,
        string $at = '2026-11-16 10:00:00',
    ): void {
        DB::table('orders')->insert([
            'id' => $orderId,
            'customer_id' => $customerId,
            'order_status' => 'delivered',
            'payment_status' => $status,
            'payment_method' => $method,
            'order_amount' => $amount ?? $paid,
            'paid_amount' => $paid,
            'total_tax_amount' => $tax,
            'included_tax_amount' => $includedTax,
            'tax_model' => $taxModel,
            'storefront_country' => $market,
            'currency_code' => $currency,
            'currency_rate' => $rate,
            'legal_entity_id' => $market === 'SA' ? self::SAUDI_COMPANY : self::EMIRATI_COMPANY,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** The real migrations for the columns these figures read: the market and company an order records, the tax on top of it, and the tax inside it. */
    private function runMoneyColumnMigrations(): void
    {
        foreach ([
            '2026_11_26_100000_add_storefront_snapshot_to_orders_table',
            '2026_11_27_100000_create_legal_entities_table',
            '2026_11_27_100100_add_legal_entity_to_orders_table',
            '2025_08_27_213749_add_total_tax_amount_column_to_orders_table',
            '2026_12_27_100000_add_included_tax_to_orders_and_order_details',
        ] as $migration) {
            (require database_path("migrations/{$migration}.php"))->up();
        }
    }
}
