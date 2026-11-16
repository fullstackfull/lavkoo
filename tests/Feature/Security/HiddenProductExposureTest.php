<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\APIGuestMiddleware;
use App\Http\Middleware\APILocalizationMiddleware;
use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\GuestMiddleware;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordApiResponseShape;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\Product;
use App\Models\User;
use App\Utils\ProductManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A product a customer may not see (disabled, never approved, or owned by a seller who is still pending) is not reachable by any other
 * door either: not through its id or slug, not through the reviews, counters and prices that hang off it, not through a banner that
 * points at it, not through a deal list and not by putting it in a cart. Every lookup answers for such a product exactly as it answers
 * for an id that does not exist.
 */
class HiddenProductExposureTest extends TestCase
{
    use BuildsProductCatalog;
    use RestoresLanguageFiles;

    /** Disabled, never approved, owned by a pending seller, and of a brand that is switched off. */
    private const HIDDEN_PRODUCTS = [2, 4, 5, 6];

    /** What the storefront's scripts send with every request, and what makes the cart answer with JSON instead of a redirect. */
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Cache::flush();
        $this->buildCatalogTables();
        $this->registerMySqlLocate();
        $this->seedCatalog();
        $this->seedStoreSettings();
        DB::table('shops')->insert(['seller_id' => 0, 'author_type' => 'admin', 'name' => 'House Shop', 'slug' => 'house-shop']);
        DB::table('brands')->insert(['id' => 7, 'name' => 'Muted brand', 'status' => 0]);
        $this->insertProduct(id: 6, name: 'Beta branded lamp', extra: ['brand_id' => 7]);

        if (!defined('VIEW_FILE_NAMES')) {
            define('VIEW_FILE_NAMES', include resource_path('themes/default/file_names.php'));
        }
        View::addLocation(resource_path('themes/default'));
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class,
            MonitorRequest::class, RecordAnalytics::class, ActivationCheckMiddleware::class, APILocalizationMiddleware::class,
            APIGuestMiddleware::class, GuestMiddleware::class, MaintenanceModeMiddleware::class, RecordApiResponseShape::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function seedStoreSettings(): void
    {
        DB::table('currencies')->insert(['id' => 1, 'name' => 'USD', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1, 'status' => 1]);
        foreach (['system_default_currency' => '1', 'currency_model' => 'single_currency', 'currency_symbol_position' => 'left', 'decimal_point_settings' => '2', 'stock_limit' => '5'] as $type => $value) {
            $this->setBusinessSetting($type, $value);
        }
        $this->setBusinessSetting('language', [['id' => 1, 'name' => 'english', 'code' => 'en', 'status' => 1, 'default' => true]]);
        Cache::flush();
    }

    /** Products 1 and 2 were both bought twice, wished for once and reviewed by the same customer; product 2 has since been disabled. */
    private function seedActivityOnProductsOneAndTwo(): void
    {
        DB::table('users')->insert(['id' => 1, 'name' => 'Rev Iewer', 'f_name' => 'Rev', 'l_name' => 'Iewer', 'email' => 'reviewer@example.test', 'phone' => '0500000001']);
        foreach ([1 => 'Great lamp', 2 => 'Great hidden lamp'] as $productId => $comment) {
            DB::table('reviews')->insert(['product_id' => $productId, 'customer_id' => 1, 'comment' => $comment, 'rating' => 5, 'status' => 1]);
            DB::table('order_details')->insert([['product_id' => $productId, 'qty' => 3, 'delivery_status' => 'delivered'], ['product_id' => $productId, 'qty' => 1, 'delivery_status' => 'delivered']]);
            DB::table('wishlists')->insert(['product_id' => $productId, 'customer_id' => 1]);
        }
    }

    private function seedFeatureDeal(array $priority, array $memberIds): void
    {
        $this->setBusinessSetting('feature_deal_priority', array_merge([
            'custom_sorting_status' => 1, 'sort_by' => 'latest_created', 'out_of_stock_product' => 'default', 'temporary_close_sorting' => 'default',
        ], $priority));
        DB::table('flash_deals')->insert([
            'id' => 1, 'title' => 'Feature', 'deal_type' => 'feature_deal', 'status' => 1,
            'start_date' => date('Y-m-d', strtotime('-1 day')), 'end_date' => date('Y-m-d', strtotime('+1 day')),
        ]);
        foreach ($memberIds as $productId) {
            DB::table('flash_deal_products')->insert(['flash_deal_id' => 1, 'product_id' => $productId]);
        }
        Cache::flush();
    }

