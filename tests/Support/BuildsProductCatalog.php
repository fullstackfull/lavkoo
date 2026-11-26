<?php

namespace Tests\Support;

use App\Models\Product;
use App\Services\Search\ProductSearchIndexer;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The storefront's product catalog as the visibility tests need it: an INFERRED copy of the store tables a customer-facing product
 * lookup reads, and five products that differ only in whether a customer may see them. Like the other schema builders it is rebuilt
 * from the models and the code that reads it, not from the installation dump, so it proves the logic and not the live schema.
 *
 * Product 1 is visible, 2 is disabled, 3 is visible and unrelated by name, 4 is a draft that was never approved and 5 belongs to a
 * seller who is still pending. Nothing but product 1 and 3 may ever reach a customer.
 *
 * The tables are dropped before they are built, so on a real engine the database must be unmistakably a throwaway one.
 */
trait BuildsProductCatalog
{
    private const CATEGORY = 10;
    private const SUB_CATEGORY = 11;
    private const SUB_SUB_CATEGORY = 12;
    private const OTHER_CATEGORY = 20;
    private const OTHER_SUB_CATEGORY = 21;

    private const CATALOG_TABLES = [
        'business_settings', 'products', 'sellers', 'shops', 'brands', 'categories', 'translations', 'tags', 'product_tag', 'order_details',
        'reviews', 'review_replies', 'users', 'storages', 'wishlists', 'stock_clearance_products', 'stock_clearance_setups', 'authors',
        'digital_product_authors', 'publishing_houses', 'digital_product_publishing_houses', 'digital_product_variations', 'flash_deals',
        'flash_deal_products', 'colors', 'carts', 'shipping_types', 'shipping_methods', 'category_shipping_costs', 'currencies', 'banners',
        'product_search_index', 'restock_products', 'restock_product_customers', 'product_seos', 'product_compares', 'seo_meta_info',
    ];

