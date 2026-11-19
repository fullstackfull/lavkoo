<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * I2-13: what a storefront that may not sell leaves open. A storefront that a person has paused browse only, or in redirect (which, for the API, is browse only: no route of the API redirects
 * anywhere), that is still a draft, that is ready and not yet approved, or that is active but whose legal texts are not approved, is still browsed: its customers fill, change, read and empty the
 * cart, keep their wishlist and read the catalogue and the details of a product exactly as they do while it sells, and only the start of a new sale (the checkout and the start of a payment) is
 * refused, with the message of the storefront (the one a person gave when pausing it, or the platform's own while it is not open yet). In maintenance the cart and the catalogue are refused with
 * the 503 and the message, because they are what browses a storefront (EnsureStorefrontIsAvailable), and the wishlist is not. That the routes which serve an order a customer already has stay open
 * in every mode is driven in StorefrontStopContinuityTest. Which routes carry which gate is pinned in StorefrontSalesRoutesTest and in the structural cases of StorefrontStopContinuityTest; this
 * class drives what the routes answer.
 *
 * Each case is run for a storefront that sells as well (the control), so the answers asserted are the answers of a storefront that sells, and the sale is taken there. The default storefront can
 * only be stopped browse only (I2-17), and is never a draft.
 *
 * The wishlist routes carry neither the storefront middleware nor a stop gate: they are not storefront scoped. The wishlist cases use the product of the storefront being driven, so they say
 * nothing about a product of another storefront, which the same routes list and accept. The cart routes driven are add, list, update and remove; the others of the group (remove all, select,
 * restock request, referral discount, merge of a guest cart) are not.
 */
class StorefrontStopBrowsingTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const PERSON = 'The head of the operations team';

    private const REASON = 'Decision of the operations meeting';

    private const MESSAGE = 'Orders are closed until Sunday.';

    private const LEGAL_TEXTS_NOT_APPROVED = 'legal_texts_not_approved';

    private const CART = '/api/v1/cart';

    private const CART_ADD = '/api/v1/cart/add';

    private const CART_UPDATE = '/api/v1/cart/update';

    private const CART_REMOVE = '/api/v1/cart/remove';

    private const WISHLIST = '/api/v1/customer/wish-list';

    private const PRODUCT_LIST = '/api/v1/products/latest?limit=10&offset=1';

    private const PRODUCT_DETAILS = '/api/v1/products/details/';

    private const PLACE_ORDER = '/api/v1/customer/order/place';

    private const DIGITAL_PAYMENT = '/api/v1/digital-payment';

    /** The product each storefront sells in this world, its name and its price in its own currency. */
    private const PRODUCTS = [
        'KW' => ['id' => 3, 'slug' => 'paracetamol-kw', 'name' => 'Paracetamol KW', 'price' => 30],
        'SA' => ['id' => 1, 'slug' => 'paracetamol-sa', 'name' => 'Paracetamol SA', 'price' => 100],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->addWhatTheCatalogueAndTheWishlistAreReadFrom();
    }

    /** @return array<string, array{0: string, 1: ?string}> the storefront and what keeps it from selling: a stop mode that browses, or a state it has not left; none is the control, a storefront that sells */
    public static function everyStorefrontThatMayNotSellAndItsControl(): array
    {
        return [
            'Kuwait, not stopped' => ['KW', null],
            'the default storefront, not stopped' => ['SA', null],
            'Kuwait, browse only' => ['KW', StorefrontLifecycle::BROWSE_ONLY],
            'Kuwait, redirect' => ['KW', StorefrontLifecycle::REDIRECT],
            'the default storefront, browse only' => ['SA', StorefrontLifecycle::BROWSE_ONLY],
            'Kuwait, a draft' => ['KW', StorefrontLifecycle::DRAFT],
            'Kuwait, ready and not yet approved' => ['KW', StorefrontLifecycle::READY],
            'Kuwait, active but its legal texts are not approved' => ['KW', self::LEGAL_TEXTS_NOT_APPROVED],
        ];
    }

    /** What the catalogue, the details of a product and the wishlist read besides the cart: the checkout world has no table for them. */
    private function addWhatTheCatalogueAndTheWishlistAreReadFrom(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->integer('rating')->default(0);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
        });
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
        });
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('tag')->nullable();
        });
        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
        });
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
        });
        Schema::create('restock_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_product_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restock_product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('flash_deal_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flash_deal_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
        });
        Schema::create('digital_product_authors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->unsignedBigInteger('product_id');
        });
        Schema::create('digital_product_publishing_houses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('publishing_house_id');
            $table->unsignedBigInteger('product_id');
        });
    }

    /** Keeps the storefront from selling: paused in the mode, put back to a draft or to ready, or left without the legal texts an active one needs. No hold is the control. */
    private function applyTheHold(string $country, ?string $hold, ?string $message = null): void
    {
        if ($hold === null) {
            return;
        }

        if (in_array($hold, StorefrontLifecycle::MODES, true)) {
            app(StorefrontLifecycle::class)->pause(country: $country, mode: $hold, by: self::PERSON, reason: self::REASON, message: $message);

            return;
        }

        if ($hold === self::LEGAL_TEXTS_NOT_APPROVED) {
            config(['gcc.storefronts.legal.grandfathered' => array_values(array_diff(config('gcc.storefronts.legal.grandfathered'), [$country]))]);

            return;
        }

        DB::table('business_settings')->where('type', StorefrontLifecycle::settingName($country))->delete();
        clearWebConfigCacheKeys();

        if ($hold === StorefrontLifecycle::READY) {
            app(StorefrontLifecycle::class)->markReady(country: $country, by: self::PERSON, reason: self::REASON);
        }
    }

    /** What the customers are told: the message a person gave when pausing, or the one of the platform for a storefront that is not open yet. */
    private function messageToTheCustomersWhile(string $hold): string
    {
        return in_array($hold, StorefrontLifecycle::MODES, true) ? self::MESSAGE : translate('storefront_not_open_for_checkout_yet');
    }

    private function requestAPayment(User $customer, string $country): TestResponse
    {
        return $this->asTheApp(customer: $customer, country: $country)->postJson(self::DIGITAL_PAYMENT, [
            'payment_method' => 'fatoorah', 'payment_platform' => 'app', 'payment_request_from' => 'app', 'customer_id' => $customer->id, 'is_guest' => 0,
        ]);
    }

    // ---- the cart, the wishlist and the catalogue --------------------------------------------------------------------------------------------

    #[DataProvider('everyStorefrontThatMayNotSellAndItsControl')]
    public function test_the_cart_is_filled_changed_and_emptied_while_the_storefront_may_not_sell(string $country, ?string $hold): void
    {
        $product = self::PRODUCTS[$country];
        $customer = $this->makeCustomer();
        $this->applyTheHold(country: $country, hold: $hold);

        $added = $this->asTheApp(customer: $customer, country: $country)->postJson(self::CART_ADD, ['id' => $product['id'], 'quantity' => 1])->assertOk();
        $this->assertSame(1, $added->json('status'));
        $row = DB::table('carts')->first();
        $this->assertSame([$customer->id, $product['id'], 1], [(int) $row->customer_id, (int) $row->product_id, (int) $row->quantity]);

        $updated = $this->asTheApp(customer: $customer, country: $country)->putJson(self::CART_UPDATE, ['key' => $row->id, 'quantity' => 2])->assertOk();
        $this->assertSame([1, 2], [$updated->json('status'), $updated->json('qty')]);
        $this->assertSame(2, (int) DB::table('carts')->where('id', $row->id)->value('quantity'));

        $removed = $this->asTheApp(customer: $customer, country: $country)->deleteJson(self::CART_REMOVE, ['key' => $row->id])->assertOk();
        $this->assertSame(translate('successfully_removed'), $removed->json());
        $this->assertSame(0, DB::table('carts')->count());
    }

    #[DataProvider('everyStorefrontThatMayNotSellAndItsControl')]
    public function test_the_cart_of_a_customer_is_shown_while_the_storefront_may_not_sell(string $country, ?string $hold): void
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();
        $product = self::PRODUCTS[$country];
        $customer = $this->makeCustomer();
        $this->applyTheHold(country: $country, hold: $hold);
        $this->fillTheCartFrom(customer: $customer, country: $country, productId: $product['id']);

        $listed = $this->asTheApp(customer: $customer, country: $country)->getJson(self::CART)->assertOk();

        $this->assertSame([$product['id']], collect($listed->json())->pluck('product_id')->all());
        $this->assertSame([$product['name'], 1], [$listed->json('0.name'), $listed->json('0.is_product_available')]);
        $this->assertEquals($product['price'], $listed->json('0.price'));
    }

    #[DataProvider('everyStorefrontThatMayNotSellAndItsControl')]
    public function test_the_wishlist_is_filled_shown_and_emptied_while_the_storefront_may_not_sell(string $country, ?string $hold): void
    {
        $product = self::PRODUCTS[$country];
        $customer = $this->makeCustomer();
        $this->applyTheHold(country: $country, hold: $hold);

        $this->asTheApp(customer: $customer, country: $country)->postJson(self::WISHLIST . '/add', ['product_id' => $product['id']])->assertOk()->assertJsonPath('message', 'successfully added!');
        $this->assertSame([$customer->id, $product['id']], [(int) DB::table('wishlists')->value('customer_id'), (int) DB::table('wishlists')->value('product_id')]);
        $this->asTheApp(customer: $customer, country: $country)->postJson(self::WISHLIST . '/add', ['product_id' => $product['id']])->assertStatus(409)->assertJsonPath('message', 'Already in your wishlist');

        $listed = $this->asTheApp(customer: $customer, country: $country)->getJson(self::WISHLIST)->assertOk();
        $this->assertSame([$product['id']], collect($listed->json())->pluck('product_id')->all());
        $this->assertSame($product['name'], $listed->json('0.productFullInfo.name'));

        $this->asTheApp(customer: $customer, country: $country)->deleteJson(self::WISHLIST . '/remove', ['product_id' => $product['id']])->assertOk()->assertJsonPath('message', translate('successfully removed!'));
        $this->assertSame(0, DB::table('wishlists')->count());
        $this->asTheApp(customer: $customer, country: $country)->getJson(self::WISHLIST)->assertOk()->assertExactJson([]);
    }

    #[DataProvider('everyStorefrontThatMayNotSellAndItsControl')]
    public function test_the_products_and_the_details_of_a_product_are_shown_while_the_storefront_may_not_sell(string $country, ?string $hold): void
    {
        $product = self::PRODUCTS[$country];
        $customer = $this->makeCustomer();
        $this->applyTheHold(country: $country, hold: $hold);

        $listed = $this->asTheApp(customer: $customer, country: $country)->getJson(self::PRODUCT_LIST)->assertOk();
        $this->assertSame([1, [$product['id']]], [$listed->json('total_size'), collect($listed->json('products'))->pluck('id')->all()]);

        $details = $this->asTheApp(customer: $customer, country: $country)->getJson(self::PRODUCT_DETAILS . $product['slug'])->assertOk();
        $this->assertSame([$product['id'], $product['name']], [$details->json('id'), $details->json('name')]);
        $this->assertEquals($product['price'], $details->json('unit_price'));
    }

    // ---- a new sale -------------------------------------------------------------------------------------------------------------------------

    #[DataProvider('everyStorefrontThatMayNotSellAndItsControl')]
    public function test_the_checkout_is_refused_with_the_message_of_a_storefront_that_may_not_sell_and_the_control_takes_the_sale(string $country, ?string $hold): void
    {
        if ($hold === null) {
            $this->skipInStrictModeUntilTheCouponCountIsFixed();
        }
        $product = self::PRODUCTS[$country];
        $shopper = $this->makeCustomer();
        $this->applyTheHold(country: $country, hold: $hold, message: self::MESSAGE);
        $this->fillTheCartFrom(customer: $shopper, country: $country, productId: $product['id']);
        $orders = DB::table('orders')->count();
        $stock = $this->stockOf(country: $country);

        $response = $this->asTheApp(customer: $shopper, country: $country)->getJson(self::PLACE_ORDER);

        if ($hold === null) {
            $response->assertOk();
            $this->assertSame([$orders + 1, $stock - 1, 0], [DB::table('orders')->count(), $this->stockOf(country: $country), DB::table('carts')->where('customer_id', $shopper->id)->count()], 'a storefront that sells takes the sale');

            return;
        }

        $response->assertStatus(422)->assertExactJson(['code' => 'storefront_not_sellable', 'message' => $this->messageToTheCustomersWhile(hold: $hold)]);
        $this->assertSame([$orders, $stock, 1], [DB::table('orders')->count(), $this->stockOf(country: $country), DB::table('carts')->where('customer_id', $shopper->id)->count()], 'a refused sale saves no order, takes no stock and keeps the cart');
    }

    #[DataProvider('everyStorefrontThatMayNotSellAndItsControl')]
    public function test_the_start_of_a_payment_is_refused_with_the_message_of_a_storefront_that_may_not_sell_and_the_control_starts_it(string $country, ?string $hold): void
    {
        if ($hold === null) {
            $this->skipInStrictModeUntilThePaymentStartIsFixed();
        }
        config(['gcc.storefronts.currency.verified_three_decimal_gateways' => ['fatoorah']]);
        $shopper = $this->makeCustomer();
        $this->applyTheHold(country: $country, hold: $hold, message: self::MESSAGE);
        $this->fillTheCartFrom(customer: $shopper, country: $country, productId: self::PRODUCTS[$country]['id']);

        $response = $this->requestAPayment(customer: $shopper, country: $country);

        if ($hold === null) {
            $response->assertOk()->assertJsonStructure(['redirect_link']);
            $this->assertSame(1, DB::table('payment_requests')->count(), 'a storefront that sells starts the payment');

            return;
        }

        $response->assertStatus(422)->assertExactJson(['code' => 'storefront_not_sellable', 'message' => $this->messageToTheCustomersWhile(hold: $hold)]);
        $this->assertSame(0, DB::table('payment_requests')->count(), 'a refused payment is not recorded');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $shopper->id)->count(), 'and the cart is kept');
    }

    // ---- maintenance ------------------------------------------------------------------------------------------------------------------------

    public function test_maintenance_refuses_the_cart_and_the_catalogue_with_the_message_and_the_end_and_leaves_the_wishlist_alone(): void
    {
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: 'KW', productId: self::PRODUCTS['KW']['id']);
        $row = DB::table('carts')->first();
        $until = now()->utc()->addDays(2)->format('Y-m-d H:i');
        app(StorefrontLifecycle::class)->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON, message: 'We are back on Sunday, with our thanks.', until: $until);
        $refusal = ['code' => 'storefront_maintenance', 'message' => 'We are back on Sunday, with our thanks.', 'until' => $until];
        $app = fn () => $this->asTheApp(customer: $customer, country: 'KW');

        $app()->postJson(self::CART_ADD, ['id' => self::PRODUCTS['KW']['id'], 'quantity' => 1])->assertStatus(503)->assertExactJson($refusal);
        $app()->getJson(self::CART)->assertStatus(503)->assertExactJson($refusal);
        $app()->putJson(self::CART_UPDATE, ['key' => $row->id, 'quantity' => 2])->assertStatus(503)->assertExactJson($refusal);
        $app()->deleteJson(self::CART_REMOVE, ['key' => $row->id])->assertStatus(503)->assertExactJson($refusal);
        $app()->getJson(self::PRODUCT_LIST)->assertStatus(503)->assertExactJson($refusal);
        $app()->getJson(self::PRODUCT_DETAILS . self::PRODUCTS['KW']['slug'])->assertStatus(503)->assertExactJson($refusal);
        $this->assertSame([[$row->id, 1]], DB::table('carts')->get()->map(fn (object $cart) => [$cart->id, (int) $cart->quantity])->all(), 'the refused cart add added nothing, the refused update changed nothing and the refused remove removed nothing');

        $app()->postJson(self::WISHLIST . '/add', ['product_id' => self::PRODUCTS['KW']['id']])->assertOk()->assertJsonPath('message', 'successfully added!');
        $listed = $app()->getJson(self::WISHLIST)->assertOk();
        $this->assertSame([self::PRODUCTS['KW']['id']], collect($listed->json())->pluck('product_id')->all());
        $app()->deleteJson(self::WISHLIST . '/remove', ['product_id' => self::PRODUCTS['KW']['id']])->assertOk()->assertJsonPath('message', translate('successfully removed!'));
        $this->assertSame(0, DB::table('wishlists')->count());
    }

    public function test_the_maintenance_of_one_storefront_leaves_the_cart_and_the_catalogue_of_another_open(): void
    {
        $customer = $this->makeCustomer();
        app(StorefrontLifecycle::class)->pause(country: 'KW', mode: StorefrontLifecycle::MAINTENANCE, by: self::PERSON, reason: self::REASON);

        $this->fillTheCartFrom(customer: $customer, country: 'SA', productId: self::PRODUCTS['SA']['id']);
        $this->asTheApp(customer: $customer, country: 'SA')->getJson(self::PRODUCT_LIST)->assertOk()->assertJsonPath('total_size', 1);
        $this->asTheApp(customer: $customer, country: 'KW')->getJson(self::PRODUCT_LIST)->assertStatus(503);
    }
}