    private function approvedSellerWithProduct(int $sellerId, int $productId, bool $shopClosed): void
    {
        DB::table('sellers')->insert(['id' => $sellerId, 'status' => 'approved']);
        DB::table('shops')->insert(['seller_id' => $sellerId, 'name' => "Shop {$sellerId}", 'slug' => "shop-{$sellerId}", 'temporary_close' => $shopClosed]);
        $this->insertProduct(id: $productId, name: "Seller {$sellerId} lamp", userId: $sellerId, addedBy: 'seller');
    }

    #[DataProvider('hideModeCallers')]
    public function test_the_hide_mode_of_the_feature_deal_keeps_to_the_visible_members_of_the_deal(Closure $dealProducts): void
    {
        $this->seedFeatureDeal(priority: ['temporary_close_sorting' => 'hide'], memberIds: [1]);

        $this->assertSame([1], $this->idsOf($dealProducts()));
    }

    public static function hideModeCallers(): array
    {
        return [
            'the priority query over the active scope' => [fn () => ProductManager::getPriorityWiseFeatureDealQuery(query: Product::active()->whereIn('id', [1]), dataLimit: 'all')],
            'the home page and the offers menu' => [fn () => getFeaturedDealsProductList()],
            'the featured deals page' => [fn () => ProductManager::getProductListData(request: Request::create('/featured-deal-products', 'GET', ['offer_type' => 'featured_deal']))],
        ];
    }

    public function test_the_featured_deals_api_lists_only_the_visible_members_of_the_deal(): void
    {
        $this->seedFeatureDeal(priority: ['temporary_close_sorting' => 'hide'], memberIds: [1, 2]);

        $listed = $this->getJson('/api/v1/deals/featured')->assertOk()->json();

        $this->assertSame([1], $this->idsOf($listed));
    }

    public function test_the_hide_mode_keeps_the_products_of_open_shops_and_drops_those_of_temporarily_closed_ones(): void
    {
        $this->approvedSellerWithProduct(sellerId: 10, productId: 11, shopClosed: false);
        $this->approvedSellerWithProduct(sellerId: 20, productId: 12, shopClosed: true);
        $this->seedFeatureDeal(priority: ['temporary_close_sorting' => 'hide'], memberIds: [1, 3, 11, 12]);
        $deal = fn () => $this->idsOf(ProductManager::getPriorityWiseFeatureDealQuery(query: Product::active()->whereIn('id', [1, 3, 11, 12]), dataLimit: 'all'));

        $this->assertSame([1, 3, 11], $deal());

        Cache::forever(IN_HOUSE_SHOP_TEMPORARY_CLOSE_STATUS, 1);
        $this->assertSame([11], $deal());
    }

    public function test_the_other_sorting_modes_of_the_feature_deal_are_not_narrowed(): void
    {
        $this->seedFeatureDeal(priority: ['temporary_close_sorting' => 'default'], memberIds: [1, 2, 3]);

        $listed = ProductManager::getPriorityWiseFeatureDealQuery(query: Product::active()->whereIn('id', [1, 2, 3]), dataLimit: 'all');

        $this->assertSame([1, 3], $this->idsOf($listed));
    }

    public function test_the_reviews_of_a_visible_product_are_listed_with_the_reviewers_name_and_rating(): void
    {
        $this->seedActivityOnProductsOneAndTwo();

        $response = $this->getJson('/api/v1/products/reviews/product-1')->assertOk();

        $this->assertSame(1, $response->json('total_size'));
        $this->assertSame('5.00', $response->json('average_rating'));
        $this->assertSame('Great lamp', $response->json('reviews.0.comment'));
        $this->assertSame(5, $response->json('reviews.0.rating'));
        $this->assertSame('Rev', $response->json('reviews.0.customer.f_name'));
        $this->assertSame('Iewer', $response->json('reviews.0.customer.l_name'));
        $this->assertArrayHasKey('image_full_url', $response->json('reviews.0.customer'));
    }

