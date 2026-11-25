<?php

namespace Tests\Feature\PlatformAdmin;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontLegalDocuments;

class MarketsTest extends PlatformAdminTestCase
{
    use BuildsStorefrontLegalDocuments;

    private const URL = self::PREFIX . '/markets';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
    }

    public function test_the_platform_owner_sees_the_six_markets_in_the_order_of_the_configuration(): void
    {
        $this->travelTo('2026-11-17 12:00:00');
        $token = $this->mintToken($this->platformAdmin());

        $response = $this->getJson(self::URL, $this->bearer($token));

        $response->assertOk()->assertExactJson(['data' => [
            'scope' => 'all',
            'generated_at' => Carbon::parse('2026-11-17 12:00:00')->utc()->toIso8601ZuluString(),
            'markets' => [
                ['code' => 'SA', 'path' => 'sa', 'currency' => 'SAR', 'active' => true, 'storefront_enabled' => false, 'legal_ready' => true, 'legal_entity' => null, 'legal_entity_id' => null, 'legal_entity_trade_name' => null],
                ['code' => 'AE', 'path' => 'ae', 'currency' => 'AED', 'active' => false, 'storefront_enabled' => false, 'legal_ready' => false, 'legal_entity' => null, 'legal_entity_id' => null, 'legal_entity_trade_name' => null],
                ['code' => 'KW', 'path' => 'kw', 'currency' => 'KWD', 'active' => false, 'storefront_enabled' => false, 'legal_ready' => false, 'legal_entity' => null, 'legal_entity_id' => null, 'legal_entity_trade_name' => null],
                ['code' => 'QA', 'path' => 'qa', 'currency' => 'QAR', 'active' => false, 'storefront_enabled' => false, 'legal_ready' => false, 'legal_entity' => null, 'legal_entity_id' => null, 'legal_entity_trade_name' => null],
                ['code' => 'BH', 'path' => 'bh', 'currency' => 'BHD', 'active' => false, 'storefront_enabled' => false, 'legal_ready' => false, 'legal_entity' => null, 'legal_entity_id' => null, 'legal_entity_trade_name' => null],
                ['code' => 'OM', 'path' => 'om', 'currency' => 'OMR', 'active' => false, 'storefront_enabled' => false, 'legal_ready' => false, 'legal_entity' => null, 'legal_entity_id' => null, 'legal_entity_trade_name' => null],
            ],
        ]]);
    }

    public function test_active_and_storefront_enabled_come_from_the_configuration_and_are_not_the_same_thing(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        $token = $this->mintToken($this->platformAdmin());

        $markets = collect($this->getJson(self::URL, $this->bearer($token))->json('data.markets'))->keyBy('code');

        $this->assertTrue($markets['KW']['active']);
        $this->assertFalse($markets['AE']['active']);
        $this->assertSame([true, true, true, true, true, true], $markets->pluck('storefront_enabled')->values()->all(), 'the platform switch, repeated on each market so that each is self-contained');
    }

    public function test_legal_readiness_is_what_the_storefront_gate_says_and_not_a_second_opinion(): void
    {
        $this->publishRequiredLegalDocuments(country: 'AE');
        $this->publishRequiredLegalDocuments(country: 'KW', withheldType: 'refund');
        $token = $this->mintToken($this->platformAdmin());

        $markets = collect($this->getJson(self::URL, $this->bearer($token))->json('data.markets'))->pluck('legal_ready', 'code');

        $this->assertSame(['SA' => true, 'AE' => true, 'KW' => false, 'QA' => false, 'BH' => false, 'OM' => false], $markets->all());
    }

    public function test_without_the_legal_table_a_market_that_is_not_grandfathered_is_not_ready_and_nothing_fails(): void
    {
        Schema::drop('storefront_legal_documents');
        $token = $this->mintToken($this->platformAdmin());

        $markets = collect($this->getJson(self::URL, $this->bearer($token))->assertOk()->json('data.markets'))->pluck('legal_ready', 'code');

        $this->assertSame(['SA' => true, 'AE' => false, 'KW' => false, 'QA' => false, 'BH' => false, 'OM' => false], $markets->all());
    }

    /**
     * The company of a market is the one `legal_entities` records for that country, and a market with none says so.
     *
     * It used to be `gcc.storefronts.legal.legal_entity`, ONE value repeated under all six markets, which said that one company sells in six
     * countries -- the opposite of the approved plan that each country has a selling company of its own (L-08). That value is a platform-wide
     * label and is deliberately not what this endpoint answers; the per-company figures are pinned in SalesByEntityAndCurrencyTest.
     */
    public function test_the_company_of_a_market_is_the_one_recorded_for_that_country_and_not_one_name_for_all_six(): void
    {
        config(['gcc.storefronts.legal.legal_entity' => 'Lavkoo Test Trading Co.']);
        (require database_path('migrations/2026_11_27_100000_create_legal_entities_table.php'))->up();
        DB::table('legal_entities')->insert(['country' => 'KW', 'legal_name' => 'Lavkoo Kuwait', 'status' => true, 'created_at' => now(), 'updated_at' => now()]);
        $token = $this->mintToken($this->platformAdmin());

        $markets = collect($this->getJson(self::URL, $this->bearer($token))->json('data.markets'))->pluck('legal_entity', 'code');

        $this->assertSame(['SA' => null, 'AE' => null, 'KW' => 'Lavkoo Kuwait', 'QA' => null, 'BH' => null, 'OM' => null], $markets->all());
    }

    public function test_a_restricted_viewer_sees_only_their_markets(): void
    {
        $token = $this->mintToken($this->platformAdmin(countries: ['KW', 'AE']));

        $response = $this->getJson(self::URL, $this->bearer($token));

        $response->assertOk()->assertJsonPath('data.scope', 'restricted');
        $this->assertSame(['AE', 'KW'], array_column($response->json('data.markets'), 'code'));
    }

    public function test_a_viewer_whose_grant_names_no_market_that_exists_sees_none(): void
    {
        $token = $this->mintToken($this->platformAdmin(countries: ['XX']));

        $this->getJson(self::URL, $this->bearer($token))->assertOk()->assertJsonPath('data.markets', [])->assertJsonPath('data.scope', 'restricted');
    }

    public function test_a_market_that_is_removed_from_the_configuration_is_not_listed(): void
    {
        $countries = config('gcc.storefronts.countries');
        unset($countries['OM']);
        config(['gcc.storefronts.countries' => $countries]);
        $token = $this->mintToken($this->platformAdmin());

        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH'], array_column($this->getJson(self::URL, $this->bearer($token))->json('data.markets'), 'code'));
    }

    public function test_it_reads_the_configuration_even_while_storefronts_are_switched_off_and_the_resolver_serves_only_saudi(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.countries.QA.active' => true]);
        $token = $this->mintToken($this->platformAdmin());

        $markets = collect($this->getJson(self::URL, $this->bearer($token))->json('data.markets'))->keyBy('code');

        $this->assertTrue($markets['QA']['active']);
        $this->assertSame(6, $markets->count());
    }
}
