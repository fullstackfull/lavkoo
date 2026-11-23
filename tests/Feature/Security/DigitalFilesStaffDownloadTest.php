<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\BuildsDigitalFileWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The staff of the shop and its sellers see a digital file in their own screens and apps, so each of them has a route of its own area that streams the file to
 * the people who may see the product or the order: the admin panel to the admins that hold the module, the vendor panel and the seller apps to the seller whose
 * product or order it is. A staff member is never sent the public address of a file, because while the private mode is on there is none.
 */
class DigitalFilesStaffDownloadTest extends TestCase
{
    use BuildsDigitalFileWorld;
    use RestoresLanguageFiles;

    /** The file of the product of seller 7, of the product of seller 8, of the product the shop sells itself, and of the variations and order lines of each. */
    private const OWN = '2026-11-16-own.pdf';

    private const OTHER = '2026-11-16-other.pdf';

    private const HOUSE = '2026-11-16-house.pdf';

    private const MASTER = 1;

    private const CATALOG_ONLY = 2;

    private const ORDERS_ONLY = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildDigitalFileWorld();
        $this->withoutTheShellOfTheWeb();
        DB::table('admin_roles')->insert(['id' => 4, 'name' => 'Orders only', 'module_access' => json_encode(['orders']), 'status' => 1]);
        DB::table('admins')->insert(['id' => 4, 'name' => 'Orders admin', 'email' => 'orders@example.test', 'admin_role_id' => 4]);
        DB::table('sellers')->insert(['id' => 8, 'auth_token' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'status' => 'approved']);
        DB::table('products')->insert([
            ['id' => 5, 'added_by' => 'seller', 'user_id' => 7, 'name' => 'Own', 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::OWN],
            ['id' => 6, 'added_by' => 'seller', 'user_id' => 8, 'name' => 'Other', 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::OTHER],
            ['id' => 7, 'added_by' => 'admin', 'user_id' => 7, 'name' => 'House', 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::HOUSE],
        ]);
        DB::table('digital_product_variations')->insert([
            ['id' => 1, 'product_id' => 5, 'variant_key' => 'Format-PDF', 'file' => '2026-11-16-own-variation.pdf'],
            ['id' => 2, 'product_id' => 6, 'variant_key' => 'Format-PDF', 'file' => '2026-11-16-other-variation.pdf'],
            ['id' => 3, 'product_id' => 7, 'variant_key' => 'Format-PDF', 'file' => '2026-11-16-house-variation.pdf'],
        ]);
        foreach ([1 => [7, 'seller', self::OWN], 2 => [8, 'seller', self::OTHER], 3 => [7, 'admin', self::HOUSE]] as $id => [$sellerId, $sellerIs, $file]) {
            DB::table('orders')->insert(['id' => $id, 'customer_id' => 5, 'payment_status' => 'paid', 'seller_id' => $sellerId, 'seller_is' => $sellerIs]);
            DB::table('order_details')->insert([
                'id' => $id, 'order_id' => $id, 'product_id' => 5, 'seller_id' => $sellerId, 'payment_status' => 'paid', 'digital_file_after_sell' => $file,
                'product_details' => json_encode(['product_type' => 'digital', 'digital_product_type' => 'ready_after_sell', 'digital_file_ready' => null]),
            ]);
        }
        $this->useDigitalDisks(private: true);
        foreach ([self::OWN, self::OTHER, self::HOUSE, '2026-11-16-own-variation.pdf', '2026-11-16-other-variation.pdf', '2026-11-16-house-variation.pdf'] as $name) {
            $this->putOnPrivateDisk($name, 'the file ' . $name);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDigitalDisks();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function assertStreamed(TestResponse $response, string $name): void
    {
        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame('the file ' . $name, file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function assertNoFile(TestResponse $response): void
    {
        $this->assertNotInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertStringNotContainsString('the file ', (string) $response->getContent());
    }

    private function asAdmin(int $id): static
    {
        return $this->actingAs(Admin::findOrFail($id), 'admin');
    }

    private function asSeller(): static
    {
        return $this->actingAs($this->sellerOfTheToken(), 'seller');
    }

    private function sellerApi(string $token = self::SELLER_TOKEN): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    // ---- the admin panel -----------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: int, 2: int, 3: string}> the address, the admin who may open it, the admin who may not, and the file */
    public static function adminAddresses(): array
    {
        return [
            'the file of an order line' => ['/admin/orders/digital-file/1/' . self::OWN, self::ORDERS_ONLY, self::CATALOG_ONLY, self::OWN],
            'the file of an order line of another seller' => ['/admin/orders/digital-file/2/' . self::OTHER, self::MASTER, self::CATALOG_ONLY, self::OTHER],
            'the file of a product' => ['/admin/products/digital-file/6/' . self::OTHER, self::CATALOG_ONLY, self::ORDERS_ONLY, self::OTHER],
            'the file of a product of the shop' => ['/admin/products/digital-file/7/' . self::HOUSE, self::MASTER, self::ORDERS_ONLY, self::HOUSE],
            'the file of a variation' => ['/admin/products/digital-variation-file/2/2026-11-16-other-variation.pdf', self::CATALOG_ONLY, self::ORDERS_ONLY, '2026-11-16-other-variation.pdf'],
        ];
    }

    #[DataProvider('adminAddresses')]
    public function test_an_admin_who_holds_the_module_downloads_the_file(string $address, int $admin, int $deniedAdmin, string $file): void
    {
        $this->assertStreamed($this->asAdmin($admin)->get($address), $file);
    }

    #[DataProvider('adminAddresses')]
    public function test_an_admin_without_the_module_is_turned_away_and_gets_no_file(string $address, int $admin, int $deniedAdmin): void
    {
        $response = $this->asAdmin($deniedAdmin)->get($address);

        $response->assertRedirect();
        $this->assertNoFile($response);
    }

    #[DataProvider('adminAddresses')]
    public function test_a_visitor_who_is_not_an_admin_gets_no_file_and_does_not_learn_that_the_address_exists(string $address): void
    {
        $response = $this->getJson($address);

        $response->assertNotFound();
        $this->assertNoFile($response);
    }

    public function test_the_admin_panel_answers_not_found_for_an_order_a_product_or_a_file_that_is_not_there(): void
    {
        $this->asAdmin(self::MASTER);
        DB::table('products')->insert(['id' => 8, 'name' => 'Empty', 'digital_file_ready' => '2026-11-16-gone.pdf']);

        $this->getJson('/admin/orders/digital-file/99')->assertNotFound();
        $this->getJson('/admin/products/digital-file/99')->assertNotFound();
        $this->getJson('/admin/products/digital-variation-file/99')->assertNotFound();
        $this->getJson('/admin/products/digital-file/8')->assertNotFound();
    }

    public function test_the_admin_downloads_the_ready_file_of_a_line_of_a_ready_product_and_the_name_in_the_address_decides_nothing(): void
    {
        DB::table('order_details')->where('id', 2)->update(['product_details' => json_encode(['product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::HOUSE]), 'digital_file_after_sell' => null]);

        $this->assertStreamed($this->asAdmin(self::MASTER)->get('/admin/orders/digital-file/2/anything-the-url-says.pdf'), self::HOUSE);
    }

    public function test_the_admin_panel_streams_the_file_of_a_product_stored_before_the_switch_from_the_public_disk(): void
    {
        $this->putOnPublicDisk('2026-11-16-before.pdf', 'the file 2026-11-16-before.pdf');
        DB::table('products')->where('id', 6)->update(['digital_file_ready' => '2026-11-16-before.pdf']);

        $this->assertStreamed($this->asAdmin(self::MASTER)->get('/admin/products/digital-file/6'), '2026-11-16-before.pdf');
    }

    // ---- the vendor panel ----------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> the address of what the seller 7 owns, and the file */
    public static function vendorAddressesOfTheirOwn(): array
    {
        return [
            'the file of an order line' => ['/vendor/orders/digital-file/1/' . self::OWN, self::OWN],
            'the file of a product' => ['/vendor/products/digital-file/5/' . self::OWN, self::OWN],
            'the file of a variation' => ['/vendor/products/digital-variation-file/1/2026-11-16-own-variation.pdf', '2026-11-16-own-variation.pdf'],
        ];
    }

    /** @return array<string, array{0: string}> what belongs to another seller or to the shop, which the seller 7 is told is not there */
    public static function vendorAddressesOfOthers(): array
    {
        return [
            'an order line of another seller' => ['/vendor/orders/digital-file/2'],
            'an order line of the shop that carries the id of this seller' => ['/vendor/orders/digital-file/3'],
            'a product of another seller' => ['/vendor/products/digital-file/6'],
            'a product of the shop that carries the id of this seller' => ['/vendor/products/digital-file/7'],
            'a variation of another seller' => ['/vendor/products/digital-variation-file/2'],
            'a variation of the shop' => ['/vendor/products/digital-variation-file/3'],
        ];
    }

    #[DataProvider('vendorAddressesOfTheirOwn')]
    public function test_a_seller_downloads_the_file_of_their_own_order_or_product_in_the_vendor_panel(string $address, string $file): void
    {
        $this->assertStreamed($this->asSeller()->get($address), $file);
    }

    #[DataProvider('vendorAddressesOfOthers')]
    public function test_a_seller_is_told_there_is_nothing_at_the_address_of_what_another_seller_or_the_shop_owns(string $address): void
    {
        $response = $this->asSeller()->getJson($address);

        $response->assertNotFound();
        $this->assertNoFile($response);
    }

    public function test_a_visitor_who_is_not_signed_in_to_the_vendor_panel_is_sent_to_its_login(): void
    {
        foreach ([...array_column(self::vendorAddressesOfTheirOwn(), 0), ...array_column(self::vendorAddressesOfOthers(), 0)] as $address) {
            $response = $this->get($address);

            $response->assertRedirect(route('vendor.auth.login'));
            $this->assertNoFile($response);
        }
    }

    // ---- the seller apps -----------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> the two generations of the seller app */
    public static function sellerApps(): array
    {
        return ['the seller app v3' => ['v3'], 'the seller app v2' => ['v2']];
    }

    #[DataProvider('sellerApps')]
    public function test_a_seller_app_downloads_the_file_of_the_order_or_the_product_of_the_seller(string $version): void
    {
        $this->assertStreamed($this->sellerApi()->get("/api/{$version}/seller/orders/digital-file/1/" . self::OWN), self::OWN);
        $this->assertStreamed($this->sellerApi()->get("/api/{$version}/seller/products/digital-file/5/" . self::OWN), self::OWN);
        $this->assertStreamed($this->sellerApi()->get("/api/{$version}/seller/products/digital-variation-file/1"), '2026-11-16-own-variation.pdf');
    }

    #[DataProvider('sellerApps')]
    public function test_a_seller_app_is_told_there_is_nothing_at_the_address_of_what_another_seller_or_the_shop_owns(string $version): void
    {
        foreach (['orders/digital-file/2', 'orders/digital-file/3', 'products/digital-file/6', 'products/digital-file/7', 'products/digital-variation-file/2', 'products/digital-variation-file/3', 'orders/digital-file/99'] as $address) {
            $response = $this->sellerApi()->getJson("/api/{$version}/seller/{$address}");

            $response->assertNotFound();
            $this->assertNoFile($response);
        }
    }

    #[DataProvider('sellerApps')]
    public function test_a_caller_of_the_seller_app_without_a_seller_token_gets_no_file(string $version): void
    {
        foreach (['orders/digital-file/1', 'products/digital-file/5', 'products/digital-variation-file/1'] as $address) {
            $response = $this->getJson("/api/{$version}/seller/{$address}");
            $this->assertSame(401, $response->getStatusCode(), $address);
            $this->assertNoFile($response);

            $response = $this->sellerApi(token: str_repeat('x', 40))->getJson("/api/{$version}/seller/{$address}");
            $this->assertSame(401, $response->getStatusCode(), $address);
        }
    }

    public function test_another_sellers_token_opens_that_sellers_files_and_not_this_ones(): void
    {
        $other = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

        $this->assertStreamed($this->sellerApi(token: $other)->get('/api/v3/seller/orders/digital-file/2'), self::OTHER);
        $this->sellerApi(token: $other)->getJson('/api/v3/seller/orders/digital-file/1')->assertNotFound();
    }

    // ---- the permissions the routes carry ----------------------------------------------------------------------------------------------

    /** @return list<string> what the route really runs: the middleware of the route and of its groups */
    private function middlewareOf(string $name): array
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, $name);

        return array_map(static fn ($middleware): string => (string) $middleware, $route->gatherMiddleware());
    }

    /** @return array<string, array{0: string, 1: string}> the route, and the middleware that decides who may use it */
    public static function routesAndTheirGuards(): array
    {
        return [
            'admin, order line' => ['admin.orders.digital-file', 'module:orders'],
            'admin, product' => ['admin.products.digital-file', 'module:catalog'],
            'admin, variation' => ['admin.products.digital-variation-file', 'module:catalog'],
            'vendor, order line' => ['vendor.orders.digital-file', 'seller_staff_access'],
            'vendor, product' => ['vendor.products.digital-file', 'seller_staff_access'],
            'vendor, variation' => ['vendor.products.digital-variation-file', 'seller_staff_access'],
            'seller app v3, order line' => ['seller-api.v3.orders.digital-file', 'seller_can:orders.view,orders.manage'],
            'seller app v3, product' => ['seller-api.v3.products.digital-file', 'seller_can:products.view,products.manage'],
            'seller app v3, variation' => ['seller-api.v3.products.digital-variation-file', 'seller_can:products.view,products.manage'],
        ];
    }

    #[DataProvider('routesAndTheirGuards')]
    public function test_each_staff_route_carries_the_guard_of_its_area_and_the_permission_of_what_it_streams(string $name, string $guard): void
    {
        $middleware = $this->middlewareOf($name);

        $this->assertContains($guard, $middleware, $name);
        $this->assertNotEmpty(array_intersect(['admin', 'seller', 'seller_api_auth'], $middleware), $name . ' needs the sign-in of its area');
    }

    private const STAFF_TOKEN = 'ssssssssssssssssssssssssssssssssssssssss';

    /** A member of the staff of seller 7 whose role grants the permissions given, who signs in to the vendor panel as the seller and to the app with a token of their own. */
    private function staffWith(array $permissions): void
    {
        Schema::create('seller_roles', static function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name')->nullable();
            $table->text('permissions')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_staff', static function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
        DB::table('seller_roles')->insert(['id' => 1, 'seller_id' => 7, 'name' => 'Role', 'permissions' => json_encode($permissions), 'status' => 'active']);
        DB::table('seller_staff')->insert(['id' => 1, 'seller_id' => 7, 'seller_role_id' => 1, 'name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'x', 'auth_token' => self::STAFF_TOKEN, 'status' => 'active']);
    }

    public function test_a_member_of_the_staff_of_a_seller_downloads_in_the_vendor_panel_what_their_role_lets_them_see(): void
    {
        $this->staffWith(['products.view']);

        $this->assertStreamed($this->asSeller()->withSession(['seller_staff_id' => 1])->get('/vendor/products/digital-file/5'), self::OWN);
        $this->assertStreamed($this->asSeller()->withSession(['seller_staff_id' => 1])->get('/vendor/products/digital-variation-file/1'), '2026-11-16-own-variation.pdf');
        $response = $this->asSeller()->withSession(['seller_staff_id' => 1])->getJson('/vendor/orders/digital-file/1');
        $response->assertForbidden();
        $this->assertNoFile($response);
    }

    public function test_a_member_of_the_staff_of_a_seller_who_may_see_orders_and_not_products_downloads_the_file_of_an_order_only(): void
    {
        $this->staffWith(['orders.view']);

        $this->assertStreamed($this->asSeller()->withSession(['seller_staff_id' => 1])->get('/vendor/orders/digital-file/1'), self::OWN);
        $response = $this->asSeller()->withSession(['seller_staff_id' => 1])->getJson('/vendor/products/digital-file/5');
        $response->assertForbidden();
        $this->assertNoFile($response);
    }

    public function test_a_member_of_the_staff_of_a_seller_downloads_in_the_seller_app_what_their_role_lets_them_see(): void
    {
        $this->staffWith(['products.view']);

        $this->assertStreamed($this->sellerApi(token: self::STAFF_TOKEN)->get('/api/v3/seller/products/digital-file/5'), self::OWN);
        $this->assertStreamed($this->sellerApi(token: self::STAFF_TOKEN)->get('/api/v3/seller/products/digital-variation-file/1'), '2026-11-16-own-variation.pdf');
        $response = $this->sellerApi(token: self::STAFF_TOKEN)->getJson('/api/v3/seller/orders/digital-file/1');
        $response->assertForbidden();
        $this->assertNoFile($response);
    }
}
