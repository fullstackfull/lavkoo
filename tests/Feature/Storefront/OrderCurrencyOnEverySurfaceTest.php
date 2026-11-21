<?php

namespace Tests\Feature\Storefront;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsOrderCurrencySurfaces;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * What the invoice, the order e-mail and the exports print of an order's money (D1-06): the currency the order was sold in, at the rate it was
 * sold at, and never the currency of whoever is looking or the rate of today.
 *
 * Every figure here is read from the template a real route renders, inside that request. A Kuwaiti order is placed by a real cash-on-delivery
 * checkout of the API and then printed from a request that names no storefront, which the Saudi one serves — an administrator at a desk, or a
 * customer opening their own history. The order e-mail is written where production writes it, in the queue worker that has no request at all.
 * Nothing in the setup loads a currency or writes one into the session: the world is a platform that holds amounts in a base unit and converts
 * them at the rate of whichever currency is in force, which is what made every one of these documents print the wrong money.
 */
class OrderCurrencyOnEverySurfaceTest extends ApiAddressTestCase
{
    use BuildsOrderCurrencySurfaces;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const KUWAITI_PRODUCT = 3;

    private const EMIRATI_PRODUCT = 2;

    private const SAUDI_PRODUCT = 1;

    /** 30 dinars, charged. What the platform's rate turns the same stored 30 into, and the sign it puts on it: the figure nobody was charged. */
    private const KUWAITI_ORDER_IN_ITS_OWN_MONEY = '30.000KD';

