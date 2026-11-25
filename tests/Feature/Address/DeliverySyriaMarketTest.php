<?php

namespace Tests\Feature\Address;

use App\Services\DeliverySyria\DeliverySyriaConfigService;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontShippingMethods;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * H1-11's last criterion: a Gulf storefront never reaches the legacy Delivery Syria hook.
 *
 * Delivery Syria is a Syrian courier. The hook in `OrderManager` keys on ONE thing -- that the order's shipping method is named
 * `DeliverySyria` and is switched on -- and says nothing at all about where the parcel is going. A market lists only its own methods since
 * H1-14, so a Gulf order carrying that method should not exist; but the id on an order is whatever was stored, and an id from before
 * storefronts, or one an API caller chose for itself, would arrive at that hook with nothing to stop it. The parcel would be handed to a
 * company that does not deliver in that country.
 *
 * The hook now also asks whether the method belongs to the market the order was sold in, by the rule `StorefrontMarketRows` already holds.
 * It refuses only a DISAGREEMENT, which is what the first and last tests here are for: with storefronts off, and for an order that records no
 * market, the hook runs exactly as it always has.
 *
 * The disagreement is staged from the SAUDI side -- a Saudi order carrying a method of another market -- and not by placing a Kuwaiti order.
 * The rule is symmetrical, the hook is the same hook, and a Kuwaiti order would first have to pass the storefront's own selling gate, which
 * is a market readiness world this test has no business building to prove a courier question. Which way round the markets sit is covered as
 * well, by asking the rule itself for a Gulf market in the last test.
 *
 * Every test drives the REAL hook through a placed order, and reads how far the hook GOT from the lines it writes as it goes. `shipping cost
 * loaded` is the first thing the hook logs after the guard, so its presence is the hook continuing and its absence is the hook stopping. That
 * is the seam rather than the courier's own HTTP call, because reaching the call needs a governorate rate table, a weight on the product and
 * a state on the address -- a world this test would have to build to prove something it can read directly. A test of the gate alone would
 * prove nothing at all about the hook: the whole defect is that the hook did not ask.
 *
 * `shipping_methods.name` is the column the hook reads and is NOT in this repository's migrations or in the model's own property list -- the
 * table comes from the installation dump. It is created here because the hook reads it; whether the live table has it is UNVERIFIED and
 * recorded as such in the H1-11 card. If it does not, the hook has never fired on any installation, which the card also says.
 */
class DeliverySyriaMarketTest extends ApiAddressTestCase
{
    private const COD = '/api/v1/customer/order/place';

    private const METHOD_ID = 1;

    private const DELIVERY_SYRIA_HOST = 'https://delivery-syria.test';

    /** The first line the hook writes AFTER the market guard: its presence is the hook continuing. */
    private const PAST_THE_GUARD = 'Delivery Syria: shipping cost loaded';

