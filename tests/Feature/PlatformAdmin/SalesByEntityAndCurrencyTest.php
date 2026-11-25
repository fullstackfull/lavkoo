<?php

namespace Tests\Feature\PlatformAdmin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SeedsPlatformMarketData;

/**
 * What a market sold, by the company that sold it and by the currency its own amounts are in (L-08).
 *
 * The world of the other overview tests has no order snapshot at all, which is the state of an installation whose GCC-05 migration has not run,
 * and there the answer is still "no figure, and the reason". Here the REAL migrations are run, so the columns are exactly the ones production
 * gets, and then the rule is pinned: one row per market, company and currency, orders whose currency cannot be honoured counted apart with no
 * amount, and no figure across two currencies unless the owner has written down the rate, where it came from and the day it is from.
 */
class SalesByEntityAndCurrencyTest extends PlatformAdminTestCase
{
    use SeedsPlatformMarketData;

    private const OVERVIEW = self::PREFIX . '/overview';

    private const MARKETS = self::PREFIX . '/markets';

    /**
     * The companies of the test world: one recorded for Saudi Arabia, one for the Emirates, and none for Kuwait.
     *
     * `legal_entities.country` is UNIQUE -- the approved rule is one company for each country -- so a market holding figures of two companies is
     * not two rows of the registry for one country. It is an order whose own `legal_entity_id` is another country's company, which is what a
     * cross-border sale is, and the partition the criterion asks for is the order's own company and not the registry's.
     */
    private const SAUDI_COMPANY = 1;

