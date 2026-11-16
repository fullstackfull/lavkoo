<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\RestAPI\v2\seller\ProductController as ProductControllerV2;
use App\Http\Controllers\RestAPI\v2\seller\SellerController as SellerControllerV2;
use App\Http\Controllers\RestAPI\v3\seller\ProductController as ProductControllerV3;
use App\Http\Controllers\RestAPI\v3\seller\SellerController as SellerControllerV3;
use App\Models\Seller;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Throwable;

/**
 * The seller APIs reach a product or a review by an id the app sends. The permission middleware only says that a seller may manage
 * products, so each lookup must answer only for the seller who owns the row: another seller's product, an in-house product whose
 * user_id happens to equal this seller's id, and a review of either are not found, and nothing changes.
 */
class SellerProductOwnershipTest extends TestCase
{
    private const SELLER = 1;
    private const OTHER_SELLER = 2;
    private const TOKEN = 'seller-a-token-that-is-longer-than-thirty-characters';

    private const OWN_PRODUCT = 10;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;

    private const OWN_REVIEW = 100;
    private const OTHER_SELLERS_REVIEW = 200;
    private const IN_HOUSE_REVIEW = 300;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->createTables();

        DB::table('sellers')->insert([
            ['id' => self::SELLER, 'f_name' => 'Seller', 'auth_token' => self::TOKEN],
            ['id' => self::OTHER_SELLER, 'f_name' => 'Other seller', 'auth_token' => null],
        ]);
        $this->insertProduct(id: self::OWN_PRODUCT, userId: self::SELLER, addedBy: 'seller', name: 'Own product');
        $this->insertProduct(id: self::OTHER_SELLERS_PRODUCT, userId: self::OTHER_SELLER, addedBy: 'seller', name: 'Other sellers product');
        $this->insertProduct(id: self::IN_HOUSE_PRODUCT, userId: self::SELLER, addedBy: 'admin', name: 'In-house product');
        foreach ([[self::OWN_REVIEW, self::OWN_PRODUCT], [self::OTHER_SELLERS_REVIEW, self::OTHER_SELLERS_PRODUCT], [self::IN_HOUSE_REVIEW, self::IN_HOUSE_PRODUCT]] as [$reviewId, $productId]) {
            DB::table('reviews')->insert(['id' => $reviewId, 'product_id' => $productId, 'customer_id' => 7, 'rating' => 5, 'status' => 0]);
        }
    }

    // ---- API v3 (the seller app) ----

    public function test_v3_edit_of_another_sellers_product_is_not_found(): void
    {
        $response = $this->v3(fn (Request $request) => app(ProductControllerV3::class)->edit($request, self::OTHER_SELLERS_PRODUCT));

        $this->assertNotFound($response, code: 'product');
        $this->assertStringNotContainsString('Other sellers product', (string) $response->getContent());
    }

    public function test_v3_edit_of_an_in_house_product_sharing_the_sellers_id_is_not_found(): void
    {
        $response = $this->v3(fn (Request $request) => app(ProductControllerV3::class)->edit($request, self::IN_HOUSE_PRODUCT));

        $this->assertNotFound($response, code: 'product');
    }

    public function test_v3_update_of_another_sellers_product_is_not_found_and_changes_nothing(): void
    {
        $response = $this->v3(fn (Request $request) => app(ProductControllerV3::class)->updateProduct($request, self::OTHER_SELLERS_PRODUCT), input: ['name' => 'Taken over']);

        $this->assertNotFound($response, code: 'product');
        $this->assertSame(['Other sellers product', self::OTHER_SELLER], $this->nameAndOwnerOf(self::OTHER_SELLERS_PRODUCT));
    }

    public function test_v3_update_of_an_in_house_product_sharing_the_sellers_id_changes_nothing(): void
    {
        $response = $this->v3(fn (Request $request) => app(ProductControllerV3::class)->updateProduct($request, self::IN_HOUSE_PRODUCT), input: ['name' => 'Taken over']);

        $this->assertNotFound($response, code: 'product');
        $this->assertSame(['In-house product', self::SELLER], $this->nameAndOwnerOf(self::IN_HOUSE_PRODUCT));
    }

    public function test_v3_delete_of_another_sellers_product_is_not_found_and_keeps_it(): void
    {
        $response = $this->v3(fn (Request $request) => app(ProductControllerV3::class)->delete($request, self::OTHER_SELLERS_PRODUCT));

        $this->assertNotFound($response, code: 'product');
        $this->assertTrue(DB::table('products')->where('id', self::OTHER_SELLERS_PRODUCT)->exists());
    }

    public function test_v3_delete_of_an_in_house_product_sharing_the_sellers_id_keeps_it(): void
    {
        $response = $this->v3(fn (Request $request) => app(ProductControllerV3::class)->delete($request, self::IN_HOUSE_PRODUCT));

        $this->assertNotFound($response, code: 'product');
        $this->assertTrue(DB::table('products')->where('id', self::IN_HOUSE_PRODUCT)->exists());
    }

    public function test_v3_the_seller_can_still_delete_their_own_product(): void
    {
        $response = $this->v3(fn (Request $request) => app(ProductControllerV3::class)->delete($request, self::OWN_PRODUCT));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(DB::table('products')->where('id', self::OWN_PRODUCT)->exists());
    }

    public function test_v3_review_list_of_another_sellers_product_is_not_found(): void
    {
        $response = $this->v3(fn (Request $request) => app(ProductControllerV3::class)->review_list($request, self::OTHER_SELLERS_PRODUCT), input: ['limit' => 10, 'offset' => 1]);

        $this->assertNotFound($response, code: 'product');
    }

    public function test_v3_barcode_of_another_sellers_product_is_not_found(): void
    {
        $response = $this->v3(
            fn (Request $request) => app(ProductControllerV3::class)->barcode_generate($request),
            input: ['id' => self::OTHER_SELLERS_PRODUCT, 'quantity' => 2],
        );

        $this->assertNotFound($response, code: 'product');
    }

    public function test_v3_approving_a_review_of_another_sellers_product_is_not_found_and_changes_nothing(): void
    {
        $response = $this->v3(fn (Request $request) => app(SellerControllerV3::class)->shop_product_reviews_status($request), input: ['id' => self::OTHER_SELLERS_REVIEW, 'status' => 1]);

        $this->assertNotFound($response, code: 'review');
        $this->assertSame(0, $this->reviewStatus(self::OTHER_SELLERS_REVIEW));
    }

    public function test_v3_approving_a_review_of_an_in_house_product_sharing_the_sellers_id_changes_nothing(): void
    {
        $response = $this->v3(fn (Request $request) => app(SellerControllerV3::class)->shop_product_reviews_status($request), input: ['id' => self::IN_HOUSE_REVIEW, 'status' => 1]);

        $this->assertNotFound($response, code: 'review');
        $this->assertSame(0, $this->reviewStatus(self::IN_HOUSE_REVIEW));
    }

    public function test_v3_the_seller_can_still_approve_a_review_of_their_own_product(): void
    {
        $response = $this->v3(fn (Request $request) => app(SellerControllerV3::class)->shop_product_reviews_status($request), input: ['id' => self::OWN_REVIEW, 'status' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->reviewStatus(self::OWN_REVIEW));
    }

    public function test_v3_replying_to_a_review_of_another_sellers_product_is_not_found_and_stores_nothing(): void
    {
        $response = $this->v3(fn (Request $request) => app(SellerControllerV3::class)->shopProductReviewReply($request), input: ['review_id' => self::OTHER_SELLERS_REVIEW, 'reply_text' => 'Thanks']);

        $this->assertNotFound($response, code: 'review');
        $this->assertSame(0, DB::table('review_replies')->count());
    }

    public function test_v3_the_seller_can_still_reply_to_a_review_of_their_own_product(): void
    {
        $response = $this->v3(fn (Request $request) => app(SellerControllerV3::class)->shopProductReviewReply($request), input: ['review_id' => self::OWN_REVIEW, 'reply_text' => 'Thanks']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['Thanks'], DB::table('review_replies')->where('review_id', self::OWN_REVIEW)->pluck('reply_text')->all());
    }

    // ---- API v2 (older seller app builds) ----

    public function test_v2_edit_of_another_sellers_product_is_not_found(): void
    {
        $response = $this->v2(fn (Request $request) => app(ProductControllerV2::class)->edit($request, self::OTHER_SELLERS_PRODUCT));

        $this->assertNotFound($response, code: 'product');
        $this->assertStringNotContainsString('Other sellers product', (string) $response->getContent());
    }

    public function test_v2_update_of_another_sellers_product_is_not_found_and_changes_nothing(): void
    {
        $response = $this->v2(fn (Request $request) => app(ProductControllerV2::class)->update($request, self::OTHER_SELLERS_PRODUCT), input: ['name' => 'Taken over']);

        $this->assertNotFound($response, code: 'product');
        $this->assertSame(['Other sellers product', self::OTHER_SELLER], $this->nameAndOwnerOf(self::OTHER_SELLERS_PRODUCT));
    }

    public function test_v2_switching_off_another_sellers_product_is_not_found_and_changes_nothing(): void
    {
        $response = $this->v2(fn (Request $request) => app(ProductControllerV2::class)->status_update($request), input: ['id' => self::OTHER_SELLERS_PRODUCT, 'status' => 0]);

        $this->assertNotFound($response, code: 'product');
        $this->assertSame(1, (int) DB::table('products')->where('id', self::OTHER_SELLERS_PRODUCT)->value('status'));
    }

    public function test_v2_the_seller_can_still_switch_off_their_own_product(): void
    {
        $response = $this->v2(fn (Request $request) => app(ProductControllerV2::class)->status_update($request), input: ['id' => self::OWN_PRODUCT, 'status' => 0]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) DB::table('products')->where('id', self::OWN_PRODUCT)->value('status'));
    }

    public function test_v2_delete_of_another_sellers_product_is_not_found_and_keeps_it(): void
    {
        $response = $this->v2(fn (Request $request) => app(ProductControllerV2::class)->delete($request, self::OTHER_SELLERS_PRODUCT));

        $this->assertNotFound($response, code: 'product');
        $this->assertTrue(DB::table('products')->where('id', self::OTHER_SELLERS_PRODUCT)->exists());
    }

    public function test_v2_barcode_of_another_sellers_product_is_not_found(): void
    {
        $response = $this->v2(
            fn (Request $request) => app(ProductControllerV2::class)->barcode_generate($request),
            input: ['id' => self::OTHER_SELLERS_PRODUCT, 'quantity' => 2],
        );

        $this->assertNotFound($response, code: 'product');
    }

    public function test_v2_barcode_without_a_seller_token_is_refused(): void
    {
        $response = $this->v2(
            fn (Request $request) => app(ProductControllerV2::class)->barcode_generate($request),
            input: ['id' => self::OWN_PRODUCT, 'quantity' => 2],
            token: null,
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_v2_approving_a_review_of_another_sellers_product_is_not_found_and_changes_nothing(): void
    {
        $response = $this->v2(fn (Request $request) => app(SellerControllerV2::class)->shop_product_reviews_status($request), input: ['id' => self::OTHER_SELLERS_REVIEW, 'status' => 1]);

        $this->assertNotFound($response, code: 'review');
        $this->assertSame(0, $this->reviewStatus(self::OTHER_SELLERS_REVIEW));
    }

    public function test_v2_the_seller_can_still_approve_a_review_of_their_own_product(): void
    {
        $response = $this->v2(fn (Request $request) => app(SellerControllerV2::class)->shop_product_reviews_status($request), input: ['id' => self::OWN_REVIEW, 'status' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->reviewStatus(self::OWN_REVIEW));
    }

    // ---- helpers ----

    private function assertNotFound(Response $response, string $code): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($code, json_decode((string) $response->getContent(), true)['errors'][0]['code'] ?? null);
    }

    /** @return array{0: string, 1: int} */
    private function nameAndOwnerOf(int $productId): array
    {
        $product = DB::table('products')->where('id', $productId)->first();

        return [$product->name, (int) $product->user_id];
    }

    private function reviewStatus(int $reviewId): int
    {
        return (int) DB::table('reviews')->where('id', $reviewId)->value('status');
    }

    private function v3(Closure $endpoint, array $input = []): Response
    {
        $request = Request::create('/api/v3/seller/call', 'POST', $input);
        $request->headers->set('Accept', 'application/json');
        $request['seller'] = Seller::findOrFail(self::SELLER);

        return $this->callEndpoint($endpoint, $request);
    }

    private function v2(Closure $endpoint, array $input = [], ?string $token = self::TOKEN): Response
    {
        $request = Request::create('/api/v2/seller/call', 'POST', $input);
        $request->headers->set('Accept', 'application/json');
        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $this->callEndpoint($endpoint, $request);
    }

    /** Renders an exception the way the HTTP kernel would, so a crash on a missing row shows as the 500 a seller app would receive. */
    private function callEndpoint(Closure $endpoint, Request $request): Response
    {
        $this->app->instance('request', $request);

        try {
            return $endpoint($request);
        } catch (Throwable $exception) {
            return $this->app->make(ExceptionHandler::class)->render($request, $exception);
        }
    }

    private function insertProduct(int $id, int $userId, string $addedBy, string $name): void
    {
        DB::table('products')->insert([
            'id' => $id, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => $name, 'slug' => 'product-' . $id,
            'code' => 'CODE' . $id . '000', 'status' => 1, 'request_status' => 1, 'product_type' => 'physical',
            'images' => '[]', 'thumbnail' => 'thumb.png', 'unit_price' => 10, 'current_stock' => 5,
            'category_ids' => '[]', 'colors' => '[]', 'attributes' => '[]', 'choice_options' => '[]', 'variation' => '[]',
        ]);
    }

    private function createTables(): void
    {
        foreach (['business_settings', 'sellers', 'products', 'reviews', 'review_replies', 'translations', 'flash_deal_products', 'deal_of_the_days'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('auth_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->string('product_type')->default('physical');
            $table->text('images')->nullable();
            $table->string('thumbnail')->nullable();
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->integer('current_stock')->default(0);
            $table->text('category_ids')->nullable();
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->timestamps();
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('review_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('review_id');
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('added_by_id')->nullable();
            $table->text('reply_text')->nullable();
            $table->timestamps();
        });
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('flash_deal_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('deal_of_the_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
        });
    }
}
