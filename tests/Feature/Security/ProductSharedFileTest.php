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
use Illuminate\Support\Facades\DB;
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
}
