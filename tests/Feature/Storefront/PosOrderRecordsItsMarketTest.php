<?php

namespace Tests\Feature\Storefront;

use App\Enums\SessionKey;
use App\Http\Controllers\Admin\POS\POSOrderController as AdminPos;
use App\Http\Controllers\Vendor\POS\POSOrderController as VendorPos;
use App\Models\User;
use App\Services\Storefront\OrderStorefrontSnapshot;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\ActivatesStorefront;
use Tests\Support\ApprovesStorefrontTax;
use Tests\Support\BuildsPosSaleWorld;
use Tests\Support\KeepsTranslateOffLanguageFiles;

/**
 * I2-09: a till sale records the market it was sold in.
 *
 * The customer checkout and the seller app's till have recorded it since GCC-05, and the two PANEL tills -- the admin's and the vendor's --
 * recorded nothing at all, because they create their order through the repository rather than through OrderManager. An order with no record
 * is read everywhere as the default market's: its money, the notifications about it, the company on its invoice, and the wallet a refund of
 * it would reach. A Kuwaiti till sale was a Saudi order in every one of those readings.
 *
 * The market is taken from the one country of the order's own LINES, never from the request, which is the rule
 * {@see OrderStorefrontSnapshot} already holds and which the other two paths already use: a cashier's session cannot decide which market a
 * product belongs to.
 *
 * Both panels are driven through their real `placeOrder`, with the session cart the POS screen fills, so what is asserted is what a cashier's
 * sale writes. This is about the RECORD and not about the sale gate: whether a till may sell in a market that may not is I2-09's open owner
 * decision, and nothing here refuses a sale.
 */
class PosOrderRecordsItsMarketTest extends ApiAddressTestCase
{
    use ActivatesStorefront;
    use ApprovesStorefrontTax;
    use BuildsPosSaleWorld;
    use KeepsTranslateOffLanguageFiles;

    private const SELLER_ID = 5;