    protected function buildCatalogTables(): void
    {
        $this->refuseToDropCatalogTablesOutsideATestDatabase(connection: DB::connection());

        foreach (self::CATALOG_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        $this->createStoreSettingTables();
        $this->createProductTables();
        $this->createCustomerFacingTables();
        $this->createPromotionTables();
    }

    /**
     * The same rule the other schema builders apply before they drop tables: a real engine keeps its tables between tests, so a database
     * whose name does not say "test", or an environment that is not "testing", is refused before anything is dropped.
     */
    protected function refuseToDropCatalogTablesOutsideATestDatabase(Connection $connection): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop the catalog tables of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }
    }

    /** The search orders its results with MySQL's LOCATE(), which SQLite does not have. */
    protected function registerMySqlLocate(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::connection()->getPdo()->sqliteCreateFunction(
            'LOCATE',
            fn ($needle, $haystack) => ($position = mb_stripos((string) $haystack, (string) $needle)) === false ? 0 : $position + 1,
            2,
        );
    }

    protected function seedCatalog(): void
    {
        $this->insertProduct(id: 1, name: 'Alpha lamp');
        $this->insertProduct(id: 2, name: 'Alpha hidden lamp', status: 0);
        $this->insertProduct(id: 3, name: 'Zeta chair');
        $this->insertProduct(id: 4, name: 'Alpha draft lamp', requestStatus: 0);
        $this->insertProduct(id: 5, name: 'Alpha pending seller lamp', userId: 9, addedBy: 'seller');
        DB::table('sellers')->insert(['id' => 9, 'status' => 'pending']);
        DB::table('categories')->insert([
            ['id' => self::CATEGORY, 'name' => 'Lamps', 'parent_id' => 0, 'position' => 0],
            ['id' => self::SUB_CATEGORY, 'name' => 'Table lamps', 'parent_id' => self::CATEGORY, 'position' => 1],
        ]);
    }

    protected function insertProduct(
        int    $id,
        string $name,
        int    $status = 1,
        int    $requestStatus = 1,
        ?int   $userId = null,
        string $addedBy = 'admin',
        int    $category = self::CATEGORY,
        ?int   $subCategory = self::SUB_CATEGORY,
        ?int   $subSubCategory = null,
        array  $extra = [],
    ): void {
        DB::table('products')->insert(array_merge([
            'id' => $id,
            'name' => $name,
            'slug' => 'product-' . $id,
            'user_id' => $userId,
            'added_by' => $addedBy,
            'category_id' => $category,
            'sub_category_id' => $subCategory,
            'sub_sub_category_id' => $subSubCategory,
            'category_ids' => json_encode($this->categoryIdsOf(category: $category, subCategory: $subCategory, subSubCategory: $subSubCategory)),
            'status' => $status,
            'request_status' => $requestStatus,
            'colors' => '[]',
            'attributes' => '[]',
            'choice_options' => '[]',
            'variation' => '[]',
            'images' => '[]',
        ], $extra));
        DB::table('translations')->insert([
            'translationable_type' => 'App\Models\Product',
            'translationable_id' => $id,
            'locale' => 'ar',
            'key' => 'name',
            'value' => $name,
        ]);
    }

    /** What the observer does when a product is saved: the search index holds every product, whether or not a customer may see it. */
    protected function indexEveryProduct(): void
    {
        $indexer = app(ProductSearchIndexer::class);
        foreach (Product::all() as $product) {
            $indexer->index($product);
        }
    }

    /** The list the product form stores: the ids of the category, the sub category and the sub sub category, as strings, in that order. */
    private function categoryIdsOf(int $category, ?int $subCategory, ?int $subSubCategory): array
    {
        $categoryIds = [];
        foreach (array_filter([$category, $subCategory, $subSubCategory]) as $id) {
            $categoryIds[] = ['id' => (string) $id, 'position' => count($categoryIds) + 1];
        }

        return $categoryIds;
    }

    /** @return list<int> the ids of the given products, sorted, whatever the rows are (models, arrays or generic rows) */
    protected function idsOf(iterable $products): array
    {
        $ids = collect($products)->pluck('id')->sort()->values()->all();

        return array_map('intval', $ids);
    }

    /** @var array{private: string, public: string}|null generated once per run: making a key pair is the slowest step of a test */
    private static ?array $passportKeys = null;

    /** A throwaway RSA key pair: auth('api') builds Passport's token guard, which refuses to exist without one. */
    protected function useThrowawayPassportKeys(): void
    {
        if (self::$passportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$passportKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        config(['passport.private_key' => self::$passportKeys['private'], 'passport.public_key' => self::$passportKeys['public']]);
    }

    protected function setBusinessSetting(string $type, string|array $value): void
    {
        DB::table('business_settings')->where('type', $type)->delete();
        DB::table('business_settings')->insert(['type' => $type, 'value' => is_array($value) ? json_encode($value) : $value]);
    }

    private function createStoreSettingTables(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        foreach (['product_brand' => '1', 'digital_product' => '0', 'business_mode' => 'multi'] as $type => $value) {
            DB::table('business_settings')->insert(['type' => $type, 'value' => $value]);
        }

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 24, 6)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type')->nullable();
            $table->unsignedBigInteger('data_id')->nullable();
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    private function createProductTables(): void
    {
        Schema::create('products', function (Blueprint $table) {
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
            $table->boolean('featured')->default(false);
            $table->integer('current_stock')->default(5);
            $table->integer('minimum_order_qty')->default(1);
            $table->decimal('unit_price', 40, 20)->default(10);
            $table->decimal('purchase_price', 40, 20)->default(4);
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('discount_type')->default('flat');
            $table->decimal('shipping_cost', 40, 20)->default(0);
            $table->boolean('multiply_qty')->default(false);
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->text('details')->nullable();
            $table->string('thumbnail')->nullable();
            $table->text('images')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('vendor');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('temporary_close')->default(false);
        });
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('status')->default(true);
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0);
            $table->integer('position')->default(0);
            $table->boolean('home_status')->default(false);
            $table->integer('priority')->nullable();
            $table->timestamps();
        });
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('tag')->nullable();
        });
        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
        });
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('digital_product_authors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('publishing_houses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('digital_product_publishing_houses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('publishing_house_id');
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('digital_product_variations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('variant_key')->nullable();
            $table->decimal('price', 24, 2)->default(0);
            $table->string('sku')->nullable();
            $table->string('file')->nullable();
            $table->timestamps();
        });
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
        });
        Schema::create('product_seos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('product_search_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('locale');
            $table->text('name_normalized')->nullable();
            $table->text('text_normalized')->nullable();
            $table->timestamps();
        });
    }

    private function createCustomerFacingTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->decimal('wallet_balance', 24, 2)->default(0);
            $table->string('referral_code')->nullable();
            $table->string('social_id')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->integer('qty')->default(1);
            $table->string('delivery_status')->nullable();
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('comment')->nullable();
            $table->text('attachment')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('status')->default(true);
            $table->boolean('is_saved')->default(false);
            $table->timestamps();
        });
        Schema::create('review_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('review_id');
            $table->text('reply_text')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_product_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restock_product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('product_compares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
        });
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
        });
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('cart_group_id')->nullable();
            $table->string('product_type')->nullable();
            $table->string('digital_product_type')->nullable();
            $table->string('color')->nullable();
            $table->text('choices')->nullable();
            $table->text('variations')->nullable();
            $table->decimal('price', 40, 20)->default(0);
            $table->decimal('tax', 40, 20)->default(0);
            $table->decimal('discount', 40, 20)->default(0);
            $table->string('tax_model')->nullable();
            $table->string('variant')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('slug')->nullable();
            $table->string('name')->nullable();
            $table->string('thumbnail')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->text('shop_info')->nullable();
            $table->decimal('shipping_cost', 40, 20)->default(0);
            $table->string('shipping_type')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->boolean('is_checked')->default(true);
            $table->timestamps();
        });
        Schema::create('shipping_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('shipping_type')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_type')->nullable();
            $table->string('title')->nullable();
            $table->decimal('cost', 24, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('category_shipping_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->decimal('cost', 24, 2)->default(0);
            $table->boolean('multiply_qty')->default(false);
        });
    }

    private function createPromotionTables(): void
    {
        Schema::create('stock_clearance_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('added_by')->nullable();
        });
        Schema::create('stock_clearance_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
        });
        Schema::create('flash_deals', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('deal_type')->default('feature_deal');
            $table->boolean('status')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
        Schema::create('seo_meta_info', function (Blueprint $table) {
            $table->id();
            $table->string('seoable_type');
            $table->unsignedBigInteger('seoable_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('flash_deal_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flash_deal_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('discount', 8, 2)->default(0);
            $table->string('discount_type')->default('flat');
            $table->timestamps();
        });
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('theme')->nullable();
            $table->string('photo')->nullable();
            $table->string('url')->nullable();
            $table->string('title')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('button_text')->nullable();
            $table->string('background_color')->nullable();
            $table->string('layout')->nullable();
            $table->integer('priority')->nullable();
            $table->string('mobile_photo')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('banner_type')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();
        });
    }
}
