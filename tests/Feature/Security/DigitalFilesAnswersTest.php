<?php

namespace Tests\Feature\Security;

use App\Models\DigitalProductVariation;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\User;
use App\Services\DigitalProductFileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsDigitalFileWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Every answer that used to carry the public address of a digital file (the models' `digital_file_ready_full_url`, `digital_file_after_sell_full_url` and
 * `file_full_url`, the order details the customer app and the seller apps show, the base address of the config answer) keeps its shape while the private mode is
 * off, and while it is on carries the authenticated route of the area that asks (the admin panel, the vendor panel, a seller app, the customer app) or nothing:
 * never the public address of a file, even of one that is still on the public disk.
 */
class DigitalFilesAnswersTest extends TestCase
{
    use BuildsDigitalFileWorld;
    use RestoresLanguageFiles;

    private const READY = '2026-11-16-ready.pdf';

    private const AFTER_SALE = '2026-11-16-after-sale.pdf';

    private const VARIATION = '2026-11-16-variation.pdf';

    /** @var array<string, array<string, string>> the route of each kind of file, by area */
    private const ROUTES = [
        'admin' => ['order-line' => 'admin.orders.digital-file', 'product' => 'admin.products.digital-file', 'variation' => 'admin.products.digital-variation-file'],
        'vendor' => ['order-line' => 'vendor.orders.digital-file', 'product' => 'vendor.products.digital-file', 'variation' => 'vendor.products.digital-variation-file'],
        'seller app v3' => ['order-line' => 'seller-api.v3.orders.digital-file', 'product' => 'seller-api.v3.products.digital-file', 'variation' => 'seller-api.v3.products.digital-variation-file'],
        'seller app v2' => ['order-line' => 'seller-api.v2.orders.digital-file', 'product' => 'seller-api.v2.products.digital-file', 'variation' => 'seller-api.v2.products.digital-variation-file'],
        'customer app' => ['order-line' => 'customer-api.orders.digital-file'],
        'website' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildDigitalFileWorld();
        $this->withoutTheShellOfTheWeb();
        DB::table('users')->insert(['id' => 5, 'f_name' => 'Buyer', 'email' => 'buyer@example.test', 'phone' => '0555000005']);
        DB::table('products')->insert(['id' => 5, 'added_by' => 'seller', 'user_id' => 7, 'name' => 'Guide', 'slug' => 'guide', 'status' => 1, 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::READY, 'thumbnail' => 't.webp', 'images' => '[]', 'colors' => '[]', 'color_image' => '[]']);
        DB::table('digital_product_variations')->insert(['id' => 1, 'product_id' => 5, 'variant_key' => 'Format-PDF', 'file' => self::VARIATION]);
        DB::table('orders')->insert(['id' => 1, 'customer_id' => 5, 'is_guest' => 0, 'payment_status' => 'paid', 'seller_id' => 7, 'seller_is' => 'seller']);
        DB::table('order_details')->insert([
            'id' => 1, 'order_id' => 1, 'product_id' => 5, 'seller_id' => 7, 'payment_status' => 'paid', 'digital_file_after_sell' => self::AFTER_SALE, 'variation' => '[]',
            'product_details' => json_encode(['name' => 'Guide', 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'digital_file_ready' => self::READY, 'storage_path' => 'public']),
        ]);
        $this->createTablesOfTheOrderRelations();
        foreach (['with_vat', 'no_vat'] as $kind) {
            Cache::put('tax_system_type_vendor_' . $kind, ['SystemTaxVat' => null, 'SystemTaxVatType' => 'order_wise', 'is_included' => 0, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect()], 60);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDigitalDisks();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The request the answer is made for: the address decides which app area asks. */
    private function askedAt(string $path): void
    {
        $this->app->instance('request', Request::create($path));
    }

    private function putAllOnPrivateDisk(): void
    {
        foreach ([self::READY, self::AFTER_SALE, self::VARIATION] as $name) {
            $this->putOnPrivateDisk($name);
        }
    }

    /** @return array<string, array{0: string, 1: string}> the address of a request of each area, and the area */
    public static function areas(): array
    {
        return [
            'the admin panel' => ['/admin/orders/details/1', 'admin'],
            'the admin panel, point of sale' => ['/admin/pos/order/1', 'admin'],
            'the vendor panel' => ['/vendor/orders/details/1', 'vendor'],
            'the seller app v3' => ['/api/v3/seller/orders/1', 'seller app v3'],
            'the seller app v2' => ['/api/v2/seller/orders/1', 'seller app v2'],
            'the customer app' => ['/api/v1/customer/order/details', 'customer app'],
            'the website' => ['/digital-product-download-pos', 'website'],
            'the home page' => ['/', 'website'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function kinds(): array
    {
        return ['the file after the sale of an order line' => ['order-line'], 'the ready file of a product' => ['product'], 'the file of a digital variation' => ['variation']];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> every area with every kind of file */
    public static function areasAndKinds(): array
    {
        $cases = [];
        foreach (self::areas() as $areaName => [$path, $area]) {
            foreach (self::kinds() as $kindName => [$kind]) {
                $cases["{$areaName}, {$kindName}"] = [$path, $area, $kind];
            }
        }

        return $cases;
    }

    /** What the answer of a kind of file says, read the way the screens and the apps read it. */
    private function answerOf(string $kind): array
    {
        return match ($kind) {
            'order-line' => OrderDetail::findOrFail(1)->digital_file_after_sell_full_url,
            'product' => Product::withoutGlobalScopes()->findOrFail(5)->digital_file_ready_full_url,
            'variation' => DigitalProductVariation::findOrFail(1)->file_full_url,
        };
    }

    private function nameOf(string $kind): string
    {
        return ['order-line' => self::AFTER_SALE, 'product' => self::READY, 'variation' => self::VARIATION][$kind];
    }

    private function holder(string $kind): array
    {
        return ['order-line' => ['order-line', 1], 'product' => ['product', 5], 'variation' => ['variation', 1]][$kind];
    }

    #[DataProvider('areasAndKinds')]
    public function test_while_the_mode_is_off_the_answer_is_the_public_address_in_every_area_as_always(string $path, string $area, string $kind): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPublicDisk($this->nameOf($kind));
        $this->askedAt($path);

        $answer = $this->answerOf($kind);

        $this->assertSame(storageLink('product/digital-product', $this->nameOf($kind), 'public'), $answer);
        $this->assertSame(200, $answer['status']);
        $this->assertStringContainsString('/storage/', $answer['path']);
    }

    #[DataProvider('areasAndKinds')]
    public function test_while_the_mode_is_on_the_answer_is_the_route_of_the_area_that_asks_or_nothing_and_never_a_public_address(string $path, string $area, string $kind): void
    {
        $this->useDigitalDisks(private: true);
        $this->putAllOnPrivateDisk();
        $this->askedAt($path);
        $name = $this->nameOf($kind);

        $answer = $this->answerOf($kind);

        $route = self::ROUTES[$area][$kind] ?? null;
        $this->assertSame($name, $answer['key']);
        if ($route === null) {
            $this->assertSame(['key' => $name, 'path' => null, 'status' => 404], $answer, 'there is no route of this area for this file, and no public address either');

            return;
        }
        $this->assertSame(['key' => $name, 'path' => route($route, ['id' => $this->holder($kind)[1], 'fileName' => $name]), 'status' => 200], $answer);
        $this->assertStringNotContainsString('/storage/', $answer['path']);
    }

    #[DataProvider('areasAndKinds')]
    public function test_while_the_mode_is_on_a_file_that_is_still_on_the_public_disk_is_not_given_its_public_address_either(string $path, string $area, string $kind): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPublicDisk($this->nameOf($kind));
        $this->askedAt($path);

        $answer = $this->answerOf($kind);

        $this->assertStringNotContainsString('/storage/', (string) $answer['path']);
        $this->assertSame(isset(self::ROUTES[$area][$kind]) ? 200 : 404, $answer['status']);
    }

    #[DataProvider('areasAndKinds')]
    public function test_while_the_mode_is_on_a_file_that_is_nowhere_has_no_address_and_the_status_it_always_had(string $path, string $area, string $kind): void
    {
        $this->useDigitalDisks(private: true);
        $this->askedAt($path);

        $this->assertSame(['key' => $this->nameOf($kind), 'path' => null, 'status' => 404], $this->answerOf($kind));
    }

    public function test_while_the_mode_is_on_a_row_that_keeps_its_file_on_the_bucket_is_answered_as_always(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('product/digital-product/' . self::READY, 'on the bucket');
        DB::table('products')->where('id', 5)->update(['digital_file_ready_storage_type' => 's3']);
        $this->useDigitalDisks(private: false);
        config(['filesystems.disks.default' => 's3']);
        $this->askedAt('/admin/products/view/seller/5');
        $asAlways = Product::withoutGlobalScopes()->findOrFail(5)->digital_file_ready_full_url;

        $this->useDigitalDisks(private: true);
        config(['filesystems.disks.default' => 's3']);
        $now = Product::withoutGlobalScopes()->findOrFail(5)->digital_file_ready_full_url;

        $this->assertSame(200, $asAlways['status']);
        $this->assertSame($asAlways, $now);
    }

    public function test_a_product_in_the_json_of_the_customer_app_carries_no_address_of_its_file_while_the_mode_is_on(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putAllOnPrivateDisk();
        $this->putOnPublicDisk(self::READY);
        $this->askedAt('/api/v1/products/details/guide');

        $json = json_encode(Product::withoutGlobalScopes()->with('digitalVariation')->findOrFail(5)->toArray(), JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('/storage/', $json);
        $this->assertStringNotContainsString('digital-product/', $json);
    }

    // ---- the answers of the apps -----------------------------------------------------------------------------------------------------

    private function buyer(): void
    {
        $this->actingAs(User::findOrFail(5), 'customer');
    }

    /** The tables the order details of the apps read through the relations of an order line, empty: the rows are not what these tests are about. */
    private function createTablesOfTheOrderRelations(): void
    {
        Schema::create('stock_clearance_setups', static function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->date('duration_start_date')->nullable();
            $table->date('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->time('offer_active_range_start')->nullable();
            $table->time('offer_active_range_end')->nullable();
        });
        Schema::create('stock_clearance_products', static function (Blueprint $table) {
            $table->id();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamps();
        });
        Schema::table('reviews', static function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->integer('rating')->nullable();
        });
        Schema::table('orders', static fn (Blueprint $table) => $table->decimal('init_order_amount', 40, 20)->default(0));
        Schema::table('order_details', static function (Blueprint $table) {
            $table->decimal('init_order_amount', 40, 20)->default(0);
            $table->boolean('is_stock_decreased')->default(true);
        });
        Schema::create('colors', static function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
        });
        foreach (['offline_payments' => 'order_id', 'order_delivery_verifications' => 'order_id', 'order_edit_histories' => 'order_id', 'delivery_men' => 'seller_id'] as $name => $column) {
            Schema::create($name, static function (Blueprint $table) use ($column) {
                $table->id();
                $table->unsignedBigInteger($column)->nullable();
                $table->timestamps();
            });
        }
    }

    /** What the customer app reads of the order of its customer: the ready file of the snapshot of the line, and the file delivered after the sale. */
    private function orderDetailsOfTheCustomerApp(): array
    {
        $this->buyer();
        $response = $this->getJson('/api/v1/customer/order/details?order_id=1&guest_id=1')->assertOk();

        return $response->json('0');
    }

    public function test_while_the_mode_is_off_the_order_details_of_the_customer_app_carry_the_public_addresses_as_always(): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPublicDisk(self::READY);
        $this->putOnPublicDisk(self::AFTER_SALE);

        $line = $this->orderDetailsOfTheCustomerApp();

        $this->assertSame(storageLink('product/digital-product', self::READY, 'public'), $line['product_details']['digital_file_ready_full_url']);
        $this->assertSame(storageLink('product/digital-product', self::AFTER_SALE, 'public'), $line['digital_file_after_sell_full_url']);
    }

    public function test_while_the_mode_is_on_the_order_details_of_the_customer_app_carry_the_route_that_streams_the_file_of_the_line(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::READY);
        $this->putOnPrivateDisk(self::AFTER_SALE);

        $line = $this->orderDetailsOfTheCustomerApp();

        $this->assertSame(['key' => self::READY, 'path' => route('customer-api.orders.digital-file', ['id' => 1, 'fileName' => self::READY]), 'status' => 200], $line['product_details']['digital_file_ready_full_url']);
        $this->assertSame(['key' => self::AFTER_SALE, 'path' => route('customer-api.orders.digital-file', ['id' => 1, 'fileName' => self::AFTER_SALE]), 'status' => 200], $line['digital_file_after_sell_full_url']);
        $this->assertStringNotContainsString('/storage/', json_encode($line));
    }

    public function test_the_route_the_customer_app_is_given_streams_the_file_of_the_buyer_and_keeps_its_old_address(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::READY, 'the ready file');
        $this->buyer();

        $this->assertSame('/api/v1/customer/order/digital-product-download/1/' . self::READY, parse_url(route('customer-api.orders.digital-file', ['id' => 1, 'fileName' => self::READY]), PHP_URL_PATH));
        $this->get('/api/v1/customer/order/digital-product-download/1?guest_id=1')->assertOk();
        $this->get('/api/v1/customer/order/digital-product-download/1/' . self::READY . '?guest_id=1')->assertOk();
    }