    /** What the guard itself writes when it refuses. */
    private const REFUSED = 'Delivery Syria: the order belongs to a market this shipping method does not';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->giveShippingMethodsANameAndAMarket();
        $this->chargeShippingOrderWise();
        $this->switchDeliverySyriaOn();
        Http::fake([self::DELIVERY_SYRIA_HOST . '/*' => Http::response(['id' => 'DS-1', 'tracking_number' => 'T-1'])]);
        Event::listen(MessageLogged::class, function (MessageLogged $logged): void {
            $this->logged[] = (string) $logged->message;
        });
    }

    public function test_with_storefronts_off_the_hook_runs_exactly_as_it_always_has(): void
    {
        $this->methodOf(market: 'SA');

        $this->placeAnOrder();

        $this->assertSame(1, DB::table('orders')->count(), 'the order is placed either way');
        $this->assertDeliverySyriaWasAsked(true);
    }

    /** The default storefront: its own methods, and the ones that name no market, which is every method this platform has. */
    public function test_the_default_storefront_still_reaches_the_hook(): void
    {
        $this->orderSoldIn(market: 'SA');
        $this->methodOf(market: null);

        $this->placeAnOrder();

        $this->assertSame('SA', DB::table('orders')->value('storefront_country'), 'the order records the market it was sold in');
        $this->assertDeliverySyriaWasAsked(true);
    }

    /** A market that owns the method reaches its own courier. */
    public function test_a_market_that_owns_the_method_reaches_the_hook(): void
    {
        $this->orderSoldIn(market: 'SA');
        $this->methodOf(market: 'SA');

        $this->placeAnOrder();

        $this->assertDeliverySyriaWasAsked(true);
    }

    /**
     * The criterion: an order is not handed to a courier whose method belongs to another market.
     *
     * The hook's own test is the method's NAME, which says nothing about where the parcel is going, so without this the order would be sent
     * to a Syrian courier because of a row that belongs to Kuwait.
     */
    public function test_an_order_whose_market_the_method_does_not_serve_never_reaches_the_hook(): void
    {
        $this->orderSoldIn(market: 'SA');
        $this->methodOf(market: 'KW');

        $this->placeAnOrder();

        $this->assertSame(1, DB::table('orders')->count(), 'the order is still placed; only the courier hook is refused');
        $this->assertDeliverySyriaWasAsked(false);
    }

    /** An order that records no market cannot disagree with anything, so it is not refused: that is every order of the platform today. */
    public function test_an_order_that_records_no_market_reaches_the_hook(): void
    {
        $this->methodOf(market: 'KW');

        $this->placeAnOrder();

        // Asked of the SCHEMA and not with a select: SQLite reads an unknown double-quoted name as a string literal, so
        // `value('storefront_country')` on a table without the column answers the column's own name rather than failing.
        $this->assertFalse(Schema::hasColumn('orders', 'storefront_country'), 'nothing stamped it, because storefronts are off');
        $this->assertDeliverySyriaWasAsked(true);
    }

    /** Before the table carries a market at all, nothing can disagree and the hook runs as it did. */
    public function test_before_the_column_exists_the_hook_runs_as_it_did(): void
    {
        // No method carries a market here, deliberately: the migration refuses to roll back while one does, because dropping the column
        // would forget which market it belonged to. That refusal has its own test in ShippingMethodStorefrontTest.
        $this->methodOf(market: null);
        (require database_path('migrations/2026_12_22_100000_add_storefront_country_to_shipping_methods.php'))->down();
        app(StorefrontShippingMethods::class)->forget();
        $this->orderSoldIn(market: 'SA');

        $this->placeAnOrder();

        $this->assertDeliverySyriaWasAsked(true);
    }

    /**
     * And the rule the other way round, asked of the rule itself: a Gulf market is not served by a method of the default storefront, whether
     * that method names its own market or names none.
     *
     * The hook asks this exact question -- the test above proves it asks -- so a Gulf storefront reaches the Syrian courier in neither case.
     */
    public function test_a_gulf_market_is_served_by_neither_a_saudi_method_nor_an_unmarked_one(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => 'SA']);
        $methods = app(StorefrontShippingMethods::class);

        $this->methodOf(market: 'SA');
        $this->assertFalse($methods->mayBeUsedBy(methodId: self::METHOD_ID, market: 'KW'), 'a Saudi method does not serve Kuwait');
        $this->assertTrue($methods->mayBeUsedBy(methodId: self::METHOD_ID, market: 'SA'));

        $this->methodOf(market: null);
        $this->assertFalse($methods->mayBeUsedBy(methodId: self::METHOD_ID, market: 'KW'), 'an unmarked method is the default storefront\'s');
        $this->assertTrue($methods->mayBeUsedBy(methodId: self::METHOD_ID, market: 'SA'));
        $this->assertTrue($methods->mayBeUsedBy(methodId: self::METHOD_ID, market: null), 'and an order with no market is never a disagreement');
    }

    private function placeAnOrder(): void
    {
        $customer = $this->makeCustomer();
        $address = $this->makeLegacyAddress($customer->id);
        $this->putOneProductInTheCart($customer->id);
        DB::table('cart_shippings')->insert([
            'cart_group_id' => 'group-' . $customer->id . 'c',
            'shipping_method_id' => self::METHOD_ID,
            'shipping_cost' => 15,
        ]);

        $this->asCustomer($customer)->getJson(self::COD . '?address_id=' . $address->id)->assertOk();
    }

    private function assertDeliverySyriaWasAsked(bool $expected, string $reason = ''): void
    {
        $this->assertSame($expected, in_array(self::PAST_THE_GUARD, $this->logged, true), $reason !== '' ? $reason : ($expected
            ? 'the hook must carry on past the guard for this order'
            : 'the hook must stop at the guard: the order belongs to a market this method does not'));

        if (!$expected) {
            $this->assertContains(self::REFUSED, $this->logged, 'and it must say why, naming the order, the method and the market');
        }
    }

    private function methodOf(?string $market): void
    {
        DB::table('shipping_methods')->updateOrInsert(
            ['id' => self::METHOD_ID],
            [
                'creator_id' => 0,
                'creator_type' => 'admin',
                'title' => 'Delivery Syria',
                'name' => 'DeliverySyria',
                'cost' => 15,
                'duration' => '3',
                'status' => true,
                StorefrontShippingMethods::COLUMN => $market,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        app(StorefrontShippingMethods::class)->forget();
    }

    /**
     * The market an order will RECORD, which is the country of its lines and not the storefront the request came from
     * (`OrderStorefrontSnapshot`): an order belongs to the market whose catalogue it was bought out of, and that is what survives in the
     * record once the request is gone. So the product is given the market, and the two switches the snapshot asks for are on.
     */
    private function orderSoldIn(string $market): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.default_country' => 'SA',
        ]);
        app()->instance(StorefrontContext::class, new StorefrontContext(country: $market, currency: 'SAR', storefrontsEnabled: true));

        if (!Schema::hasColumn('products', StorefrontCatalog::COLUMN)) {
            Schema::table('products', fn (Blueprint $table) => $table->char(StorefrontCatalog::COLUMN, 2)->nullable());
        }

        if (!DB::table('products')->where('id', 1)->exists()) {
            DB::table('products')->insert(['id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 100, 'current_stock' => 10]);
        }

        DB::table('products')->update([StorefrontCatalog::COLUMN => $market]);

        if (!Schema::hasColumn('orders', 'storefront_country')) {
            (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        }

        StorefrontCatalog::flush();
        app(StorefrontShippingMethods::class)->forget();
    }

    private function giveShippingMethodsANameAndAMarket(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table): void {
            if (!Schema::hasColumn('shipping_methods', 'name')) {
                $table->string('name')->nullable();
            }
        });

        (require database_path('migrations/2026_12_22_100000_add_storefront_country_to_shipping_methods.php'))->up();
        app(StorefrontShippingMethods::class)->forget();
    }

    /**
     * A shop that charges order-wise, which is what keeps a cart-shipping row at all -- and the cart-shipping row is what puts a
     * `shipping_method_id` on the order. A shop charging per product keeps none, and the hook stops at its own first test, two steps
     * before the guard: that is how the first version of this test passed its refusal cases and proved nothing.
     */
    private function chargeShippingOrderWise(): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => 'shipping_method'], ['value' => 'inhouse_shipping']);
    }

    /** Enabled with a secret, which is what `isReadyForOutbound()` asks: without both, the hook stops before the guard and proves nothing. */
    private function switchDeliverySyriaOn(): void
    {
        $configuration = [
            'status' => 1,
            'base_url' => self::DELIVERY_SYRIA_HOST,
            'platform' => 'lavkoo',
            'secret_token' => Crypt::encryptString('a-secret-token'),
            'webhook_token' => Crypt::encryptString('a-webhook-token'),
        ];

        DB::table('business_settings')->updateOrInsert(
            ['type' => DeliverySyriaConfigService::SETTING_KEY],
            ['value' => json_encode($configuration)],
        );
        Cache::flush();
    }
}