    public function test_the_reviews_of_a_visible_product_do_not_carry_the_reviewers_contact_details_or_the_products_cost(): void
    {
        $this->seedActivityOnProductsOneAndTwo();

        $response = $this->getJson('/api/v1/products/reviews/product-1')->assertOk();

        $this->assertArrayNotHasKey('email', $response->json('reviews.0.customer'));
        $this->assertArrayNotHasKey('phone', $response->json('reviews.0.customer'));
        $this->assertArrayNotHasKey('product', $response->json('reviews.0'));
        $this->assertStringNotContainsString('reviewer@example.test', $response->getContent());
        $this->assertStringNotContainsString('purchase_price', $response->getContent());
    }

    public function test_the_reviews_of_a_hidden_product_are_not_found_like_those_of_an_unknown_one(): void
    {
        $this->seedActivityOnProductsOneAndTwo();
        $unknown = $this->getJson('/api/v1/products/reviews/product-999')->assertNotFound();

        foreach (self::HIDDEN_PRODUCTS as $hiddenId) {
            $hidden = $this->getJson("/api/v1/products/reviews/product-{$hiddenId}");

            $this->assertSame($unknown->getContent(), $hidden->getContent(), "product {$hiddenId}");
            $this->assertSame(404, $hidden->getStatusCode(), "product {$hiddenId}");
        }
    }

    public static function productLookups(): array
    {
        return [
            'sales and wishlist counter' => [fn (int $id) => "/api/v1/products/counter/product-{$id}"],
            'rating' => [fn (int $id) => "/api/v1/products/rating/{$id}"],
            'related products' => [fn (int $id) => "/api/v1/products/related-products/product-{$id}"],
            'social share link' => [fn (int $id) => "/api/v1/products/social-share-link/product-{$id}"],
        ];
    }

    #[DataProvider('productLookups')]
    public function test_a_lookup_of_a_hidden_product_is_answered_like_a_lookup_of_an_unknown_one(Closure $lookup): void
    {
        $this->seedActivityOnProductsOneAndTwo();
        $unknown = $this->getJson($lookup(999));

        foreach (self::HIDDEN_PRODUCTS as $hiddenId) {
            $hidden = $this->getJson($lookup($hiddenId));

            $this->assertSame($unknown->getStatusCode(), $hidden->getStatusCode(), "product {$hiddenId}");
            $this->assertSame($unknown->getContent(), $hidden->getContent(), "product {$hiddenId}");
        }
    }

    public function test_the_counter_rating_related_products_and_share_link_of_a_visible_product_are_answered(): void
    {
        $this->seedActivityOnProductsOneAndTwo();

        $this->getJson('/api/v1/products/counter/product-1')->assertOk()->assertExactJson(['order_count' => 2, 'wishlist_count' => 1]);
        $this->assertEquals(5, $this->getJson('/api/v1/products/rating/1')->assertOk()->json());
        $this->assertSame([3], $this->idsOf($this->getJson('/api/v1/products/related-products/product-1')->assertOk()->json()));
        $this->assertSame(url('/product/product-1'), $this->getJson('/api/v1/products/social-share-link/product-1')->assertOk()->json());
    }

    public function test_the_variant_price_of_a_visible_product_is_answered(): void
    {
        $response = $this->postJson('/cart/variant_price', ['id' => 1, 'quantity' => 2])->assertOk();

        $this->assertSame('$20.00', $response->json('price'));
        $this->assertSame(5, $response->json('quantity'));
        $this->assertSame('physical', $response->json('product_type'));
    }

    public function test_the_variant_price_of_a_hidden_product_is_not_found_like_that_of_an_unknown_one(): void
    {
        $this->postJson('/cart/variant_price', ['id' => 999, 'quantity' => 1])->assertNotFound();

        foreach (self::HIDDEN_PRODUCTS as $hiddenId) {
            $this->postJson('/cart/variant_price', ['id' => $hiddenId, 'quantity' => 1])->assertNotFound();
        }
    }

    public function test_the_review_list_of_a_visible_product_is_answered(): void
    {
        $this->seedActivityOnProductsOneAndTwo();

        $response = $this->postJson('/review-list-product', ['product_id' => 1, 'offset' => 0])->assertOk();

        $this->assertSame(1, $response->json('not_empty'));
        $this->assertStringContainsString('Great lamp', $response->json('productReview'));
    }