    private const KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE = '112.50SAR';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildOrderCurrencySurfaceWorld();
    }

    /**
     * The customer's own invoice is the first money its template asks for, before any converter has run, so it is the one reader that finds
     * nothing in the session. getCurrencyCode() is declared to return a string and handed back what the session held, and PdfGenerator's
     * writer is declared to return a string and returns nothing, so the whole document was a 500 rather than an invoice.
     */
    public function test_a_customer_downloading_their_invoice_is_not_served_a_type_error(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);

        $this->asTheCustomerOnTheWebsite($order->customer);

        $this->callRouteThatWritesAFile(fn () => $this->get(route('generate-invoice', ['id' => $order->id])))->assertStatus(200);
    }

    public function test_the_customer_invoice_of_a_kuwaiti_order_prints_dinars_to_a_saudi_request(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        $this->asTheCustomerOnTheWebsite($order->customer);

        $printed = $this->moneyPrintedIn($this->captureRender(
            VIEW_FILE_NAMES['order_invoice'],
            fn () => $this->get(route('generate-invoice', ['id' => $order->id])),
        ));

        $this->assertContains(self::KUWAITI_ORDER_IN_ITS_OWN_MONEY, $printed, 'the invoice states the dinars that were charged');
        $this->assertNotContains(self::KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE, $printed, 'and never the riyals today would turn them into');
    }

    public function test_the_panel_invoice_of_a_kuwaiti_order_prints_dinars(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        $this->openTheAdminPanel();

        $printed = $this->moneyPrintedIn($this->captureRender(
            'admin-views.order.invoice',
            fn () => $this->get(route('admin.orders.generate-invoice', ['id' => $order->id])),
        ));

        $this->assertContains(self::KUWAITI_ORDER_IN_ITS_OWN_MONEY, $printed);
        $this->assertNotContains(self::KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE, $printed);
    }

    /** The order's own record wins over the storefront serving the request, not only over the platform's default. */
    public function test_a_kuwaiti_order_read_from_the_emirati_storefront_still_prints_dinars(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        $this->asTheCustomerOnTheWebsite($order->customer);

        $printed = $this->moneyPrintedIn($this->captureRender(
            VIEW_FILE_NAMES['order_invoice'],
            fn () => $this->withHeader('X-Storefront', 'AE')->get(route('generate-invoice', ['id' => $order->id])),
        ));

        $this->assertContains(self::KUWAITI_ORDER_IN_ITS_OWN_MONEY, $printed);
        $this->assertNotContains('30.00AED', $printed, 'the dirham sign is never put on a Kuwaiti sale');
    }

    /** An export is a page of many sales at once, so one currency cannot be put in force for it: each row is its own order's money and decimals. */
    public function test_the_order_export_prints_each_row_in_the_currency_that_row_was_sold_in(): void
    {
        $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        $this->placeOrderFrom(country: 'AE', productId: self::EMIRATI_PRODUCT);
        $this->openTheAdminPanel();

        $printed = $this->moneyPrintedIn($this->captureRender(
            'file-exports.order-export',
            fn () => $this->get(route('admin.orders.export-excel', ['status' => 'all'])),
        ));

        $this->assertContains(self::KUWAITI_ORDER_IN_ITS_OWN_MONEY, $printed, 'the Kuwaiti row is in dinars, to three decimals');
        $this->assertContains('250.00AED', $printed, 'the Emirati row is in dirhams, to two');
        $this->assertNotContains(self::KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE, $printed);
        $this->assertNotContains('937.50SAR', $printed, 'and neither is re-converted into the platform currency');
    }

    /** The e-mail is written by a queue worker, which has no storefront and no session: the order's record is the only thing left that knows. */
    public function test_the_order_mail_a_queue_worker_sends_prints_dinars(): void
    {
        $this->openTheAdminPanel();
        $this->switchTheOrderMailOn();
        Storage::fake('public');

        $documents = $this->orderMailsAQueueWorkerWrites(fn () => $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT));

        $this->assertCount(2, $documents, 'the customer is told and so is the shop');

        foreach ($documents as $document) {
            $printed = $this->moneyPrintedIn($document);
            $this->assertContains(self::KUWAITI_ORDER_IN_ITS_OWN_MONEY, $printed);
            $this->assertNotContains(self::KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE, $printed);
        }
    }

    /**
     * Dormancy. An order placed before the snapshot columns records no currency, and so does the default storefront on a platform that converts
     * from a base currency: both are printed exactly as they were, by the platform's settings and today's rate.
     */
    public function test_an_order_that_records_no_currency_is_printed_by_the_platform_settings_as_before(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        DB::table('orders')->where('id', $order->id)->update(['currency_code' => null, 'currency_rate' => null]);
        $this->asTheCustomerOnTheWebsite($order->customer);

        $printed = $this->moneyPrintedIn($this->captureRender(
            VIEW_FILE_NAMES['order_invoice'],
            fn () => $this->get(route('generate-invoice', ['id' => $order->id])),
        ));

        $this->assertContains(self::KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE, $printed, 'nothing about such an order is read, so nothing about it changes');
        $this->assertNotContains(self::KUWAITI_ORDER_IN_ITS_OWN_MONEY, $printed);
    }

    /** Dormancy, the other half: a sale of the default storefront, which records no currency on this platform, is the document it always was. */
    public function test_a_saudi_order_of_the_default_storefront_is_printed_as_before(): void
    {
        $order = $this->placeOrderFrom(country: 'SA', productId: self::SAUDI_PRODUCT);

        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('currency_code'), 'the default storefront of a converting platform records none');

        $this->openTheAdminPanel();

        $printed = $this->moneyPrintedIn($this->captureRender(
            'admin-views.order.invoice',
            fn () => $this->get(route('admin.orders.generate-invoice', ['id' => $order->id])),
        ));

        $this->assertContains('375.00SAR', $printed, '100 held as base, at the riyal rate of 3.75, as the panel has always printed it');
    }

    /**
     * A document is read, not entered: printing an order must not change the money the reader is shown next. loadCurrency() writes the
     * platform's default currency into the session, so an invoice that put its own currency there would leave the panel in dinars.
     */
    public function test_printing_a_kuwaiti_invoice_leaves_the_reader_in_their_own_currency(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        $this->openTheAdminPanel();

        $this->captureRender('admin-views.order.invoice', fn () => $this->get(route('admin.orders.generate-invoice', ['id' => $order->id])));

        $this->assertSame('SAR', session('currency_code'), 'the panel is still in the platform currency after the Kuwaiti invoice was printed');
    }

    /**
     * currency_rate is the rate that turns a stored amount into the order's currency. Every order recorded so far holds its amounts in its own
     * currency, so its rate is one. What any other rate would mean for a printed figure is not decided, and a document is not re-scaled by a
     * guess: such an order is left exactly as it is, and the decision is the owner's.
     */
    public function test_an_order_whose_record_names_a_rate_other_than_one_is_left_alone(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        DB::table('orders')->where('id', $order->id)->update(['currency_rate' => 0.307]);
        $this->asTheCustomerOnTheWebsite($order->customer);

        $printed = $this->moneyPrintedIn($this->captureRender(
            VIEW_FILE_NAMES['order_invoice'],
            fn () => $this->get(route('generate-invoice', ['id' => $order->id])),
        ));

        $this->assertContains(self::KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE, $printed);
        $this->assertNotContains(self::KUWAITI_ORDER_IN_ITS_OWN_MONEY, $printed);
    }

    /** A code the platform has no currency for cannot be printed, so that order is left alone too, rather than labelled with a sign nobody has. */
    public function test_an_order_whose_currency_the_platform_does_not_know_is_left_alone(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        DB::table('orders')->where('id', $order->id)->update(['currency_code' => 'ZZZ']);
        $this->asTheCustomerOnTheWebsite($order->customer);

        $printed = $this->moneyPrintedIn($this->captureRender(
            VIEW_FILE_NAMES['order_invoice'],
            fn () => $this->get(route('generate-invoice', ['id' => $order->id])),
        ));

        $this->assertContains(self::KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE, $printed);
    }

    /**
     * A currency an operator stops selling in is still the currency the order was sold in: switching the dinar off must not turn a Kuwaiti
     * invoice back into riyals at today's rate.
     */
    public function test_an_order_keeps_its_currency_after_that_currency_is_switched_off(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);
        DB::table('currencies')->where('code', 'KWD')->update(['status' => 0]);
        cache()->forget('active_currency_list');
        $this->asTheCustomerOnTheWebsite($order->customer);

        $printed = $this->moneyPrintedIn($this->captureRender(
            VIEW_FILE_NAMES['order_invoice'],
            fn () => $this->get(route('generate-invoice', ['id' => $order->id])),
        ));

        $this->assertContains(self::KUWAITI_ORDER_IN_ITS_OWN_MONEY, $printed);
        $this->assertNotContains(self::KUWAITI_ORDER_AT_TODAYS_SAUDI_RATE, $printed);
    }
}
