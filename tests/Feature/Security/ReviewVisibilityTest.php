<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\Review;
use App\Traits\CacheManagerTrait;
use App\Utils\ProductManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\TestCase;

/**
 * Only the panels, and the seller APIs they use, see reviews that have not been approved. A storefront page whose address merely starts
 * with the same letters (the shop pages of a vendor, the vendor list, a seller profile) shows approved reviews only, and so does a public
 * page that happens to live under /vendor (the vendor registration page, whose layout is the storefront's).
 *
 * The tables are built by the guarded schema helper: on a real MySQL server it refuses unless the database name says "test".
 */
class ReviewVisibilityTest extends TestCase
{
    use BuildsFulfillmentSchema;

    private const APPROVED_REVIEWS = 2;

    private const ALL_REVIEWS = 3;

    private const UNAPPROVED_REVIEW_TEXT = 'UNAPPROVED review';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();
        Schema::table('reviews', fn (Blueprint $table) => $table->unsignedTinyInteger('rating')->default(5));

        DB::table('products')->insert(['id' => 1, 'name' => 'Aspirin', 'slug' => 'aspirin']);
        DB::table('reviews')->insert([
            ['product_id' => 1, 'status' => 1, 'rating' => 5],
            ['product_id' => 1, 'status' => 1, 'rating' => 4],
            ['product_id' => 1, 'status' => 0, 'rating' => 1],
        ]);
    }

    private function visit(string $uri): void
    {
        app()->instance('request', Request::create($uri));
    }

    public static function panelAddresses(): array
    {
        return [
            'admin panel' => ['/admin/reviews'],
            'admin root' => ['/admin'],
            'vendor panel' => ['/vendor/products/list'],
            'vendor root' => ['/vendor'],
            'seller area' => ['/seller/dashboard'],
            'seller root' => ['/seller'],
            'seller API v2' => ['/api/v2/seller/products/list'],
            'API v2 root' => ['/api/v2'],
            'seller API v3' => ['/api/v3/seller/products/review-list/5'],
            'API v3 root' => ['/api/v3'],
            'a search in the admin panel for Arabic text' => ['/admin/products/search/%D8%AF%D9%88%D8%A7%D8%A1'],
            'an admin address with a Latin-1 letter' => ['/admin/search/caf%E9'],
            'an admin address that is not valid UTF-8' => ['/admin/products/search/%FF%FE'],
            'a vendor address that is not valid UTF-8' => ['/vendor/products/list/%E9'],
            'a seller API v2 address that is not valid UTF-8' => ['/api/v2/seller/products/list/%FF'],
            'a seller API v3 address that is not valid UTF-8' => ['/api/v3/seller/products/list/%C0%AF'],
        ];
    }

    public static function storefrontAddresses(): array
    {
        return [
            'the shop page of a vendor' => ['/vendor-shop/acme'],
            'the vendor list' => ['/vendors'],
            'a seller profile' => ['/seller-profile/3'],
            'the home page' => ['/'],
            'the customer API' => ['/api/v1/products/reviews/acme'],
            'the customer API of a seller shop' => ['/api/v1/seller/acme/products'],
            'the API root' => ['/api'],
            'an address that only looks like the admin one' => ['/administrators'],
            'an address that only looks like API v2' => ['/api/v20/seller'],
            'API v2 with a suffix' => ['/api/v2-beta'],
            'an address that only looks like API v3' => ['/api/v3x/seller'],
            'API v3 with a suffix' => ['/api/v3-beta'],
            'a panel word that is not the first segment' => ['/shop/vendor/acme'],
            'API v2 that is not at the root' => ['/shop/api/v2/seller'],
            'the public vendor registration page' => ['/vendor/auth/registration/index'],
            'the public vendor registration form' => ['/vendor/auth/registration/add'],
        ];
    }

    #[DataProvider('panelAddresses')]
    public function test_a_panel_sees_every_review(string $uri): void
    {
        $this->visit($uri);

        $this->assertSame(self::ALL_REVIEWS, Review::query()->count());
    }

    #[DataProvider('storefrontAddresses')]
    public function test_a_storefront_page_sees_approved_reviews_only(string $uri): void
    {
        $this->visit($uri);

        $this->assertSame(self::APPROVED_REVIEWS, Review::query()->count());
    }

    public function test_a_command_line_run_sees_approved_reviews_only(): void
    {
        $this->assertSame(self::APPROVED_REVIEWS, Review::query()->count());
    }

    #[DataProvider('panelAddresses')]
    public function test_a_product_lists_every_review_to_a_panel_like_the_review_scope_does(string $uri): void
    {
        $this->visit($uri);

        $this->assertSame(self::ALL_REVIEWS, Product::query()->findOrFail(1)->reviews->count());
    }

    #[DataProvider('storefrontAddresses')]
    public function test_a_product_lists_approved_reviews_only_to_a_storefront_like_the_review_scope_does(string $uri): void
    {
        $this->visit($uri);

        $this->assertSame(self::APPROVED_REVIEWS, Product::query()->findOrFail(1)->reviews->count());
    }

    public function test_the_home_page_top_rated_list_is_ordered_by_the_approved_ratings_only(): void
    {
        $this->createTablesTheHomePageProductListsRead();
        DB::table('products')->delete();
        DB::table('reviews')->delete();
        DB::table('products')->insert([
            ['id' => 11, 'name' => 'Approved 3 and hidden 5', 'slug' => 'p11'],
            ['id' => 12, 'name' => 'Approved 4 and 3', 'slug' => 'p12'],
            ['id' => 13, 'name' => 'Hidden 5 only', 'slug' => 'p13'],
            ['id' => 14, 'name' => 'Approved 5', 'slug' => 'p14'],
        ]);
        DB::table('reviews')->insert([
            ['product_id' => 11, 'status' => 1, 'rating' => 3],
            ['product_id' => 11, 'status' => 0, 'rating' => 5],
            ['product_id' => 12, 'status' => 1, 'rating' => 4],
            ['product_id' => 12, 'status' => 1, 'rating' => 3],
            ['product_id' => 13, 'status' => 0, 'rating' => 5],
            ['product_id' => 14, 'status' => 1, 'rating' => 5],
        ]);
        $this->visit('/');

        $topRated = (new class {
            use CacheManagerTrait;
        })->cacheTopRatedProductList();

        $this->assertSame(
            ['Approved 5', 'Approved 4 and 3', 'Approved 3 and hidden 5'],
            $topRated->getCollection()->pluck('name')->all(),
            'an approved average of 3.5 beats 3.0 even though the hidden 5-star row lifts the raw average of the other product to 4.0; a product with hidden reviews only is not listed',
        );
    }

    public static function addressesOfTheSellerApiAndTheStorefront(): array
    {
        return [
            'the seller API, a panel to the models' => ['/api/v3/seller/products/digital-publishing-house-list'],
            'the customer API' => ['/api/v1/products/digital-publishing-house-list'],
            'the storefront' => ['/'],
        ];
    }

    #[DataProvider('addressesOfTheSellerApiAndTheStorefront')]
    public function test_the_publishing_house_list_holds_the_link_rows_only_so_no_product_and_no_review_goes_with_it(string $uri): void
    {
        $this->seedTwoDigitalProductsOfTwoOwnersInOnePublishingHouse();
        $this->visit($uri);

        $houses = ProductManager::getPublishingHouseList()->values();

        $this->assertSame(['Pub house'], $houses->pluck('name')->all());
        $this->assertSame(2, $houses[0]->publishing_house_products_count, 'the count the filters show is unchanged');
        $this->assertSame([1, 2], $houses[0]->publishingHouseProducts->pluck('product_id')->sort()->values()->all());
        $this->assertSame([], $houses[0]->publishingHouseProducts->filter(fn ($link): bool => $link->relationLoaded('product'))->pluck('id')->all());
        $this->assertStringNotContainsString(self::UNAPPROVED_REVIEW_TEXT, $houses->toJson());
        $this->assertStringNotContainsString('reviews', $houses->toJson());
    }

    #[DataProvider('addressesOfTheSellerApiAndTheStorefront')]
    public function test_the_publishing_house_list_of_one_shop_counts_that_shops_products_only(string $uri): void
    {
        $this->seedTwoDigitalProductsOfTwoOwnersInOnePublishingHouse();
        $this->visit($uri);

        $houses = ProductManager::getPublishingHouseList(productIds: [2])->values();

        $this->assertSame(['Pub house'], $houses->pluck('name')->all());
        $this->assertSame(1, $houses[0]->publishing_house_products_count);
        $this->assertStringNotContainsString(self::UNAPPROVED_REVIEW_TEXT, $houses->toJson());
    }

    #[DataProvider('databasesThatAreNotForTests')]
    public function test_the_review_tables_are_never_rebuilt_outside_a_test_database(string $database, string $environment): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '1',
            'database.connections.mysql.database' => $database,
        ]);
        DB::purge();
        $this->app['env'] = $environment;

        $this->expectException(RuntimeException::class);
        // Several test-support traits carry this same guard, each with its own verb ("drop tables in", "drop tables of", "drop every table
        // of"). What matters is that it refuses, that it names the database it refused, and that it states both conditions, so the assertion
        // reads those three and not one trait's phrasing.
        $this->expectExceptionMessageMatches("/Refusing to drop[^']*'" . preg_quote($database, '/') . "'.*must contain 'test'.*must be 'testing'/s");

        $this->buildCoreSchema();
    }

    public static function databasesThatAreNotForTests(): array
    {
        return [
            'a production name' => ['pharmacy', 'testing'],
            'a test database name outside the testing environment' => ['pharmacy_testing', 'production'],
        ];
    }

    /** The admin's product 1 and the product 2 of seller 2, both digital and both with a review nobody approved, in one publishing house. */
    private function seedTwoDigitalProductsOfTwoOwnersInOnePublishingHouse(): void
    {
        $this->createTablesTheHomePageProductListsRead();
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

        DB::table('business_settings')->insert(['type' => 'digital_product', 'value' => '1']);
        Cache::flush(); // the application booted before this table was built, and may hold the settings of the test before
        DB::table('sellers')->insert(['id' => 2, 'status' => 'approved']);
        DB::table('products')->where('id', 1)->update(['product_type' => 'digital']);
        DB::table('products')->insert(['id' => 2, 'name' => 'Vendor book', 'slug' => 'vendor-book', 'product_type' => 'digital', 'added_by' => 'seller', 'user_id' => 2]);
        DB::table('reviews')->insert([
            ['product_id' => 2, 'status' => 1, 'rating' => 4, 'comment' => 'approved review'],
            ['product_id' => 2, 'status' => 0, 'rating' => 1, 'comment' => self::UNAPPROVED_REVIEW_TEXT],
        ]);
        DB::table('reviews')->where('product_id', 1)->where('status', 0)->update(['comment' => self::UNAPPROVED_REVIEW_TEXT]);
        DB::table('publishing_houses')->insert(['id' => 1, 'name' => 'Pub house']);
        DB::table('digital_product_publishing_houses')->insert([['publishing_house_id' => 1, 'product_id' => 1], ['publishing_house_id' => 1, 'product_id' => 2]]);
    }

    /** What Product::active() and the home page rails join in: sellers, and the clearance sale each product may carry. */
    private function createTablesTheHomePageProductListsRead(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('approved');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('stock_clearance_setups', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_clearance_products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamps();
        });
    }
}
