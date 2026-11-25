<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsPlatformMarketData;

/**
 * The platform summary reports sales per selling company and per currency, and never across currencies (E-17).
 *
 * The overview reported no money at all, with the reason `order_currency_not_recorded`, because an order stored
 * no currency and the amounts it stored were in the platform's base currency whatever market it was sold in.
 * Both snapshot columns exist now (`orders.currency_code`, `orders.legal_entity_id`), so the figure is possible
 * -- and the shape it is reported in is the point of this file rather than the arithmetic. Six markets price in
 * six currencies whose amounts are not interchangeable, so:
 *
 *  - every row names a currency, and there is NO total over the rows. A reader who wants one figure has to say
 *    which currency and at which rate, which is a question with an owner and a date on it;
 *  - an order recording no currency is not in any row. It is counted apart, with the reason, so a reader is
 *    told what is missing instead of being handed a total that quietly leaves it out;
 *  - a company of null is an order that recorded none, reported as such and never as the platform's own.
 *
 * The first case is the dormancy claim and it is the strongest one available: with no order recording a
 * currency -- which is every order on the platform today -- the answer is what it was before this figure
 * existed, key for key, which is why the tests written before this change assert the same bytes after it.
 */
class SalesPerCompanyAndCurrencyTest extends PlatformAdminTestCase
{
    use SeedsPlatformMarketData;

    private const URL = self::PREFIX . '/overview';

