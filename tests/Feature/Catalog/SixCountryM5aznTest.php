<?php

namespace Tests\Feature\Catalog;

use App\Models\ProviderCatalogItem;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Fulfillment\Data\ImportResult;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fulfillment\M5aznTestCase;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;

/**
 * A10 across the six countries: an M5AZN item is imported into the table default country, so it is parked for review when its SKU or its barcode is
 * held by a product of that country, and imported when only one of the other five holds them. With the switch off a product of any country parks it.
 */
class SixCountryM5aznTest extends M5aznTestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutImageDownloads();
        $this->addStorefrontCountryColumn();
        $this->stage();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** Imports the staged item once, from an empty catalogue except for the product given, and puts the item back as it was staged. */
    private function importWith(array $holder): ImportResult
    {
        DB::table('products')->delete();
        DB::table('provider_product_mappings')->delete();
        ProviderCatalogItem::query()->update(['status' => ProviderCatalogItem::STATUS_NEW]);
        $this->makeProduct(900, null, $holder);

        return $this->importer()->import($this->staged()->id);
    }

    /** @return array<string, array<string, mixed>> what a product of a country holds that the staged item also carries: its SKU, its barcode, or both */
    private function holders(string $country): array
    {
        return [
            'the SKU' => ['code' => 'VC1000', 'barcode' => 'OTHERBARCODE', 'storefront_country' => $country],
            'the barcode' => ['code' => 'OTHERCODE', 'barcode' => '6281000000011', 'storefront_country' => $country],
            'both' => ['code' => 'VC1000', 'barcode' => '6281000000011', 'storefront_country' => $country],
        ];
    }

    public function test_with_the_switch_on_an_item_is_parked_only_by_a_product_of_the_country_it_is_imported_into(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->matrixCountries() as $country) {
            foreach ($this->holders($country) as $what => $holder) {
                $parked = $this->importWith($holder)->outcome === ImportResult::NEEDS_REVIEW;

                $this->matrixCase($failures, $country === ProductCodeScope::TABLE_DEFAULT_COUNTRY, $parked, "{$what} held in {$country}: the item is parked");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_an_item_that_is_imported_is_saved_in_the_table_default_country_next_to_the_twin_of_another_country(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach (array_diff($this->matrixCountries(), [ProductCodeScope::TABLE_DEFAULT_COUNTRY]) as $country) {
            $result = $this->importWith($this->holders($country)['both']);

            $this->matrixCase($failures, true, $result->outcome === ImportResult::IMPORTED, "held in {$country}: the item is imported");
            $this->matrixCase($failures, true, DB::table('products')->where('id', $result->productId)->value('storefront_country') === ProductCodeScope::TABLE_DEFAULT_COUNTRY, "held in {$country}: the product is saved in the table default country");
            $this->matrixCase($failures, true, DB::table('products')->where('code', 'VC1000')->count() === 2, "held in {$country}: the SKU now exists once there and once here");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_the_default_storefront_does_not_move_the_country_an_item_is_imported_into(): void
    {
        $this->scopeCodesByCountry();
        config(['gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.default_country' => 'KW']);
        $failures = [];
        foreach ($this->matrixCountries() as $country) {
            $parked = $this->importWith($this->holders($country)['both'])->outcome === ImportResult::NEEDS_REVIEW;

            $this->matrixCase($failures, $country === ProductCodeScope::TABLE_DEFAULT_COUNTRY, $parked, "held in {$country}, the default storefront is KW: the item is parked");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_an_item_is_parked_by_a_product_of_any_country(): void
    {
        $failures = [];
        foreach ($this->matrixCountries() as $country) {
            foreach ($this->holders($country) as $what => $holder) {
                $this->matrixCase($failures, true, $this->importWith($holder)->outcome === ImportResult::NEEDS_REVIEW, "{$what} held in {$country}: the item is parked");
            }
        }

        $this->assertMatrixHolds($failures);
    }
}
