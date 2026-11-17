<?php

namespace Tests\Feature\Security;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\ProductSeoRepositoryInterface;
use App\Http\Controllers\RestAPI\v3\seller\ProductController as SellerApiProductController;
use App\Http\Controllers\Vendor\Product\ProductController as VendorProductController;
use App\Models\Product;
use App\Models\Seller;
use App\Services\ProductService;
use App\Traits\FileManagerTrait;
use App\Utils\ImageManager;
use App\Utils\SharedProductFile;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A product names its files in its own row, and a name is only a claim: a copy of a product shares the files of its original, and the seller
 * apps and the add form post names that a seller can take from the storefront. Removing an image, a thumbnail or a preview of one product used
 * to delete the file, whoever else held it, so a seller added a product that held another seller's file names and removed them again.
 * A file that more than one product holds stays until the last of them lets go.
 */
class ProductSharedFileTest extends TestCase
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
        foreach (['2024_05_26_152339_create_product_seos_table.php', '2024_05_26_152030_create_digital_product_variations_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
        foreach (['preview_file', 'meta_image'] as $column) {
            if (!Schema::hasColumn('products', $column)) {
                Schema::table('products', static fn ($table) => $table->string($column)->nullable());
            }
        }

        DB::table('products')->insert([
            // another seller's product: the files that are public on the storefront
            ['id' => 90, 'added_by' => 'seller', 'user_id' => 99, 'code' => 'VIC1', 'name' => 'Victim', 'thumbnail' => 'victim-thumb.webp', 'preview_file' => 'victim-preview.pdf', 'meta_image' => 'victim-meta.webp', 'images' => json_encode(['victim-a.webp', 'victim-b.webp']), 'colors' => '[]', 'color_image' => '[]'],
            // the seller's own product that claims those names (and two aliases that point into other folders of product/)
            ['id' => 91, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'ATT1', 'name' => 'Claims', 'thumbnail' => 'victim-thumb.webp', 'preview_file' => 'victim-preview.pdf', 'meta_image' => 'victim-meta.webp', 'images' => json_encode(['victim-a.webp', 'own-1.webp', 'thumbnail/victim-thumb.webp']), 'colors' => '[]', 'color_image' => '[]'],
            // a product and its copy: they share every file
            ['id' => 92, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'ORI1', 'name' => 'Original', 'thumbnail' => 'shared-thumb.webp', 'preview_file' => 'shared-preview.pdf', 'meta_image' => 'shared-meta.webp', 'images' => json_encode([['image_name' => 'shared-1.webp', 'storage' => 'public'], ['image_name' => 'shared-2.webp', 'storage' => 'public']]), 'colors' => '[]', 'color_image' => '[]'],
            ['id' => 93, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'COP1', 'name' => 'Copy', 'thumbnail' => 'shared-thumb.webp', 'preview_file' => 'shared-preview.pdf', 'meta_image' => 'shared-meta.webp', 'images' => json_encode([['image_name' => 'shared-1.webp', 'storage' => 'public'], ['image_name' => 'shared-2.webp', 'storage' => 'public']]), 'colors' => '[]', 'color_image' => '[]'],
            // a product whose files nobody else holds
            ['id' => 94, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'SOL1', 'name' => 'Alone', 'thumbnail' => 'solo-thumb.webp', 'preview_file' => 'solo-preview.pdf', 'meta_image' => 'solo-meta.webp', 'images' => json_encode(['solo-1.webp', 'solo-2.webp']), 'colors' => '[]', 'color_image' => '[]'],
        ]);

        foreach (['victim-a.webp', 'victim-b.webp', 'own-1.webp', 'shared-1.webp', 'shared-2.webp', 'solo-1.webp', 'solo-2.webp',
                     'thumbnail/victim-thumb.webp', 'thumbnail/shared-thumb.webp', 'thumbnail/solo-thumb.webp',
                     'preview/victim-preview.pdf', 'preview/shared-preview.pdf', 'preview/solo-preview.pdf',
                     'meta/victim-meta.webp', 'meta/shared-meta.webp', 'meta/solo-meta.webp'] as $name) {
            $this->fileOnDisk(base_path(self::DIRECTORY . '/' . $name), $name);
        }
        $this->fileOnDisk(base_path('storage/app/public/category/probe-category.png'), 'category');
    }

    protected function tearDown(): void
    {
        foreach (['thumbnail', 'preview', 'meta', 'digital-product'] as $folder) {
            File::deleteDirectory(base_path(self::DIRECTORY . '/' . $folder));
        }
        File::deleteDirectory(base_path('storage/app/public/category'));
        array_map('unlink', array_filter($this->created, 'file_exists'));
        @rmdir(base_path('storage/app/public/profile'));
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

    private function assertTheVictimsFilesRemain(): void
    {
        foreach (['victim-a.webp', 'victim-b.webp', 'thumbnail/victim-thumb.webp', 'preview/victim-preview.pdf', 'meta/victim-meta.webp'] as $file) {
            $this->assertFileExists($this->product($file), $file);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function namesTheSellerClaims(): array
    {
        return [
            'an image another product holds' => ['victim-a.webp'],
            'a thumbnail written as an image with its folder' => ['thumbnail/victim-thumb.webp'],
        ];
    }

    #[DataProvider('namesTheSellerClaims')]
    public function test_removing_a_claimed_image_in_the_vendor_panel_deletes_the_file_of_the_other_product_no_more(string $claimed): void
    {
        $this->actingAs($this->seller(), 'seller');

        app(VendorProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 91, 'name' => $claimed, 'image' => $claimed]), app(ProductService::class));

        $this->assertTheVictimsFilesRemain();
    }

    public function test_removing_a_claimed_image_in_the_seller_app_deletes_the_file_of_the_other_product_no_more(): void
    {
        $response = app(SellerApiProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 91, 'name' => 'victim-a.webp', 'seller' => $this->seller()]));

        $this->assertSame(200, $response->status());
        $this->assertTheVictimsFilesRemain();
        $this->assertSame(['own-1.webp', 'thumbnail/victim-thumb.webp'], json_decode(DB::table('products')->where('id', 91)->value('images'), true));
    }

    public function test_deleting_a_product_deletes_only_the_files_no_other_product_holds(): void
    {
        app(ProductService::class)->deleteImages(product: Product::withoutGlobalScopes()->find(91));
        app(ProductService::class)->deletePreviewFile(product: Product::withoutGlobalScopes()->find(91));

        $this->assertTheVictimsFilesRemain();
        $this->assertFileDoesNotExist($this->product('own-1.webp'), 'the one file only this product held goes with it');
    }

    public function test_the_v2_seller_api_helper_keeps_a_file_another_product_holds(): void
    {
        ImageManager::delete('/product/victim-a.webp');
        ImageManager::delete('/product/thumbnail/victim-thumb.webp');
        ImageManager::delete('/product/own-1.webp');

        $this->assertFileExists($this->product('victim-a.webp'));
        $this->assertFileExists($this->product('thumbnail/victim-thumb.webp'));
        $this->assertFileDoesNotExist($this->product('own-1.webp'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> the folder, the file the product holds, and the column */
    public static function replacements(): array
    {
        return [
            'a thumbnail' => ['product/thumbnail/', 'victim-thumb.webp', 'webp'],
            'a meta image' => ['product/meta/', 'victim-meta.webp', 'png'],
            'a preview file' => ['product/preview/', 'victim-preview.pdf', 'pdf'],
        ];
    }

    #[DataProvider('replacements')]
    public function test_replacing_a_file_keeps_the_old_one_when_another_product_holds_it(string $directory, string $held, string $format): void
    {
        $writer = new class {
            use FileManagerTrait;
        };
        $upload = new UploadedFile($this->temporaryImage(), 'new.' . $format, null, null, true);

        $stored = $writer->update(dir: $directory, oldImage: $held, format: $format, image: $upload, fileType: $format === 'pdf' ? 'file' : 'image');

        $this->assertFileExists(base_path('storage/app/public/' . $directory . $held), 'the file of the other product stays');
        $this->assertFileExists(base_path('storage/app/public/' . $directory . $stored), 'the new file is stored');
        File::delete(base_path('storage/app/public/' . $directory . $stored));
    }

    private function temporaryImage(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ps');
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);
        file_put_contents($path, (string) ob_get_clean());
        $this->created[] = $path;

        return $path;
    }

    public function test_a_copy_that_lets_go_of_an_image_leaves_it_to_the_original_and_the_last_one_to_let_go_deletes_it(): void
    {
        $this->actingAs($this->seller(), 'seller');

        app(VendorProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 93, 'name' => 'shared-1.webp', 'image' => 'shared-1.webp']), app(ProductService::class));
        $this->assertFileExists($this->product('shared-1.webp'), 'the original still shows it');

        app(VendorProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 92, 'name' => 'shared-1.webp', 'image' => 'shared-1.webp']), app(ProductService::class));
        $this->assertFileDoesNotExist($this->product('shared-1.webp'), 'nobody holds it any more');
        $this->assertFileExists($this->product('shared-2.webp'));
    }

    public function test_a_file_only_one_product_holds_is_deleted_as_before(): void
    {
        $this->actingAs($this->seller(), 'seller');

        app(VendorProductController::class)->deleteImage(Request::create('/', 'GET', ['id' => 94, 'name' => 'solo-1.webp', 'image' => 'solo-1.webp']), app(ProductService::class));
        app(ProductService::class)->deletePreviewFile(product: Product::withoutGlobalScopes()->find(94));
        app(ProductService::class)->deleteImages(product: Product::withoutGlobalScopes()->find(94));

        foreach (['solo-1.webp', 'solo-2.webp', 'thumbnail/solo-thumb.webp', 'preview/solo-preview.pdf'] as $file) {
            $this->assertFileDoesNotExist($this->product($file), $file);
        }
    }

    public function test_a_digital_file_that_a_product_and_a_variation_hold_is_kept_until_the_last_lets_go(): void
    {
        DB::table('products')->where('id', 90)->update(['digital_file_ready' => 'shared-digital.zip']);
        DB::table('digital_product_variations')->insert(['product_id' => 91, 'file' => 'shared-digital.zip']);
        DB::table('digital_product_variations')->insert(['product_id' => 94, 'file' => 'solo-digital.zip']);
        $this->fileOnDisk($this->product('digital-product/shared-digital.zip'), 'zip');
        $this->fileOnDisk($this->product('digital-product/solo-digital.zip'), 'zip');

        ImageManager::delete('/product/digital-product/shared-digital.zip');
        ImageManager::delete('/product/digital-product/solo-digital.zip');

        $this->assertFileExists($this->product('digital-product/shared-digital.zip'));
        $this->assertFileDoesNotExist($this->product('digital-product/solo-digital.zip'));
        File::deleteDirectory($this->product('digital-product'));
    }

    public function test_a_file_that_is_no_product_file_is_deleted_as_before(): void
    {
        ImageManager::delete('/category/probe-category.png');

        $this->assertFileDoesNotExist(base_path('storage/app/public/category/probe-category.png'));
    }

    /** @param array<string, mixed> $attributes */
    private function insertProduct(int $id, array $attributes = []): void
    {
        DB::table('products')->insert(array_merge(['id' => $id, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'T' . $id, 'name' => 'Product ' . $id, 'images' => '[]', 'colors' => '[]', 'color_image' => '[]'], $attributes));
    }

    public function test_a_product_that_names_a_file_in_its_thumbnail_its_gallery_and_its_colour_images_is_one_holder_and_deletes_it(): void
    {
        $entry = ['image_name' => 'triple.webp', 'storage' => 'public'];
        $this->insertProduct(81, ['thumbnail' => 'triple.webp', 'images' => json_encode([$entry]), 'colors' => json_encode(['FF0000']), 'color_image' => json_encode([['color' => 'FF0000'] + $entry])]);
        $this->fileOnDisk($this->product('triple.webp'), 'gallery');
        $this->fileOnDisk($this->product('thumbnail/triple.webp'), 'thumbnail');

        ImageManager::delete('/product/triple.webp');
        ImageManager::delete('/product/thumbnail/triple.webp');

        $this->assertFileDoesNotExist($this->product('triple.webp'));
        $this->assertFileDoesNotExist($this->product('thumbnail/triple.webp'));
    }

    public function test_a_file_that_one_product_holds_only_as_a_colour_image_and_another_in_its_gallery_is_shared(): void
    {
        $this->insertProduct(81, ['images' => json_encode(['other.webp']), 'colors' => json_encode(['FF0000']), 'color_image' => json_encode([['color' => 'FF0000', 'image_name' => 'colour-only.webp', 'storage' => 'public']])]);
        $this->insertProduct(82, ['images' => json_encode(['colour-only.webp'])]);
        $this->fileOnDisk($this->product('colour-only.webp'), 'colour');

        ImageManager::delete('/product/colour-only.webp');

        $this->assertTrue(SharedProductFile::isShared(path: 'product/colour-only.webp'));
        $this->assertFileExists($this->product('colour-only.webp'));
    }

    public function test_a_gallery_entry_with_the_folder_in_front_counts_although_the_product_has_another_thumbnail(): void
    {
        $this->insertProduct(81, ['thumbnail' => 'own-thumb.webp', 'images' => json_encode(['thumbnail/claimed-thumb.webp'])]);
        $this->insertProduct(82, ['thumbnail' => 'claimed-thumb.webp']);
        $this->fileOnDisk($this->product('thumbnail/claimed-thumb.webp'), 'thumbnail');

        ImageManager::delete('/product/thumbnail/claimed-thumb.webp');

        $this->assertFileExists($this->product('thumbnail/claimed-thumb.webp'), 'product 81 claims the thumbnail of product 82 in its gallery: both hold it');
    }

    public function test_a_meta_image_that_one_product_holds_and_the_seo_row_of_another_names_is_shared(): void
    {
        $this->insertProduct(81, ['meta_image' => 'seo-shared.png']);
        $this->insertProduct(82);
        DB::table('product_seos')->insert(['product_id' => 82, 'image' => 'seo-shared.png']);
        $this->fileOnDisk($this->product('meta/seo-shared.png'), 'meta');

        ImageManager::delete('/product/meta/seo-shared.png');

        $this->assertTrue(SharedProductFile::isShared(path: 'product/meta/seo-shared.png'));
        $this->assertFileExists($this->product('meta/seo-shared.png'));
    }

    public function test_a_meta_image_that_a_product_holds_in_its_row_and_in_its_seo_row_is_one_holder_and_goes_with_it(): void
    {
        $this->insertProduct(81, ['meta_image' => 'seo-own.png']);
        DB::table('product_seos')->insert(['product_id' => 81, 'image' => 'seo-own.png']);
        $this->fileOnDisk($this->product('meta/seo-own.png'), 'meta');

        ImageManager::delete('/product/meta/seo-own.png');

        $this->assertFileDoesNotExist($this->product('meta/seo-own.png'));
    }

    public function test_a_digital_file_that_a_product_holds_in_its_row_and_in_a_variation_is_one_holder_and_goes_with_it(): void
    {
        $this->insertProduct(81, ['digital_file_ready' => 'own-book.zip']);
        DB::table('digital_product_variations')->insert(['product_id' => 81, 'file' => 'own-book.zip']);
        $this->fileOnDisk($this->product('digital-product/own-book.zip'), 'zip');

        ImageManager::delete('/product/digital-product/own-book.zip');

        $this->assertFileDoesNotExist($this->product('digital-product/own-book.zip'));
    }

    public function test_a_file_outside_the_product_directory_is_deleted_although_two_products_name_it(): void
    {
        $this->insertProduct(81, ['images' => json_encode(['profile-like.webp'])]);
        $this->insertProduct(82, ['images' => json_encode(['profile-like.webp'])]);
        $this->fileOnDisk(base_path('storage/app/public/profile/profile-like.webp'), 'profile');

        ImageManager::delete('profile/profile-like.webp');

        $this->assertFileDoesNotExist(base_path('storage/app/public/profile/profile-like.webp'), 'a profile picture is no product file');
    }

    public function test_a_path_with_a_second_folder_level_is_no_thumbnail_even_when_two_products_hold_that_name_as_thumbnail(): void
    {
        $this->insertProduct(81, ['thumbnail' => 'deep.webp']);
        $this->insertProduct(82, ['thumbnail' => 'deep.webp']);

        $this->assertFalse(SharedProductFile::isShared(path: 'product/thumbnail/deeper/deep.webp'));
        $this->assertTrue(SharedProductFile::isShared(path: 'product/thumbnail/deep.webp'));
    }

    private function giveTheProductsTableItsFillableColumns(): void
    {
        foreach ((new Product())->getFillable() as $column) {
            if (!Schema::hasColumn('products', $column)) {
                Schema::table('products', static fn ($table) => $table->text($column)->nullable());
            }
        }
    }

    /**
     * @param array<string, UploadedFile> $files
     * @param array<string, mixed> $fields
     */
    private function panelRequest(string $name, array $files = [], array $fields = []): Request
    {
        return Request::create('/', 'POST', $fields + [
            'lang' => ['en'], 'name' => [$name], 'description' => ['<p>d</p>'], 'code' => strtoupper(substr(md5($name), 0, 8)), 'product_type' => 'physical',
            'category_id' => 1, 'unit' => 'pc', 'unit_price' => 10, 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude', 'discount' => 0,
            'discount_type' => 'flat', 'current_stock' => 5, 'minimum_order_qty' => 1, 'shipping_cost' => 0, 'meta_title' => 't', 'meta_description' => 'd',
            'meta_index' => 'index',
        ], [], $files);
    }

    private function newImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    /** The order of Vendor\Product\ProductController::add and of the admin panel's: the data of the form, the row, then the SEO row. */
    private function addAsThePanelDoes(Request $request, int $sellerId): Product
    {
        $service = app(ProductService::class);
        $data = array_merge($service->getAddProductData(request: $request, addedBy: 'seller', shopId: 1), ['user_id' => $sellerId]);
        $saved = Product::withoutEvents(static fn () => app(ProductRepositoryInterface::class)->add(data: $data));
        app(ProductSeoRepositoryInterface::class)->add(data: $service->getProductSEOData(request: $request, product: $saved, action: 'add'));

        return $saved;
    }

    /** The order of Vendor\Product\ProductController::update and of the admin panel's: the data of the form, the row, and only then the SEO row. */
    private function updateAsThePanelDoes(int $productId, Request $request): void
    {
        $service = app(ProductService::class);
        $product = app(ProductRepositoryInterface::class)->getFirstWhereWithoutGlobalScope(params: ['id' => $productId], relations: ['seoInfo']);
        $dataArray = $service->getUpdateProductData(request: $request, product: $product, updateBy: 'seller');
        Product::withoutEvents(static fn () => app(ProductRepositoryInterface::class)->update(id: (string) $productId, data: $dataArray));
        app(ProductSeoRepositoryInterface::class)->updateOrInsert(params: ['product_id' => $product['id']], data: $service->getProductSEOData(request: $request, product: $product, action: 'update'));
    }

    public function test_editing_a_copy_with_a_new_meta_image_keeps_the_meta_image_that_the_original_shows_as_its_seo_image(): void
    {
        $this->giveTheProductsTableItsFillableColumns();
        $this->insertProduct(81, ['name' => 'Original', 'meta_image' => 'stale-original.png']);
        $this->insertProduct(82, ['name' => 'Copy', 'meta_image' => 'shown-meta.png']);
        DB::table('product_seos')->insert([['product_id' => 81, 'image' => 'shown-meta.png'], ['product_id' => 82, 'image' => 'copy-own-seo.webp']]);
        $this->fileOnDisk($this->product('meta/shown-meta.png'), 'meta');

        $this->updateAsThePanelDoes(productId: 82, request: $this->panelRequest(name: 'Copy', files: ['meta_image' => $this->newImage('new-meta.png')]));

        $this->assertFileExists($this->product('meta/shown-meta.png'), 'the original still shows it');
        $newMetaImage = DB::table('products')->where('id', 82)->value('meta_image');
        $this->assertNotSame('shown-meta.png', $newMetaImage);
        $this->assertFileExists($this->product('meta/' . $newMetaImage));
        $this->assertFileExists($this->product('meta/' . DB::table('product_seos')->where('product_id', 82)->value('image')));
    }

    public function test_a_seller_who_wrote_the_meta_image_of_another_sellers_product_into_his_own_cannot_delete_it_by_editing_his_product(): void
    {
        $this->giveTheProductsTableItsFillableColumns();
        $this->insertProduct(90 + 20, ['user_id' => 99, 'name' => 'Victim', 'meta_image' => 'stale-victim.png']);
        DB::table('product_seos')->insert(['product_id' => 110, 'image' => 'victim-seo.png']);
        $this->fileOnDisk($this->product('meta/victim-seo.png'), 'meta');

        $added = $this->addAsThePanelDoes(request: $this->panelRequest(name: 'Mine', files: ['image' => $this->newImage('thumbnail.png')], fields: ['existing_meta_image' => 'victim-seo.png']), sellerId: 7);
        $this->assertFileExists($this->product('meta/victim-seo.png'), 'adding the product does not delete it');

        $this->updateAsThePanelDoes(productId: $added->id, request: $this->panelRequest(name: 'Mine', files: ['meta_image' => $this->newImage('meta.png')]));

        $this->assertFileExists($this->product('meta/victim-seo.png'), 'the seller deleted the meta image that the product of another seller shows');
    }

    public function test_editing_a_product_with_a_new_meta_image_deletes_the_old_one_when_no_other_product_holds_it(): void
    {
        $this->giveTheProductsTableItsFillableColumns();
        $this->insertProduct(81, ['name' => 'Alone', 'meta_image' => 'old-meta.png']);
        DB::table('product_seos')->insert(['product_id' => 81, 'image' => 'old-meta.png']);
        $this->fileOnDisk($this->product('meta/old-meta.png'), 'meta');

        $this->updateAsThePanelDoes(productId: 81, request: $this->panelRequest(name: 'Alone', files: ['meta_image' => $this->newImage('new-meta.png')]));

        $this->assertFileDoesNotExist($this->product('meta/old-meta.png'));
        $this->assertFileExists($this->product('meta/' . DB::table('products')->where('id', 81)->value('meta_image')));
        $this->assertFileExists($this->product('meta/' . DB::table('product_seos')->where('product_id', 81)->value('image')));
    }

    public function test_a_copy_made_from_the_gallery_and_edited_with_a_new_meta_image_leaves_the_meta_image_of_its_original(): void
    {
        $this->giveTheProductsTableItsFillableColumns();
        $original = $this->addAsThePanelDoes(request: $this->panelRequest(name: 'Original', files: ['image' => $this->newImage('original.png'), 'meta_image' => $this->newImage('original-meta.png')]), sellerId: 7);
        $original = Product::withoutGlobalScopes()->with('seoInfo')->find($original->id);
        $metaImageOfTheOriginal = $original->seoInfo->image;
        $this->assertFileExists($this->product('meta/' . $metaImageOfTheOriginal));

        $copyForm = ['existing_thumbnail' => $original->thumbnail, 'existing_meta_image' => $metaImageOfTheOriginal, 'existing_images' => []];
        $copy = $this->addAsThePanelDoes(request: $this->panelRequest(name: 'Copy', files: ['image' => $this->newImage('copy.png')], fields: $copyForm), sellerId: 7);
        $this->updateAsThePanelDoes(productId: $copy->id, request: $this->panelRequest(name: 'Copy', files: ['meta_image' => $this->newImage('copy-meta.png')]));

        $this->assertFileExists($this->product('meta/' . $metaImageOfTheOriginal), 'the original still shows it');
    }

    public function test_a_meta_image_whose_last_live_holder_lets_go_is_deleted_although_a_deleted_product_left_its_seo_row(): void
    {
        foreach ([81, 82] as $id) {
            $this->insertProduct($id, ['thumbnail' => 'orphan-' . $id . '.webp', 'meta_image' => 'orphan-meta.png']);
            DB::table('product_seos')->insert(['product_id' => $id, 'image' => 'orphan-meta.png']);
        }
        $this->fileOnDisk($this->product('meta/orphan-meta.png'), 'meta');

        app(ProductService::class)->deleteImages(product: Product::withoutGlobalScopes()->find(81));
        DB::table('products')->where('id', 81)->delete();
        $this->assertFileExists($this->product('meta/orphan-meta.png'), 'product 82 still holds it');

        ImageManager::delete('/product/meta/orphan-meta.png');

        $this->assertFileDoesNotExist($this->product('meta/orphan-meta.png'), 'product 82 was the last live holder; the SEO row of the deleted product 81 is no holder');
    }

    public function test_a_digital_file_whose_last_live_holder_lets_go_is_deleted_although_a_deleted_product_left_its_variation_row(): void
    {
        foreach ([81, 82] as $id) {
            $this->insertProduct($id, ['product_type' => 'digital', 'digital_file_ready' => 'orphan-book.zip']);
        }
        DB::table('digital_product_variations')->insert(['product_id' => 81, 'file' => 'orphan-book.zip']);
        $this->fileOnDisk($this->product('digital-product/orphan-book.zip'), 'zip');
        DB::table('products')->where('id', 81)->delete();

        ImageManager::delete('/product/digital-product/orphan-book.zip');

        $this->assertFileDoesNotExist($this->product('digital-product/orphan-book.zip'));
    }

    public function test_the_rows_of_a_deleted_product_are_no_holders_of_the_file_a_live_product_holds(): void
    {
        $this->insertProduct(82, ['meta_image' => 'orphan-only.png', 'digital_file_ready' => 'orphan-only.zip']);
        DB::table('product_seos')->insert(['product_id' => 81, 'image' => 'orphan-only.png']);
        DB::table('digital_product_variations')->insert(['product_id' => 81, 'file' => 'orphan-only.zip']);

        $this->assertFalse(SharedProductFile::isShared(path: 'product/meta/orphan-only.png'));
        $this->assertFalse(SharedProductFile::isShared(path: 'product/digital-product/orphan-only.zip'));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}> the rows of two products that hold one file, and the path of the file */
    public static function twoHoldersOfOneFile(): array
    {
        $arabic = "\u{0635}\u{0648}\u{0631}\u{0629}-1.webp";
        $list = static fn (array $names, int $flags = 0): string => json_encode($names, $flags);
        $object = static fn (string $name, string $color): string => json_encode([['color' => $color, 'image_name' => $name, 'storage' => 'public']]);

        return [
            'a non-ASCII gallery name, which json_encode writes as \u escapes' => [['images' => $list([$arabic])], ['images' => $list([$arabic])], 'product/' . $arabic],
            'a non-ASCII gallery name written unescaped' => [['images' => $list([$arabic], JSON_UNESCAPED_UNICODE)], ['images' => $list([$arabic], JSON_UNESCAPED_UNICODE)], 'product/' . $arabic],
            'a non-ASCII gallery name written once escaped and once unescaped' => [['images' => $list([$arabic])], ['images' => $list([$arabic], JSON_UNESCAPED_UNICODE)], 'product/' . $arabic],
            'a non-ASCII colour image name' => [['color_image' => $object($arabic, 'FF0000')], ['color_image' => $object($arabic, '00FF00')], 'product/' . $arabic],
            'a non-ASCII thumbnail' => [['thumbnail' => $arabic], ['thumbnail' => $arabic], 'product/thumbnail/' . $arabic],
            'a name with a double quote' => [['images' => $list(['q"1.webp'])], ['images' => $list(['q"1.webp'])], 'product/q"1.webp'],
            'a name with a backslash, which the disk reads as a folder' => [['images' => $list(['b\\1.webp'])], ['images' => $list(['b\\1.webp'])], 'product/b\\1.webp'],
            'a name with a percent sign' => [['images' => $list(['p%1.webp'])], ['images' => $list(['p%1.webp'])], 'product/p%1.webp'],
            'a name with an underscore' => [['images' => $list(['u_1.webp'])], ['images' => $list(['u_1.webp'])], 'product/u_1.webp'],
            'a name with the escape character of the search' => [['images' => $list(['e!1.webp'])], ['images' => $list(['e!1.webp'])], 'product/e!1.webp'],
            'a name with an apostrophe and a space' => [['images' => $list(["a'1 b.webp"])], ['images' => $list(["a'1 b.webp"])], "product/a'1 b.webp"],
            'a gallery entry written with a dot segment and one without' => [['images' => $list(['./ps2.webp'])], ['images' => $list(['ps2.webp'])], 'product/ps2.webp'],
            'a gallery entry written with the folder and two slashes' => [['images' => $list(['thumbnail//ps3.webp'])], ['thumbnail' => 'ps3.webp'], 'product/thumbnail/ps3.webp'],
        ];
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[DataProvider('twoHoldersOfOneFile')]
    public function test_two_products_that_hold_a_file_make_it_shared_however_its_name_is_written_in_their_rows(array $first, array $second, string $path): void
    {
        $this->insertProduct(81, $first);
        $this->insertProduct(82, $second);

        $this->assertTrue(SharedProductFile::isShared(path: $path), 'two products hold ' . $path);
    }

    /** @return array<string, array{0: string, 1: int}> a name, and the flags of the JSON writer that wrote it into the rows of two products */
    public static function writersOfAGalleryText(): array
    {
        $quotedArabic = "q\"\u{0635}\u{0648}\u{0631}\u{0629}.webp";

        return [
            'raw letters and an escaped quote' => [$quotedArabic, JSON_UNESCAPED_UNICODE],
            'raw letters, an escaped quote and raw slashes' => [$quotedArabic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES],
            'a backslash that the disk reads as a folder, and raw letters' => ["b\\\u{0635}.webp", JSON_UNESCAPED_UNICODE],
            'an apostrophe, an ampersand, angle brackets and a letter with an accent, all escaped' => ["abcdef&ghijkl<mnopqr>stuvwx'yz\u{00e9}.webp", JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT],
            'a name of which no character stays as it is but the dot and the extension' => ["\u{0635}\u{0648}\u{0631}\u{0629}\"'.webp", JSON_HEX_APOS | JSON_HEX_QUOT],
        ];
    }

    #[DataProvider('writersOfAGalleryText')]
    public function test_two_products_that_hold_a_name_make_it_shared_whatever_the_flags_of_the_json_writer_of_their_rows(string $name, int $flags): void
    {
        $this->insertProduct(81, ['images' => json_encode([$name], $flags)]);
        $this->insertProduct(82, ['images' => json_encode([$name], $flags)]);

        $this->assertTrue(SharedProductFile::isShared(path: 'product/' . $name), 'two products hold ' . $name . ' in the text ' . json_encode([$name], $flags));
    }

    public function test_two_products_whose_gallery_text_spells_its_escapes_in_upper_case_hold_the_file(): void
    {
        $name = "caf\u{00e9}-\u{0635}.webp";
        $this->assertSame('["caf\\u00e9-\\u0635.webp"]', json_encode([$name]), 'json_encode writes the hex digits in lower case, other writers in upper case');
        $this->insertProduct(81, ['images' => '["caf\\u00E9-\\u0635.webp"]']);
        $this->insertProduct(82, ['images' => '["caf\\u00E9-\\u0635.webp"]']);

        $this->assertTrue(SharedProductFile::isShared(path: 'product/' . $name));
    }

    public function test_a_name_with_no_character_that_a_json_writer_leaves_alone_is_found_in_the_rows_of_its_holders(): void
    {
        $name = "\u{0635}\u{0648}\u{0631}\u{0629}";
        $this->insertProduct(81, ['images' => json_encode([$name])]);
        $this->insertProduct(82, ['images' => json_encode(['other.webp'])]);

        $this->assertFalse(SharedProductFile::isShared(path: 'product/' . $name), 'one holder');

        $this->insertProduct(83, ['images' => json_encode([$name], JSON_UNESCAPED_UNICODE)]);

        $this->assertTrue(SharedProductFile::isShared(path: 'product/' . $name), 'two holders, one of them with raw letters');
    }

    public function test_the_search_asks_for_the_longest_run_of_the_name_that_no_json_writer_spells_in_another_way(): void
    {
        $patterns = [];
        DB::listen(static function ($query) use (&$patterns): void {
            if (preg_match('/\bfrom [`"]?products[`"]?\b/i', $query->sql) === 1) {
                $patterns[] = array_values(array_filter($query->bindings, 'is_string'));
            }
        });

        SharedProductFile::sharedAmong(paths: ["product/ab\u{0635}cdefg.webp", 'product/plain-name_1.webp', "product/\u{0635}\u{0648}"]);

        // the gallery and the colour images, for each file: the run, with the characters of the pattern language escaped; a name with no such character asks for every row
        $this->assertSame([['%cdefg.webp%', '%cdefg.webp%', '%plain-name!_1.webp%', '%plain-name!_1.webp%', '%%', '%%']], $patterns);
    }

    /** @return array<string, array{0: string}> the paths that the disk reads as product/ps1.webp */
    public static function pathsOfTheGalleryFile(): array
    {
        return [
            'with a leading slash' => ['/product/ps1.webp'],
            'with two slashes after product' => ['product//ps1.webp'],
            'with a dot segment' => ['product/./ps1.webp'],
            'with a leading dot segment' => ['./product/ps1.webp'],
            'with two leading slashes' => ['//product/ps1.webp'],
            'with a segment and a way up' => ['product/zz/../ps1.webp'],
            'with a backslash as the separator' => ['product\\ps1.webp'],
            'with a trailing slash' => ['product/ps1.webp/'],
            'with a trailing dot segment' => ['product/ps1.webp/.'],
        ];
    }

    #[DataProvider('pathsOfTheGalleryFile')]
    public function test_every_spelling_of_the_path_of_a_gallery_file_that_two_products_hold_is_shared(string $path): void
    {
        $this->insertProduct(81, ['images' => json_encode(['ps1.webp'])]);
        $this->insertProduct(82, ['images' => json_encode(['ps1.webp'])]);

        $this->assertTrue(SharedProductFile::isShared(path: $path), $path);
    }

    /** @return array<string, array{0: string}> the paths that the disk reads as product/thumbnail/ts1.webp */
    public static function pathsOfTheThumbnail(): array
    {
        return [
            'with two slashes before the name' => ['product/thumbnail//ts1.webp'],
            'with two slashes after product' => ['product//thumbnail/ts1.webp'],
            'with a dot segment before the name' => ['product/thumbnail/./ts1.webp'],
            'with a dot segment before the folder' => ['product/./thumbnail/ts1.webp'],
            'with a trailing dot segment' => ['product/thumbnail/ts1.webp/.'],
            'with backslashes as the separators' => ['product\\thumbnail\\ts1.webp'],
        ];
    }

    #[DataProvider('pathsOfTheThumbnail')]
    public function test_every_spelling_of_the_path_of_a_thumbnail_that_two_products_hold_keeps_the_file(string $path): void
    {
        $this->insertProduct(81, ['thumbnail' => 'ts1.webp']);
        $this->insertProduct(82, ['thumbnail' => 'ts1.webp']);
        $this->fileOnDisk($this->product('thumbnail/ts1.webp'), 'thumbnail');

        ImageManager::delete($path);

        $this->assertFileExists($this->product('thumbnail/ts1.webp'), $path);
    }

    public function test_a_meta_image_and_a_digital_file_are_found_under_every_spelling_of_their_paths_too(): void
    {
        $this->insertProduct(81, ['meta_image' => 'sm1.png', 'digital_file_ready' => 'sd1.zip']);
        $this->insertProduct(82);
        DB::table('product_seos')->insert(['product_id' => 82, 'image' => 'sm1.png']);
        DB::table('digital_product_variations')->insert(['product_id' => 82, 'file' => 'sd1.zip']);

        $this->assertTrue(SharedProductFile::isShared(path: 'product//meta/./sm1.png'));
        $this->assertTrue(SharedProductFile::isShared(path: './product/digital-product//sd1.zip'));
    }

    /** @return array<string, array{0: string}> */
    public static function pathsThatAreNoProductFile(): array
    {
        return [
            'the empty path' => [''],
            'the root' => ['/'],
            'the directory itself' => ['product'],
            'a directory of the same name' => ['productx/ps1.webp'],
            'another case' => ['Product/ps1.webp'],
            'a file of another directory' => ['profile/ps1.webp'],
        ];
    }

    #[DataProvider('pathsThatAreNoProductFile')]
    public function test_a_path_that_is_no_file_of_the_product_directory_is_never_shared(string $path): void
    {
        $this->insertProduct(81, ['images' => json_encode(['ps1.webp'])]);
        $this->insertProduct(82, ['images' => json_encode(['ps1.webp'])]);

        $this->assertFalse(SharedProductFile::isShared(path: $path), $path);
    }

    /** @return list<string> the statements that read the products table while the work runs */
    private function productQueriesDuring(callable $work): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            if (preg_match('/\bfrom [`"]?products[`"]?\b/i', $query->sql) === 1) {
                $statements[] = $query->sql;
            }
        });
        $work();

        return $statements;
    }

    private function fileWriter(): object
    {
        return new class {
            use FileManagerTrait;

            public function remove(string $filePath): array
            {
                return $this->delete($filePath);
            }

            /** @param list<string> $filePaths */
            public function removeAll(array $filePaths): array
            {
                return $this->deleteAll($filePaths);
            }
        };
    }

    public function test_a_file_that_is_not_on_the_disk_costs_no_query_on_the_products_table_in_any_of_the_three_deletes(): void
    {
        $writer = $this->fileWriter();
        $upload = new UploadedFile($this->temporaryImage(), 'new.webp', null, null, true);

        $statements = $this->productQueriesDuring(function () use ($writer, $upload): void {
            ImageManager::delete('/product/thumbnail/ghost.webp');
            $writer->remove('/product/ghost.webp');
            $writer->update(dir: 'product/thumbnail/', oldImage: 'ghost.webp', format: 'webp', image: $upload);
            app(ProductService::class)->deleteImages(product: (new Product())->forceFill(['images' => json_encode(['ghost-a.webp', 'ghost-b.webp']), 'thumbnail' => 'ghost-thumb.webp']));
        });

        $this->assertSame([], $statements);
    }

    public function test_a_file_on_the_disk_is_one_query_on_the_products_table_and_the_files_of_a_product_are_one_query_together(): void
    {
        $images = ['batch-1.webp', 'batch-2.webp', 'batch-3.webp', 'batch-4.webp', 'batch-5.webp', 'batch-6.webp'];
        $this->insertProduct(81, ['thumbnail' => 'batch-thumb.webp', 'images' => json_encode($images)]);
        foreach ([...$images, 'thumbnail/batch-thumb.webp'] as $name) {
            $this->fileOnDisk($this->product($name), $name);
        }

        $product = Product::withoutGlobalScopes()->find(81);

        $single = $this->productQueriesDuring(static fn () => ImageManager::delete('/product/batch-1.webp'));
        $all = $this->productQueriesDuring(fn () => app(ProductService::class)->deleteImages(product: $product));

        $this->assertCount(1, $single);
        $this->assertCount(1, $all, 'the six images and the thumbnail are asked for together');
        foreach ([...$images, 'thumbnail/batch-thumb.webp'] as $name) {
            $this->assertFileDoesNotExist($this->product($name), $name);
        }
    }

    public function test_a_product_that_deletes_its_files_keeps_the_ones_another_product_holds_when_they_are_asked_for_together(): void
    {
        $this->insertProduct(81, ['thumbnail' => 'mixed-thumb.webp', 'images' => json_encode(['mixed-own.webp', 'mixed-shared.webp', 'mixed-colour.webp'])]);
        $this->insertProduct(82, ['thumbnail' => 'mixed-thumb.webp', 'images' => json_encode(['mixed-shared.webp']), 'colors' => json_encode(['FF0000']), 'color_image' => json_encode([['color' => 'FF0000', 'image_name' => 'mixed-colour.webp', 'storage' => 'public']])]);
        foreach (['mixed-own.webp', 'mixed-shared.webp', 'mixed-colour.webp', 'thumbnail/mixed-thumb.webp'] as $name) {
            $this->fileOnDisk($this->product($name), $name);
        }

        app(ProductService::class)->deleteImages(product: Product::withoutGlobalScopes()->find(81));

        $this->assertFileDoesNotExist($this->product('mixed-own.webp'));
        foreach (['mixed-shared.webp', 'mixed-colour.webp', 'thumbnail/mixed-thumb.webp'] as $name) {
            $this->assertFileExists($this->product($name), $name);
        }
    }

    public function test_the_answer_for_a_list_of_paths_names_the_shared_ones_and_only_those(): void
    {
        $this->insertProduct(81, ['thumbnail' => 'list-thumb.webp', 'images' => json_encode(['list-shared.webp', 'list-own.webp'])]);
        $this->insertProduct(82, ['thumbnail' => 'list-thumb.webp', 'images' => json_encode(['list-shared.webp'])]);

        $shared = SharedProductFile::sharedAmong(paths: ['/product/list-shared.webp', 'product/list-own.webp', '/product/thumbnail/list-thumb.webp', '/category/list-shared.webp', 'product/not-held.webp']);

        $this->assertSame(['/product/list-shared.webp', '/product/thumbnail/list-thumb.webp'], $shared);
        $this->assertSame([], SharedProductFile::sharedAmong(paths: []));
    }

    public function test_a_list_of_files_whose_second_holders_are_in_different_later_rows_names_every_shared_file(): void
    {
        $this->insertProduct(81, ['images' => json_encode(['stag-a.webp', 'stag-b.webp'])]);
        $this->insertProduct(82, ['images' => json_encode(['stag-a.webp'])]);
        $this->insertProduct(83, ['images' => json_encode(['stag-b.webp'])]);

        $shared = SharedProductFile::sharedAmong(paths: ['product/stag-a.webp', 'product/stag-b.webp']);

        $this->assertSame(['product/stag-a.webp', 'product/stag-b.webp'], $shared, 'the search goes on until every file has a second holder');
    }

    public function test_a_product_that_deletes_its_files_keeps_every_file_that_another_product_holds_although_each_of_them_has_a_different_other_holder(): void
    {
        $this->insertProduct(81, ['thumbnail' => 'stag-thumb.webp', 'images' => json_encode(['stag-ga.webp', 'stag-gb.webp', 'stag-gc.webp'])]);
        $this->insertProduct(82, ['images' => json_encode(['stag-ga.webp'])]);
        $this->insertProduct(83, ['images' => json_encode(['stag-gb.webp'])]);
        $this->insertProduct(84, ['thumbnail' => 'stag-thumb.webp']);
        foreach (['stag-ga.webp', 'stag-gb.webp', 'stag-gc.webp', 'thumbnail/stag-thumb.webp'] as $name) {
            $this->fileOnDisk($this->product($name), $name);
        }

        app(ProductService::class)->deleteImages(product: Product::withoutGlobalScopes()->find(81));

        $this->assertFileExists($this->product('stag-ga.webp'), 'product 82 holds it');
        $this->assertFileExists($this->product('stag-gb.webp'), 'product 83 holds it');
        $this->assertFileDoesNotExist($this->product('stag-gc.webp'), 'nobody else holds it');
        $this->assertFileExists($this->product('thumbnail/stag-thumb.webp'), 'product 84 holds it');
    }

    public function test_holders_on_both_sides_of_the_boundary_between_two_statements_of_the_search_are_counted(): void
    {
        // 260 products whose names only contain the searched ones: all of them are candidates, the search reads a hundred of them at a time
        DB::table('products')->insert(array_map(static fn (int $number): array => ['id' => 1000 + $number, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'ED' . $number, 'name' => 'Edge ' . $number, 'images' => json_encode(['x-edge-a.webp', 'x-edge-b.webp']), 'colors' => '[]', 'color_image' => '[]'], range(1, 260)));
        // edge-a: the last row of the first statement and the first of the second; edge-b: the first of the second and a row of the third
        DB::table('products')->where('id', 1100)->update(['images' => json_encode(['edge-a.webp'])]);
        DB::table('products')->where('id', 1101)->update(['images' => json_encode(['edge-a.webp', 'edge-b.webp'])]);
        DB::table('products')->where('id', 1250)->update(['images' => json_encode(['edge-b.webp'])]);

        $shared = SharedProductFile::sharedAmong(paths: ['product/edge-a.webp', 'product/edge-b.webp', 'product/edge-c.webp']);

        $this->assertSame(['product/edge-a.webp', 'product/edge-b.webp'], $shared, 'the products that only hold a longer name are no holders');
    }

    public function test_a_file_that_one_product_holds_in_each_statement_of_the_search_is_still_held_by_each_of_them_alone(): void
    {
        DB::table('products')->insert(array_map(static fn (int $number): array => ['id' => 1000 + $number, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'SG' . $number, 'name' => 'Single ' . $number, 'images' => json_encode(['single-' . $number . '.webp']), 'colors' => '[]', 'color_image' => '[]'], range(1, 300)));

        $this->assertSame([], SharedProductFile::sharedAmong(paths: ['product/single-1.webp', 'product/single-150.webp', 'product/single-300.webp']));
    }

    public function test_the_same_file_under_two_spellings_in_one_list_is_answered_for_both_spellings(): void
    {
        $this->insertProduct(81, ['images' => json_encode(['spelled.webp'])]);
        $this->insertProduct(82, ['images' => json_encode(['spelled.webp'])]);

        $shared = SharedProductFile::sharedAmong(paths: ['product/spelled.webp', '/product/./spelled.webp', 'product//spelled.webp']);

        $this->assertSame(['product/spelled.webp', '/product/./spelled.webp', 'product//spelled.webp'], $shared);
    }

    public function test_a_file_in_the_seo_table_counts_as_the_second_holder_when_the_first_is_a_product_of_the_same_list(): void
    {
        $this->insertProduct(81, ['meta_image' => 'seo-listed.png', 'images' => json_encode(['seo-listed-gallery.webp'])]);
        $this->insertProduct(82, ['images' => json_encode(['seo-listed-gallery.webp'])]);
        $this->insertProduct(83);
        DB::table('product_seos')->insert(['product_id' => 83, 'image' => 'seo-listed.png']);

        $shared = SharedProductFile::sharedAmong(paths: ['product/meta/seo-listed.png', 'product/seo-listed-gallery.webp']);

        $this->assertSame(['product/meta/seo-listed.png', 'product/seo-listed-gallery.webp'], $shared);
    }

    public function test_a_long_list_of_files_is_answered_for_every_file_whichever_statement_of_the_search_it_was_in(): void
    {
        $names = [];
        foreach (range(1, 120) as $number) {
            $names[$number] = 'chunk-' . $number . '.webp';
        }
        $isHeldTwice = static fn (int $number): bool => $number % 3 === 0 || $number >= 100;
        $this->insertProduct(81, ['images' => json_encode(array_values($names))]);
        $this->insertProduct(82, ['images' => json_encode(array_values(array_filter($names, static fn (int $number): bool => $number % 3 === 0, ARRAY_FILTER_USE_KEY)))]);
        $this->insertProduct(83, ['images' => json_encode(array_values(array_filter($names, static fn (int $number): bool => $number >= 100, ARRAY_FILTER_USE_KEY)))]);

        $shared = SharedProductFile::sharedAmong(paths: array_map(static fn (string $name): string => 'product/' . $name, array_values($names)));

        $expected = array_map(static fn (string $name): string => 'product/' . $name, array_values(array_filter($names, $isHeldTwice, ARRAY_FILTER_USE_KEY)));
        $this->assertSame($expected, $shared);
    }

    /** @return array<string, array{0: int, 1: int}> the number of files asked about, and the statements on the products table that answer it */
    public static function sizesOfAList(): array
    {
        return ['one file' => [1, 1], 'fifty files' => [50, 1], 'fifty-one files' => [51, 2], 'a hundred files' => [100, 2], 'a hundred and one files' => [101, 3]];
    }

    #[DataProvider('sizesOfAList')]
    public function test_a_list_of_files_is_asked_for_fifty_at_a_time(int $count, int $statementsNeeded): void
    {
        $paths = array_map(static fn (int $number): string => 'product/asked-' . $number . '.webp', range(1, $count));
        $answer = [];

        $statements = $this->productQueriesDuring(function () use ($paths, &$answer): void {
            $answer = SharedProductFile::sharedAmong(paths: $paths);
        });

        $this->assertSame([], $answer);
        $this->assertCount($statementsNeeded, $statements);
    }

    public function test_a_product_with_a_gallery_of_hundreds_of_files_can_be_deleted_and_keeps_the_files_another_product_holds(): void
    {
        $names = array_map(static fn (int $number): string => 'long-gallery-' . $number . '.webp', range(1, 600));
        $this->insertProduct(81, ['thumbnail' => 'long-gallery-thumb.webp', 'images' => json_encode($names)]);
        $this->insertProduct(82, ['images' => json_encode([$names[0], $names[299], $names[599]])]);
        foreach ([...$names, 'thumbnail/long-gallery-thumb.webp'] as $name) {
            $this->fileOnDisk($this->product($name), $name);
        }

        app(ProductService::class)->deleteImages(product: Product::withoutGlobalScopes()->find(81));

        $left = array_map('basename', glob($this->product('long-gallery-*.webp')) ?: []);
        sort($left);
        $this->assertSame(['long-gallery-1.webp', 'long-gallery-300.webp', 'long-gallery-600.webp'], $left, 'the three files that product 82 holds stay, the others go');
        $this->assertFileDoesNotExist($this->product('thumbnail/long-gallery-thumb.webp'));
    }

    public function test_a_product_with_a_gallery_of_objects_deletes_its_files_and_keeps_the_ones_another_product_holds(): void
    {
        $entry = static fn (string $name): array => ['image_name' => $name, 'storage' => 'public'];
        $this->insertProduct(81, ['thumbnail' => 'obj-thumb.webp', 'images' => json_encode([$entry('obj-1.webp'), $entry('obj-2.webp')]), 'colors' => json_encode(['FF0000']), 'color_image' => json_encode([['color' => 'FF0000'] + $entry('obj-1.webp')])]);
        $this->insertProduct(82, ['images' => json_encode([$entry('obj-2.webp')])]);
        foreach (['obj-1.webp', 'obj-2.webp', 'thumbnail/obj-thumb.webp'] as $name) {
            $this->fileOnDisk($this->product($name), $name);
        }

        app(ProductService::class)->deleteImages(product: Product::withoutGlobalScopes()->find(81));

        $this->assertFileDoesNotExist($this->product('obj-1.webp'), 'product 81 holds it in its gallery and as a colour image, and no other product does');
        $this->assertFileExists($this->product('obj-2.webp'), 'product 82 holds it too');
        $this->assertFileDoesNotExist($this->product('thumbnail/obj-thumb.webp'));
    }

    /** @return array<string, array{0: string, 1: string}> a column, and a value that holds the name `shape-held.webp` in a shape that names no image */
    public static function valuesThatHoldTheNameWithoutNamingAnImage(): array
    {
        return [
            'a colour image whose name is a list' => ['color_image', json_encode([['color' => 'FF0000', 'image_name' => ['shape-held.webp']]])],
            'a colour image without a name' => ['color_image', json_encode([['color' => 'FF0000', 'image_name' => null, 'note' => 'shape-held.webp']])],
            'a gallery entry that is a list' => ['images', json_encode([['shape-held.webp']])],
            'a gallery entry that is an object with another key' => ['images', json_encode([['name' => 'shape-held.webp']])],
            'a gallery entry whose name is a list' => ['images', json_encode([['image_name' => ['shape-held.webp']]])],
            'a gallery entry whose name is a number' => ['images', json_encode([['image_name' => 5, 'note' => 'shape-held.webp']])],
            'a gallery entry whose name is true' => ['images', json_encode([['image_name' => true, 'note' => 'shape-held.webp']])],
            'a gallery written as JSON inside JSON' => ['images', json_encode(json_encode(['shape-held.webp']))],
        ];
    }

    #[DataProvider('valuesThatHoldTheNameWithoutNamingAnImage')]
    public function test_a_row_that_holds_the_name_in_a_shape_that_names_no_image_neither_breaks_the_search_nor_counts_as_a_holder(string $column, string $value): void
    {
        $this->insertProduct(81, ['images' => json_encode(['shape-held.webp'])]);
        $this->insertProduct(82, [$column => $value]);

        $this->assertFalse(SharedProductFile::isShared(path: 'product/shape-held.webp'), 'only product 81 holds it');

        $this->insertProduct(83, ['images' => json_encode(['shape-held.webp'])]);

        $this->assertTrue(SharedProductFile::isShared(path: 'product/shape-held.webp'), 'products 81 and 83 hold it, and the search goes on past the row of product 82');
    }

    public function test_a_delete_through_the_trait_forgets_what_the_file_manager_remembers_of_its_files(): void
    {
        $writer = $this->fileWriter();
        foreach (['remove', 'removeAll'] as $method) {
            $this->fileOnDisk($this->product('forget-' . $method . '.webp'), $method);
            foreach (['cache_for_recent_file_list_public', 'cache_for_recent_file_list_s3', 'cache_all_files_for_public_storage'] as $key) {
                Cache::put($key, 'what was listed before the delete');
            }

            $method === 'remove' ? $writer->remove('/product/forget-remove.webp') : $writer->removeAll(['/product/forget-removeAll.webp']);

            $this->assertFileDoesNotExist($this->product('forget-' . $method . '.webp'));
            foreach (['cache_for_recent_file_list_public', 'cache_for_recent_file_list_s3', 'cache_all_files_for_public_storage'] as $key) {
                $this->assertNull(Cache::get($key), $method . ' forgets ' . $key);
            }
        }
    }

    public function test_a_file_that_hundreds_of_products_hold_is_answered_from_plain_rows_and_one_statement(): void
    {
        DB::table('products')->insert(array_map(static fn (int $number): array => ['id' => 1000 + $number, 'added_by' => 'seller', 'user_id' => 7, 'code' => 'PH' . $number, 'name' => 'Imported ' . $number, 'thumbnail' => 'placeholder.png', 'images' => json_encode(['placeholder.png']), 'colors' => '[]', 'color_image' => '[]'], range(1, 250)));
        $hydrated = 0;
        Event::listen('eloquent.retrieved: ' . Product::class, static function () use (&$hydrated): void {
            $hydrated++;
        });

        $answers = [];
        $statements = $this->productQueriesDuring(function () use (&$answers): void {
            $answers = [SharedProductFile::isShared(path: 'product/placeholder.png'), SharedProductFile::isShared(path: 'product/thumbnail/placeholder.png')];
        });

        $this->assertSame([true, true], $answers);
        $this->assertSame(0, $hydrated, 'the rows are read as plain rows, not as products');
        $this->assertCount(2, $statements, 'one statement for each question: the search stops at the second holder');
        foreach ($statements as $statement) {
            $this->assertStringNotContainsString('*', $statement, 'only the columns the check reads');
        }
    }

    public function test_the_folder_of_a_kind_of_product_file_is_no_file_and_costs_no_query(): void
    {
        $this->insertProduct(81, ['images' => json_encode(['thumbnail'])]);
        $this->insertProduct(82, ['images' => json_encode(['thumbnail'])]);
        $answers = [];

        $statements = $this->productQueriesDuring(function () use (&$answers): void {
            $answers = array_map(static fn (string $path): bool => SharedProductFile::isShared(path: $path), ['product/thumbnail', '/product/thumbnail/', 'product/preview/', 'product/meta', 'product/digital-product/']);
        });

        $this->assertSame([false, false, false, false, false], $answers);
        $this->assertSame([], $statements);
    }
}
