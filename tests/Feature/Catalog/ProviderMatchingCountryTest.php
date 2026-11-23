<?php

namespace Tests\Feature\Catalog;

use App\Enums\Fulfillment\ProviderCode;
use App\Http\Controllers\Admin\Fulfillment\TorodMatchingController;
use App\Models\ProviderCatalogItem;
use App\Services\Fulfillment\CatalogStagingService;
use App\Services\Fulfillment\ProviderMappingService;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fulfillment\FulfillmentTestCase;
use Tests\Support\BuildsCodeScopeCatalog;

/**
 * E1, E2: a warehouse item is suggested the store products that look like it, and the picker offers store products to match by hand. A wrong
 * approval binds the warehouse's stock to the product. Provider connections have no country of their own yet, so with the country-scoped codes
 * on they serve the default storefront's country, and a twin of another country is neither suggested nor offered.
 */
class ProviderMatchingCountryTest extends FulfillmentTestCase
{
    use BuildsCodeScopeCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->addStorefrontCountryColumn();
        $this->makeProduct(1, null, ['code' => 'SKU-100', 'barcode' => '6291234567890', 'name' => 'Saudi serum', 'storefront_country' => 'SA']);
        $this->makeProduct(2, null, ['code' => 'SKU-100', 'barcode' => '6291234567890', 'name' => 'Kuwaiti serum', 'storefront_country' => 'KW']);
        $this->makeProduct(3, null, ['code' => 'OTHER', 'barcode' => 'OTHERBARCODE', 'name' => 'Other serum', 'storefront_country' => 'KW']);
        app(CatalogStagingService::class)->stage(ProviderCode::TorodPoint, 'api', [[
            'external_id' => '555', 'name' => 'Serum', 'sku' => 'SKU-100', 'barcode' => '6291234567890', 'stock' => 4, 'payload' => ['id' => 555, 'externalId' => '2'],
        ]]);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @return list<int> */
    private function suggested(?string $echo = null): array
    {
        $suggestions = app(ProviderMappingService::class)->suggestionsFor(ProviderCatalogItem::where('external_id', '555')->firstOrFail(), echoedExternalId: $echo);

        return array_column($suggestions, 'product_id');
    }

    /** @return list<int> */
    private function picked(string $term): array
    {
        $answer = app(TorodMatchingController::class)->productSearch(Request::create('/admin/fulfillment/torod/products', 'GET', ['q' => $term]));

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $answer->getData(true));
        sort($ids);

        return $ids;
    }

    // ---- E1: the suggestions ---------------------------------------------------------------------------------------------------

    public function test_with_the_switch_off_the_twins_of_every_country_are_suggested(): void
    {
        $this->assertSame([1, 2], $this->suggested());
    }

    public function test_with_the_switch_on_only_the_default_storefronts_product_is_suggested(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame([1], $this->suggested());
    }

    public function test_with_the_switch_on_a_provider_of_another_default_storefront_is_suggested_that_countrys_product(): void
    {
        $this->scopeCodesByCountry();
        config(['gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.default_country' => 'KW']);

        $this->assertSame([2], $this->suggested());
    }

    public function test_with_the_switch_on_an_echoed_external_id_never_suggests_another_countrys_product(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame([1], $this->suggested(echo: '3'), 'the echo names a Kuwaiti product; the Saudi twin is still the only suggestion');
    }

    public function test_a_missing_column_acts_as_off(): void
    {
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertSame([1, 2], $this->suggested());
    }

    /**
     * B-05, the half that matters: a connection that serves a country we have no storefront for is offered **nothing**, whichever of its two
     * country settings says so. Suggesting the Saudi twin to an Egyptian warehouse is the cross-country match this unit exists to prevent.
     */
    public function test_nothing_is_suggested_for_a_connection_that_serves_a_country_we_have_no_storefront_for(): void
    {
        $this->scopeCodesByCountry();

        foreach ([['storefront_country' => 'EG'], ['country_code' => 'EG'], ['storefront_country' => '', 'country_code' => 'EG']] as $settings) {
            $this->connectionSettings(provider: ProviderCode::TorodPoint->value, settings: $settings);

            $this->assertSame([], $this->suggested(), json_encode($settings));
            $this->assertSame([], $this->suggested(echo: '1'), 'not even the echoed id: ' . json_encode($settings));
        }
    }

    public function test_a_connection_that_names_one_of_our_countries_is_suggested_that_countrys_product(): void
    {
        $this->scopeCodesByCountry();
        $this->connectionSettings(provider: ProviderCode::TorodPoint->value, settings: ['country_code' => 'KW']);

        $this->assertSame([2], $this->suggested());
    }

    /** @param array<string, mixed> $settings */
    private function connectionSettings(string $provider, array $settings): void
    {
        DB::table('integration_settings')->updateOrInsert(
            ['provider' => $provider],
            ['enabled' => 1, 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now()],
        );
    }
    // ---- E2: the picker --------------------------------------------------------------------------------------------------------

    public function test_with_the_switch_off_the_picker_offers_every_country(): void
    {
        $this->assertSame([1, 2, 3], $this->picked('serum'));
    }

    public function test_with_the_switch_on_the_picker_offers_the_default_storefronts_products_only(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame([1], $this->picked('serum'));
        $this->assertSame([1], $this->picked('SKU-100'));
        $this->assertSame([], $this->picked('OTHERBARCODE'));
    }

    public function test_the_picker_rows_keep_their_columns(): void
    {
        $this->scopeCodesByCountry();

        $row = app(TorodMatchingController::class)->productSearch(Request::create('/x', 'GET', ['q' => 'Saudi']))->getData(true)[0];

        $this->assertSame(['id', 'name', 'code', 'barcode', 'fulfillment_provider'], array_keys($row));
    }
}
