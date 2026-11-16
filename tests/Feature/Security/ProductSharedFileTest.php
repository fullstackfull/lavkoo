<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\RestAPI\v3\seller\ProductController as SellerApiProductController;
use App\Http\Controllers\Vendor\Product\ProductController as VendorProductController;
use App\Models\Product;
use App\Models\Seller;
use App\Services\ProductService;
use App\Traits\FileManagerTrait;
use App\Utils\ImageManager;
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
        foreach (['product_seos' => 'image', 'digital_product_variations' => 'file'] as $table => $column) {
            if (!Schema::hasTable($table)) {
                Schema::create($table, static function ($blueprint) use ($column) {
                    $blueprint->id();
                    $blueprint->unsignedBigInteger('product_id');
                    $blueprint->string($column)->nullable();
                });
            }
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
        foreach (['thumbnail', 'preview', 'meta'] as $folder) {
            File::deleteDirectory(base_path(self::DIRECTORY . '/' . $folder));
        }
        File::deleteDirectory(base_path('storage/app/public/category'));
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
}
