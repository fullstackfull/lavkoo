<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * The seller apps reach a product or a review by an id the app sends, in thirteen handlers: the v3 app's edit, update, delete, review list,
 * barcode, review status and review reply, and the older v2 app's edit, update, status update, delete, barcode and review status. The
 * permission middleware only says that a seller may manage products, so each handler must answer only for the seller who owns the row.
 *
 * Every handler is tried against the three rows a seller can name: the seller's own, another seller's, and the in-house shop's, which
 * carries the seller's id as its user_id (added_by 'admin'). The other two are not found, are not described by the answer, and nothing in
 * the catalogue changes, not even by a request that is valid in every other respect; the own row works as it did.
 *
 * The fixture is complete enough for every handler to run to its end, so that a handler which forgets the check shows as the row it
 * served or wrote and not as a crash on a missing table.
 */
class SellerProductOwnershipTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const OWN_PRODUCT = 10;
    private const OWN_PRODUCT_WITHOUT_CODE = 11;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;

    private const OWN_REVIEW = 100;
    private const OTHER_SELLERS_REVIEW = 200;
    private const IN_HOUSE_REVIEW = 300;

    private const PRODUCT_HANDLERS = [
        'v3 edit', 'v3 update', 'v3 delete', 'v3 review list', 'v3 barcode',
        'v2 edit', 'v2 update', 'v2 status update', 'v2 delete', 'v2 barcode',
    ];
    private const REVIEW_HANDLERS = ['v3 review status', 'v3 review reply', 'v2 review status'];
    private const V2_HANDLERS = ['v2 edit', 'v2 update', 'v2 status update', 'v2 delete', 'v2 barcode', 'v2 review status'];

    private bool $createdTheBarcodeDirectory = false;
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->buildSellerApiWorld();
        $this->createCatalogueTables();
        $this->seedCatalogue();
        $this->prepareTheBarcodeDirectory();
    }

    protected function tearDown(): void
    {
        $this->removeTheBarcodeFile();
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- what the seller cannot reach ----

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function productsThatAreNotTheSellers(): iterable
    {
        foreach (self::PRODUCT_HANDLERS as $handler) {
            yield $handler . ', another seller\'s product' => [$handler, self::OTHER_SELLERS_PRODUCT];
            yield $handler . ', the in-house product sharing the seller\'s id' => [$handler, self::IN_HOUSE_PRODUCT];
        }
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function reviewsThatAreNotTheSellers(): iterable
    {
        foreach (self::REVIEW_HANDLERS as $handler) {
            yield $handler . ', a review of another seller\'s product' => [$handler, self::OTHER_SELLERS_REVIEW];
            yield $handler . ', a review of the in-house product sharing the seller\'s id' => [$handler, self::IN_HOUSE_REVIEW];
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function olderAppHandlers(): iterable
    {
        foreach (self::V2_HANDLERS as $handler) {
            yield $handler => [$handler];
        }
    }

    #[DataProvider('productsThatAreNotTheSellers')]
    public function test_a_product_that_is_not_the_sellers_is_not_found_not_described_and_not_changed(string $handler, int $productId): void
    {
        $before = $this->theCatalogue();
        $name = $this->nameOf($productId);

        $response = $this->attempt($handler, $productId);

        $this->assertNotFound($response, code: 'product');
        $this->assertStringNotContainsString($name, (string) $response->getContent());
        $this->assertSame($before, $this->theCatalogue());
    }

    #[DataProvider('reviewsThatAreNotTheSellers')]
    public function test_a_review_of_a_product_that_is_not_the_sellers_is_not_found_and_not_changed(string $handler, int $reviewId): void
    {
        $before = $this->theCatalogue();

        $response = $this->attempt($handler, $reviewId);

        $this->assertNotFound($response, code: 'review');
        $this->assertSame($before, $this->theCatalogue());
    }

    #[DataProvider('olderAppHandlers')]
    public function test_the_older_app_refuses_a_request_without_a_seller_token_and_changes_nothing(string $handler): void
    {
        $before = $this->theCatalogue();
        $target = str_contains($handler, 'review') ? self::OWN_REVIEW : self::OWN_PRODUCT_WITHOUT_CODE;

        $response = $this->attempt($handler, $target, as: null);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame($before, $this->theCatalogue());
    }

    // ---- what the seller can still do: API v3 ----

    public function test_v3_the_seller_can_still_edit_their_own_product(): void
    {
        $response = $this->attempt('v3 edit', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_PRODUCT, 'Own product'], [$response->json('id'), $response->json('name')]);
    }

    public function test_v3_the_seller_can_still_update_their_own_product(): void
    {
        $response = $this->attempt('v3 update', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Updated name', self::SELLER, 'seller'], $this->nameOwnerAndAdderOf(self::OWN_PRODUCT));
        $this->assertSame(['Other sellers product', self::OTHER_SELLER, 'seller'], $this->nameOwnerAndAdderOf(self::OTHER_SELLERS_PRODUCT));
    }

    public function test_v3_the_seller_can_still_delete_their_own_product(): void
    {
        $response = $this->attempt('v3 delete', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_PRODUCT_WITHOUT_CODE, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT], $this->productIds());
    }

    public function test_v3_the_seller_can_still_list_the_reviews_of_their_own_product(): void
    {
        $response = $this->attempt('v3 review list', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_REVIEW], array_column($response->json('reviews'), 'id'));
    }

    public function test_v3_the_seller_can_still_generate_the_barcodes_of_their_own_product(): void
    {
        $response = $this->attempt('v3 barcode', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith(url('/api/v3/seller/products/barcode/sheet?'), $response->json());
        $this->assertFileDoesNotExist(storage_path('app/public/product/barcode.pdf'));
    }

    public function test_v3_the_seller_can_still_approve_a_review_of_their_own_product(): void
    {
        $response = $this->attempt('v3 review status', self::OWN_REVIEW);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1, 0, 0], $this->reviewStatuses());
    }

    public function test_v3_the_seller_can_still_reply_to_a_review_of_their_own_product(): void
    {
        $response = $this->attempt('v3 review reply', self::OWN_REVIEW);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([[self::OWN_REVIEW, 'Thanks']], array_map(fn ($reply) => [(int) $reply->review_id, $reply->reply_text], DB::table('review_replies')->get()->all()));
    }

    // ---- what the seller can still do: API v2 ----

    public function test_v2_the_seller_can_still_edit_their_own_product(): void
    {
        $response = $this->attempt('v2 edit', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_PRODUCT, 'Own product'], [$response->json('id'), $response->json('name')]);
    }

    public function test_v2_the_seller_can_still_update_their_own_product(): void
    {
        $response = $this->attempt('v2 update', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Updated name', self::SELLER, 'seller'], $this->nameOwnerAndAdderOf(self::OWN_PRODUCT));
        $this->assertSame(['Other sellers product', self::OTHER_SELLER, 'seller'], $this->nameOwnerAndAdderOf(self::OTHER_SELLERS_PRODUCT));
    }

    public function test_v2_the_seller_can_still_switch_off_their_own_product(): void
    {
        $response = $this->attempt('v2 status update', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([0, 1, 1, 1], $this->productStatuses());
    }

    public function test_v2_the_seller_can_still_delete_their_own_product(): void
    {
        $response = $this->attempt('v2 delete', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_PRODUCT_WITHOUT_CODE, self::OTHER_SELLERS_PRODUCT, self::IN_HOUSE_PRODUCT], $this->productIds());
    }

    public function test_v2_the_seller_can_still_generate_the_barcodes_of_their_own_product(): void
    {
        $response = $this->attempt('v2 barcode', self::OWN_PRODUCT);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith(url('/api/v3/seller/products/barcode/sheet?'), $response->json());
        $this->assertFileDoesNotExist(storage_path('app/public/product/barcode.pdf'));
    }

    public function test_v2_the_seller_can_still_approve_a_review_of_their_own_product(): void
    {
        $response = $this->attempt('v2 review status', self::OWN_REVIEW);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([1, 0, 0], $this->reviewStatuses());
    }

    // ---- helpers ----

    private function attempt(string $handler, int $id, ?int $as = self::SELLER): TestResponse
    {
        return match ($handler) {
            'v3 edit' => $this->sellerApi('GET', 'products/edit/' . $id, as: $as),
            'v3 update' => $this->sellerApi('PUT', 'products/update/' . $id, $this->v3UpdatePayload(), as: $as),
            'v3 delete' => $this->sellerApi('DELETE', 'products/delete/' . $id, as: $as),
            'v3 review list' => $this->sellerApi('GET', 'products/review-list/' . $id . '?limit=10&offset=1', as: $as),
            'v3 barcode' => $this->sellerApi('GET', 'products/barcode/generate?quantity=2&id=' . $id, as: $as),
            'v3 review status' => $this->sellerApi('GET', 'shop-product-reviews-status?status=1&id=' . $id, as: $as),
            'v3 review reply' => $this->sellerApi('POST', 'shop-product-reviews-reply', ['review_id' => $id, 'reply_text' => 'Thanks'], as: $as),
            'v2 edit' => $this->sellerApiV2('GET', 'products/edit/' . $id, as: $as),
            'v2 update' => $this->sellerApiV2('PUT', 'products/update/' . $id, $this->v2UpdatePayload(), as: $as),
            'v2 status update' => $this->sellerApiV2('GET', 'products/status-update?status=0&id=' . $id, as: $as),
            'v2 delete' => $this->sellerApiV2('DELETE', 'products/delete/' . $id, as: $as),
            'v2 barcode' => $this->sellerApiV2('GET', 'products/barcode/generate?quantity=2&id=' . $id, as: $as),
            'v2 review status' => $this->sellerApiV2('GET', 'shop-product-reviews-status?status=1&id=' . $id, as: $as),
        };
    }

    /** A complete, valid update of the v2 app: it renames the product and, were it served, it would also take the product over. @return array<string, mixed> */
    private function v2UpdatePayload(): array
    {
        return [
            'name' => ['Updated name'], 'lang' => ['en'], 'description' => ['Updated description'], 'category_id' => 1, 'product_type' => 'physical', 'unit' => 'pc',
            'discount_type' => 'flat', 'tax' => 0, 'unit_price' => 100, 'purchase_price' => 50, 'discount' => 0, 'shipping_cost' => 0, 'minimum_order_qty' => 1,
            'code' => '7654321', 'current_stock' => 5, 'images' => ['updated.png'], 'thumbnail' => 'updated-thumbnail.png', 'choice_attributes' => [],
        ];
    }

    /** A complete, valid update of the v3 app, whose list fields travel as JSON text. @return array<string, mixed> */
    private function v3UpdatePayload(): array
    {
        return [
            'name' => json_encode(['Updated name']), 'lang' => json_encode(['en']), 'description' => json_encode(['Updated description']), 'colors' => json_encode([]),
            'images' => json_encode([['image_name' => 'updated.png', 'storage' => 'public']]), 'color_image' => json_encode([]), 'tags' => json_encode([]),
            'choice' => json_encode([]), 'choice_no' => json_encode([]), 'choice_attributes' => json_encode([]), 'tax_ids' => json_encode([]),
            'category_id' => 1, 'product_type' => 'physical', 'unit' => 'pc', 'discount_type' => 'flat', 'unit_price' => 100, 'discount' => 0, 'shipping_cost' => 0,
            'minimum_order_qty' => 1, 'code' => 'UPDATED7654321', 'current_stock' => 5, 'thumbnail' => 'updated-thumbnail.png',
        ];
    }

    private function assertNotFound(TestResponse $response, string $code): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($code, $response->json('errors.0.code'));
    }

    /** @return array<string, list<array<string, mixed>>> every row the handlers can read or write */
    private function theCatalogue(): array
    {
        $rows = [];
        foreach (['products', 'reviews', 'review_replies', 'translations', 'digital_product_variations', 'product_seos'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    private function nameOf(int $productId): string
    {
        return (string) DB::table('products')->where('id', $productId)->value('name');
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function nameOwnerAndAdderOf(int $productId): array
    {
        $product = DB::table('products')->where('id', $productId)->first();

        return [$product->name, (int) $product->user_id, $product->added_by];
    }

    /** @return list<int> */
    private function productIds(): array
    {
        return DB::table('products')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> the status of the own, the own codeless, the other seller's and the in-house product */
    private function productStatuses(): array
    {
        return DB::table('products')->orderBy('id')->pluck('status')->map(fn ($status) => (int) $status)->all();
    }

    /** @return list<int> the status of the own, the other seller's and the in-house product's review */
    private function reviewStatuses(): array
    {
        return DB::table('reviews')->orderBy('id')->pluck('status')->map(fn ($status) => (int) $status)->all();
    }

    private function prepareTheBarcodeDirectory(): void
    {
        $directory = storage_path('app/public/product');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
            $this->createdTheBarcodeDirectory = true;
        }
    }

    private function removeTheBarcodeFile(): void
    {
        @unlink(storage_path('app/public/product/barcode.pdf'));
        if ($this->createdTheBarcodeDirectory) {
            @rmdir(storage_path('app/public/product'));
        }
    }
    private function seedCatalogue(): void
    {
        Cache::flush();
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => null, 'SystemTaxVatType' => 'order_wise', 'is_included' => 1, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
        DB::table('business_settings')->insert([
            ['type' => 'product_brand', 'value' => '0'],
            ['type' => 'system_default_currency', 'value' => '1'],
        ]);
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]);
        DB::table('shops')->insert([['seller_id' => self::SELLER, 'name' => 'Shop'], ['seller_id' => self::OTHER_SELLER, 'name' => 'Other shop']]);

        $product = fn (int $id, int $userId, string $addedBy, string $name, ?string $code) => DB::table('products')->insert([
            'id' => $id, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => $name, 'slug' => 'product-' . $id, 'code' => $code, 'status' => 1, 'request_status' => 1,
            'product_type' => 'physical', 'images' => '[]', 'thumbnail' => 'thumb.png', 'unit_price' => 100, 'purchase_price' => 50, 'current_stock' => 5,
            'category_ids' => '[]', 'colors' => '[]', 'attributes' => '[]', 'choice_options' => '[]', 'variation' => '[]',
            'digital_product_file_types' => '[]', 'digital_product_extensions' => '[]',
        ]);
        $product(self::OWN_PRODUCT, self::SELLER, 'seller', 'Own product', '1000010');
        $product(self::OWN_PRODUCT_WITHOUT_CODE, self::SELLER, 'seller', 'Own product without a code', null);
        $product(self::OTHER_SELLERS_PRODUCT, self::OTHER_SELLER, 'seller', 'Other sellers product', null);
        $product(self::IN_HOUSE_PRODUCT, self::IN_HOUSE_ID, 'admin', 'In-house product', null);

        foreach ([[self::OWN_REVIEW, self::OWN_PRODUCT], [self::OTHER_SELLERS_REVIEW, self::OTHER_SELLERS_PRODUCT], [self::IN_HOUSE_REVIEW, self::IN_HOUSE_PRODUCT]] as [$reviewId, $productId]) {
            DB::table('reviews')->insert(['id' => $reviewId, 'product_id' => $productId, 'customer_id' => 7, 'rating' => 5, 'status' => 0]);
        }
    }

    private function createCatalogueTables(): void
    {
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->boolean('featured_status')->default(false);
            $table->string('product_type')->default('physical');
            $table->string('digital_product_type')->nullable();
            $table->string('digital_file_ready')->nullable();
            $table->string('digital_file_ready_storage_type')->nullable();
            $table->text('images')->nullable();
            $table->text('color_image')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('thumbnail_storage_type')->nullable();
            $table->string('preview_file')->nullable();
            $table->string('preview_file_storage_type')->nullable();
            $table->string('unit')->nullable();
            $table->text('category_ids')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->unsignedBigInteger('sub_sub_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('purchase_price', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('tax_model')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->integer('current_stock')->default(0);
            $table->integer('minimum_order_qty')->default(1);
            $table->text('details')->nullable();
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->text('digital_product_file_types')->nullable();
            $table->text('digital_product_extensions')->nullable();
            $table->string('video_provider')->nullable();
            $table->string('video_url')->nullable();
            $table->decimal('shipping_cost', 16, 2)->default(0);
            $table->integer('multiply_qty')->default(0);
            $table->decimal('temp_shipping_cost', 16, 2)->default(0);
            $table->integer('is_shipping_cost_updated')->default(1);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_image')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->text('comment')->nullable();
            $table->text('attachment')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('review_replies', function (Blueprint $table) {
            $table->unsignedBigInteger('review_id');
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('added_by_id')->nullable();
            $table->text('reply_text')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('translations', function (Blueprint $table) {
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        $this->createSellerApiTable('storages', function (Blueprint $table) {
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('colors', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('users', function (Blueprint $table) {
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('currencies', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 16, 4)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('tags', function (Blueprint $table) {
            $table->string('tag')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('product_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();
        });
        $this->createSellerApiTable('digital_product_variations', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->string('variant_key')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 16, 2)->default(0);
            $table->string('file')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('product_seos', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('index')->nullable();
            $table->string('no_follow')->nullable();
            $table->string('no_image_index')->nullable();
            $table->string('no_archive')->nullable();
            $table->integer('no_snippet')->default(0);
            $table->integer('max_snippet')->default(0);
            $table->integer('max_snippet_value')->default(0);
            $table->integer('max_video_preview')->default(0);
            $table->integer('max_video_preview_value')->default(0);
            $table->integer('max_image_preview')->default(0);
            $table->string('max_image_preview_value')->default('0');
            $table->string('image')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('digital_product_authors', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('digital_product_publishing_houses', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('publishing_house_id')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('stock_clearance_setups', function (Blueprint $table) {
            $table->string('setup_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->integer('is_active')->default(1);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('offer_active_time')->nullable();
            $table->string('offer_active_range_start')->nullable();
            $table->string('offer_active_range_end')->nullable();
            $table->dateTime('duration_start_date')->nullable();
            $table->dateTime('duration_end_date')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('stock_clearance_products', function (Blueprint $table) {
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('is_active')->default(1);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('restock_products', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->string('variant')->nullable();
            $table->integer('restock_request_count')->default(1);
            $table->timestamps();
        });
        $this->createSellerApiTable('restock_product_customers', function (Blueprint $table) {
            $table->unsignedBigInteger('restock_product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('taxables', function (Blueprint $table) {
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->unsignedBigInteger('taxable_id');
            $table->string('taxable_type');
            $table->string('tax_for')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('flash_deal_products', fn (Blueprint $table) => $table->unsignedBigInteger('product_id'));
        $this->createSellerApiTable('deal_of_the_days', fn (Blueprint $table) => $table->unsignedBigInteger('product_id'));
    }
}
