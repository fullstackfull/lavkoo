<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Product\ProductController as AdminProductController;
use App\Http\Controllers\RestAPI\v3\seller\ProductController as SellerApiProductController;
use App\Http\Controllers\Vendor\Product\ProductController as VendorProductController;
use App\Models\Product;
use App\Models\Seller;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RemovesFilesItCreated;
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
    use RemovesFilesItCreated;
    use RestoresLanguageFiles;

    private const DIRECTORY = 'storage/app/public/product';

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
        $this->removeTheFilesItCreated(folders: [$this->product('thumbnail'), $this->product('digital-product'), base_path(self::DIRECTORY), base_path('storage/app/public/company')]);
        $this->restoreLanguageFiles();

        parent::tearDown();
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
            'by its name with the text null as colour, as the app sends it' => [['name' => 'extras-1.webp', 'color' => 'null']],
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

    /**
     * @param list<string> $colors
     * @param list<array{color: ?string, image_name: string}> $entries
     */
    private function insertColourProduct(int $id, array $colors, array $entries): void
    {
        $images = array_map(static fn (array $entry): array => ['image_name' => $entry['image_name'], 'storage' => 'public'], $entries);
        DB::table('products')->insert(['id' => $id, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'COL' . $id, 'name' => 'Colour product ' . $id, 'thumbnail' => 'thumb-' . $id . '.webp', 'images' => json_encode($images), 'colors' => json_encode($colors), 'color_image' => json_encode(array_map(static fn (array $entry): array => $entry + ['storage' => 'public'], $entries))]);
        foreach ($entries as $entry) {
            $this->fileOnDisk($this->product($entry['image_name']), $entry['image_name']);
        }
    }

    /** @param array<string, string> $query */
    private function removeThroughTheSellerApp(int $id, array $query): void
    {
        $response = app(SellerApiProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => $id, 'seller' => $this->seller()] + $query));

        $this->assertSame(200, $response->status());
    }

    /** @return list<array<string, mixed>> */
    private function colourImagesOf(int $id): array
    {
        return json_decode(DB::table('products')->where('id', $id)->value('color_image'), true);
    }

    public function test_the_seller_app_removes_the_image_of_a_colour_with_the_text_null_as_colour_as_it_sends_it(): void
    {
        $this->removeThroughTheSellerApp(id: 8, query: ['name' => 'colour-red.webp', 'color' => 'null']);

        $this->assertFileDoesNotExist($this->product('colour-red.webp'));
        $this->assertFileExists($this->product('colour-blue.webp'));
        $this->assertSame([['color' => '#FF0000', 'image_name' => null], ['color' => '#0000FF', 'image_name' => 'colour-blue.webp', 'storage' => 'public']], $this->colourImagesOf(id: 8));
    }

    public function test_the_seller_app_removes_the_image_of_a_colour_by_the_colour_alone_with_its_file(): void
    {
        $this->removeThroughTheSellerApp(id: 8, query: ['color' => '#FF0000']);

        $this->assertFileDoesNotExist($this->product('colour-red.webp'), 'the colour the request names lost its image file');
        $this->assertFileExists($this->product('colour-blue.webp'));
        $this->assertSame([['color' => '#FF0000', 'image_name' => null], ['color' => '#0000FF', 'image_name' => 'colour-blue.webp', 'storage' => 'public']], $this->colourImagesOf(id: 8));
    }

    public function test_the_seller_app_never_matches_a_colour_image_by_a_name_that_is_the_same_number_written_another_way(): void
    {
        $this->insertColourProduct(id: 12, colors: ['FF0000'], entries: [['color' => 'FF0000', 'image_name' => '20'], ['color' => null, 'image_name' => 'numeric-keep.webp']]);
        $this->fileOnDisk($this->product('2e1'), '2e1');

        $this->removeThroughTheSellerApp(id: 12, query: ['name' => '2e1', 'color' => 'null']);

        $this->assertFileExists($this->product('20'));
        $this->assertFileExists($this->product('2e1'));
        $this->assertFileExists($this->product('numeric-keep.webp'));
        $this->assertSame(['20', 'numeric-keep.webp'], array_column($this->colourImagesOf(id: 12), 'image_name'));
    }

    public function test_the_seller_app_does_not_read_an_empty_colour_as_the_colour_of_entries_whose_colour_is_empty_text(): void
    {
        $this->insertColourProduct(id: 13, colors: ['FF0000'], entries: [['color' => '', 'image_name' => 'empty-a.webp'], ['color' => '', 'image_name' => 'empty-b.webp']]);

        $this->removeThroughTheSellerApp(id: 13, query: ['name' => 'empty-a.webp', 'color' => '']);

        $this->assertFileDoesNotExist($this->product('empty-a.webp'));
        $this->assertFileExists($this->product('empty-b.webp'), 'an empty colour removed every entry whose colour is the empty text');
        $this->assertSame(['empty-b.webp'], array_column($this->colourImagesOf(id: 13), 'image_name'));
    }

    public function test_the_seller_app_tells_two_colours_apart_that_are_the_same_number(): void
    {
        $this->insertColourProduct(id: 14, colors: ['0E0000', '000000'], entries: [['color' => '0E0000', 'image_name' => 'dark-red.webp'], ['color' => '000000', 'image_name' => 'black.webp']]);

        $this->removeThroughTheSellerApp(id: 14, query: ['color' => '000000']);

        $this->assertFileDoesNotExist($this->product('black.webp'));
        $this->assertFileExists($this->product('dark-red.webp'), 'the image of the dark red colour went with the black one');
        $this->assertSame([['color' => '0E0000', 'image_name' => 'dark-red.webp', 'storage' => 'public'], ['color' => '000000', 'image_name' => null]], $this->colourImagesOf(id: 14));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function requestsThatNameNothing(): array
    {
        return [
            'no name and no colour' => [[]],
            'an empty name and an empty colour' => [['name' => '', 'color' => '']],
            'an empty name and the text null as colour' => [['name' => '', 'color' => 'null']],
        ];
    }

    #[DataProvider('requestsThatNameNothing')]
    public function test_the_seller_app_removes_nothing_for_a_request_that_names_no_image_and_no_colour(array $query): void
    {
        $before = DB::table('products')->where('id', 9)->first();

        $this->removeThroughTheSellerApp(id: 9, query: $query);

        foreach (['extras-red.webp', 'extras-blue.webp', 'extras-1.webp', 'extras-2.webp', 'extras-3.webp'] as $kept) {
            $this->assertFileExists($this->product($kept), $kept);
        }
        $after = DB::table('products')->where('id', 9)->first();
        $this->assertEquals(json_decode($before->color_image, true), json_decode($after->color_image, true));
        $this->assertSame($before->images, $after->images);
    }

    /**
     * What the panel's delete-image route makes of the product's lists, as the service answers it.
     *
     * @param array<string, string> $query
     * @return array{images: list<mixed>, color_images: list<array<string, mixed>>}
     */
    private function listsAfterRemovingThroughThePanel(int $id, array $query): array
    {
        return app(ProductService::class)->deleteImage(request: Request::create('/', 'GET', ['id' => $id] + $query), product: Product::withoutGlobalScopes()->find($id));
    }

    public function test_the_panel_removes_the_image_of_one_colour_and_leaves_the_colour_of_another_that_is_the_same_number(): void
    {
        $this->insertColourProduct(id: 15, colors: ['000000', '0E0000'], entries: [['color' => '000000', 'image_name' => 'black.webp'], ['color' => '0E0000', 'image_name' => 'dark-red.webp']]);

        $lists = $this->listsAfterRemovingThroughThePanel(id: 15, query: ['name' => 'black.webp', 'color' => '000000']);

        $this->assertSame(['dark-red.webp'], array_column($lists['images'], 'image_name'));
        $this->assertSame([['color' => '0E0000', 'image_name' => 'dark-red.webp', 'storage' => 'public']], $lists['color_images'], 'the dark red image lost its colour with the black one');
    }

    public function test_the_panel_compares_names_as_text_so_a_number_written_another_way_removes_another_image(): void
    {
        $this->insertColourProduct(id: 16, colors: ['FF0000'], entries: [['color' => 'FF0000', 'image_name' => '100'], ['color' => null, 'image_name' => '1e2']]);

        $lists = $this->listsAfterRemovingThroughThePanel(id: 16, query: ['name' => '1e2']);

        $this->assertSame(['100'], array_column($lists['images'], 'image_name'));
        $this->assertSame([['color' => 'FF0000', 'image_name' => '100', 'storage' => 'public']], $lists['color_images']);
    }

    public function test_the_panel_compares_names_as_text_in_a_gallery_of_a_product_without_colours_too(): void
    {
        DB::table('products')->insert(['id' => 17, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'NUM1', 'name' => 'Numeric names', 'thumbnail' => 'thumb-17.webp', 'images' => json_encode(['100', '1e2', 'plain.webp']), 'colors' => '[]', 'color_image' => '[]']);

        $lists = $this->listsAfterRemovingThroughThePanel(id: 17, query: ['name' => '1e2']);

        $this->assertSame(['100', 'plain.webp'], $lists['images']);
    }

    public function test_the_panel_still_removes_the_image_of_a_colour_by_its_name_and_by_its_colour_and_keeps_the_others_in_place(): void
    {
        $entries = [['color' => 'FF0000', 'image_name' => 'red.webp'], ['color' => '0000FF', 'image_name' => 'blue.webp'], ['color' => null, 'image_name' => 'extra-1.webp'], ['color' => null, 'image_name' => 'extra-2.webp']];
        $this->insertColourProduct(id: 18, colors: ['FF0000', '0000FF'], entries: $entries);

        $byName = $this->listsAfterRemovingThroughThePanel(id: 18, query: ['name' => 'red.webp', 'color' => 'FF0000']);
        $extra = $this->listsAfterRemovingThroughThePanel(id: 18, query: ['name' => 'extra-1.webp']);

        $this->assertSame(['blue.webp', 'extra-1.webp', 'extra-2.webp'], array_column($byName['images'], 'image_name'));
        $this->assertSame(['blue.webp', 'extra-1.webp', 'extra-2.webp'], array_column($byName['color_images'], 'image_name'));
        $this->assertSame(['red.webp', 'blue.webp', 'extra-2.webp'], array_column($extra['images'], 'image_name'));
        $this->assertSame(['red.webp', 'blue.webp', 'extra-2.webp'], array_column($extra['color_images'], 'image_name'));
    }

    /** The lists the panel's update makes of the images of a product and of the colours and the files in its form. */
    private function updatedLists(int $id, Request $request): array
    {
        return $this->trackingTheFilesStoredBelow(folder: base_path(self::DIRECTORY), work: static fn (): array => app(ProductService::class)->getProcessedUpdateImages(request: $request, product: Product::withoutGlobalScopes()->find($id)));
    }

    /** @param array<string, UploadedFile> $files */
    private function updateRequest(array $colors, array $files = []): Request
    {
        return Request::create('/', 'POST', ['colors_active' => 'on', 'colors' => $colors], [], $files);
    }

    public function test_the_panel_replaces_the_image_of_one_colour_and_leaves_the_image_of_another_that_is_the_same_number(): void
    {
        $this->insertColourProduct(id: 19, colors: ['000000', '0E0000'], entries: [['color' => '000000', 'image_name' => 'update-black.webp'], ['color' => '0E0000', 'image_name' => 'update-dark-red.webp']]);

        $lists = $this->updatedLists(id: 19, request: $this->updateRequest(colors: ['#000000', '#0E0000'], files: ['color_image_000000' => UploadedFile::fake()->image('new-black.png', 40, 40)]));

        $this->assertFileDoesNotExist($this->product('update-black.webp'), 'the image of black is replaced');
        $this->assertFileExists($this->product('update-dark-red.webp'), 'replacing the image of black deleted the file of the dark red colour');
        $colours = array_column($lists['colored_image_names'], 'image_name', 'color');
        $this->assertSame(['0E0000', '000000'], array_keys($colours));
        $this->assertSame('update-dark-red.webp', $colours['0E0000']);
        $this->assertNotSame('update-black.webp', $colours['000000']);
        $this->assertFileExists($this->product($colours['000000']), 'the new image of black is stored');
    }

    public function test_the_panel_still_replaces_the_image_of_a_colour_and_deletes_its_old_file_and_only_that(): void
    {
        $this->insertColourProduct(id: 20, colors: ['FF0000', '0000FF'], entries: [['color' => 'FF0000', 'image_name' => 'update-red.webp'], ['color' => '0000FF', 'image_name' => 'update-blue.webp']]);

        $lists = $this->updatedLists(id: 20, request: $this->updateRequest(colors: ['#FF0000', '#0000FF'], files: ['color_image_FF0000' => UploadedFile::fake()->image('new-red.png', 40, 40)]));

        $this->assertFileDoesNotExist($this->product('update-red.webp'));
        $this->assertFileExists($this->product('update-blue.webp'));
        $this->assertSame(['0000FF', 'FF0000'], array_column($lists['colored_image_names'], 'color'));
        $this->assertSame('update-blue.webp', $lists['colored_image_names'][0]['image_name']);
        $this->assertNotContains('update-red.webp', array_column($lists['image_names'], 'image_name'));
    }

    public function test_the_panel_adds_the_image_of_a_new_colour_although_the_product_has_a_colour_that_is_the_same_number(): void
    {
        $this->insertColourProduct(id: 22, colors: ['000000'], entries: [['color' => '000000', 'image_name' => 'added-black.webp']]);

        $lists = $this->updatedLists(id: 22, request: $this->updateRequest(colors: ['#000000', '#0E0000'], files: ['color_image_0E0000' => UploadedFile::fake()->image('new-dark-red.png', 40, 40)]));

        $colours = array_column($lists['colored_image_names'], 'image_name', 'color');
        $this->assertSame(['000000', '0E0000'], array_keys($colours), 'the image of the new colour was taken for a replacement of the image of black and left out');
        $this->assertSame('added-black.webp', $colours['000000']);
        $this->assertFileExists($this->product($colours['0E0000']));
    }

    public function test_the_panel_removes_the_image_of_a_colour_by_the_colour_alone_and_keeps_the_image_whose_name_is_the_same_number_as_its_own(): void
    {
        $this->insertColourProduct(id: 23, colors: ['FF0000'], entries: [['color' => 'FF0000', 'image_name' => '100'], ['color' => null, 'image_name' => '1e2']]);

        $lists = $this->listsAfterRemovingThroughThePanel(id: 23, query: ['color' => 'FF0000']);

        $this->assertSame(['100', '1e2'], array_column($lists['images'], 'image_name'), 'the image stays in the gallery');
        $this->assertSame(['1e2', '100'], array_column($lists['color_images'], 'image_name'), 'and stays an image of the product, with no colour now');
    }

    public function test_the_panel_removes_an_image_by_a_colour_and_another_by_a_name_that_is_the_same_number_as_the_first(): void
    {
        $this->insertColourProduct(id: 24, colors: ['FF0000'], entries: [['color' => 'FF0000', 'image_name' => '100'], ['color' => null, 'image_name' => '1e2']]);

        $lists = $this->listsAfterRemovingThroughThePanel(id: 24, query: ['color' => 'FF0000', 'name' => '1e2']);

        $this->assertSame(['100'], array_column($lists['images'], 'image_name'));
        $this->assertSame([['color' => null, 'image_name' => '100', 'storage' => 'public']], $lists['color_images'], 'the image that only lost its colour is still an image of the product');
    }

    public function test_the_panel_takes_the_colour_from_the_image_of_a_colour_that_was_removed_and_not_from_one_that_is_the_same_number(): void
    {
        $this->insertColourProduct(id: 21, colors: ['000000', '0E0000'], entries: [['color' => '000000', 'image_name' => 'removed-black.webp'], ['color' => '0E0000', 'image_name' => 'removed-dark-red.webp']]);

        $lists = $this->updatedLists(id: 21, request: $this->updateRequest(colors: ['#000000']));

        $this->assertSame([['color' => '000000', 'image_name' => 'removed-black.webp', 'storage' => 'public'], ['color' => null, 'image_name' => 'removed-dark-red.webp', 'storage' => 'public']], $lists['colored_image_names'], 'the dark red image keeps a colour that the form no longer holds');
        $this->assertEqualsCanonicalizing(['removed-black.webp', 'removed-dark-red.webp'], array_column($lists['image_names'], 'image_name'));
    }
}
