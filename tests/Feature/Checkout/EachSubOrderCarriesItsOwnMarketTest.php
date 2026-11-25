<?php

namespace Tests\Feature\Checkout;

use App\Services\Address\AddressSchema;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\ActivatesStorefront;
use Tests\Support\ApprovesStorefrontTax;

/**
 * D1-12, last criterion: EACH sub-order of a multi-vendor basket carries its own storefront and its own selling company.
 *
 * A basket of two vendors becomes two orders (SubOrdersPerVendorTest pins that), and the storefront and the company are written by
 * `OrderStorefrontSnapshot::record()`, which is called from `OrderManager::addOrderDetailsData()` -- once per vendor group, after that
 * group's lines are saved. So the shape is right by construction, and that is exactly why it needed a test: nothing said so, and the
 * cheapest plausible "tidy-up" of that call is to lift it out of the per-group work and make it once per checkout. Doing that leaves the
 * FIRST order stamped and every later one blank -- a second vendor's order with no market and no company, which is an invoice nobody can
 * issue and a ledger row nobody can file, on the quietest possible path.
 *
 * It is written as an identity rather than as a presence check. Both orders must carry the market the goods were sold in and the company
 * of that market, and `legal_entity_id` must be that market's row and not the platform's default, because the company on an order is who
 * the sale is a liability of.
 *
 * The stamp is also asserted to be WRITTEN ONCE: `record()` updates `whereNull`, so a second call cannot restamp an order, which is what
 * keeps a reprinted invoice saying what it said. And the control case is a Saudi platform with the switches off, where both orders carry
 * nothing at all -- the state every installation is in today.
 *
 * TEETH, each mutation applied and restored, on MariaDB 10.11.14:
 *
 *   - making `OrderStorefrontSnapshot::record()` act once per request instead of once per sub-order -> 3 of 5 red, and the failure is
 *     exactly the real-world shape: the in-house order keeps its market and its company, the vendor's order has neither;
 *   - dropping the `whereNull` from the company stamp, so a later call may rewrite it -> 1 red.
 *
 * ENGINES: 5 tests / 33 assertions green on MariaDB 10.11.14 (`-c phpunit.mysql.xml`) and on SQLite.
 */
class EachSubOrderCarriesItsOwnMarketTest extends ApiAddressTestCase
{
    use ActivatesStorefront;
    use ApprovesStorefrontTax;

    private const COD = '/api/v1/customer/order/place';

    private const MARKET = 'KW';

    private const IN_HOUSE_PRICE = 100.0;

    private const VENDOR_PRICE = 250.0;

    /** @var array<string, object> */
    private static array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->useTaxIncludedPrices();
        $this->runMarketMigrations();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();

