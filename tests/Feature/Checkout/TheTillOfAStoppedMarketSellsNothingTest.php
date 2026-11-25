<?php

namespace Tests\Feature\Checkout;

use App\Services\Checkout\PointOfSaleSaleRule;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\ActivatesStorefront;
use Tests\Support\ApprovesStorefrontTax;

/**
 * D1-16: a till does not open a NEW sale in a market that may not sell, and the owner's decision is already written down.
 *
 * I2-09, taken: *every NEW point-of-sale sale follows the country of its products and their legal company and respects the sale gate and
 * the market's state. Orders placed before a stop keep being processed, sales-channel obligations included, without opening new sales in an
 * unauthorised way.* The customer-facing routes have carried the gate since GCC-11 -- `storefront.sellable` on every checkout and payment
 * route -- and the three tills carried nothing at all, so a market that had been stopped, paused or never made ready went on selling over
 * the counter and through the seller app, which is the one thing I2-09 names as unauthorised.
 *
 * The seller app's till is driven here because it is the only one of the three that is an HTTP route, so the whole middleware stack, the
 * seller's token and the real controller run. The two panel tills ask the SAME rule of the SAME products in the same place and their
 * wiring is held by `OrderCreatorsAreAccountedForTest` -- which is said out loud rather than left to be assumed, because a test of one
 * caller is not a test of the other two.
 *
 * WHAT IS ASSERTED, in the order that matters:
 *
 *  0. with the till's own switch OFF -- how it ships -- the sale goes through even in a market that may not sell, because the rule is not
 *     adopted yet and the reason it is not is written beside the key in `config/gcc.php`;
 *  1. with markets OFF -- every installation today -- the sale goes through, to the unit of stock and the halala;
 *  2. a market that is active and ready sells;
 *  3. a market a person has PAUSED is refused with the same `storefront_not_sellable` a shopper is given, and NOTHING is written: no
 *     order, no line, no stock taken, no ledger row, and the wallet exactly where it was. That is the property that makes this safe to
 *     put on a money path at all, and it is why the rule is asked where the one-company rule is asked;
 *  4. the DEFAULT market is never refused by being default -- only by being paused;
 *  5. goods that name no market, and goods of a market the configuration does not name, sell exactly as they do today. The second is not
 *     hypothetical tidiness: `StorefrontResolver::currencyOf()` THROWS for an unconfigured country, so a stale country value on one
 *     product would have been a 500 at the till instead of a sale.
 */
class TheTillOfAStoppedMarketSellsNothingTest extends ApiAddressTestCase
{
    use ActivatesStorefront;
    use ApprovesStorefrontTax;

    private const SELLER_TOKEN = 'seller-sale-rule-token-0123456789abcdefgh';

    private const SELLER_ID = 5;

    private const CUSTOMER_ID = 7;

    private const PRODUCT_ID = 1;

    private const STOCK = 10;

    private const PRICE = 40.0;

    private const WALLET_BALANCE = 100.0;

    private const MARKET = 'KW';

    /** @var array<string, object> */
    private static array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->createTillTables();
        $this->runMarketMigrations();
        $this->useTaxIncludedPrices();

        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
        Cache::flush();

        DB::table('sellers')->insert(['id' => self::SELLER_ID, 'f_name' => 'Pharmacy', 'status' => 'approved', 'auth_token' => self::SELLER_TOKEN]);
        DB::table('users')->insert(['id' => self::CUSTOMER_ID, 'f_name' => 'Sara', 'phone' => '0551234567', 'wallet_balance' => self::WALLET_BALANCE]);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function migration(string $file): object
    {
        if (isset(self::$migrations[$file])) {
            return self::$migrations[$file];
        }

        $loaded = require database_path('migrations/' . $file);

        return self::$migrations[$file] = is_object($loaded)
            ? $loaded
            : new ('\\' . Str::studly(implode('_', array_slice(explode('_', basename($file, '.php')), 4))))();
    }

