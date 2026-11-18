<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Product;
use App\Providers\AppServiceProvider;
use App\Utils\RequestArea;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Booting the application for a web request fills the caches every shopper reads (the category tree with the reviews of its products, the
 * banners, the brands, the flash deal) unless the request is for a panel. The models decide the same thing with RequestArea and, for a panel,
 * load the reviews nobody approved. A boot that decides with a test of its own disagrees with them on the bare /vendor and /seller, on every
 * seller and delivery man API and on addresses with an encoded slash, and caches the category tree of the whole store with those reviews.
 *
 * The real boot runs here, on the tables it reads. The storefront branch has run when the shared view data carries the category tree.
 */
class StorefrontCacheFillTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const INSTALLED_IN_VENDOR_STORE = ['SCRIPT_NAME' => '/vendor-store/index.php', 'SCRIPT_FILENAME' => '/var/www/html/vendor-store/index.php', 'PHP_SELF' => '/vendor-store/index.php'];

    private const INSTALLED_IN_API_SHOP = ['SCRIPT_NAME' => '/api-shop/index.php', 'SCRIPT_FILENAME' => '/var/www/html/api-shop/index.php', 'PHP_SELF' => '/api-shop/index.php'];

    private const UNAPPROVED_REVIEW_TEXT = 'UNAPPROVED review';

    /** @var list<string> */
    private array $errorsLoggedByTheBoot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->createTablesTheBootReads();
        $this->seedOneProductWithAnApprovedAndAnUnapprovedReview();
        Cache::flush(); // the application booted before these tables existed, and may hold the settings of the test before

        Log::listen(function (MessageLogged $event): void {
            $this->errorsLoggedByTheBoot[] = $event->message;
        });
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public static function addresses(): array
    {
        return [
            'the home page' => ['http://localhost/', []],
            'a product list' => ['http://localhost/products', []],
            'a blog post whose name starts like the app' => ['http://localhost/blog/apple-news', []],
            'the shop page of a vendor' => ['http://localhost/vendor-shop/acme', []],
            'the vendor list' => ['http://localhost/vendors', []],
            'a seller profile' => ['http://localhost/seller-profile/3', []],
            'the customer API' => ['http://localhost/api/v1/products', []],
            'the API root' => ['http://localhost/api', []],
            'a host that starts with api' => ['http://api.example.com/', []],
            'a store installed in /api-shop' => ['http://localhost/api-shop/', self::INSTALLED_IN_API_SHOP],
            'the customer API of a store installed in /api-shop' => ['http://localhost/api-shop/api/v1/products', self::INSTALLED_IN_API_SHOP],
            'the public vendor registration page' => ['http://localhost/vendor/auth/registration/index', []],
            'the public vendor registration page without its last segment' => ['http://localhost/vendor/auth/registration', []],
            'the admin panel root' => ['http://localhost/admin', []],
            'the admin panel' => ['http://localhost/admin/dashboard', []],
            'the vendor panel root' => ['http://localhost/vendor', []],
            'the vendor panel' => ['http://localhost/vendor/dashboard', []],
            'the seller area root' => ['http://localhost/seller', []],
            'the seller area' => ['http://localhost/seller/dashboard', []],
            'the admin panel of a store installed in /vendor-store' => ['http://localhost/vendor-store/admin/dashboard', self::INSTALLED_IN_VENDOR_STORE],
            'an admin address that is not valid UTF-8' => ['http://localhost/admin/search/caf%E9', []],
            'the seller API v2' => ['http://localhost/api/v2/seller/products/list', []],
            'the seller API v3' => ['http://localhost/api/v3/seller/products/list', []],
            'the sign-in of the seller API, open to everyone' => ['http://localhost/api/v3/seller/auth/login', []],
            'the delivery man API' => ['http://localhost/api/v2/delivery-man/info', []],
            'the sign-in of the delivery man API, open to everyone' => ['http://localhost/api/v2/delivery-man/auth/login', []],
            'a panel root behind an encoded slash' => ['http://localhost/%2Fadmin/dashboard', []],
            'a vendor root behind an encoded slash' => ['http://localhost/%2Fvendor/dashboard', []],
            'the seller API behind two slashes' => ['http://localhost//api/v3/seller/products/list', []],
        ];
    }

    #[DataProvider('addresses')]
    public function test_the_storefront_caches_are_filled_for_a_request_the_models_do_not_take_for_a_panel_only(string $url, array $server): void
    {
        $this->bootForAWebRequestAt($url, $server);

        $this->assertSame([], $this->errorsLoggedByTheBoot, 'the boot failed on the tables of this test, so a skipped storefront branch could not be told from a failed one');
        $this->assertSame(!RequestArea::isPanel(), $this->storefrontBranchHasRun(), "{$url}: the boot and the models must take the request for the same thing");
    }

    #[DataProvider('addresses')]
    public function test_no_cache_the_shoppers_read_holds_a_review_that_is_not_approved(string $url, array $server): void
    {
        $this->bootForAWebRequestAt($url, $server);

        $this->assertSame([], $this->errorsLoggedByTheBoot);
        $this->assertSame([], $this->reviewsInTheCategoryTreeThatAreNotApproved(), "{$url}: the cached category tree carries a review nobody approved");
    }

    #[DataProvider('addresses')]
    public function test_no_entry_of_the_shared_cache_holds_the_text_of_a_review_that_is_not_approved(string $url, array $server): void
    {
        $this->bootForAWebRequestAt($url, $server);

        $this->assertSame([], $this->errorsLoggedByTheBoot);
        $this->assertSame([], $this->cacheKeysHoldingTheUnapprovedReview(), "{$url}: a cache every shopper reads holds the text of a review nobody approved");
    }

    private function bootForAWebRequestAt(string $url, array $server): void
    {
        app()->instance('request', Request::create(uri: $url, server: $server));
        Facade::clearResolvedInstance('request');
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

        (new AppServiceProvider($this->app))->boot();
    }

    private function storefrontBranchHasRun(): bool
    {
        return array_key_exists('main_categories', View::shared('web_config', []));
    }

    /** @return list<int> */
    private function reviewsInTheCategoryTreeThatAreNotApproved(): array
    {
        return collect(Cache::get(CACHE_MAIN_CATEGORIES_LIST, []))
            ->flatMap(fn (Category $category): Collection => $category->product)
            ->flatMap(fn (Product $product): Collection => $product->reviews)
            ->reject(fn ($review): bool => (int) $review->status === 1)
            ->pluck('id')
            ->all();
    }

    /** @return list<string> */
    private function cacheKeysHoldingTheUnapprovedReview(): array
    {
        $store = Cache::getStore();

        return collect((new ReflectionProperty($store, 'storage'))->getValue($store))
            ->filter(fn (array $entry): bool => str_contains(serialize($entry['value'] ?? null), self::UNAPPROVED_REVIEW_TEXT))
            ->keys()
            ->map(fn (int|string $key): string => (string) $key)
            ->all();
    }

    private function seedOneProductWithAnApprovedAndAnUnapprovedReview(): void
    {
        DB::table('categories')->insert(['id' => 1, 'name' => 'Medicines', 'slug' => 'medicines', 'parent_id' => 0, 'position' => 0]);
        DB::table('products')->insert(['id' => 1, 'name' => 'Aspirin', 'slug' => 'aspirin', 'category_id' => 1]);
        DB::table('reviews')->insert([
            ['id' => 1, 'product_id' => 1, 'status' => 1, 'comment' => 'approved review'],
            ['id' => 2, 'product_id' => 1, 'status' => 0, 'comment' => self::UNAPPROVED_REVIEW_TEXT],
        ]);
        DB::table('publishing_houses')->insert(['id' => 1, 'name' => 'Pub house']);
        DB::table('digital_product_publishing_houses')->insert(['publishing_house_id' => 1, 'product_id' => 1]);
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Riyal', 'symbol' => 'SAR', 'code' => 'SAR']);
        DB::table('business_settings')->insert([['type' => 'social_login', 'value' => '[]'], ['type' => 'apple_login', 'value' => '[]']]);
    }

    /** What the boot of a storefront request reads on top of the core store tables. */
    private function createTablesTheBootReads(): void
    {
        Schema::table('reviews', fn (Blueprint $table) => $table->text('comment')->nullable());
        Schema::create('publishing_houses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('digital_product_publishing_houses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('publishing_house_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
        });
        Schema::table('categories', fn (Blueprint $table) => $table->integer('priority')->default(0));
        Schema::create('business_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->longText('description')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('default_status')->default(false);
            $table->timestamps();
        });
        Schema::create('flash_deals', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('deal_type')->default('flash_deal');
            $table->boolean('status')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
        Schema::create('flash_deal_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flash_deal_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('addon_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_name')->nullable();
            $table->string('settings_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->longText('additional_data')->nullable();
            $table->timestamps();
        });
        Schema::create('login_setups', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_clearance_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_clearance_products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamps();
        });
        Schema::create('social_medias', function (Blueprint $table) {
            $table->id();
            $table->boolean('active_status')->default(true);
            $table->timestamps();
        });
        Schema::create('analytic_scripts', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('banner_type')->nullable();
            $table->string('theme')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();
        });
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->integer('status')->default(1);
            $table->integer('priority')->default(0);
            $table->timestamps();
        });
        Schema::create('robots_meta_contents', function (Blueprint $table) {
            $table->id();
            $table->string('page_name')->nullable();
            $table->timestamps();
        });
    }
}