    public function test_the_review_list_of_a_hidden_product_is_not_found_like_that_of_an_unknown_one(): void
    {
        $this->seedActivityOnProductsOneAndTwo();
        $this->postJson('/review-list-product', ['product_id' => 999, 'offset' => 0])->assertNotFound();

        foreach (self::HIDDEN_PRODUCTS as $hiddenId) {
            $response = $this->postJson('/review-list-product', ['product_id' => $hiddenId, 'offset' => 0])->assertNotFound();

            $this->assertStringNotContainsString('Great hidden lamp', $response->getContent());
        }
    }

    /** The reviews of product 1 and of every hidden product, each reading "Review of <product id>". */
    private function seedOneReviewPerProduct(): void
    {
        DB::table('users')->insert(['id' => 1, 'name' => 'Rev Iewer', 'f_name' => 'Rev', 'l_name' => 'Iewer', 'email' => 'reviewer@example.test', 'phone' => '0500000001']);
        foreach ([1, ...self::HIDDEN_PRODUCTS] as $productId) {
            DB::table('reviews')->insert(['product_id' => $productId, 'customer_id' => 1, 'comment' => "Review of {$productId}", 'rating' => 5, 'status' => 1]);
        }
    }

    /** What a browser, a script or an app can put in the product_id of a form: one number, or something that holds several. */
    public static function productIdsThatHoldSeveralIds(): array
    {
        return [
            'a list' => [fn (int $first, int $second) => [$first, $second]],
            'a list with numbered keys' => [fn (int $first, int $second) => [3 => $first, 7 => $second]],
            'a list with named keys' => [fn (int $first, int $second) => ['a' => $first, 'b' => $second]],
            'a list of lists' => [fn (int $first, int $second) => [[$first], [$second]]],
        ];
    }

    #[DataProvider('productIdsThatHoldSeveralIds')]
    public function test_a_product_id_that_holds_several_ids_does_not_open_the_reviews_of_a_hidden_product_behind_a_visible_one(Closure $severalIds): void
    {
        $this->seedOneReviewPerProduct();
        $unknown = $this->postJson('/review-list-product', ['product_id' => 999, 'offset' => 0]);

        foreach (self::HIDDEN_PRODUCTS as $hiddenId) {
            foreach ([[$hiddenId, 1], [1, $hiddenId]] as [$first, $second]) {
                $response = $this->postJson('/review-list-product', ['product_id' => $severalIds($first, $second), 'offset' => 0]);

                $this->assertStringNotContainsString("Review of {$hiddenId}", $response->getContent(), "ids {$first} and {$second}");
                $this->assertSame($unknown->getStatusCode(), $response->getStatusCode(), "ids {$first} and {$second}");
                $this->assertSame($unknown->getContent(), $response->getContent(), "ids {$first} and {$second}");
            }
        }
    }

    public function test_several_product_ids_sent_as_a_form_or_in_the_query_string_are_both_refused(): void
    {
        $this->seedOneReviewPerProduct();

        $form = $this->post('/review-list-product', ['product_id' => [2, 1], 'offset' => 0], ['Accept' => 'application/json']);
        $queryString = $this->post('/review-list-product?product_id[]=2&product_id[]=1', ['offset' => 0], ['Accept' => 'application/json']);

        $form->assertNotFound();
        $queryString->assertNotFound();
        $this->assertStringNotContainsString('Review of 2', $form->getContent() . $queryString->getContent());
    }

    public static function visibleProductIds(): array
    {
        return [
            'a number' => [1],
            'a numeric string, the way a form carries it' => ['1'],
        ];
    }

    #[DataProvider('visibleProductIds')]
    public function test_a_form_post_of_a_single_visible_product_id_still_lists_its_reviews(int|string $productId): void
    {
        $this->seedOneReviewPerProduct();

        $response = $this->post('/review-list-product', ['product_id' => $productId, 'offset' => 0], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, $response->json('not_empty'));
        $this->assertStringContainsString('Review of 1', $response->json('productReview'));
    }

    public function test_a_form_post_of_a_single_hidden_or_unknown_product_id_is_still_not_found_with_the_same_answer(): void
    {
        $this->seedOneReviewPerProduct();
        $unknown = $this->post('/review-list-product', ['product_id' => 999, 'offset' => 0], ['Accept' => 'application/json'])->assertNotFound();

        foreach (self::HIDDEN_PRODUCTS as $hiddenId) {
            $hidden = $this->post('/review-list-product', ['product_id' => (string) $hiddenId, 'offset' => 0], ['Accept' => 'application/json'])->assertNotFound();

            $this->assertSame($unknown->getContent(), $hidden->getContent(), "product {$hiddenId}");
        }
    }

