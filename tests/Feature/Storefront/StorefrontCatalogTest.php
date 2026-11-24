<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * Every product belongs to one country. A request bound to a storefront sees that country's products only, so a price or a stock level
 * is never shared with another country. While storefronts are off, and for anything that was not bound to a storefront, the catalog is
 * exactly what it was.
 */
class StorefrontCatalogTest extends TestCase
{
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();

        $this->insertCatalogProduct(id: 1, name: 'Saudi serum', price: 40, stock: 12);
        $this->insertCatalogProduct(id: 2, name: 'Emirati serum', price: 55, stock: 3, country: 'AE');
        $this->insertCatalogProduct(id: 3, name: 'Kuwaiti serum', price: 9, stock: 7, country: 'KW');
    }

    private function switchStorefrontsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
            'gcc.storefronts.countries.KW.active' => true,
        ]);
    }

    private function serveRequestFrom(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function visibleProductIds(): array
    {
        return Product::query()->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_a_product_added_without_a_country_is_a_saudi_product(): void
    {
        $this->assertSame('SA', DB::table('products')->where('id', 1)->value('storefront_country'));
    }

    public function test_the_migration_can_run_twice_and_be_rolled_back(): void
    {
        DB::table('products')->where('storefront_country', '<>', 'SA')->delete();
        $migration = require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('products', 'storefront_country'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'storefront_country'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'storefront_country'));
    }

    public function test_the_rollback_is_refused_while_a_product_of_another_country_exists(): void
    {
        $migration = require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php');

        try {
            $migration->down();
            $this->fail('the rollback would have merged the catalogues');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('another country', $refusal->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('products', 'storefront_country'));
        $this->assertSame('AE', DB::table('products')->where('id', 2)->value('storefront_country'));
    }

    public function test_the_column_carries_no_index_because_the_optimizer_merges_it_with_the_selective_ones(): void
    {
        $indexesOfTheColumn = collect(Schema::getIndexes('products'))->filter(fn (array $index) => in_array('storefront_country', $index['columns'], true));

        $this->assertCount(0, $indexesOfTheColumn);
    }

    public function test_a_rollback_also_removes_the_index_an_operator_added_by_the_runbook(): void
    {
        DB::table('products')->where('storefront_country', '<>', 'SA')->delete();
        Schema::table('products', fn ($table) => $table->index(['storefront_country', 'id'], 'products_storefront_id_idx'));
        $migration = require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('products', 'storefront_country'));
        $this->assertFalse(Schema::hasIndex('products', 'products_storefront_id_idx'));
    }

    public function test_a_country_code_is_compared_byte_for_byte_in_sql(): void
    {
        $this->switchStorefrontsOn();
        DB::table('products')->insert(['id' => 4, 'name' => 'Lower case serum', 'added_by' => 'admin', 'status' => 1, 'request_status' => 1, 'storefront_country' => 'sa']);
        $this->serveRequestFrom('SA');

        $this->assertSame([1], $this->visibleProductIds());
    }

    public function test_while_storefronts_are_off_every_product_is_visible_whatever_its_country(): void
    {
        $this->serveRequestFrom('AE');

        $this->assertSame([1, 2, 3], $this->visibleProductIds());
    }

    public function test_a_request_that_was_not_bound_to_a_storefront_is_not_narrowed(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame([1, 2, 3], $this->visibleProductIds());
    }

    public function test_a_storefront_request_the_middleware_has_not_reached_yet_is_narrowed_to_the_storefront_it_asks_for(): void
    {
        $this->switchStorefrontsOn();

        $this->serveAsHttpRequest('/products?storefront=AE');
        $this->assertSame([2], $this->visibleProductIds());

        $this->serveAsHttpRequest('/products', ['X-Storefront' => 'KW']);
        $this->assertSame([3], $this->visibleProductIds());

        $this->serveAsHttpRequest('/products');
        $this->assertSame([1], $this->visibleProductIds());
    }

    public function test_an_unbound_request_for_a_panel_or_a_seller_api_still_sees_every_country(): void
    {
        $this->switchStorefrontsOn();

        foreach (['/admin/products/list', '/vendor/products/list', '/seller/dashboard', '/api/v2/seller/products/list', '/api/v3/seller/products/list'] as $uri) {
            $this->serveAsHttpRequest($uri . '?storefront=AE');
            $this->assertSame([1, 2, 3], $this->visibleProductIds(), $uri);
        }
    }

    /**
     * A1-11: a webhook, a payment callback and the platform admin API are never narrowed to the default market.
     *
     * A provider or a gateway calls back about work of whichever market the work belongs to, and it names the record rather than the market.
     * Narrowed to the default market, such a call would read the catalogue of Saudi Arabia while settling a Kuwaiti shipment -- it could fail
     * to find the very rows it was called about. Each of these answered the default market's catalogue until the list of cross-country paths
     * was given their addresses.
     */
    public function test_a_webhook_a_callback_and_the_platform_api_see_every_country(): void
    {
        $this->switchStorefrontsOn();

        $addresses = [
            '/api/fulfillment-webhooks/torod_point/secret-token/status',
            '/api/myfatoorah/webhook',
            '/api/payments/account/7/webhook',
            '/api/delivery-syria/orders',
            '/api/platform-admin/v1/countries',
        ];

        foreach ($addresses as $uri) {
            $this->serveAsHttpRequest($uri);
            $this->assertSame([1, 2, 3], $this->visibleProductIds(), $uri);
        }
    }

    /** And the list must not have swallowed the storefront traffic it sits beside: `api/v1` is a market's own. */
    public function test_a_storefront_api_request_is_still_narrowed_to_the_market_it_asks_for(): void
    {
        $this->switchStorefrontsOn();

        $this->serveAsHttpRequest('/api/v1/products/latest?storefront=AE');

        $this->assertSame([2], $this->visibleProductIds());
    }

    public function test_the_bound_storefront_wins_over_what_the_address_says(): void
    {
        $this->switchStorefrontsOn();
        $this->serveAsHttpRequest('/products?storefront=AE');
        $this->serveRequestFrom('KW');

        $this->assertSame([3], $this->visibleProductIds());
    }

    public function test_while_storefronts_are_off_a_missing_or_broken_storefront_configuration_does_not_reach_the_catalog(): void
    {
        foreach ([null, ['storefronts' => ['enabled' => false, 'default_country' => 'XX', 'countries' => []]], ['storefronts' => ['enabled' => false]]] as $gcc) {
            config(['gcc' => $gcc]);

            $this->assertSame([1, 2, 3], $this->visibleProductIds());
        }
    }

    public function test_a_serialized_product_does_not_carry_its_country(): void
    {
        $this->buildProductRelationTables();

        $this->assertArrayNotHasKey('storefront_country', Product::query()->findOrFail(2)->toArray());
        $this->assertSame('AE', Product::query()->findOrFail(2)->storefront_country);
    }

    public function test_the_migration_run_again_changes_nothing(): void
    {
        $migration = require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php');

        $migration->up();

        $this->assertSame('SA', DB::table('products')->where('id', 1)->value('storefront_country'));
        $this->assertSame('AE', DB::table('products')->where('id', 2)->value('storefront_country'));
    }

    public function test_the_visibility_query_is_unchanged_while_storefronts_are_off_or_the_request_is_not_bound(): void
    {
        $this->serveRequestFrom('AE');
        $this->assertStringNotContainsString('storefront_country', Product::query()->active()->toSql());

        app()->forgetInstance(StorefrontContext::class);
        $this->switchStorefrontsOn();
        $this->assertStringNotContainsString('storefront_country', Product::query()->active()->toSql());
    }

    public function test_the_saudi_storefront_shows_the_products_that_exist_today(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');

        $this->assertSame([1], $this->visibleProductIds());
    }

    public function test_another_storefront_shows_only_its_own_products(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $this->assertSame([2], $this->visibleProductIds());
    }

    public function test_each_country_keeps_its_own_price_and_stock_for_the_same_product(): void
    {
        $this->insertCatalogProduct(id: 4, name: 'Shared serum', price: 100, stock: 20);
        $this->insertCatalogProduct(id: 5, name: 'Shared serum', price: 120, stock: 2, country: 'AE');
        $this->switchStorefrontsOn();

        $this->serveRequestFrom('SA');
        $saudiCopy = Product::query()->active()->where('name', 'Shared serum')->toBase()->sole();

        $this->serveRequestFrom('AE');
        $emiratiCopy = Product::query()->active()->where('name', 'Shared serum')->toBase()->sole();

        $this->assertSame([4, 100.0, 20], [$saudiCopy->id, (float) $saudiCopy->unit_price, (int) $saudiCopy->current_stock]);
        $this->assertSame([5, 120.0, 2], [$emiratiCopy->id, (float) $emiratiCopy->unit_price, (int) $emiratiCopy->current_stock]);
    }

    public function test_the_other_visibility_rules_still_apply_inside_a_storefront(): void
    {
        DB::table('products')->where('id', 2)->update(['status' => 0]);
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $this->assertSame([], $this->visibleProductIds());
    }

    public function test_before_the_migration_runs_the_saudi_storefront_serves_what_it_always_served(): void
    {
        $this->removeStorefrontCountryColumn();
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('SA');

        $this->assertSame([1, 2, 3], $this->visibleProductIds());
    }

    public function test_before_the_migration_runs_another_storefront_shows_nothing(): void
    {
        $this->removeStorefrontCountryColumn();
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $this->assertSame([], $this->visibleProductIds());
    }

    public function test_a_late_migration_is_noticed_without_restarting_the_process(): void
    {
        $this->removeStorefrontCountryColumn();
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');
        $this->assertSame([], $this->visibleProductIds());

        $this->runStorefrontCountryMigration();
        DB::table('products')->where('id', 2)->update(['storefront_country' => 'AE']);
        StorefrontCatalog::flush();

        $this->assertSame([2], $this->visibleProductIds());
    }
}
