<?php

namespace Tests\Feature\Security;

use App\Utils\ProductManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A search answers only with products a customer may see. The translated-name searches and the category filter used to add products
 * the active scope had already left out, so a draft, disabled or unapproved product could be found by name or by its category.
 */
class SearchVisibilityTest extends TestCase
{
    private const CATEGORY = 10;
    private const SUB_CATEGORY = 11;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->buildCatalogTables();
        $this->registerMySqlLocate();

        $this->insertProduct(id: 1, name: 'Alpha lamp');
        $this->insertProduct(id: 2, name: 'Alpha hidden lamp', status: 0);
        $this->insertProduct(id: 3, name: 'Zeta chair');
        $this->insertProduct(id: 4, name: 'Alpha draft lamp', requestStatus: 0);
        $this->insertProduct(id: 5, name: 'Alpha pending seller lamp', userId: 9, addedBy: 'seller');
        DB::table('sellers')->insert(['id' => 9, 'status' => 'pending']);
    }

    private function buildCatalogTables(): void
    {
        foreach (['business_settings', 'products', 'sellers', 'brands', 'translations', 'tags', 'product_tag', 'order_details', 'reviews', 'wishlists', 'stock_clearance_products', 'stock_clearance_setups', 'authors', 'digital_product_authors', 'publishing_houses', 'digital_product_publishing_houses'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function ($table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        foreach (['product_brand' => '1', 'digital_product' => '0', 'business_mode' => 'multi'] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        }
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->unsignedBigInteger('sub_sub_category_id')->nullable();
            $table->text('category_ids')->nullable();
            $table->string('product_type')->default('physical');
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->integer('current_stock')->default(5);
            $table->decimal('unit_price', 24, 2)->default(10);
            $table->timestamps();
        });
        Schema::create('sellers', function ($table) {
            $table->id();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        Schema::create('brands', function ($table) {
            $table->id();
            $table->boolean('status')->default(true);
        });
        Schema::create('translations', function ($table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('tags', function ($table) {
            $table->id();
            $table->string('tag')->nullable();
        });
        Schema::create('product_tag', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
        });
        Schema::create('order_details', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('reviews', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('status')->default(true);
        });
        Schema::create('wishlists', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
        });
        Schema::create('stock_clearance_products', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('added_by')->nullable();
        });
        Schema::create('authors', function ($table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('digital_product_authors', function ($table) {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('publishing_houses', function ($table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('digital_product_publishing_houses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('publishing_house_id');
            $table->unsignedBigInteger('product_id');
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

    /** The search orders its results with MySQL's LOCATE(), which SQLite does not have. */
    private function registerMySqlLocate(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'LOCATE',
            fn ($needle, $haystack) => ($position = mb_stripos((string) $haystack, (string) $needle)) === false ? 0 : $position + 1,
            2,
        );
    }

    private function insertProduct(int $id, string $name, int $status = 1, int $requestStatus = 1, ?int $userId = null, string $addedBy = 'admin'): void
    {
        DB::table('products')->insert([
            'id' => $id,
            'name' => $name,
            'slug' => 'product-' . $id,
            'user_id' => $userId,
            'added_by' => $addedBy,
            'category_id' => self::CATEGORY,
            'sub_category_id' => self::SUB_CATEGORY,
            'status' => $status,
            'request_status' => $requestStatus,
        ]);
        DB::table('translations')->insert([
            'translationable_type' => 'App\Models\Product',
            'translationable_id' => $id,
            'locale' => 'ar',
            'key' => 'name',
            'value' => $name,
        ]);
    }

    private function idsOf(iterable $products): array
    {
        $ids = collect($products)->pluck('id')->sort()->values()->all();

        return array_map('intval', $ids);
    }

    public static function nameSearches(): array
    {
        return [
            'translated name search for the API' => [fn (string $name) => ProductManager::translated_product_search(base64_encode($name))['products']],
            'translated name search for the web' => [fn (string $name) => ProductManager::getTranslatedProductSearchForWeb($name)['products']],
        ];
    }

    public static function categorySearches(): array
    {
        return [
            'API search' => [fn (string $name, int $category) => ProductManager::search_products(Request::create('/'), $name, $category)['products']],
            'web search' => [fn (string $name, int $category) => ProductManager::getSearchProductsForWeb($name, $category)['products']],
            'translated API search' => [fn (string $name, int $category) => ProductManager::translated_product_search(base64_encode($name), $category)['products']],
        ];
    }

    #[DataProvider('nameSearches')]
    public function test_a_translated_name_search_finds_only_products_a_customer_may_see(Closure $search): void
    {
        $this->assertSame([1], $this->idsOf($search('Alpha')));
    }

    #[DataProvider('categorySearches')]
    public function test_a_search_inside_a_sub_category_finds_only_visible_products_that_match_the_name(Closure $search): void
    {
        $this->assertSame([1], $this->idsOf($search('Alpha', self::SUB_CATEGORY)));
    }

    #[DataProvider('categorySearches')]
    public function test_a_search_inside_a_main_category_finds_only_visible_products_that_match_the_name(Closure $search): void
    {
        $this->assertSame([1], $this->idsOf($search('Alpha', self::CATEGORY)));
    }
}