    private ?string $owner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
    }

    /** What the overview says about one market's sales. */
    private function salesOf(string $market): array
    {
        $markets = collect($this->overview()->assertOk()->json('data.markets'))->keyBy('market');

        return $markets[$market]['sales'];
    }

    private function overview()
    {
        return $this->getJson(self::URL, $this->bearer($this->owner ??= $this->mintToken($this->platformAdmin())));
    }

    /**
     * The two snapshot columns, from their REAL migrations rather than from a shape this test invented. The
     * platform-admin world builds `orders` by hand, so without this the columns are simply absent -- which is
     * one of the two dormant states and has a case of its own below.
     */
    private function ordersCanRecordWhatTheyWereSoldIn(): void
    {
        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        (require database_path('migrations/2026_11_27_100100_add_legal_entity_to_orders_table.php'))->up();
    }

    /** An order of the seeded scenario, given what it was sold in. */
    private function soldIn(int $orderId, ?string $currency, ?int $company, float $amount, float $rate = 1.0, ?string $market = 'SA'): void
    {
        $this->ordersCanRecordWhatTheyWereSoldIn();

        DB::table('orders')->where('id', $orderId)->update(array_filter([
            // The MARKET the order recorded. `PlatformMarketFigures::sales()` reads this stamp rather than attributing through the
            // order's products, which is the stricter reading: an order that recorded no market belongs to NO market and is never read
            // as Saudi Arabia. The seeded orders this fixture does not touch therefore stay out of every market's figures, which is why
            // the unpriced count below is one and not four.
            'storefront_country' => $market,
            'currency_code' => $currency,
            'legal_entity_id' => $company,
            'order_amount' => $amount,
            // The RATE, which this fixture used to leave unset. `PlatformMarketFigures::sales()` counts an amount only while the rate is
            // exactly one, because that is the single case where a stored amount really is an amount of the recorded currency -- an order
            // at any other rate is counted as UNPRICED rather than scaled by a conversion rule nobody has approved. A fixture with no
            // rate is therefore an unpriced order, and every test here that means "a sale in this market's own currency" has to say so.
            'currency_rate' => $rate,
        ], static fn (mixed $value): bool => $value !== null));
    }

    // ---------------------------------------------------------------- the platform of today

    /** Before the snapshot migrations have run at all: the installation of today, which has no such columns. */
    public function test_with_no_snapshot_columns_at_all_the_answer_is_what_it_always_was(): void
    {
        $this->assertTheAnswerIsWhatItAlwaysWas();
    }

    /**
     * After the migrations and before storefront currencies are switched on: the columns are there and empty.
     *
     * The answer is no longer BYTE for byte what it was before the columns existed, and that is the surviving implementation being more
     * careful rather than less. Once the columns are there the figure can be computed, so it is -- and it comes back with an empty
     * `by_currency`, every order counted under `unpriced` with the reason, and `combined` null because the owner has recorded no
     * conversion rule. No amount is claimed anywhere in it. The never-migrated case above still answers exactly as it always did.
     */
    public function test_with_the_columns_present_and_empty_every_order_is_unpriced_and_no_amount_is_claimed(): void
    {
        $this->ordersCanRecordWhatTheyWereSoldIn();

        $sales = $this->salesOf('SA');

        $this->assertSame([], $sales['by_currency'], 'no currency can be reported when no order records one');
        $this->assertSame('order_currency_not_recorded', $sales['unpriced']['reason']);
        $this->assertNull($sales['combined'], 'a figure across currencies needs a conversion rule, and there is none');
        $this->assertSame('no_conversion_rule_recorded', $sales['reason']);
    }

    private function assertTheAnswerIsWhatItAlwaysWas(): void
    {
        $this->assertSame(['by_currency' => null, 'reason' => 'order_currency_not_recorded'], $this->salesOf('SA'));
        $this->assertSame(
            ['per_order' => false, 'reason' => 'order_currency_not_recorded'],
            $this->overview()->json('data.attribution.currency'),
        );
        // The surviving design publishes no `sales` key at all -- the rule lives inside `attribution.currency` -- so what is worth
        // asserting here is that the currency attribution says plainly that nothing is per order, which the line above does. Keeping the
        // old `assertArrayNotHasKey('sales', ...)` would have been an assertion that can no longer fail.
        $this->assertArrayNotHasKey('priced_when', (array) $this->overview()->json('data.attribution.currency'), 'a pricing rule was published for a figure that is not reported');
    }

    // ---------------------------------------------------------------- one currency, one company

    public function test_the_orders_of_one_company_in_one_currency_are_added_up(): void
    {
        // Three of the five Saudi orders of the scenario, one company, one currency.
        $this->soldIn(1, 'SAR', 1, 100.00);
        $this->soldIn(2, 'SAR', 1, 50.50);

        $sales = $this->salesOf('SA');

        $this->assertCount(1, $sales['by_currency']);
        $this->assertSame('SAR', $sales['by_currency'][0]['currency']);
        $this->assertSame(1, $sales['by_currency'][0]['legal_entity_id']);
        $this->assertSame(2, $sales['by_currency'][0]['orders']);
        $this->assertEqualsWithDelta(150.50, (float) $sales['by_currency'][0]['gross'], 0.0001);
    }

    public function test_the_rule_that_produced_the_figure_is_published_once_there_is_one(): void
    {
        $this->soldIn(1, 'SAR', 1, 100.00);

        // The rule is published under `attribution.currency` and not under a `sales` key of its own, and it names THREE columns rather
        // than two: the market the order recorded is part of what put the sale in a market. It also publishes `priced_when`, which is
        // the rule that makes the figure safe to read -- an amount is counted only while the order's rate is exactly one, and an order
        // at any other rate is counted apart rather than scaled by a conversion nobody has approved. So this asserts more than it did.
        $this->assertSame(
            [
                'per_order' => true,
                'rule' => 'order_market_company_and_currency',
                'via' => 'orders.storefront_country, orders.legal_entity_id, orders.currency_code',
                'priced_when' => 'currency_rate = 1',
            ],
            $this->overview()->json('data.attribution.currency'),
        );
    }

    // ---------------------------------------------------------------- never across currencies

    /**
     * THE CASE THE CRITERION IS ABOUT. One market's orders in two currencies are two rows and never one sum:
     * 100 riyals and 100 dinars are not 200 of anything.
     */
    public function test_two_currencies_in_one_market_are_two_rows_and_never_one_sum(): void
    {
        $this->soldIn(1, 'SAR', 1, 100.00);
        $this->soldIn(2, 'KWD', 1, 100.00);

        $sales = $this->salesOf('SA');

        $this->assertCount(2, $sales['by_currency']);
        $this->assertSame(['KWD', 'SAR'], array_column($sales['by_currency'], 'currency'));
        foreach ($sales['by_currency'] as $row) {
            $this->assertSame(1, $row['orders'], 'two currencies were added into one row');
            $this->assertEqualsWithDelta(100.00, (float) $row['gross'], 0.0001);
        }
        $this->assertArrayNotHasKey('total', $sales, 'a total over currencies was published');
        $this->assertArrayNotHasKey('amount', $sales, 'a single amount over currencies was published');
    }

    public function test_two_companies_in_one_currency_are_two_rows(): void
    {
        $this->soldIn(1, 'SAR', 1, 100.00);
        $this->soldIn(2, 'SAR', 2, 70.00);

        $sales = $this->salesOf('SA');

        $this->assertCount(2, $sales['by_currency']);
        $this->assertSame([1, 2], array_column($sales['by_currency'], 'legal_entity_id'));
        $this->assertEqualsWithDelta(100.00, (float) $sales['by_currency'][0]['gross'], 0.0001);
        $this->assertEqualsWithDelta(70.00, (float) $sales['by_currency'][1]['gross'], 0.0001);
    }

    // ---------------------------------------------------------------- what is not counted

    public function test_an_order_recording_no_currency_is_counted_apart_with_the_reason(): void
    {
        $this->soldIn(1, 'SAR', 1, 100.00);
        $this->soldIn(2, null, null, 999.00);

        $sales = $this->salesOf('SA');

        $this->assertCount(1, $sales['by_currency'], 'an order with no currency was given one');
        $this->assertEqualsWithDelta(100.00, (float) $sales['by_currency'][0]['gross'], 0.0001, 'the 999 of an order with no currency was added in');
        // ONE: the order stamped Saudi that records no currency. The other seeded orders record no market at all and so belong to
        // none -- an order with no stamp is deliberately never read as Saudi Arabia, which is a separate assertion in
        // SalesByEntityAndCurrencyTest.
        $this->assertSame(1, $sales['unpriced']['orders'], 'an order stamped for this market with no currency is counted apart');
        $this->assertSame('order_currency_not_recorded', $sales['unpriced']['reason']);
    }

    /** A company of null is an order that recorded none, and never the platform's own. */
    public function test_an_order_recording_a_currency_and_no_company_names_no_company(): void
    {
        $this->soldIn(1, 'SAR', null, 100.00);

        $sales = $this->salesOf('SA');

        $this->assertCount(1, $sales['by_currency']);
        $this->assertNull($sales['by_currency'][0]['legal_entity_id']);
    }

    // ---------------------------------------------------------------- the dinar's third decimal

    /**
     * The amount is summed in the database and carried as TEXT. A dinar has three decimals, and reading each
     * amount into a float to add them is how a financial total comes to differ from the rows it was made of.
     */
    public function test_a_dinars_third_decimal_survives_the_sum(): void
    {
        $this->soldIn(1, 'KWD', 1, 12.345);
        $this->soldIn(2, 'KWD', 1, 12.345);

        $sales = $this->salesOf('SA');

        $this->assertIsString($sales['by_currency'][0]['gross'], 'the amount came back as a number, so it went through a float');
        $this->assertEqualsWithDelta(24.690, (float) $sales['by_currency'][0]['gross'], 0.0001);
    }

    // ---------------------------------------------------------------- the markets stay apart

    public function test_one_markets_sales_are_not_another_markets(): void
    {
        $this->soldIn(1, 'SAR', 1, 100.00);

        $this->assertCount(1, $this->salesOf('SA')['by_currency']);
        $this->assertSame([], $this->salesOf('KW')['by_currency'], "a Saudi sale was reported in Kuwait's figures");
    }

    // ---------------------------------------------------------------- settlements, which have no market

    /** An installation that keeps no settlements says so, rather than reporting zeros that read like a quiet month. */
    public function test_an_installation_with_no_settlement_table_says_so(): void
    {
        $this->assertSame(
            ['by_currency' => null, 'reason' => 'no_settlements_are_recorded_on_this_installation'],
            $this->overview()->json('data.settlements'),
        );
    }

    public function test_settlements_are_reported_per_company_and_per_currency(): void
    {
        $this->settlementsExist();
        $this->aSettlement(reference: 'S-1', company: 1, currency: 'SAR', net: 100.00, status: 'calculated');
        $this->aSettlement(reference: 'S-2', company: 1, currency: 'SAR', net: 50.00, status: 'calculated');
        $this->aSettlement(reference: 'S-3', company: 2, currency: 'KWD', net: 30.000, status: 'paid');

        $settlements = $this->overview()->json('data.settlements');

        $this->assertCount(2, $settlements['by_currency'], 'two currencies were folded into one row');
        $this->assertSame(
            [['KWD', 2, 'paid', 1], ['SAR', 1, 'calculated', 2]],
            array_map(static fn (array $row): array => [$row['currency'], $row['legal_entity_id'], $row['status'], $row['settlements']], $settlements['by_currency']),
        );
        $this->assertEqualsWithDelta(30.0, (float) $settlements['by_currency'][0]['net'], 0.0001);
        $this->assertEqualsWithDelta(150.0, (float) $settlements['by_currency'][1]['net'], 0.0001);
    }

    public function test_a_settlement_recording_no_currency_is_counted_apart(): void
    {
        $this->settlementsExist();
        $this->aSettlement(reference: 'S-1', company: 1, currency: 'SAR', net: 100.00, status: 'calculated');
        $this->aSettlement(reference: 'S-2', company: 1, currency: null, net: 999.00, status: 'calculated');

        $settlements = $this->overview()->json('data.settlements');

        $this->assertCount(1, $settlements['by_currency']);
        $this->assertEqualsWithDelta(100.0, (float) $settlements['by_currency'][0]['net'], 0.0001, 'the 999 of a settlement with no currency was added in');
        $this->assertSame(1, $settlements['not_counted']['settlements']);
        $this->assertSame('settlement_currency_not_recorded', $settlements['not_counted']['reason']);
    }

    /** A settlement outside the window is another period's figure and not this one's. */
    public function test_a_settlement_outside_the_window_is_not_reported(): void
    {
        $this->settlementsExist();
        $this->aSettlement(reference: 'S-OLD', company: 1, currency: 'SAR', net: 100.00, status: 'paid', at: '2025-01-01 10:00:00');

        $this->assertNull($this->overview()->json('data.settlements.by_currency'));
    }

    /** `vendor_settlements` has no migration in this repository, so its shape is the one the ledger suite builds. */
    private function settlementsExist(): void
    {
        DB::statement('DROP TABLE IF EXISTS vendor_settlements');
        \Illuminate\Support\Facades\Schema::create('vendor_settlements', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('net_amount', 24, 4)->default(0);
            $table->string('currency', 20)->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
        });
    }

    private function aSettlement(string $reference, ?int $company, ?string $currency, float $net, string $status, string $at = '2026-11-15 10:00:00'): void
    {
        DB::table('vendor_settlements')->insert([
            'reference' => $reference,
            'seller_id' => 1,
            'net_amount' => $net,
            'currency' => $currency,
            'legal_entity_id' => $company,
            'status' => $status,
            'calculated_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