    /** The in-house shop is open and its shipping is a flat cost per product: what the cart reads before it adds a line. */
    private function openTheShopForTheCart(): void
    {
        $this->setBusinessSetting('shipping_method', 'inhouse_shipping');
        $this->setBusinessSetting('temporary_close', ['status' => 0]);
        $this->setBusinessSetting('vacation_add', ['status' => 0, 'vacation_start_date' => null, 'vacation_end_date' => null, 'vacation_note' => '']);
        DB::table('shipping_types')->insert(['seller_id' => 0, 'shipping_type' => 'product_wise']);
        DB::table('users')->insert(['id' => 1, 'name' => 'Rev Iewer', 'f_name' => 'Rev', 'l_name' => 'Iewer', 'email' => 'reviewer@example.test', 'phone' => '0500000001']);
        $this->useThrowawayPassportKeys();
        Cache::flush();
    }

    /** The ways a customer or a guest puts a product in a cart: the app and the storefront, each as a guest and signed in, and the shop check of a product card. */
    public static function cartDoors(): array
    {
        return [
            'the app as a guest' => ['app as guest'],
            'the app as a signed in customer' => ['app as customer'],
            'the storefront as a guest' => ['storefront as guest'],
            'the storefront as a signed in customer' => ['storefront as customer'],
            'the shop check of a product card' => ['shop check'],
        ];
    }

