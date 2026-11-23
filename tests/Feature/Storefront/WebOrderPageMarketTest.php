<?php

namespace Tests\Feature\Storefront;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\StorefrontTestCase;
use Tests\Support\BuildsStorefrontOrderPages;

/**
 * The other half of GCC-05 seen from the customer's side: the website's own order pages. The apps read an order as JSON and the three keys of its
 * record reach them by themselves, because the answer is the model as it stands. A page is not an answer of keys, so what it prints is pinned
 * here instead: an order's record puts none of `storefront_country`, `currency_code` or `currency_rate` into the markup, and the page of an order
 * that carries a record is the same page, byte for byte, as the page of one that does not.
 *
 * So nothing may be read off these pages as data, and the money they print does not follow the record either: the amounts go through the
 * platform's own converter and its currency settings, as they always have, which the last test below says in so many words. The order of the fixture
 * carries a line, because the money of an item is printed inside the loop over the lines and a page without one renders neither item row, so the
 * comparison would not cover the markup W6-C has to change. Making a page print the money the order was sold in is GCC-05's remaining work (the
 * storefront money of an order, W6-C) and is deliberately not done here; when it is done, the byte-for-byte pin below is what will say so.
 */
class WebOrderPageMarketTest extends StorefrontTestCase
{
    use BuildsStorefrontOrderPages;

    private const MARKET_KEYS = ['storefront_country', 'currency_code', 'currency_rate'];

    private const ORDER = 100;

    private const PRODUCT = 'Dates 1kg';

    private const UNIT_PRICE = 12.5;

    private const QUANTITY = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPageTables();
        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();
        $this->signIn();

        // The page is rendered in one language: the first render of a session is the one that works the language out, and it would be the only
        // render with a text direction, which has nothing to do with the order.
        session(['local' => 'en', 'direction' => 'ltr']);
    }

    /** @return array<string, array{0: string}> the two pages of an order a customer of the website reads */
    public static function theOrderPagesOfTheWebsite(): array
    {
        return ['the order details page' => ['renderOrderDetails'], 'the invoice' => ['renderInvoice']];
    }

    private function orderSoldInKuwait(): Order
    {
        $this->makeOrderKeeping(['contact_person_name' => 'Sara Alharbi', 'address' => 'Salmiya Block 2', 'phone' => '+96555123456'], id: self::ORDER);
        $this->addOrderLine(orderId: self::ORDER, productName: self::PRODUCT, price: self::UNIT_PRICE, quantity: self::QUANTITY);
        DB::table('orders')->where('id', self::ORDER)->update(['storefront_country' => 'KW', 'currency_code' => 'KWD', 'currency_rate' => 1]);

        return Order::findOrFail(self::ORDER);
    }

    #[DataProvider('theOrderPagesOfTheWebsite')]
    public function test_an_order_page_prints_none_of_the_three_keys_of_the_record(string $render): void
    {
        $html = $this->{$render}($this->orderSoldInKuwait());

        $this->assertNotSame('', trim($html));
        foreach (self::MARKET_KEYS as $key) {
            $this->assertStringNotContainsString($key, $html, 'a page is not an answer of keys, so nothing may be read off it as data');
        }
    }

    #[DataProvider('theOrderPagesOfTheWebsite')]
    public function test_an_order_page_is_the_same_whether_the_order_carries_a_record_or_not(string $render): void
    {
        $withARecord = $this->{$render}($this->orderSoldInKuwait());

        DB::table('orders')->where('id', self::ORDER)->update(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null]);
        $withNone = $this->{$render}(Order::findOrFail(self::ORDER));

        $this->assertSame($withNone, $withARecord, 'the record changes nothing the website prints today: the money of an order of another storefront is W6-C');
    }

    /**
     * What the pages print instead of the money the order was sold in: the platform's own. The order is stored in dinars and says so, and both
     * pages put every amount through the platform's converter and label it with the platform's currency, so the line of a Kuwaiti order reads as
     * rials. That is today's behaviour and it is what W6-C changes.
     */
    #[DataProvider('theOrderPagesOfTheWebsite')]
    public function test_an_order_page_prints_the_money_of_a_kuwaiti_order_in_the_platforms_own_currency(string $render): void
    {
        $html = $this->{$render}($this->orderSoldInKuwait());

        $this->assertStringContainsString(self::PRODUCT, $html, 'the item rows render, so the money printed per line is in what these tests compare');
        $this->assertMatchesRegularExpression('/' . (self::UNIT_PRICE * self::QUANTITY) . '(\.0+)?\s*SAR/', $html, 'the line total goes through the platform converter');
        $this->assertStringNotContainsString('KWD', $html, 'the currency the order was sold in is printed nowhere today');
    }
}
