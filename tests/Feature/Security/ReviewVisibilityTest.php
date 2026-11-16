<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCoreSchema();

        DB::table('products')->insert(['id' => 1, 'name' => 'Aspirin', 'slug' => 'aspirin']);
        DB::table('reviews')->insert([
            ['product_id' => 1, 'status' => 1],
            ['product_id' => 1, 'status' => 1],
            ['product_id' => 1, 'status' => 0],
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
        $this->expectExceptionMessage("Refusing to drop every table of '{$database}'");

        $this->buildCoreSchema();
    }

    public static function databasesThatAreNotForTests(): array
    {
        return [
            'a production name' => ['pharmacy', 'testing'],
            'a test database name outside the testing environment' => ['pharmacy_testing', 'production'],
        ];
    }
}
