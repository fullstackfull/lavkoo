<?php

namespace Tests\Feature\Storefront;

use App\Models\LegalEntity;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsLegalEntityWorld;
use Tests\TestCase;

/**
 * Which company sells a product (GCC-13, D1-03): the column the one-selling-company-per-order rule reads, the resolver that answers for a product and
 * a storefront, and the admin screens that set it from the product's country.
 *
 * The first group is the way back: while legal entities are off, and while they are on with an empty registry, a product is saved and read exactly as
 * it is today. That is the live Saudi catalogue, so it is pinned first.
 */
class ProductLegalEntityTest extends TestCase
{
    use BuildsLegalEntityWorld;

    private const MIGRATION = 'migrations/2026_11_27_100200_add_legal_entity_to_products_table.php';

    private const REGISTRY_MIGRATION = 'migrations/2026_11_27_100000_create_legal_entities_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        Schema::table('products', function ($table) {
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
        $this->runStorefrontCountryMigration();
        // Only the registry itself: the orders half of the legal entity migrations needs an orders table, and nothing here sells anything.
        (require database_path(self::REGISTRY_MIGRATION))->up();
        $this->runProductLegalEntityMigration();
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('legal_entities');
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();

        parent::tearDown();
    }

    private function runProductLegalEntityMigration(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    private function registry(): LegalEntityRegistry
    {
        return app(LegalEntityRegistry::class);
    }

    private function adminAddRequest(array $input = []): Request
    {
        return Request::create('/admin/products/add', 'POST', $input + [
            'name' => ['Serum', 'سيروم'],
            'lang' => ['en', 'ar'],
            'description' => ['A serum', 'سيروم'],
            'code' => 'SER12345',
            'barcode' => '6291234567890',
            'category_id' => 1,
            'product_type' => 'physical',
            'unit' => 'pc',
            'unit_price' => 10,
            'tax' => 0,
            'tax_type' => 'percent',
            'tax_model' => 'exclude',
            'discount' => 0,
            'discount_type' => 'percent',
            'current_stock' => 5,
            'minimum_order_qty' => 1,
            'shipping_cost' => 0,
            'existing_thumbnail' => 'existing.webp',
        ]);
    }

    private function savedAdminProduct(array $input = []): Product
    {
        $data = app(ProductService::class)->getAddProductData(request: $this->adminAddRequest($input), addedBy: 'admin', shopId: 1);

        return Product::create(Arr::only($data, [
            'storefront_country', 'legal_entity_id', 'added_by', 'name', 'code', 'barcode', 'status', 'request_status', 'unit_price', 'current_stock',
        ]));
    }

    // ---- the way back: the switch off, and the switch on with an empty registry -------------------------------------------------

    public function test_a_product_names_no_company_while_legal_entities_are_off(): void
    {
        $this->assertFalse($this->registry()->tracksProducts(), 'the switch is off, so the column is not read at all');

        $data = app(ProductService::class)->getAddProductData(request: $this->adminAddRequest(['storefront_country' => 'KW']), addedBy: 'admin', shopId: 1);

        $this->assertArrayNotHasKey('legal_entity_id', $data, 'nothing is saved about a company while the switch is off');
        $this->assertSame([], $this->registry()->newProductAttributes(country: 'KW'));
        $this->assertNull($this->registry()->forProduct(product: null, country: 'SA'));
    }

    public function test_the_switch_on_with_an_empty_registry_saves_exactly_what_the_switch_off_saves(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $withTheSwitchOff = $this->addDataOfASaudiProduct();

        config(['gcc.storefronts.legal_entities.enabled' => true]);
        $this->registry()->flush();
        $this->assertSame(0, LegalEntity::query()->count(), 'the registry ships empty');

        $withTheSwitchOn = $this->addDataOfASaudiProduct();

        $this->assertArrayNotHasKey('legal_entity_id', $withTheSwitchOn, 'an empty registry names no company');
        $this->assertSame(
            $withTheSwitchOff,
            $withTheSwitchOn,
            'every value a Saudi product is saved with is what it is with the switch off',
        );
    }

    /** @return array<string, mixed> what a Saudi product is saved with, less the slug, which carries a fresh suffix on every call */
    private function addDataOfASaudiProduct(): array
    {
        $data = app(ProductService::class)->getAddProductData(request: $this->adminAddRequest(['storefront_country' => 'SA']), addedBy: 'admin', shopId: 1);

        return Arr::except($data, ['slug']);
    }

    public function test_a_saudi_product_of_an_empty_registry_is_saved_with_no_company_and_resolves_to_none(): void
    {
        $this->turnLegalEntitiesOn();

        $saudi = $this->savedAdminProduct(['storefront_country' => 'SA']);

        $this->assertNull(DB::table('products')->where('id', $saudi->id)->value('legal_entity_id'));
        $this->assertNull($this->registry()->forProduct(product: $saudi, country: 'SA'), 'an empty registry never answers a company');
    }

    public function test_a_seller_product_never_carries_a_company(): void
    {
        $this->turnLegalEntitiesOn();
        $this->registerEntity(country: 'SA');

        $sellerData = app(ProductService::class)->getAddProductData(request: $this->adminAddRequest(['storefront_country' => 'SA']), addedBy: 'seller', shopId: 7);

        $this->assertArrayNotHasKey('legal_entity_id', $sellerData, 'a seller does not choose a country, so no company follows from one');
    }

    // ---- the column ------------------------------------------------------------------------------------------------------------

    public function test_the_column_is_the_one_the_one_selling_company_rule_reads(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'legal_entity_id'));
        $this->assertSame('legal_entity_id', LegalEntityRegistry::COLUMN);
    }

    public function test_running_the_migration_again_changes_nothing(): void
    {
        $this->turnLegalEntitiesOn();
        $this->registerEntity(country: 'KW');
        $product = $this->savedAdminProduct(['storefront_country' => 'KW']);
        $before = (array) DB::table('products')->where('id', $product->id)->first();

        $this->runProductLegalEntityMigration();

        $this->assertSame($before, (array) DB::table('products')->where('id', $product->id)->first());
    }

    public function test_the_migration_refuses_to_roll_back_while_a_product_names_a_company(): void
    {
        $this->turnLegalEntitiesOn();
        $this->registerEntity(country: 'KW');
        $this->savedAdminProduct(['storefront_country' => 'KW']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Products name a selling company');

        (require database_path(self::MIGRATION))->down();
    }

    public function test_the_migration_rolls_back_while_no_product_names_a_company(): void
    {
        (require database_path(self::MIGRATION))->down();

        $this->assertFalse(Schema::hasColumn('products', 'legal_entity_id'));
    }

    public function test_nothing_is_tracked_before_the_migration_has_run(): void
    {
        $this->turnLegalEntitiesOn();
        (require database_path(self::MIGRATION))->down();
        $this->registry()->flush();

        $this->assertFalse($this->registry()->tracksProducts());
        $this->assertSame([], $this->registry()->newProductAttributes(country: 'SA'));
    }

    // ---- the resolver ----------------------------------------------------------------------------------------------------------

    public function test_a_product_that_names_no_company_is_sold_by_the_company_of_the_storefronts_country(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $this->registerEntity(country: 'SA');

        $unstamped = $this->savedAdminProduct(['storefront_country' => 'KW']);
        DB::table('products')->where('id', $unstamped->id)->update(['legal_entity_id' => null]);

        $this->assertSame($kuwait->id, $this->registry()->forProduct(product: $unstamped->fresh(), country: 'KW')?->id);
    }

    public function test_a_product_that_names_a_company_is_sold_by_that_company(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $saudi = $this->registerEntity(country: 'SA');

        $product = $this->savedAdminProduct(['storefront_country' => 'KW']);

        $this->assertSame($kuwait->id, $product->legal_entity_id);
        $this->assertSame(
            $kuwait->id,
            $this->registry()->forProduct(product: $product, country: 'SA')?->id,
            'the company a product names is the one that sells it, whichever storefront is asking',
        );
        $this->assertNotSame($saudi->id, $kuwait->id);
    }

    public function test_the_resolver_reads_a_plain_query_builder_row_as_it_reads_a_model(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $product = $this->savedAdminProduct(['storefront_country' => 'KW']);

        $row = DB::table('products')->where('id', $product->id)->first();

        $this->assertSame($kuwait->id, $this->registry()->forProduct(product: $row, country: 'KW')?->id);
    }

    public function test_a_row_loaded_without_the_column_falls_back_to_the_countrys_company_rather_than_failing(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $product = $this->savedAdminProduct(['storefront_country' => 'KW']);

        $thin = DB::table('products')->select('id', 'storefront_country')->where('id', $product->id)->first();

        $this->assertSame($kuwait->id, $this->registry()->forProduct(product: $thin, country: 'KW')?->id);
    }

    public function test_a_country_without_an_active_company_answers_nothing_and_never_throws(): void
    {
        $this->turnLegalEntitiesOn();
        $this->registerEntity(country: 'KW', overrides: ['status' => false]);

        $this->assertNull($this->registry()->forProduct(product: null, country: 'KW'));
        $this->assertNull($this->registry()->forProduct(product: null, country: 'QA'));
    }

    public function test_a_product_whose_company_is_no_longer_active_answers_nothing_rather_than_another_company(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $saudi = $this->registerEntity(country: 'SA');
        $product = $this->savedAdminProduct(['storefront_country' => 'KW']);

        $kuwait->update(['status' => false]);
        $this->registry()->flush();

        $this->assertNull(
            $this->registry()->forProduct(product: $product->fresh(), country: 'SA'),
            'a product is never quietly sold by a company it does not name',
        );
        $this->assertSame($saudi->id, $this->registry()->forCountry(country: 'SA')?->id);
    }

    // ---- a later change of the registry never rewrites a product ---------------------------------------------------------------

    public function test_a_company_renamed_later_does_not_rewrite_the_product_it_already_sells(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $product = $this->savedAdminProduct(['storefront_country' => 'KW']);

        $kuwait->update(['legal_name' => 'Lavkoo Kuwait Holding Co.']);
        $this->registry()->flush();

        $this->assertSame($kuwait->id, DB::table('products')->where('id', $product->id)->value('legal_entity_id'));
        $this->assertSame('Lavkoo Kuwait Holding Co.', $this->registry()->forProduct(product: $product->fresh(), country: 'KW')?->legal_name);
    }

    public function test_a_company_switched_off_later_never_moves_a_product_already_added(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $product = $this->savedAdminProduct(['storefront_country' => 'KW']);

        $kuwait->update(['status' => false]);
        $this->registry()->flush();

        $this->assertSame($kuwait->id, DB::table('products')->where('id', $product->id)->value('legal_entity_id'), 'the stored company is not rewritten');
        $this->assertSame([], $this->registry()->newProductAttributes(country: 'KW'), 'the next product of that country names no company instead');
    }

    // ---- the company is not typed ----------------------------------------------------------------------------------------------

    public function test_a_company_the_request_asks_for_is_ignored_and_the_countrys_company_is_used(): void
    {
        $this->turnLegalEntitiesOn();
        $kuwait = $this->registerEntity(country: 'KW');
        $saudi = $this->registerEntity(country: 'SA');

        $data = app(ProductService::class)->getAddProductData(
            request: $this->adminAddRequest(['storefront_country' => 'KW', 'legal_entity_id' => $saudi->id]),
            addedBy: 'admin',
            shopId: 1,
        );

        $this->assertSame($kuwait->id, $data['legal_entity_id'], 'the company follows from the country, never from the request');
    }

    public function test_a_product_of_an_unconfigured_country_falls_back_to_the_default_storefronts_company(): void
    {
        $this->turnLegalEntitiesOn();
        $saudi = $this->registerEntity(country: 'SA');

        $data = app(ProductService::class)->getAddProductData(request: $this->adminAddRequest(['storefront_country' => 'XX']), addedBy: 'admin', shopId: 1);

        $this->assertSame('SA', $data['storefront_country']);
        $this->assertSame($saudi->id, $data['legal_entity_id']);
    }
}
