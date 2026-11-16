<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\RestAPI\v2\seller\ProductController as ProductControllerV2;
use App\Http\Controllers\RestAPI\v3\seller\ProductController as ProductControllerV3;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A seller's product add and update take the names of images and files that were uploaded earlier. The names are stored, and the platform
 * reads, downloads and deletes the files they name, so a name that is a path is refused where it comes in.
 */
class SellerProductFileNamesTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        foreach (['with_vat', 'no_vat'] as $kind) {
            Cache::put('tax_system_type_vendor_' . $kind, ['SystemTaxVat' => null, 'SystemTaxVatType' => 'order_wise', 'is_included' => 0, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect()], 60);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** The fields of the seller app v3, which sends its lists as JSON text. */
    private function v3Fields(array $overrides): array
    {
        $seller = new Seller();
        $seller->id = 7;

        return $overrides + [
            'seller' => $seller,
            'name' => json_encode(['Guide']), 'description' => json_encode(['A guide']), 'category_id' => 1, 'product_type' => 'physical', 'unit' => 'pc', 'lang' => json_encode(['en']),
            'images' => json_encode([['image_name' => '2026-11-16-a.webp', 'storage' => 'public']]),
            'thumbnail' => '2026-11-16-thumb.webp',
            'discount_type' => 'percent', 'unit_price' => 10, 'discount' => 0, 'shipping_cost' => 0,
            'code' => 'GUIDE123', 'minimum_order_qty' => 1,
            'colors' => '[]', 'color_image' => '[]', 'tags' => '[]', 'choice' => '[]', 'choice_no' => '[]', 'choice_attributes' => '[]',
        ];
    }

    /** The fields of the seller app v2, which sends its lists as arrays and is known by a bearer token. */
    private function v2Request(array $overrides): Request
    {
        $request = Request::create('/api/v2/seller/products/add', 'POST', $overrides + [
            'name' => ['Guide'], 'description' => ['A guide'], 'category_id' => 1, 'product_type' => 'physical', 'unit' => 'pc', 'lang' => ['en'],
            'images' => ['2026-11-16-a.webp'], 'thumbnail' => '2026-11-16-thumb.webp', 'tax' => 0,
            'discount_type' => 'percent', 'unit_price' => 10, 'purchase_price' => 5, 'discount' => 0, 'shipping_cost' => 0,
            'code' => '123456789', 'minimum_order_qty' => 1,
        ]);
        $request->headers->set('authorization', 'Bearer ' . self::TOKEN);

        return $request;
    }

    /** @return array<string, array{0: string, 1: mixed}> the field that carries a name, and what it holds instead: a path, or a list or an object that holds no name */
    public static function fieldsThatCarryAName(): array
    {
        return [
            'the thumbnail' => ['thumbnail', '../../../../../.env'],
            'the images, as the seller app v3 sends them' => ['images', json_encode([['image_name' => '../shop/banner/victim-banner.webp', 'storage' => 'public']])],
            'the digital file' => ['digital_file_ready', '../../../../../.env'],
            'the meta image' => ['meta_image', '../../.env'],
            'the colour images' => ['color_image', json_encode([['color' => '#ff0000', 'image_name' => '../../.env', 'storage' => 'public']])],
            'the thumbnail as a list that holds a list' => ['thumbnail', json_encode([['2026-11-16-a.webp']])],
            'the digital file as an object that holds an object' => ['digital_file_ready', json_encode(['a' => ['b' => 1]])],
            'the meta image as an entry that has no image name' => ['meta_image', json_encode([['color' => '#ff0000']])],
        ];
    }

    #[DataProvider('fieldsThatCarryAName')]
    public function test_the_seller_app_v3_add_refuses_a_name_that_is_a_path(string $field, mixed $value): void
    {
        $response = app(ProductControllerV3::class)->add_new(Request::create('/api/v3/seller/products/add', 'POST', $this->v3Fields([$field => $value])));

        $this->assertSame(403, $response->status());
        $this->assertStringContainsString($field, $response->getContent());
    }

    #[DataProvider('fieldsThatCarryAName')]
    public function test_the_seller_app_v3_update_refuses_a_name_that_is_a_path(string $field, mixed $value): void
    {
        foreach (['digital_product_variations', 'product_seos'] as $relatedTable) {
            Schema::create($relatedTable, function ($table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->nullable();
            });
        }
        DB::table('products')->insert(['id' => 5, 'user_id' => 7, 'added_by' => 'seller', 'code' => 'GUIDE123', 'name' => 'Guide', 'thumbnail' => 'a.webp', 'images' => '[]']);

        $response = app(ProductControllerV3::class)->updateProduct(Request::create('/api/v3/seller/products/update/5', 'PUT', $this->v3Fields([$field => $value])), 5);

        $this->assertSame(403, $response->status());
        $this->assertStringContainsString($field, $response->getContent());
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function v2FieldsThatCarryAName(): array
    {
        return [
            'the thumbnail' => ['thumbnail', '../../seller/victim-avatar.png'],
            'the images' => ['images', ['x.webp', '../shop/banner/victim-banner.webp']],
            'the digital file' => ['digital_file_ready', '../../../../../.env'],
            'the meta image' => ['meta_image', '../../.env'],
            'the thumbnail as a list that holds a list' => ['thumbnail', [['2026-11-16-a.webp']]],
            'the digital file as an entry that has no image name' => ['digital_file_ready', [['color' => '#ff0000']]],
        ];
    }

    #[DataProvider('v2FieldsThatCarryAName')]
    public function test_the_seller_app_v2_add_refuses_a_name_that_is_a_path(string $field, mixed $value): void
    {
        $this->seedSellerOfTheV2Token();

        $response = app(ProductControllerV2::class)->add_new($this->v2Request([$field => $value]));

        $this->assertStringContainsString($field, $response->getContent());
        $this->assertSame(0, DB::table('products')->count(), 'nothing was stored');
    }

    #[DataProvider('v2FieldsThatCarryAName')]
    public function test_the_seller_app_v2_update_refuses_a_name_that_is_a_path(string $field, mixed $value): void
    {
        $this->seedSellerOfTheV2Token();
        DB::table('products')->insert(['id' => 5, 'user_id' => 7, 'added_by' => 'seller', 'code' => '123456789', 'name' => 'Guide', 'thumbnail' => 'a.webp', 'images' => '[]']);

        $response = app(ProductControllerV2::class)->update($this->v2Request([$field => $value]), 5);

        $this->assertStringContainsString($field, $response->getContent());
        $this->assertSame('a.webp', DB::table('products')->where('id', 5)->value('thumbnail'));
    }

    private function seedSellerOfTheV2Token(): void
    {
        Schema::create('sellers', function ($table) {
            $table->id();
            $table->string('auth_token')->nullable();
            $table->string('status')->nullable();
            $table->softDeletes();
        });
        DB::table('sellers')->insert(['id' => 7, 'auth_token' => self::TOKEN, 'status' => 'approved']);
        DB::table('business_settings')->insert(['type' => 'product_brand', 'value' => '0']);
    }
}
