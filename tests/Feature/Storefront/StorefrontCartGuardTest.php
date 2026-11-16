<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\Web\CartController as WebCartController;
use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use App\Utils\CartManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * A cart takes only the products of the storefront it is used from. A customer cannot reach another country's price or stock by adding
 * the product by id, and the cart never ends up holding two countries. While storefronts are off, or when the request was not bound to a
 * storefront, the cart takes every product as before.
 */
class StorefrontCartGuardTest extends TestCase
{
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->buildTablesTheCartLoadsWithAProduct();

        $this->insertCatalogProduct(id: 1, name: 'Saudi serum');
        $this->insertCatalogProduct(id: 2, name: 'Emirati serum', country: 'AE');
    }

    private function buildTablesTheCartLoadsWithAProduct(): void
    {
        foreach (['carts', 'translations', 'reviews', 'digital_product_variations', 'stock_clearance_products', 'stock_clearance_setups'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('carts', function ($table) {
            $table->id();
        });

        Schema::create('translations', function ($table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('reviews', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(true);
        });
        Schema::create('digital_product_variations', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('stock_clearance_products', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('added_by')->nullable();
        });
        Schema::create('stock_clearance_setups', function ($table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
        });
    }

    private function switchStorefrontsOn(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
    }

    private function serveRequestFrom(string $country): void
    {
        app()->instance(StorefrontContext::class, StorefrontResolver::fromConfig()->context(explicitChoice: $country));
    }

    private function productOf(int $id): Product
    {
        return Product::query()->findOrFail($id);
    }

    public function test_while_storefronts_are_off_every_product_is_offered(): void
    {
        $this->serveRequestFrom('AE');

        $this->assertTrue(StorefrontCatalog::offers($this->productOf(1)));
        $this->assertTrue(StorefrontCatalog::offers($this->productOf(2)));
    }

    public function test_a_request_not_bound_to_a_storefront_is_offered_every_product(): void
    {
        $this->switchStorefrontsOn();

        $this->assertTrue(StorefrontCatalog::offers($this->productOf(1)));
        $this->assertTrue(StorefrontCatalog::offers($this->productOf(2)));
    }

    public function test_a_storefront_is_offered_only_its_own_products(): void
    {
        $this->switchStorefrontsOn();

        $this->serveRequestFrom('SA');
        $this->assertTrue(StorefrontCatalog::offers($this->productOf(1)));
        $this->assertFalse(StorefrontCatalog::offers($this->productOf(2)));

        $this->serveRequestFrom('AE');
        $this->assertFalse(StorefrontCatalog::offers($this->productOf(1)));
        $this->assertTrue(StorefrontCatalog::offers($this->productOf(2)));
    }

    public function test_a_product_that_cannot_be_read_is_left_to_the_cart_to_refuse(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $this->assertTrue(StorefrontCatalog::offers(null));
    }

    public function test_before_the_migration_runs_only_the_default_storefront_is_offered_products(): void
    {
        $this->removeStorefrontCountryColumn();
        $this->switchStorefrontsOn();

        $this->serveRequestFrom('SA');
        $this->assertTrue(StorefrontCatalog::offers($this->productOf(1)));

        $this->serveRequestFrom('AE');
        $this->assertFalse(StorefrontCatalog::offers($this->productOf(1)));
    }

    public function test_a_storefront_request_the_middleware_has_not_reached_is_offered_only_its_own_products(): void
    {
        $this->switchStorefrontsOn();

        $this->serveAsHttpRequest('/shop-cart?storefront=AE');

        $this->assertFalse(StorefrontCatalog::offers($this->productOf(1)));
        $this->assertTrue(StorefrontCatalog::offers($this->productOf(2)));
    }

    public function test_changing_the_variation_of_another_countrys_product_is_refused_and_leaves_the_cart_empty(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $result = app(WebCartController::class)->update_variation(Request::create('/cart/update-variation', 'POST', ['product_id' => 1]));

        $this->assertSame(0, $result['status']);
        $this->assertSame(translate('Product_is_unavailable'), $result['message']);
        $this->assertSame(0, DB::table('carts')->count());
    }

    public function test_adding_another_countrys_product_to_the_cart_is_refused_and_leaves_the_cart_empty(): void
    {
        $this->switchStorefrontsOn();
        $this->serveRequestFrom('AE');

        $result = CartManager::add_to_cart(Request::create('/api/v1/cart/add', 'POST', ['id' => 1, 'quantity' => 1]));

        $this->assertSame(0, $result['status']);
        $this->assertSame(translate('Product_is_unavailable'), $result['message']);
        $this->assertSame(0, DB::table('carts')->count());
    }
}
