<?php

namespace Tests\Feature\Catalog;

use App\Models\ProviderCatalogItem;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Fulfillment\Data\ImportResult;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fulfillment\M5aznTestCase;
use Tests\Support\BuildsCodeScopeCatalog;

/**
 * A10: an M5AZN item is parked for review when its SKU or barcode is already used by a product of the country the item would be imported
 * into. With the switch off that is a product of any country, as before.
 */
class M5aznImporterCodeScopeTest extends M5aznTestCase
{
    use BuildsCodeScopeCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutImageDownloads();
        $this->addStorefrontCountryColumn();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function imported(): ImportResult
    {
        $this->stage();

        return $this->importer()->import($this->staged()->id);
    }

    public function test_with_the_switch_off_a_sku_held_by_a_product_of_another_country_still_parks_the_item(): void
    {
        $this->makeProduct(900, null, ['code' => 'VC1000', 'storefront_country' => 'KW']);

        $result = $this->imported();

        $this->assertSame(ImportResult::NEEDS_REVIEW, $result->outcome);
        $this->assertSame('sku_or_barcode_already_used_by_another_product', $result->reason);
    }

    public function test_with_the_switch_off_a_barcode_held_by_a_product_of_another_country_still_parks_the_item(): void
    {
        $this->makeProduct(900, null, ['code' => 'OTHER', 'barcode' => '6281000000011', 'storefront_country' => 'KW']);

        $this->assertSame(ImportResult::NEEDS_REVIEW, $this->imported()->outcome);
    }

    public function test_with_the_switch_on_a_sku_held_only_by_another_country_does_not_park_the_item(): void
    {
        $this->scopeCodesByCountry();
        $this->makeProduct(900, null, ['code' => 'VC1000', 'barcode' => 'OTHERBARCODE', 'storefront_country' => 'KW']);

        $result = $this->imported();

        $this->assertSame(ImportResult::IMPORTED, $result->outcome);
        $this->assertSame('VC1000', DB::table('products')->where('id', $result->productId)->value('code'));
    }

    public function test_with_the_switch_on_a_barcode_held_only_by_another_country_does_not_park_the_item(): void
    {
        $this->scopeCodesByCountry();
        $this->makeProduct(900, null, ['code' => 'OTHER', 'barcode' => '6281000000011', 'storefront_country' => 'KW']);

        $this->assertSame(ImportResult::IMPORTED, $this->imported()->outcome);
    }

    public function test_with_the_switch_on_a_sku_held_in_the_country_the_item_is_imported_into_parks_it(): void
    {
        $this->scopeCodesByCountry();
        $this->makeProduct(900, null, ['code' => 'VC1000', 'storefront_country' => 'SA']);

        $result = $this->imported();

        $this->assertSame(ImportResult::NEEDS_REVIEW, $result->outcome);
        $this->assertSame('sku_or_barcode_already_used_by_another_product', $result->reason);
        $this->assertSame(1, DB::table('products')->count());
    }

    public function test_with_the_switch_on_a_barcode_held_in_the_country_the_item_is_imported_into_parks_it(): void
    {
        $this->scopeCodesByCountry();
        $this->makeProduct(900, null, ['code' => 'OTHER', 'barcode' => '6281000000011', 'storefront_country' => 'SA']);

        $this->assertSame(ImportResult::NEEDS_REVIEW, $this->imported()->outcome);
    }

    public function test_the_item_is_checked_in_the_country_it_is_written_to(): void
    {
        $this->scopeCodesByCountry();

        $result = $this->imported();

        $this->assertSame(ProductCodeScope::TABLE_DEFAULT_COUNTRY, DB::table('products')->where('id', $result->productId)->value('storefront_country'));
    }

    /** @return list<string> the statements that wrote a product row */
    private function productInserts(callable $import): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            if (preg_match('/^insert into [`"]products[`"]/', $query->sql) === 1) {
                $statements[] = $query->sql;
            }
        });
        $import();

        return $statements;
    }

    public function test_the_row_names_its_country_only_while_the_switch_is_on(): void
    {
        $off = $this->productInserts(fn () => $this->imported());
        DB::table('products')->delete();
        DB::table('provider_product_mappings')->delete();
        ProviderCatalogItem::query()->update(['status' => ProviderCatalogItem::STATUS_NEW]);
        $this->scopeCodesByCountry();
        $on = $this->productInserts(fn () => $this->importer()->import($this->staged()->id));

        $this->assertCount(1, $off);
        $this->assertStringNotContainsString('storefront_country', $off[0], 'the row is what it always was');
        $this->assertCount(1, $on);
        $this->assertStringContainsString('storefront_country', $on[0], 'what was checked is what is written');
    }

    public function test_a_missing_column_acts_as_off(): void
    {
        $this->scopeCodesByCountry();
        $this->makeProduct(900, null, ['code' => 'VC1000', 'storefront_country' => 'KW']);
        $this->dropStorefrontCountryColumn();

        $this->assertSame(ImportResult::NEEDS_REVIEW, $this->imported()->outcome);
    }

    public function test_an_operator_can_still_force_the_import_whatever_the_country(): void
    {
        $this->scopeCodesByCountry();
        $this->makeProduct(900, null, ['code' => 'VC1000', 'storefront_country' => 'SA']);
        $this->stage();

        $result = $this->importer()->import($this->staged()->id, force: true);

        $this->assertSame(ImportResult::IMPORTED, $result->outcome);
        $this->assertSame(ProviderCatalogItem::STATUS_IMPORTED, $this->staged()->status);
    }

    public function test_an_item_without_a_sku_or_barcode_is_never_parked_by_the_check(): void
    {
        $this->scopeCodesByCountry();
        $this->makeProduct(900, null, ['code' => 'VC1000', 'storefront_country' => 'SA']);
        $this->stage([['id' => 777, 'name' => 'No identifiers', 'quantity' => 3, 'cost' => 1.0, 'price' => 2.0]]);

        $this->assertSame(ImportResult::IMPORTED, $this->importer()->import($this->staged('777')->id)->outcome);
    }
}