    private const KUWAIT = 'KW';

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->buildPosSaleWorld(sellerId: self::SELLER_ID);
        $this->keepTranslateOffLanguageFiles();
        $this->ordersAndProductsCanRecordTheirMarket();
    }

    protected function tearDown(): void
    {
        $this->assertLanguageFilesUntouched();

        parent::tearDown();
    }

    /** The two columns the record needs: the market a product belongs to, and the snapshot an order keeps of it. */
    private function ordersAndProductsCanRecordTheirMarket(): void
    {
        if (!Schema::hasColumn('products', StorefrontCatalog::COLUMN)) {
            Schema::table('products', fn ($table) => $table->string(StorefrontCatalog::COLUMN, 2)->nullable());
        }
        if (!Schema::hasColumn('orders', OrderStorefrontSnapshot::COLUMN)) {
            (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        }

        app()->forgetInstance(OrderStorefrontSnapshot::class);
        StorefrontCatalog::flush();
    }

    /**
     * Kuwait served, and served by a market that may actually SELL: its tax decision recorded and its storefront activated, which is what
     * `approveNoTaxFor` and `activateStorefrontFor` exist for -- "so that the sale gate is not what is under test", as both traits say.
     *
     * The world used to stop at the three configuration keys, and that was an incomplete world rather than a deliberate one: a market
     * whose lifecycle record is ABSENT reads DRAFT, so a CUSTOMER could not have checked out in it either. It went unnoticed only because
     * the till asked no gate at all. {@see \App\Services\Checkout\PointOfSaleSaleRule} named this file first among the worlds that would
     * break when the till's switch was thrown, and with the switch on it was the only one of them that did -- in both panel tills, which
     * is the measurement that sized the work rather than guessing at it.
     *
     * Completing it takes FOUR things and not the two the handover expected, because {@see \App\Services\Storefront\StorefrontSellingGate}
     * asks the lifecycle AND the preflight: the lifecycle record, the tax decision, the legal texts (grandfathered here, as
     * TheTillOfAStoppedMarketSellsNothingTest does) and the market's own currency row, without which the preflight is not met while
     * storefront currencies are on. The lifecycle and the tax alone left both tills still refusing, which is how the other two were found.
     *
     * Activating a market HERE is activating it in this test's own world: a `business_settings` row this class writes and the trait takes
     * away when the class ends. It opens no market on the platform, which stays the owner's decision and G0's.
     */
    private function sixMarketsAreServed(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.legal.grandfathered' => ['SA', self::KUWAIT],
        ]);

        DB::table('currencies')->insertOrIgnore(['name' => 'Kuwaiti Dinar', 'code' => 'KWD', 'symbol' => 'KWD', 'exchange_rate' => 1, 'status' => 1]);
        cache()->forget('active_currency_list');

        $this->approveNoTaxFor(self::KUWAIT);
        $this->activateStorefrontFor(self::KUWAIT);

        StorefrontCatalog::flush();
    }

    private function theProductsBelongTo(string $country): void
    {
        DB::table('products')->update([StorefrontCatalog::COLUMN => $country]);
    }

    /** @return array<string, array{0: class-string}> */
    public static function tills(): array
    {
        return ['the admin till' => [AdminPos::class], 'the vendor till' => [VendorPos::class]];
    }

    /** @param class-string $controller */
    private function sellThrough(string $controller): JsonResponse
    {
        $cartId = 'saved-customer-' . $this->customer->id;
        $line = fn (int $productId, int $quantity, float $price): array => [
            'id' => $productId, 'customerId' => $this->customer->id, 'customerOnHold' => false, 'quantity' => $quantity, 'price' => $price,
            'name' => 'Product ' . $productId, 'productType' => 'physical', 'image' => null, 'discount' => 0, 'variant' => '', 'variations' => [],
        ];
        session([SessionKey::CURRENT_USER => $cartId, SessionKey::CART_NAME => [$cartId], $cartId => [$line(1, 2, 30), $line(2, 1, 20)]]);

        return app($controller)->placeOrder(Request::create('/pos/place-order', 'POST', ['amount' => 80, 'type' => 'wallet', 'fulfillment' => 'instant']));
    }

    private function marketOfTheOrder(): ?string
    {
        return DB::table('orders')->orderByDesc('id')->value(OrderStorefrontSnapshot::COLUMN);
    }

    #[DataProvider('tills')]
    public function test_a_till_sale_of_one_markets_products_records_that_market(string $controller): void
    {
        $this->sixMarketsAreServed();
        $this->theProductsBelongTo(self::KUWAIT);

        $this->sellThrough($controller);

        $this->assertSame(1, DB::table('orders')->count(), 'the sale went through');
        $this->assertSame(self::KUWAIT, $this->marketOfTheOrder(), 'the market of the lines, which is what the order was sold in');
    }

    /** The dormancy, which is what keeps a Saudi till exactly as it is: with the switches off nothing is recorded at all. */
    #[DataProvider('tills')]
    public function test_a_till_sale_records_nothing_while_the_switches_are_off(string $controller): void
    {
        $this->theProductsBelongTo(self::KUWAIT);

        $this->sellThrough($controller);

        $this->assertSame(1, DB::table('orders')->count(), 'the sale still goes through');
        $this->assertNull($this->marketOfTheOrder());
    }

    /**
     * A cashier's own market cannot decide the order's. The request is served from Kuwait while the products are the default market's, and
     * the order records the products' market -- which is the rule that keeps a till from selling one market's goods as another's.
     */
    #[DataProvider('tills')]
    public function test_the_market_comes_from_the_products_and_not_from_the_request(string $controller): void
    {
        $this->sixMarketsAreServed();
        $this->theProductsBelongTo('SA');

        StorefrontResolver::fromConfig()->context(explicitChoice: self::KUWAIT)->serve(fn () => $this->sellThrough($controller));

        $this->assertSame('SA', $this->marketOfTheOrder());
    }
}