    private const EMIRATES_COMPANY = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketScenario();
        $this->runOrderSnapshotMigrations();
        $this->giveCompanies();
    }

    public function test_a_market_reports_one_figure_for_each_company_and_currency_and_never_one_across_two(): void
    {
        $this->sell(orderId: 101, market: 'SA', currency: 'SAR', amount: '120.50', entityId: self::SAUDI_COMPANY);
        $this->sell(orderId: 102, market: 'SA', currency: 'SAR', amount: '9.50', entityId: self::SAUDI_COMPANY);
        $this->sell(orderId: 103, market: 'SA', currency: 'SAR', amount: '40.00', entityId: self::EMIRATES_COMPANY);
        $this->sell(orderId: 104, market: 'AE', currency: 'AED', amount: '70.00', entityId: self::EMIRATES_COMPANY);

        $saudi = $this->salesOf(market: 'SA');

        $this->assertSame([
            ['currency' => 'SAR', 'legal_entity_id' => self::SAUDI_COMPANY, 'legal_entity' => 'Lavkoo Trading Company', 'orders' => 2, 'gross' => '130.00'],
            ['currency' => 'SAR', 'legal_entity_id' => self::EMIRATES_COMPANY, 'legal_entity' => 'Lavkoo Emirates', 'orders' => 1, 'gross' => '40.00'],
        ], $saudi['by_currency'], 'a market whose orders name two companies is partitioned by company and never added up');

        $this->assertSame(
            [['currency' => 'AED', 'legal_entity_id' => self::EMIRATES_COMPANY, 'legal_entity' => 'Lavkoo Emirates', 'orders' => 1, 'gross' => '70.00']],
            $this->salesOf(market: 'AE')['by_currency'],
        );
        $this->assertSame([], $this->salesOf(market: 'KW')['by_currency'], 'a market that sold nothing in the window reports no row rather than a zero in some currency');
    }

    public function test_an_order_whose_currency_cannot_be_honoured_is_counted_apart_and_carries_no_amount(): void
    {
        $this->sell(orderId: 111, market: 'SA', currency: 'SAR', amount: '100.00', entityId: self::SAUDI_COMPANY);
        // A rate that is not one: what such a rate means for a stored amount is an open decision, so the amount is not scaled by a guess.
        $this->sell(orderId: 112, market: 'SA', currency: 'SAR', amount: '100.00', entityId: self::SAUDI_COMPANY, rate: '3.75');
        $this->sell(orderId: 113, market: 'SA', currency: null, amount: '100.00', entityId: self::SAUDI_COMPANY);

        $saudi = $this->salesOf(market: 'SA');

        $this->assertSame([['currency' => 'SAR', 'legal_entity_id' => self::SAUDI_COMPANY, 'legal_entity' => 'Lavkoo Trading Company', 'orders' => 1, 'gross' => '100.00']], $saudi['by_currency']);
        $this->assertSame(['orders' => 2, 'reason' => 'order_currency_not_recorded'], $saudi['unpriced'], 'both orders are counted, and neither puts an amount under a currency');
    }

    public function test_the_market_of_a_sale_is_the_market_the_order_recorded_and_an_order_that_recorded_none_is_never_read_as_saudi(): void
    {
        $this->sell(orderId: 121, market: null, currency: 'SAR', amount: '500.00', entityId: self::SAUDI_COMPANY);
        $this->sell(orderId: 122, market: 'ZZ', currency: 'SAR', amount: '500.00', entityId: self::SAUDI_COMPANY);

        $this->assertSame([], $this->salesOf(market: 'SA')['by_currency'], 'neither order is Saudi, because neither recorded Saudi Arabia');

        $unattributed = $this->overview()->json('data.unattributed.sales');

        $this->assertSame(1_000.0, array_sum(array_map('floatval', array_column($unattributed['by_currency'], 'gross'))));
        $this->assertSame(2, array_sum(array_column($unattributed['by_currency'], 'orders')));
    }

    public function test_no_figure_is_made_across_currencies_while_the_owner_has_recorded_no_conversion_rule(): void
    {
        $this->sell(orderId: 131, market: 'SA', currency: 'SAR', amount: '100.00', entityId: self::SAUDI_COMPANY);

        $saudi = $this->salesOf(market: 'SA');

        $this->assertNull($saudi['combined']);
        $this->assertSame('no_conversion_rule_recorded', $saudi['reason']);
        $this->assertSame(['currency' => null, 'source' => null, 'as_of' => null, 'rates' => []], config('gcc.platform_admin.reports.conversion'), 'the rule ships empty, so no installation produces a combined figure by default');
    }

    public function test_a_combined_figure_exists_only_with_a_rate_for_every_currency_and_carries_that_rate_its_source_and_its_date(): void
    {
        $this->sell(orderId: 141, market: 'SA', currency: 'SAR', amount: '100.00', entityId: self::SAUDI_COMPANY);
        $this->sell(orderId: 142, market: 'SA', currency: 'AED', amount: '100.00', entityId: self::SAUDI_COMPANY);

        config(['gcc.platform_admin.reports.conversion' => ['currency' => 'SAR', 'source' => 'SAMA daily reference', 'as_of' => '2026-11-17', 'rates' => ['SAR' => '1']]]);

        $withoutEveryRate = $this->salesOf(market: 'SA');
        $this->assertNull($withoutEveryRate['combined'], 'one missing rate means no combined figure, not a figure that quietly leaves a currency out');
        $this->assertSame('no_conversion_rate_for_every_currency', $withoutEveryRate['reason']);

        config(['gcc.platform_admin.reports.conversion' => ['currency' => 'SAR', 'source' => 'SAMA daily reference', 'as_of' => '2026-11-17', 'rates' => ['SAR' => '1', 'AED' => '1.02']]]);

        $this->assertSame([
            'currency' => 'SAR',
            'gross' => '202.00',
            'rate_source' => 'SAMA daily reference',
            'rate_date' => '2026-11-17',
            'rates' => [['currency' => 'AED', 'rate' => '1.02'], ['currency' => 'SAR', 'rate' => '1']],
        ], $this->salesOf(market: 'SA')['combined']);
        $this->assertNull($this->salesOf(market: 'SA')['reason']);
    }

    public function test_the_answer_names_the_rule_that_put_a_sale_in_a_market_a_company_and_a_currency(): void
    {
        $this->assertSame([
            'per_order' => true,
            'rule' => 'order_market_company_and_currency',
            'via' => 'orders.storefront_country, orders.legal_entity_id, orders.currency_code',
            'priced_when' => 'currency_rate = 1',
        ], $this->overview()->json('data.attribution.currency'));
    }

    public function test_each_market_names_its_own_selling_company_and_one_platform_wide_name_is_never_repeated_under_six(): void
    {
        config(['gcc.storefronts.legal.legal_entity' => 'Lavkoo Holding']);

        $markets = collect($this->getJson(self::MARKETS, $this->bearer($this->mintToken($this->platformAdmin())))->json('data.markets'))->keyBy('code');

        $this->assertSame('Lavkoo Trading Company', $markets['SA']['legal_entity']);
        $this->assertSame(self::SAUDI_COMPANY, $markets['SA']['legal_entity_id']);
        $this->assertSame(self::EMIRATES_COMPANY, $markets['AE']['legal_entity_id']);
        $this->assertSame('Lavkoo Trading Co.', $markets['SA']['legal_entity_trade_name']);
        $this->assertSame('Lavkoo Emirates', $markets['AE']['legal_entity']);
        $this->assertNull($markets['KW']['legal_entity'], 'a market with no company recorded says so rather than borrowing one');
        $this->assertNull($markets['KW']['legal_entity_id']);
        $this->assertNotContains('Lavkoo Holding', array_column($markets->all(), 'legal_entity'), 'the one platform-wide name is not what sells in a market');
    }

    public function test_a_company_that_no_longer_sells_still_names_the_sales_it_made(): void
    {
        $this->sell(orderId: 151, market: 'SA', currency: 'SAR', amount: '10.00', entityId: self::SAUDI_COMPANY);
        DB::table('legal_entities')->where('id', self::SAUDI_COMPANY)->update(['status' => false]);

        $this->assertSame('Lavkoo Trading Company', $this->salesOf(market: 'SA')['by_currency'][0]['legal_entity'], 'an order sold by a closed company was still sold by it');
        $this->assertNull($this->getJson(self::MARKETS, $this->bearer($this->mintToken($this->platformAdmin())))->json('data.markets.0.legal_entity'), 'who sells there NOW is nobody, which is what the market answers');
    }

    /** @return array<string, mixed> */
    private function salesOf(string $market): array
    {
        $markets = collect($this->overview()->json('data.markets'))->keyBy('market');

        return $markets[$market]['sales'];
    }

    private function overview(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(self::OVERVIEW, $this->bearer($this->mintToken($this->platformAdmin())));
    }

    /** An order of a market, in a currency, sold by a company: the record GCC-05 and D1-03 write, put straight in the table. */
    private function sell(int $orderId, ?string $market, ?string $currency, string $amount, ?int $entityId, string $rate = '1'): void
    {
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_status' => 'delivered',
            'order_amount' => $amount,
            'storefront_country' => $market,
            'currency_code' => $currency,
            'currency_rate' => $currency === null ? null : $rate,
            'legal_entity_id' => $entityId,
            'created_at' => '2026-11-16 10:00:00',
            'updated_at' => '2026-11-16 10:00:00',
        ]);
    }

    /** The real migrations of what an order was sold in, so the columns under test are the ones production gets. */
    private function runOrderSnapshotMigrations(): void
    {
        foreach (['2026_11_26_100000_add_storefront_snapshot_to_orders_table', '2026_11_27_100100_add_legal_entity_to_orders_table', '2026_11_27_100000_create_legal_entities_table'] as $migration) {
            (require database_path("migrations/{$migration}.php"))->up();
        }

        $this->assertTrue(Schema::hasColumn('orders', 'currency_code'));
        $this->assertTrue(Schema::hasColumn('orders', 'legal_entity_id'));
    }

    /** One company for Saudi Arabia and one for the Emirates, as the registry's unique country allows; Kuwait has none recorded. */
    private function giveCompanies(): void
    {
        DB::table('legal_entities')->insert([
            ['id' => self::SAUDI_COMPANY, 'country' => 'SA', 'legal_name' => 'Lavkoo Trading Company', 'trade_name' => 'Lavkoo Trading Co.', 'status' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => self::EMIRATES_COMPANY, 'country' => 'AE', 'legal_name' => 'Lavkoo Emirates', 'trade_name' => null, 'status' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