    private function putInTheCart(string $door, int $productId): TestResponse
    {
        $customer = User::find(1);

        return match ($door) {
            'app as guest' => $this->postJson('/api/v1/cart/add', ['id' => $productId, 'guest_id' => 'guest-1', 'quantity' => 1, 'variant' => null, 'buy_now' => 0]),
            'app as customer' => $this->actingAs($customer, 'api')->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1, 'variant' => null, 'buy_now' => 0]),
            'storefront as guest' => $this->withSession(['guest_id' => 'guest-1'])->postJson('/cart/add', ['id' => $productId, 'quantity' => 1], self::AJAX),
            'storefront as customer' => $this->actingAs($customer, 'customer')->postJson('/cart/add', ['id' => $productId, 'quantity' => 1], self::AJAX),
            'shop check' => $this->withSession(['guest_id' => 'guest-1'])->getJson('/ajax-shop-vacation-check?' . http_build_query(['id' => $productId, 'added_by' => 'admin']), self::AJAX),
        };
    }

    #[DataProvider('cartDoors')]
    public function test_a_visible_product_is_added_to_the_cart_and_the_answer_carries_it(string $door): void
    {
        $this->openTheShopForTheCart();

        $response = $this->putInTheCart($door, productId: 1)->assertOk();

        $this->assertSame(1, $response->json('status'));
        $this->assertSame('Alpha lamp', $response->json('cart.name'));
        $this->assertSame(1, DB::table('carts')->where('product_id', 1)->count());
    }

    #[DataProvider('cartDoors')]
    public function test_an_unknown_product_is_refused_as_unavailable_and_not_with_a_server_error(string $door): void
    {
        $this->openTheShopForTheCart();

        $response = $this->putInTheCart($door, productId: 999)->assertOk();

        $this->assertSame(['status' => 0, 'message' => translate('Product_is_unavailable')], $response->json());
        $this->assertSame(0, DB::table('carts')->count());
    }

    #[DataProvider('cartDoors')]
    public function test_a_product_a_customer_may_not_see_is_not_added_to_the_cart_and_is_refused_like_an_unknown_one(string $door): void
    {
        $this->openTheShopForTheCart();
        $unknown = $this->putInTheCart($door, productId: 999);

        foreach (self::HIDDEN_PRODUCTS as $hiddenId) {
            $hidden = $this->putInTheCart($door, productId: $hiddenId);

            $this->assertSame(['status' => 0, 'message' => translate('Product_is_unavailable')], $hidden->json(), "product {$hiddenId}");
            $this->assertSame($unknown->getStatusCode(), $hidden->getStatusCode(), "product {$hiddenId}");
            $this->assertSame($unknown->getContent(), $hidden->getContent(), "product {$hiddenId}");
            $this->assertSame(0, DB::table('carts')->count(), "product {$hiddenId}");
        }
    }

    public function test_a_plain_form_post_of_a_hidden_product_goes_back_with_the_same_answer_as_one_of_an_unknown_product(): void
    {
        $this->openTheShopForTheCart();
        $unknown = $this->post('/cart/add', ['id' => 999, 'quantity' => 1])->assertRedirect();

        $this->assertStringContainsString(translate('Product_is_unavailable'), json_encode(session('toastr::messages')));
        foreach (self::HIDDEN_PRODUCTS as $hiddenId) {
            $hidden = $this->post('/cart/add', ['id' => $hiddenId, 'quantity' => 1])->assertRedirect();

            $this->assertSame($unknown->headers->get('Location'), $hidden->headers->get('Location'), "product {$hiddenId}");
        }
        $this->assertSame(0, DB::table('carts')->count());
    }

    public function test_the_wishlist_adds_the_visible_products_to_the_cart_and_passes_over_those_a_customer_may_not_see(): void
    {
        $this->openTheShopForTheCart();
        $wishedProductIds = [999, ...self::HIDDEN_PRODUCTS, 1, 3];
        $wishlist = ['items' => ['data' => array_map(fn (int $id) => ['product_id' => $id, 'product_full_info' => ['minimum_order_qty' => 1]], $wishedProductIds)]];

        $this->withSession(['guest_id' => 'guest-1'])->postJson('/cart/add-all-to-cart', $wishlist)->assertOk()->assertJson(['success' => true]);

        $this->assertEquals([1, 3], DB::table('carts')->orderBy('product_id')->pluck('product_id')->all());
    }

    public function test_the_search_hint_names_the_words_that_match_nothing(): void
    {
        $this->indexEveryProduct();

        $response = $this->getJson('/searched-products?name=nonexistentwordxyz')->assertOk();

        $this->assertSame(['nonexistentwordxyz'], $response->json('unmatched_terms'));
    }

    public function test_the_search_hint_does_not_tell_which_words_a_product_a_customer_may_not_see_contains(): void
    {
        DB::table('products')->where('id', 2)->update(['details' => '<p>Unreleased crimsonwidget edition</p>']);
        $this->indexEveryProduct();

        foreach (['hidden', 'draft', 'branded', 'crimsonwidget'] as $wordOfAHiddenProduct) {
            $response = $this->getJson("/searched-products?name={$wordOfAHiddenProduct}")->assertOk();

            $this->assertSame([$wordOfAHiddenProduct], $response->json('unmatched_terms'), $wordOfAHiddenProduct);
        }
    }

    public function test_a_banner_of_a_visible_product_carries_the_product(): void
    {
        $this->insertBanner(id: 1, productId: 1);

        $banners = $this->getJson('/api/v1/banners')->assertOk()->json();

        $this->assertSame([1], collect($banners)->pluck('id')->all());
        $this->assertSame('Alpha lamp', $banners[0]['product']['name']);
    }

    public function test_a_banner_of_a_product_a_customer_may_not_see_is_left_out_and_embeds_nothing(): void
    {
        $this->insertBanner(id: 1, productId: 1);
        foreach (self::HIDDEN_PRODUCTS as $position => $hiddenId) {
            $this->insertBanner(id: 10 + $position, productId: $hiddenId);
        }
        $this->insertBanner(id: 20, productId: 2);
        DB::table('banners')->insert(['id' => 30, 'theme' => 'default', 'title' => 'Lamps', 'resource_type' => 'category', 'resource_id' => self::CATEGORY, 'banner_type' => 'Main Banner', 'published' => 1]);
        Cache::flush();

        $response = $this->getJson('/api/v1/banners')->assertOk();

        $this->assertSame([1, 30], collect($response->json())->pluck('id')->sort()->values()->all());
        foreach (['Alpha hidden lamp', 'Alpha draft lamp', 'Alpha pending seller lamp', 'Beta branded lamp'] as $hiddenName) {
            $this->assertStringNotContainsString($hiddenName, $response->getContent());
        }
    }

    private function insertBanner(int $id, int $productId): void
    {
        DB::table('banners')->insert([
            'id' => $id, 'theme' => 'default', 'title' => "Promo {$id}", 'resource_type' => 'product', 'resource_id' => $productId,
            'banner_type' => 'Main Banner', 'published' => 1,
        ]);
        Cache::flush();
    }
}
