<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Product\ProductController as AdminProductController;
use App\Http\Controllers\RestAPI\v3\seller\ProductController as SellerApiProductController;
use App\Http\Controllers\Vendor\Product\ProductController as VendorProductController;
use App\Models\Product;
use App\Models\Seller;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Removing an image from a product deleted the file the request named: `image=` in the admin and vendor panels, `name=` with a matching colour in
 * the seller app. The name was never compared with the product's own images, so a seller removed another seller's image, a product thumbnail or
 * another seller's digital file by name (the admin panel even deleted before it looked the product up, and the vendor panel before it checked that
 * the product keeps an image). A file is only deleted when the product holds an image of that name.
 */
class ProductImageDeleteTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const DIRECTORY = 'storage/app/public/product';

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        DB::table('products')->insert([
            ['id' => 5, 'added_by' => 'admin', 'user_id' => 1, 'code' => 'ADM1', 'name' => 'Admin product', 'thumbnail' => 'thumb-admin.webp', 'images' => json_encode(['own-a.webp', 'own-b.webp']), 'colors' => '[]', 'color_image' => '[]'],
            ['id' => 6, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'SEL1', 'name' => 'Seller product', 'thumbnail' => 'thumb-seller.webp', 'images' => json_encode(['seller-a.webp', 'seller-b.webp']), 'colors' => '[]', 'color_image' => '[]'],
            ['id' => 8, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'SEL2', 'name' => 'Seller product with colours', 'thumbnail' => 'thumb-colours.webp', 'images' => json_encode([['image_name' => 'colour-red.webp', 'storage' => 'public'], ['image_name' => 'colour-blue.webp', 'storage' => 'public']]), 'colors' => json_encode(['#FF0000', '#0000FF']), 'color_image' => json_encode([['color' => '#FF0000', 'image_name' => 'colour-red.webp', 'storage' => 'public'], ['color' => '#0000FF', 'image_name' => 'colour-blue.webp', 'storage' => 'public']])],
            // What the seller app writes for a product with colours: the extra gallery images are colour images with no colour.
            ['id' => 9, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'SEL3', 'name' => 'Seller product with colours and extras', 'thumbnail' => 'thumb-extras.webp', 'images' => json_encode(['extras-red.webp', 'extras-blue.webp', 'extras-1.webp', 'extras-2.webp', 'extras-3.webp']), 'colors' => json_encode(['#FF0000', '#0000FF']), 'color_image' => json_encode([['color' => '#FF0000', 'image_name' => 'extras-red.webp', 'storage' => 'public'], ['color' => '#0000FF', 'image_name' => 'extras-blue.webp', 'storage' => 'public'], ['color' => null, 'image_name' => 'extras-1.webp', 'storage' => 'public'], ['color' => null, 'image_name' => 'extras-2.webp', 'storage' => 'public'], ['color' => null, 'image_name' => 'extras-3.webp', 'storage' => 'public']])],
            ['id' => 11, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'SEL4', 'name' => 'Seller product with numeric names', 'thumbnail' => 'thumb-numeric.webp', 'images' => json_encode(['10', 'numeric-own.webp']), 'colors' => '[]', 'color_image' => '[]'],
        ]);
        foreach (['own-a.webp', 'own-b.webp', 'seller-a.webp', 'seller-b.webp', 'colour-red.webp', 'colour-blue.webp', 'extras-red.webp', 'extras-blue.webp', 'extras-1.webp', 'extras-2.webp', 'extras-3.webp', '10', '1e1', 'numeric-own.webp', 'victim-other-seller.webp', 'thumbnail/victim-thumb.webp', 'digital-product/victim.zip'] as $name) {
            $this->fileOnDisk(base_path(self::DIRECTORY . '/' . $name), $name);
        }
        $this->fileOnDisk(base_path('storage/app/public/company/victim-logo.png'), 'logo');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path(self::DIRECTORY . '/thumbnail'));
        File::deleteDirectory(base_path(self::DIRECTORY . '/digital-product'));
        File::deleteDirectory(base_path('storage/app/public/company'));
        array_map('unlink', array_filter($this->created, 'file_exists'));
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function fileOnDisk(string $path, string $content): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);
        $this->created[] = $path;
    }

    private function product(string $name): string
    {
        return base_path(self::DIRECTORY . '/' . $name);
    }

    private function seller(): Seller
    {
        $seller = new Seller();
        $seller->id = 7;

        return $seller;
    }

    /** @return array<string, array{0: string}> files that belong to nobody on the product that is edited */
    public static function filesOfSomeoneElse(): array
    {
        return [
            'an image of another product' => ['victim-other-seller.webp'],
            'the thumbnail of another product' => ['thumbnail/victim-thumb.webp'],
            'the digital file of another product' => ['digital-product/victim.zip'],
            'the thumbnail of this product' => ['thumbnail/victim-thumb.webp'],
            'a file of the shop' => ['../company/victim-logo.png'],
        ];
    }

    #[DataProvider('filesOfSomeoneElse')]
    public function test_the_admin_panel_deletes_no_file_that_the_product_does_not_hold(string $victim): void
    {
        app(AdminProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 5, 'name' => 'own-a.webp', 'image' => $victim]), app(ProductService::class));

        foreach (['victim-other-seller.webp', 'thumbnail/victim-thumb.webp', 'digital-product/victim.zip'] as $file) {
            $this->assertFileExists($this->product($file), $file);
        }
        $this->assertFileExists(base_path('storage/app/public/company/victim-logo.png'));
    }

    public function test_the_admin_panel_still_removes_an_image_of_the_product_and_its_file(): void
    {
        app(AdminProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 5, 'name' => 'own-a.webp', 'image' => 'own-a.webp']), app(ProductService::class));

        $this->assertFileDoesNotExist($this->product('own-a.webp'));
        $this->assertFileExists($this->product('own-b.webp'));
        $this->assertSame(['own-b.webp'], json_decode(DB::table('products')->where('id', 5)->value('images'), true));
    }

    public function test_the_admin_panel_keeps_the_last_image_and_its_file(): void
    {
        DB::table('products')->where('id', 5)->update(['images' => json_encode(['own-a.webp'])]);

        app(AdminProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 5, 'name' => 'own-a.webp', 'image' => 'own-a.webp']), app(ProductService::class));

        $this->assertFileExists($this->product('own-a.webp'));
    }

    public function test_a_product_that_does_not_exist_answers_a_refusal_and_deletes_nothing(): void
    {
        app(AdminProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 999, 'name' => 'own-a.webp', 'image' => 'victim-other-seller.webp']), app(ProductService::class));

        $this->assertFileExists($this->product('victim-other-seller.webp'));
    }

    #[DataProvider('filesOfSomeoneElse')]
    public function test_the_vendor_panel_deletes_no_file_that_the_product_does_not_hold(string $victim): void
    {
        $this->actingAs($this->seller(), 'seller');

        app(VendorProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 6, 'name' => 'seller-a.webp', 'image' => $victim]), app(ProductService::class));

        foreach (['victim-other-seller.webp', 'thumbnail/victim-thumb.webp', 'digital-product/victim.zip'] as $file) {
            $this->assertFileExists($this->product($file), $file);
        }
        $this->assertFileExists(base_path('storage/app/public/company/victim-logo.png'));
    }

    public function test_the_vendor_panel_still_removes_an_image_of_the_product_and_its_file(): void
    {
        $this->actingAs($this->seller(), 'seller');

        app(VendorProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 6, 'name' => 'seller-a.webp', 'image' => 'seller-a.webp']), app(ProductService::class));

        $this->assertFileDoesNotExist($this->product('seller-a.webp'));
        $this->assertSame(['seller-b.webp'], json_decode(DB::table('products')->where('id', 6)->value('images'), true));
    }

    #[DataProvider('filesOfSomeoneElse')]
    public function test_the_seller_app_deletes_no_file_the_product_does_not_hold_even_with_the_colour_of_one_of_its_images(string $victim): void
    {
        $response = app(SellerApiProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 8, 'color' => '#FF0000', 'name' => $victim, 'seller' => $this->seller()]));

        $this->assertSame(200, $response->status());
        foreach (['victim-other-seller.webp', 'thumbnail/victim-thumb.webp', 'digital-product/victim.zip'] as $file) {
            $this->assertFileExists($this->product($file), $file);
        }
        $this->assertFileExists(base_path('storage/app/public/company/victim-logo.png'));
    }

    public function test_the_seller_app_still_removes_the_image_of_a_colour_with_its_file(): void
    {
        $response = app(SellerApiProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 8, 'color' => '#FF0000', 'name' => 'colour-red.webp', 'seller' => $this->seller()]));

        $this->assertSame(200, $response->status());
        $this->assertFileDoesNotExist($this->product('colour-red.webp'));
        $this->assertFileExists($this->product('colour-blue.webp'));
    }

    public function test_the_seller_app_still_removes_a_plain_image_by_its_name(): void
    {
        $response = app(SellerApiProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 6, 'name' => 'seller-b.webp', 'seller' => $this->seller()]));

        $this->assertSame(200, $response->status());
        $this->assertFileDoesNotExist($this->product('seller-b.webp'));
        $this->assertFileExists($this->product('seller-a.webp'));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function removalsOfOneExtraImage(): array
    {
        return [
            'by its name' => [['name' => 'extras-1.webp']],
            'by its name with an empty colour' => [['name' => 'extras-1.webp', 'color' => '']],
        ];
    }

    #[DataProvider('removalsOfOneExtraImage')]
    public function test_the_seller_app_removes_one_extra_image_of_a_product_with_colours_and_keeps_the_others(array $query): void
    {
        $response = app(SellerApiProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 9, 'seller' => $this->seller()] + $query));

        $this->assertSame(200, $response->status());
        $this->assertFileDoesNotExist($this->product('extras-1.webp'));
        foreach (['extras-red.webp', 'extras-blue.webp', 'extras-2.webp', 'extras-3.webp'] as $kept) {
            $this->assertFileExists($this->product($kept), $kept);
        }
        $product = DB::table('products')->where('id', 9)->first();
        $this->assertSame(['extras-red.webp', 'extras-blue.webp', 'extras-2.webp', 'extras-3.webp'], json_decode($product->images, true));
        $this->assertSame(['extras-red.webp', 'extras-blue.webp', 'extras-2.webp', 'extras-3.webp'], array_column(json_decode($product->color_image, true), 'image_name'));
    }

    public function test_the_seller_app_removes_the_image_of_a_colour_of_a_product_with_extras_and_keeps_the_extras(): void
    {
        $response = app(SellerApiProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 9, 'color' => '#FF0000', 'name' => 'extras-red.webp', 'seller' => $this->seller()]));

        $this->assertSame(200, $response->status());
        $this->assertFileDoesNotExist($this->product('extras-red.webp'));
        foreach (['extras-blue.webp', 'extras-1.webp', 'extras-2.webp', 'extras-3.webp'] as $kept) {
            $this->assertFileExists($this->product($kept), $kept);
        }
        $this->assertSame(['extras-1.webp', 'extras-2.webp', 'extras-3.webp'], array_values(array_filter(array_column(json_decode(DB::table('products')->where('id', 9)->value('color_image'), true), 'image_name'), static fn (?string $name): bool => str_starts_with((string) $name, 'extras-') && $name !== 'extras-red.webp' && $name !== 'extras-blue.webp')));
    }

    public function test_the_seller_app_compares_names_as_text_so_a_number_written_another_way_deletes_another_file(): void
    {
        $response = app(SellerApiProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 11, 'name' => '1e1', 'seller' => $this->seller()]));

        $this->assertSame(200, $response->status());
        $this->assertFileExists($this->product('1e1'));
        $this->assertFileExists($this->product('10'));
        $this->assertSame(['10', 'numeric-own.webp'], json_decode(DB::table('products')->where('id', 11)->value('images'), true));
    }

    public function test_the_service_knows_the_image_names_a_product_holds(): void
    {
        $service = app(ProductService::class);

        $this->assertEqualsCanonicalizing(['colour-red.webp', 'colour-blue.webp'], array_values(array_unique($service->imageNames(Product::withoutGlobalScopes()->find(8)))));
        $this->assertSame(['own-a.webp', 'own-b.webp'], $service->imageNames(Product::withoutGlobalScopes()->find(5)));
    }
}
