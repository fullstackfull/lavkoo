<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Product\ProductController as AdminProductController;
use App\Http\Controllers\RestAPI\v3\seller\ProductController as SellerApiProductController;
use App\Http\Controllers\Vendor\Product\ProductController as VendorProductController;
use App\Models\Admin;
use App\Models\Product;
use App\Services\DigitalProductFileService;
use App\Services\ProductService;
use App\Traits\FileManagerTrait;
use App\Utils\ImageManager;
use App\Utils\SharedProductFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsDigitalFileWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Paid digital products must not be fetchable from a public address. The files used to land on the public disk under a date and a time-based id, so
 * anyone who knew or guessed a name could fetch a file from the storage address, with no code and no payment. With DIGITAL_FILES_PRIVATE on, a new
 * digital file goes to a private disk that the web server does not serve, and a file stored before the switch is still read from the public disk
 * until it is moved. With it off (the default) nothing changes for a shop that already has its files on the public disk.
 */
class DigitalFilesPrivateDiskTest extends TestCase
{
    use BuildsDigitalFileWorld;
    use RestoresLanguageFiles;

    private const PRIVATE = 'digital_private';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildDigitalFileWorld();
        $this->withoutTheShellOfTheWeb();
        DB::table('orders')->insert(['id' => 1, 'customer_id' => 5, 'payment_status' => 'paid', 'seller_id' => 7, 'seller_is' => 'seller']);
        DB::table('order_details')->insert(['id' => 1, 'order_id' => 1, 'product_id' => 5, 'seller_id' => 7, 'payment_status' => 'paid']);
        DB::table('products')->insert(['id' => 5, 'user_id' => 7, 'added_by' => 'seller', 'code' => 'GUIDE123', 'name' => 'Guide', 'product_type' => 'digital', 'digital_product_type' => 'ready_product', 'images' => '[]', 'colors' => '[]', 'color_image' => '[]', 'thumbnail' => 't.webp']);
    }

    protected function tearDown(): void
    {
        $this->removeDigitalDisks();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- the switch and the disk -------------------------------------------------------------------------------------------------

    public function test_the_private_mode_ships_off(): void
    {
        $this->assertFalse(config('filesystems.digital_files_private'));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function valuesOfTheSwitch(): array
    {
        return [
            'true' => ['true', true], 'one' => ['1', true], 'on' => ['on', true], 'yes' => ['yes', true],
            'false' => ['false', false], 'zero' => ['0', false], 'off' => ['off', false], 'no' => ['no', false], 'a typo' => ['ture', false], 'empty' => ['', false],
        ];
    }

    #[DataProvider('valuesOfTheSwitch')]
    public function test_the_switch_is_read_as_a_boolean_and_only_a_clear_yes_turns_it_on(string $value, bool $expected): void
    {
        $_SERVER['DIGITAL_FILES_PRIVATE'] = $value;
        try {
            $filesystems = require base_path('config/filesystems.php');
        } finally {
            unset($_SERVER['DIGITAL_FILES_PRIVATE']);
        }

        $this->assertSame($expected, $filesystems['digital_files_private'], $value);
    }

    public function test_the_private_disk_is_a_local_folder_below_storage_that_is_neither_served_nor_linked(): void
    {
        $disk = config('filesystems.disks.' . self::PRIVATE);

        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/private/digital'), $disk['root']);
        $this->assertSame('private', $disk['visibility'], 'the files are not readable by other users of the machine');
        $this->assertArrayNotHasKey('url', $disk, 'no address is built for a file of the disk');
        $this->assertSame([], array_filter((array) config('filesystems.links', []), static fn (string $target): bool => str_contains($target, 'app/private')), 'nothing in public/ links to it');
    }

    public function test_the_web_server_of_a_shop_served_from_the_application_root_is_told_not_to_serve_the_private_disk(): void
    {
        $rules = (string) file_get_contents(base_path('.htaccess'));

        $this->assertMatchesRegularExpression('#^\s*RewriteRule\s+\^storage/app/private\S*\s+-\s+\[F,L\]\s*$#m', $rules);
        $this->assertLessThan(strpos($rules, 'index.php'), strpos($rules, 'storage/app/private'), 'the rule is read before any request is handed to the files or to the front controller');
    }

    // ---- where a new file goes ---------------------------------------------------------------------------------------------------

    private function sellerApp(string $version): string
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . self::SELLER_TOKEN)->postJson("/api/{$version}/seller/products/upload-digital-product", ['digital_file_ready' => $this->pdf()]);

        return $response->assertOk()->json('digital_file_ready_name');
    }

    private function afterSaleLine(): string
    {
        return (string) DB::table('order_details')->where('id', 1)->value('digital_file_after_sell');
    }

    private function afterSale(string $version): string
    {
        $this->withHeader('Authorization', 'Bearer ' . self::SELLER_TOKEN)->putJson("/api/{$version}/seller/orders/order-wise-product-upload", ['order_id' => 1, 'digital_file_after_sell' => $this->pdf()])->assertOk();

        return $this->afterSaleLine();
    }

    private function adminPanelAfterSale(): string
    {
        $this->actingAs(Admin::findOrFail(1), 'admin')->post('/admin/orders/digital-file-upload-after-sell', ['order_id' => 1, 'digital_file_after_sell' => $this->pdf()])->assertRedirect();

        return $this->afterSaleLine();
    }

    private function vendorPanelAfterSale(): string
    {
        $this->actingAs($this->sellerOfTheToken(), 'seller')->post('/vendor/orders/digital-file-upload-after-sell', ['order_id' => 1, 'digital_file_after_sell' => $this->pdf()])->assertRedirect();

        return $this->afterSaleLine();
    }

    /** @return array<string, string> the fields of the product forms of the panels */
    private function productFields(): array
    {
        return [
            'name' => ['Guide'], 'lang' => ['en'], 'description' => ['A guide'], 'code' => 'GUIDE123', 'category_id' => 1, 'product_type' => 'digital', 'digital_product_type' => 'ready_product',
            'unit_price' => 10, 'discount' => 0, 'discount_type' => 'percent', 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude', 'minimum_order_qty' => 1, 'current_stock' => 1,
        ];
    }

    private function productService(): ProductService
    {
        $this->actingAs($this->sellerOfTheToken(), 'seller');

        return app(ProductService::class);
    }

    private function productFormAdd(): string
    {
        $request = Request::create('/', 'POST', $this->productFields(), [], ['digital_file_ready' => $this->pdf()]);

        return $this->productService()->getAddProductData(request: $request, addedBy: 'seller', shopId: 1)['digital_file_ready'];
    }

    private function productFormUpdate(): string
    {
        $request = Request::create('/', 'POST', $this->productFields(), [], ['digital_file_ready' => $this->pdf()]);

        return $this->productService()->getUpdateProductData(request: $request, product: Product::withoutGlobalScopes()->find(5), updateBy: 'seller')['digital_file_ready'];
    }

    /** The fields of the panels' forms for one digital variation (`Format` `PDF`), which carry the file as `digital_files[format_pdf]`. */
    private function variationRequest(): Request
    {
        return Request::create('/', 'POST', $this->productFields() + [
            'extensions_type' => ['Format'], 'extensions_options_Format' => ['PDF'],
            'digital_product_variant_key' => ['format_pdf' => 'Format-PDF'], 'digital_product_sku' => ['format_pdf' => 'SKU1'], 'digital_product_price' => ['format_pdf' => 5],
        ], [], ['digital_files' => ['format_pdf' => $this->pdf()]]);
    }

    private function storedVariation(): string
    {
        return (string) DB::table('digital_product_variations')->latest('id')->value('file');
    }

    private function productFormVariation(): string
    {
        return $this->productService()->getAddProductDigitalVariationData(request: $this->variationRequest(), product: Product::withoutGlobalScopes()->find(5))[0]['file'];
    }

    private function adminPanelVariation(): string
    {
        app(AdminProductController::class)->getDigitalProductUpdateProcess($this->variationRequest(), Product::withoutGlobalScopes()->find(5));

        return $this->storedVariation();
    }

    private function vendorPanelVariation(): string
    {
        app(VendorProductController::class)->getDigitalProductUpdateProcess($this->variationRequest(), Product::withoutGlobalScopes()->find(5));

        return $this->storedVariation();
    }

    /** The seller app v3 sends its lists as JSON text, and each file as `digital_files_<key>`. */
    private function sellerAppVariationRequest(): Request
    {
        return Request::create('/', 'POST', $this->productFields() + [
            'extensions_type' => json_encode(['Format']), 'extensions_options_Format' => json_encode(['PDF']),
            'digital_product_variant_key' => json_encode(['format_pdf' => 'Format-PDF']), 'digital_product_sku' => json_encode(['format_pdf' => 'SKU1']), 'digital_product_price' => json_encode(['format_pdf' => 5]),
        ], [], ['digital_files_format_pdf' => $this->pdf()]);
    }

    private function sellerAppVariationOnUpdate(): string
    {
        app(SellerApiProductController::class)->getDigitalProductUpdateProcess($this->sellerAppVariationRequest(), Product::withoutGlobalScopes()->find(5));

        return $this->storedVariation();
    }

    private function sellerAppVariationOnAdd(): string
    {
        return app(SellerApiProductController::class)->getAddProductDigitalVariationData($this->sellerAppVariationRequest(), Product::withoutGlobalScopes()->find(5))[0]['file'];
    }

    /** @return array<string, array{0: string}> every place that writes a digital file */
    public static function writers(): array
    {
        return [
            'the seller app v3, upload of the product file' => ['sellerAppV3Upload'],
            'the seller app v2, upload of the product file' => ['sellerAppV2Upload'],
            'the seller app v3, file after the sale' => ['sellerAppV3AfterSale'],
            'the seller app v2, file after the sale' => ['sellerAppV2AfterSale'],
            'the admin panel, file after the sale' => ['adminPanelAfterSale'],
            'the vendor panel, file after the sale' => ['vendorPanelAfterSale'],
            'the product form, a new product' => ['productFormAdd'],
            'the product form, an update of the file' => ['productFormUpdate'],
            'the product form, a new variation' => ['productFormVariation'],
            'the admin panel, a digital variation' => ['adminPanelVariation'],
            'the vendor panel, a digital variation' => ['vendorPanelVariation'],
            'the seller app v3, a digital variation on update' => ['sellerAppVariationOnUpdate'],
            'the seller app v3, a digital variation on add' => ['sellerAppVariationOnAdd'],
        ];
    }

    private function write(string $writer): string
    {
        return match ($writer) {
            'sellerAppV3Upload' => $this->sellerApp(version: 'v3'),
            'sellerAppV2Upload' => $this->sellerApp(version: 'v2'),
            'sellerAppV3AfterSale' => $this->afterSale(version: 'v3'),
            'sellerAppV2AfterSale' => $this->afterSale(version: 'v2'),
            default => $this->{$writer}(),
        };
    }

    #[DataProvider('writers')]
    public function test_with_the_mode_off_every_writer_stores_the_file_on_the_public_disk_as_before(string $writer): void
    {
        $this->useDigitalDisks(private: false);

        $name = $this->write($writer);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-[0-9a-f]+\.pdf$/', $name);
        Storage::disk('public')->assertExists(self::DIRECTORY . $name);
        Storage::disk(self::PRIVATE)->assertMissing(self::DIRECTORY . $name);
        $this->assertSame(200, $this->publicAddressStatus($name), 'the address of the file answers, as it always did');
    }

    #[DataProvider('writers')]
    public function test_with_the_mode_on_every_writer_stores_the_file_on_the_private_disk_and_nowhere_else(string $writer): void
    {
        $this->useDigitalDisks(private: true);

        $name = $this->write($writer);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-[0-9a-f]+\.pdf$/', $name, 'the name is the one the platform always generated');
        Storage::disk(self::PRIVATE)->assertExists(self::DIRECTORY . $name);
        Storage::disk('public')->assertMissing(self::DIRECTORY . $name);
        $this->assertSame(404, $this->publicAddressStatus($name), 'the address of the file answers nothing');
    }

    public function test_with_the_mode_off_the_private_disk_is_never_touched_not_even_to_look(): void
    {
        $root = sys_get_temp_dir() . '/digital-private-never-' . getmypid();
        $this->useDigitalDisks(private: false);
        config(['filesystems.disks.' . self::PRIVATE . '.root' => $root]);
        Storage::forgetDisk(self::PRIVATE);
        $this->putOnPublicDisk('2026-11-16-old.pdf');

        $this->sellerApp(version: 'v3');
        $this->afterSale(version: 'v3');
        $this->afterSale(version: 'v2');
        ImageManager::delete('product/digital-product/2026-11-16-old.pdf');
        ImageManager::delete('product/digital-product/2026-11-16-gone.pdf');
        $this->deleteThroughTheTrait('product/digital-product/2026-11-16-gone.pdf');

        $this->assertDirectoryDoesNotExist($root);
    }

    // ---- where a file is read ----------------------------------------------------------------------------------------------------

    public function test_while_the_mode_is_on_a_file_is_read_from_the_private_disk_first_and_then_from_the_public_one(): void
    {
        $this->useDigitalDisks(private: true);
        $service = app(DigitalProductFileService::class);
        $this->putOnPrivateDisk('2026-11-16-new.pdf');
        $this->putOnPublicDisk('2026-11-16-old.pdf');
        $this->putOnPrivateDisk('2026-11-16-both.pdf', 'private copy');
        $this->putOnPublicDisk('2026-11-16-both.pdf', 'public copy');

        $this->assertSame(self::PRIVATE, $service->diskHolding(name: '2026-11-16-new.pdf'));
        $this->assertSame('public', $service->diskHolding(name: '2026-11-16-old.pdf'), 'a file stored before the switch is read until it is moved');
        $this->assertSame(self::PRIVATE, $service->diskHolding(name: '2026-11-16-both.pdf'));
        $this->assertNull($service->diskHolding(name: '2026-11-16-nowhere.pdf'));
        $this->assertTrue($service->exists(name: '2026-11-16-old.pdf'));
        $this->assertFalse($service->exists(name: '2026-11-16-nowhere.pdf'));
    }

    public function test_while_the_mode_is_off_only_the_public_disk_is_read(): void
    {
        $this->useDigitalDisks(private: false);
        $service = app(DigitalProductFileService::class);
        $this->putOnPrivateDisk('2026-11-16-new.pdf');
        $this->putOnPublicDisk('2026-11-16-old.pdf');

        $this->assertNull($service->diskHolding(name: '2026-11-16-new.pdf'));
        $this->assertSame('public', $service->diskHolding(name: '2026-11-16-old.pdf'));
    }

    /** @return array<string, array{0: ?string}> */
    public static function namesThatAreNoFileOfTheFolder(): array
    {
        return [
            'a way up' => ['../../../../.env'], 'a path' => ['sub/guide.pdf'], 'a backslash path' => ['..\\guide.pdf'], 'a null byte' => ["guide.pdf\0.png"],
            'the folder itself' => ['.'], 'empty' => [''], 'nothing' => [null],
        ];
    }

    #[DataProvider('namesThatAreNoFileOfTheFolder')]
    public function test_a_name_that_is_no_plain_file_name_is_found_on_no_disk(?string $name): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk('guide.pdf');
        Storage::disk(self::PRIVATE)->put('secret.txt', 'outside the folder');

        $service = app(DigitalProductFileService::class);

        $this->assertNull($service->diskHolding(name: $name));
        $this->assertFalse($service->exists(name: $name));
        $this->assertNull($service->download(name: $name));
    }

    // ---- deleting and replacing a file that is on the private disk -----------------------------------------------------------------

    private function deleteThroughTheTrait(string $path): void
    {
        $writer = new class {
            use FileManagerTrait {
                delete as public;
            }
        };
        $writer->delete(filePath: $path);
    }

    /** @return array<string, array{0: string}> the paths the code asks to delete: with and without the slash in front of them */
    public static function pathsOfTheFile(): array
    {
        return ['without a slash in front' => ['product/digital-product/2026-11-16-guide.pdf'], 'with a slash in front' => ['/product/digital-product/2026-11-16-guide.pdf']];
    }

    #[DataProvider('pathsOfTheFile')]
    public function test_a_file_that_is_only_on_the_private_disk_is_deleted_by_the_helper_of_the_seller_app_v2(string $path): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk('2026-11-16-guide.pdf');

        ImageManager::delete($path);

        Storage::disk(self::PRIVATE)->assertMissing(self::DIRECTORY . '2026-11-16-guide.pdf');
    }

    #[DataProvider('pathsOfTheFile')]
    public function test_a_file_that_is_only_on_the_private_disk_is_deleted_by_the_trait_of_the_panels(string $path): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk('2026-11-16-guide.pdf');

        $this->deleteThroughTheTrait($path);

        Storage::disk(self::PRIVATE)->assertMissing(self::DIRECTORY . '2026-11-16-guide.pdf');
    }

    public function test_a_file_that_is_on_both_disks_is_deleted_from_both_so_no_public_copy_of_a_deleted_file_stays_reachable(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk('2026-11-16-guide.pdf');
        $this->putOnPublicDisk('2026-11-16-guide.pdf');
        $this->putOnPrivateDisk('2026-11-16-other.pdf');
        $this->putOnPublicDisk('2026-11-16-other.pdf');

        ImageManager::delete('product/digital-product/2026-11-16-guide.pdf');
        $this->deleteThroughTheTrait('product/digital-product/2026-11-16-other.pdf');

        foreach (['2026-11-16-guide.pdf', '2026-11-16-other.pdf'] as $name) {
            Storage::disk(self::PRIVATE)->assertMissing(self::DIRECTORY . $name);
            Storage::disk('public')->assertMissing(self::DIRECTORY . $name);
        }
    }

    public function test_a_file_of_another_folder_that_happens_to_share_a_name_is_not_taken_for_a_digital_file(): void
    {
        $this->useDigitalDisks(private: true);
        Storage::disk(self::PRIVATE)->put('product/thumbnail/2026-11-16-guide.pdf', 'not a digital file');

        ImageManager::delete('product/thumbnail/2026-11-16-guide.pdf');

        Storage::disk(self::PRIVATE)->assertExists('product/thumbnail/2026-11-16-guide.pdf');
    }

    public function test_replacing_the_file_after_the_sale_deletes_the_old_file_of_the_private_disk_and_stores_the_new_one_there(): void
    {
        $this->useDigitalDisks(private: true);

        foreach (['sellerAppV3AfterSale', 'adminPanelAfterSale', 'vendorPanelAfterSale', 'sellerAppV2AfterSale'] as $writer) {
            $before = $this->afterSaleLine();
            $name = $this->write($writer);

            $this->assertNotSame($before, $name, $writer);
            Storage::disk(self::PRIVATE)->assertExists(self::DIRECTORY . $name);
            if ($before !== '') {
                Storage::disk(self::PRIVATE)->assertMissing(self::DIRECTORY . $before);
            }
        }
        $this->assertCount(1, Storage::disk(self::PRIVATE)->allFiles(), 'one file is left: the last one');
    }

    public function test_replacing_the_file_of_a_product_after_the_switch_moves_it_to_the_private_disk_and_deletes_the_public_one(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPublicDisk('2026-11-16-before-the-switch.pdf');
        DB::table('products')->where('id', 5)->update(['digital_file_ready' => '2026-11-16-before-the-switch.pdf']);

        $name = $this->productFormUpdate();

        Storage::disk(self::PRIVATE)->assertExists(self::DIRECTORY . $name);
        Storage::disk('public')->assertMissing(self::DIRECTORY . '2026-11-16-before-the-switch.pdf');
    }

    /** @return array<string, array{0: string, 1: string}> the panel, the writer of its own variation, and the address that removes a variation's file */
    public static function panelsThatRemoveAVariationFile(): array
    {
        return [
            'the admin panel' => ['admin', 'adminPanelVariation', '/admin/products/digital-variation-file-delete'],
            'the vendor panel' => ['seller', 'vendorPanelVariation', '/vendor/products/digital-variation-file-delete'],
        ];
    }

    #[DataProvider('panelsThatRemoveAVariationFile')]
    public function test_the_panels_remove_the_file_of_a_variation_from_the_private_disk(string $guard, string $writer, string $url): void
    {
        $this->useDigitalDisks(private: true);
        $name = $this->write($writer);

        $this->actingAs($guard === 'admin' ? Admin::findOrFail(1) : $this->sellerOfTheToken(), $guard)
            ->postJson($url, ['product_id' => 5, 'variant_key' => 'Format-PDF'])
            ->assertJsonPath('status', 1);

        Storage::disk(self::PRIVATE)->assertMissing(self::DIRECTORY . $name);
        $this->assertNull(DB::table('digital_product_variations')->value('file'));
    }

    // ---- the rule of the files that more than one product holds ----------------------------------------------------------------------

    public function test_a_file_that_two_products_hold_stays_on_the_private_disk_when_one_of_them_lets_go_of_it(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk('2026-11-16-shared.pdf');
        DB::table('products')->where('id', 5)->update(['digital_file_ready' => '2026-11-16-shared.pdf']);
        DB::table('products')->insert(['id' => 6, 'user_id' => 7, 'added_by' => 'seller', 'code' => 'COPY', 'name' => 'Copy', 'digital_file_ready' => '2026-11-16-shared.pdf', 'images' => '[]', 'colors' => '[]', 'color_image' => '[]']);

        ImageManager::delete('product/digital-product/2026-11-16-shared.pdf');
        $this->deleteThroughTheTrait('/product/digital-product/2026-11-16-shared.pdf');
        app(DigitalProductFileService::class)->replace(oldName: '2026-11-16-shared.pdf', file: $this->pdf());

        Storage::disk(self::PRIVATE)->assertExists(self::DIRECTORY . '2026-11-16-shared.pdf');
    }

    public function test_the_last_holder_that_lets_go_of_a_private_file_deletes_it(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk('2026-11-16-alone.pdf');
        DB::table('products')->where('id', 5)->update(['digital_file_ready' => '2026-11-16-alone.pdf']);

        app(DigitalProductFileService::class)->replace(oldName: '2026-11-16-alone.pdf', file: $this->pdf());

        Storage::disk(self::PRIVATE)->assertMissing(self::DIRECTORY . '2026-11-16-alone.pdf');
    }

    public function test_the_shared_file_rule_counts_the_holders_of_a_name_whichever_disk_the_file_is_on(): void
    {
        $this->useDigitalDisks(private: true);
        $this->putOnPrivateDisk('2026-11-16-by-product.pdf');
        $this->putOnPrivateDisk('2026-11-16-by-variation.pdf');
        $this->putOnPublicDisk('2026-11-16-before-the-switch.pdf');
        DB::table('products')->insert([
            ['id' => 6, 'digital_file_ready' => '2026-11-16-by-product.pdf'], ['id' => 7, 'digital_file_ready' => '2026-11-16-by-product.pdf'],
            ['id' => 8, 'digital_file_ready' => '2026-11-16-before-the-switch.pdf'], ['id' => 9, 'digital_file_ready' => '2026-11-16-before-the-switch.pdf'],
        ]);
        DB::table('digital_product_variations')->insert([['product_id' => 5, 'file' => '2026-11-16-by-variation.pdf'], ['product_id' => 6, 'file' => '2026-11-16-by-variation.pdf']]);

        $shared = SharedProductFile::sharedAmong(paths: [
            'product/digital-product/2026-11-16-by-product.pdf', 'product/digital-product/2026-11-16-by-variation.pdf', 'product/digital-product/2026-11-16-before-the-switch.pdf', 'product/digital-product/2026-11-16-alone.pdf',
        ]);

        $this->assertSame(['product/digital-product/2026-11-16-by-product.pdf', 'product/digital-product/2026-11-16-by-variation.pdf', 'product/digital-product/2026-11-16-before-the-switch.pdf'], $shared);
    }
}