        parent::tearDown();
    }

    /** An older migration declares a NAMED class, so the file is loaded once per process and the object remembered; the tables are not. */
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

    /** The columns a market and a selling company are recorded in, from the REAL migrations. */
    private function runMarketMigrations(): void
    {
        foreach ([
            '2026_11_25_100000_add_storefront_country_to_products.php',
            '2026_11_26_100000_add_storefront_snapshot_to_orders_table.php',
            '2026_11_27_100000_create_legal_entities_table.php',
            '2026_11_27_100100_add_legal_entity_to_orders_table.php',
        ] as $file) {
            $this->migration($file)->up();
        }

        StorefrontCatalog::flush();
        app()->forgetInstance(OrderStorefrontSnapshot::class);
        app(LegalEntityRegistry::class)->flush();
    }

    /** Markets on, with a company registered for the market the goods are sold in and for a market they are not. */
    private function sellFromTheKuwaitiShop(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.legal_entities.enabled' => true,
            'gcc.storefronts.legal_entities.required_to_sell' => false,
            'gcc.storefronts.countries.' . self::MARKET . '.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
            // The legal-text review of a market is its own criterion (I2-05) and is not what is under test, so both markets are
            // grandfathered, which is what `BuildsStorefrontCart::switchStorefrontsOn()` does for the same reason.
            'gcc.storefronts.legal.grandfathered' => [self::MARKET, 'AE'],
        ]);

        // A market that trades in its own currency must have that currency, or the preflight refuses the sale.
        DB::table('currencies')->insertOrIgnore([
            ['id' => 3, 'name' => 'Kuwaiti Dinar', 'code' => 'KWD', 'symbol' => 'KWD', 'exchange_rate' => 1, 'status' => 1],
            ['id' => 2, 'name' => 'UAE Dirham', 'code' => 'AED', 'symbol' => 'AED', 'exchange_rate' => 1, 'status' => 1],
        ]);
        cache()->forget('active_currency_list');

        // The sale gate is not what is under test here, so the market is given everything it needs to sell: an approved tax decision and
        // an active lifecycle. A test of the gate itself builds the world without them.
        $this->approveNoTaxFor(self::MARKET, 'AE');
        $this->activateStorefrontFor(self::MARKET, 'AE');

        foreach ([self::MARKET, 'AE'] as $country) {
            DB::table('legal_entities')->insert([
                'country' => $country,
                'legal_name' => "Lavkoo {$country} Trading Co.",
                'trade_name' => "Lavkoo {$country}",
                'tax_registration_number' => "TAX-{$country}-001",
                'commercial_registration_number' => "CR-{$country}-777",
                'invoice_series_key' => "INV-{$country}",
                'address' => "1 Example Street, {$country}",
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        StorefrontCatalog::flush();
        app(LegalEntityRegistry::class)->flush();
    }

    /** The in-house product of the market's shop, in the cart. */
    private function putTheInHouseProductInTheCart(int $customerId): void
    {
        DB::table('products')->insert([
            'id' => 1, 'name' => 'Paracetamol', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => self::IN_HOUSE_PRICE,
            'current_stock' => 10, StorefrontCatalog::COLUMN => self::MARKET,
        ]);

        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => false, 'cart_group_id' => 'group-admin',
            'product_id' => 1, 'quantity' => 1, 'price' => self::IN_HOUSE_PRICE, 'seller_id' => 1, 'seller_is' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A real vendor's product of the SAME market, in its own cart group -- one group per vendor is what the checkout splits on. */
    private function alsoTheVendorsProduct(int $customerId, int $sellerId = 7, int $productId = 2): void
    {
        DB::table('sellers')->insert(['id' => $sellerId, 'f_name' => 'Vendor ' . $sellerId, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shops')->insert(['seller_id' => $sellerId, 'name' => 'Shop ' . $sellerId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_types')->insert(['seller_id' => $sellerId, 'shipping_type' => 'order_wise', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            'id' => $productId, 'name' => 'Vendor product', 'added_by' => 'seller', 'user_id' => $sellerId,
            'unit_price' => self::VENDOR_PRICE, 'current_stock' => 10, StorefrontCatalog::COLUMN => self::MARKET,
        ]);

        DB::table('carts')->insert([
            'customer_id' => $customerId, 'is_guest' => false, 'cart_group_id' => 'group-seller-' . $sellerId,
            'product_id' => $productId, 'quantity' => 1, 'price' => self::VENDOR_PRICE, 'seller_id' => $sellerId, 'seller_is' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A cash-on-delivery checkout of a two-vendor basket through the real route, with the market's header on the request. */
    private function checkOutTwoVendors(): \Illuminate\Support\Collection
    {
        $this->setAddressMode(AddressSchema::MODE_SOFT);
        $customer = $this->makeCustomer();
        $address = $this->makeStructuredAddress($this->ids, $customer->id, overrides: ['zip' => '12211']);

        $this->putTheInHouseProductInTheCart($customer->id);
        $this->alsoTheVendorsProduct($customer->id);

        $this->asCustomer($customer)
            ->withHeader('X-Storefront', self::MARKET)
            ->getJson(self::COD . '?address_id=' . $address->id . '&billing_address_id=' . $address->id)
            ->assertOk();

        $orders = DB::table('orders')->orderBy('id')->get();
        $this->assertCount(2, $orders, 'a two-vendor basket must become two orders before there is anything to stamp');

        return $orders;
    }

    public function test_both_sub_orders_carry_the_market_the_goods_were_sold_in(): void
    {
        $this->sellFromTheKuwaitiShop();

        $orders = $this->checkOutTwoVendors();

        foreach ($orders as $order) {
            $this->assertSame(
                self::MARKET,
                $order->{OrderStorefrontSnapshot::COLUMN},
                'the ' . $order->seller_is . ' sub-order carries no market, or the wrong one',
            );
        }

        // One purchase, one market: a sub-order with a different market would mean two shops had sold one basket.
        $this->assertCount(1, $orders->pluck(OrderStorefrontSnapshot::COLUMN)->unique());
        $this->assertCount(1, $orders->pluck('order_group_id')->unique());
    }

    public function test_both_sub_orders_carry_the_selling_company_of_that_market_and_not_another(): void
    {
        $this->sellFromTheKuwaitiShop();

        $kuwait = (int) DB::table('legal_entities')->where('country', self::MARKET)->value('id');
        $emirates = (int) DB::table('legal_entities')->where('country', 'AE')->value('id');

        $orders = $this->checkOutTwoVendors();

        foreach ($orders as $order) {
            $this->assertSame($kuwait, (int) $order->{LegalEntityRegistry::COLUMN}, 'the ' . $order->seller_is . ' sub-order names the wrong company');
            $this->assertNotSame($emirates, (int) $order->{LegalEntityRegistry::COLUMN});

            // The frozen copy, not a pointer to a row that can be edited: this is what an invoice is reprinted from.
            $snapshot = json_decode((string) $order->entity_snapshot, true);
            $this->assertIsArray($snapshot, 'the ' . $order->seller_is . ' sub-order carries no frozen copy of its company');
            $this->assertSame('Lavkoo ' . self::MARKET . ' Trading Co.', $snapshot['legal_name'] ?? null);
        }

        $this->assertCount(1, $orders->pluck(LegalEntityRegistry::COLUMN)->unique(), 'one basket, one selling company');
    }

    /** Both sub-orders are stamped where the vendor's lines are, so each stamp reads that vendor's own goods and not the first group's. */
    public function test_the_vendors_sub_order_is_stamped_from_its_own_lines(): void
    {
        $this->sellFromTheKuwaitiShop();

        $orders = $this->checkOutTwoVendors();
        $vendorOrder = $orders->firstWhere('seller_is', 'seller');

        $this->assertNotNull($vendorOrder, 'the vendor half of the basket did not become an order');
        $this->assertSame(7, (int) $vendorOrder->seller_id);
        $this->assertSame(self::MARKET, $vendorOrder->{OrderStorefrontSnapshot::COLUMN});

        $lineProducts = DB::table('order_details')
            ->join('products', 'products.id', '=', 'order_details.product_id')
            ->where('order_details.order_id', $vendorOrder->id)
            ->pluck('products.' . StorefrontCatalog::COLUMN);

        $this->assertSame([self::MARKET], $lineProducts->unique()->values()->all());
    }

    /** A stamp is written once. Asking again must not rewrite a market or a company an invoice may already have been printed from. */
    public function test_a_second_stamp_cannot_rewrite_a_sub_orders_market(): void
    {
        $this->sellFromTheKuwaitiShop();

        $orders = $this->checkOutTwoVendors();
        $before = $orders->map(fn ($order) => [$order->{OrderStorefrontSnapshot::COLUMN}, (int) $order->{LegalEntityRegistry::COLUMN}])->all();

        // The market of every product is moved, and both orders are offered to the writer again.
        DB::table('products')->update([StorefrontCatalog::COLUMN => 'AE']);
        StorefrontCatalog::flush();

        foreach ($orders as $order) {
            app(OrderStorefrontSnapshot::class)->record(orderId: $order->id);
        }

        $after = DB::table('orders')->orderBy('id')->get()
            ->map(fn ($order) => [$order->{OrderStorefrontSnapshot::COLUMN}, (int) $order->{LegalEntityRegistry::COLUMN}])->all();

        $this->assertSame($before, $after, 'an order that already names its market and its company must never be restamped');
    }

    /** The control: with markets off, which is every installation today, neither sub-order records anything and the split is unchanged. */
    public function test_with_markets_off_neither_sub_order_records_a_market_or_a_company(): void
    {
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.legal_entities.enabled' => false]);
        StorefrontCatalog::flush();

        $orders = $this->checkOutTwoVendors();

        foreach ($orders as $order) {
            $this->assertNull($order->{OrderStorefrontSnapshot::COLUMN});
            $this->assertNull($order->{LegalEntityRegistry::COLUMN});
        }

        $this->assertSame(['admin:1', 'seller:7'], $orders->map(fn ($o) => $o->seller_is . ':' . (int) $o->seller_id)->sort()->values()->all());
    }
}