    /** What the seller app v3 reads of an order: the ready file of the snapshot of each line, and the file delivered after the sale. */
    private function orderDetailsOfTheSellerApp(): array
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . self::SELLER_TOKEN)->getJson('/api/v3/seller/orders/1');

        return $response->assertOk()->json('0');
    }

    public function test_while_the_mode_is_on_the_order_details_of_the_seller_app_carry_the_route_of_the_seller_apps(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk(self::READY);
        $this->putOnPrivateDisk(self::AFTER_SALE);

        $line = $this->orderDetailsOfTheSellerApp();

        $this->assertSame(['key' => self::READY, 'path' => route('seller-api.v3.orders.digital-file', ['id' => 1, 'fileName' => self::READY]), 'status' => 200], $line['product_details']['digital_file_ready_full_url']);
        $this->assertSame(['key' => self::AFTER_SALE, 'path' => route('seller-api.v3.orders.digital-file', ['id' => 1, 'fileName' => self::AFTER_SALE]), 'status' => 200], $line['digital_file_after_sell_full_url']);
    }

    public function test_while_the_mode_is_off_the_order_details_of_the_seller_app_carry_the_public_addresses_as_always(): void
    {
        $this->useDigitalDisks(private: false);
        $this->putOnPublicDisk(self::READY);
        $this->putOnPublicDisk(self::AFTER_SALE);

        $line = $this->orderDetailsOfTheSellerApp();

        $this->assertSame(storageLink('product/digital-product', self::READY, 'public'), $line['product_details']['digital_file_ready_full_url']);
        $this->assertSame(storageLink('product/digital-product', self::AFTER_SALE, 'public'), $line['digital_file_after_sell_full_url']);
    }

    // ---- the config answer ---------------------------------------------------------------------------------------------------------------

    public function test_the_base_address_of_the_config_answer_is_the_public_folder_while_the_mode_is_off_and_nothing_while_it_is_on(): void
    {
        $this->useDigitalDisks(private: false);
        $this->assertSame(dynamicStorage(path: 'storage/app/public/product/digital-product'), app(DigitalProductFileService::class)->publicBaseUrl());

        $this->useDigitalDisks(private: true);
        $this->assertSame('', app(DigitalProductFileService::class)->publicBaseUrl());
    }

    public function test_the_config_answer_asks_the_service_for_the_base_address_of_the_digital_files(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/RestAPI/v1/ConfigController.php'));

        $this->assertStringContainsString("'digital_product_url' => app(DigitalProductFileService::class)->publicBaseUrl(),", $source);
        $this->assertStringNotContainsString("dynamicStorage(path: 'storage/app/public/product/digital-product')", $source);
    }
}