    private function createTillTables(): void
    {
        Schema::table('sellers', static function (Blueprint $table): void {
            $table->string('auth_token')->nullable();
        });

        Schema::create('wallet_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('transaction_id')->nullable();
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
    }

    private function runMarketMigrations(): void
    {
        $this->migration('2026_11_25_100000_add_storefront_country_to_products.php')->up();
        $this->migration('2026_11_26_100000_add_storefront_snapshot_to_orders_table.php')->up();
        StorefrontCatalog::flush();
    }

    /**
     * The goods on the counter, of the given market.
     *
     * The EMPTY STRING is what "names no market" looks like on this platform, not null: `products.storefront_country` is NOT NULL and
     * takes `''`, which the runbook records as the state a bad write leaves behind and every product before the migration had a value.
     * A null fixture fails the insert outright, which is how this was found.
     */
    private function stockTheCounter(string $market = self::MARKET): void
    {
        DB::table('products')->insert([
            'id' => self::PRODUCT_ID, 'name' => 'Paracetamol', 'added_by' => 'seller', 'user_id' => self::SELLER_ID,
            'unit_price' => self::PRICE, 'current_stock' => self::STOCK, StorefrontCatalog::COLUMN => $market,
        ]);
    }

    /** Markets on, with this one configured. Whether it may SELL is then the lifecycle's business and each test's own. */
    private function switchMarketsOn(bool $gateTheTill = true): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            PointOfSaleSaleRule::SETTING => $gateTheTill,
            'gcc.storefronts.countries.' . self::MARKET . '.active' => true,
            'gcc.storefronts.legal.grandfathered' => ['SA', self::MARKET],
        ]);

        DB::table('currencies')->insertOrIgnore(['id' => 3, 'name' => 'Kuwaiti Dinar', 'code' => 'KWD', 'symbol' => 'KWD', 'exchange_rate' => 1, 'status' => 1]);
        cache()->forget('active_currency_list');
        $this->approveNoTaxFor(self::MARKET);
        StorefrontCatalog::flush();
    }

    private function sellOverTheCounter(): TestResponse
    {
        $cartLine = [
            'id' => self::PRODUCT_ID, 'quantity' => 1, 'variant' => null, 'variation' => [], 'price' => self::PRICE,
            'discounted_price' => self::PRICE, 'discount' => 0, 'applied_tax_amount' => 0,
        ];

        return $this->withHeader('Authorization', 'Bearer ' . self::SELLER_TOKEN)->postJson('/api/v3/seller/pos/place-order', [
            'customer_id' => self::CUSTOMER_ID, 'cart' => [$cartLine], 'modified_cart' => [$cartLine], 'fulfillment' => 'instant',
            'payment_method' => 'wallet', 'paid_amount' => self::PRICE,
        ]);
    }

    private function assertTheSaleWentThrough(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame(1, DB::table('orders')->count(), 'the sale should have become an order');
        $this->assertSame(self::STOCK - 1, (int) DB::table('products')->where('id', self::PRODUCT_ID)->value('current_stock'));
        $this->assertSame(self::WALLET_BALANCE - currencyConverter(amount: self::PRICE), (float) DB::table('users')->where('id', self::CUSTOMER_ID)->value('wallet_balance'));
    }

    /** The whole point of asking the rule beside the one-company rule: a refusal costs the till nothing. */
    private function assertNothingWasWritten(): void
    {
        $this->assertSame(0, DB::table('orders')->count(), 'no order');
        $this->assertSame(0, DB::table('order_details')->count(), 'no order line');
        $this->assertSame(self::STOCK, (int) DB::table('products')->where('id', self::PRODUCT_ID)->value('current_stock'), 'no stock taken');
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'no ledger row');
        $this->assertSame(self::WALLET_BALANCE, (float) DB::table('users')->where('id', self::CUSTOMER_ID)->value('wallet_balance'), 'the wallet is untouched');
    }

    // ---------------------------------------------------------------- 1. the platform as it ships

    public function test_with_markets_off_the_till_sells_exactly_as_it_does_today(): void
    {
        $this->stockTheCounter();

        $this->assertTheSaleWentThrough($this->sellOverTheCounter());
    }

    /**
     * And the dormancy that matters more, because it is the state this ships in: the rule is WIRED into all three tills and switched OFF,
     * so a market nobody has made active still sells over the counter exactly as it does today.
     *
     * This is the case that would otherwise be a silent behaviour change on a money path in six existing test worlds, and the measurement
     * that put the switch there is written beside the key in `config/gcc.php`.
     */
    public function test_with_the_tills_own_switch_off_a_market_that_may_not_sell_still_sells_over_the_counter(): void
    {
        $this->switchMarketsOn(gateTheTill: false);
        $this->activateStorefrontFor('SA');
        $this->stockTheCounter();

        $this->assertFalse(app(PointOfSaleSaleRule::class)->isEnabled(), 'the till gate ships off');
        $this->assertTheSaleWentThrough($this->sellOverTheCounter());
    }

    // ---------------------------------------------------------------- 2 and 3. a market that may sell, and one that may not

    public function test_a_till_of_a_market_that_may_sell_sells(): void
    {
        $this->switchMarketsOn();
        $this->activateStorefrontFor('SA', self::MARKET);
        $this->stockTheCounter();

        $this->assertTheSaleWentThrough($this->sellOverTheCounter());
    }

    public function test_a_till_of_a_market_nobody_has_made_active_is_refused_and_writes_nothing(): void
    {
        $this->switchMarketsOn();
        $this->activateStorefrontFor('SA');
        $this->stockTheCounter();

        $response = $this->sellOverTheCounter();

        $response->assertStatus(422);
        $this->assertSame('storefront_not_sellable', $response->json('code'), 'a till is told what a shopper is told');
        $this->assertNotEmpty($response->json('message'));
        $this->assertNothingWasWritten();
    }

    public function test_a_till_of_a_market_a_person_has_paused_is_refused_and_writes_nothing(): void
    {
        $this->switchMarketsOn();
        $this->activateStorefrontFor('SA', self::MARKET);
        $this->pauseTheMarket();
        $this->stockTheCounter();

        $response = $this->sellOverTheCounter();

        $response->assertStatus(422);
        $this->assertSame('storefront_not_sellable', $response->json('code'));
        $this->assertNothingWasWritten();
    }

    // ---------------------------------------------------------------- 4 and 5. what must never be refused

    public function test_the_default_market_is_not_refused_for_being_the_default(): void
    {
        $this->switchMarketsOn();
        $this->activateStorefrontFor('SA');
        $this->stockTheCounter(market: 'SA');

        $this->assertTheSaleWentThrough($this->sellOverTheCounter());
    }

    public function test_goods_that_name_no_market_sell_as_they_do_today(): void
    {
        $this->switchMarketsOn();
        $this->activateStorefrontFor('SA');
        $this->stockTheCounter(market: '');

        $this->assertTheSaleWentThrough($this->sellOverTheCounter());
    }

    /**
     * A stale country value on a product must not be a 500 at the till.
     *
     * `StorefrontResolver::currencyOf()` raises `InvalidArgumentException` for a country the configuration does not name, and the sale rule
     * has to ask it for the currency the gate is judged in -- so without the guard this case is an unhandled exception in the middle of a
     * counter sale rather than a sale.
     */
    public function test_goods_of_a_market_the_configuration_does_not_name_still_sell(): void
    {
        $this->switchMarketsOn();
        $this->activateStorefrontFor('SA');
        $this->stockTheCounter(market: 'ZW');

        $this->assertTheSaleWentThrough($this->sellOverTheCounter());
    }

    // ---------------------------------------------------------------- the rule asked directly

    public function test_the_rule_names_the_market_that_may_not_sell_and_nothing_otherwise(): void
    {
        $this->switchMarketsOn();
        $this->activateStorefrontFor('SA');

        $kuwaitiGoods = [(object) [StorefrontCatalog::COLUMN => self::MARKET]];
        $saudiGoods = [(object) [StorefrontCatalog::COLUMN => 'SA']];

        $this->assertSame(self::MARKET, app(PointOfSaleSaleRule::class)->marketThatMayNotSell(products: $kuwaitiGoods));
        $this->assertNull(app(PointOfSaleSaleRule::class)->marketThatMayNotSell(products: $saudiGoods));

        // Two markets on one counter is the OTHER rule's refusal (OneSellingCompanyPerOrder), and two refusals for one cart would make
        // the operator fix the second fault to be told about the first.
        $this->assertNull(app(PointOfSaleSaleRule::class)->marketThatMayNotSell(products: [...$kuwaitiGoods, ...$saudiGoods]));
    }

    /** A person pauses the market: the lifecycle record says paused, which is what `isOpenForSale` reads. */
    private function pauseTheMarket(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => \App\Services\Storefront\StorefrontLifecycle::settingName(self::MARKET)],
            ['value' => json_encode([
                'state' => \App\Services\Storefront\StorefrontLifecycle::PAUSED,
                'mode' => 'maintenance',
                'reason' => 'The test world',
                'message' => null,
                'until' => null,
                'changed_by' => 'The owner of the test world',
                'reference' => 'The test world',
                'changed_at' => now()->toIso8601String(),
            ]), 'updated_at' => now()],
        );

        clearWebConfigCacheKeys();
    }
}
