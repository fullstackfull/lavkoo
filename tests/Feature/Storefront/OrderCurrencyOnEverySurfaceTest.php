<?php

namespace Tests\Feature\Storefront;

use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsOrderCurrencySurfaces;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * What the invoice, the order e-mail and the exports print of an order's money (D1-06): the currency the order was sold in, at the rate it was
 * sold at, and not the currency of whoever is looking or the rate of today.
 *
 * Every figure here is read from the template a real route renders, in that request: a Kuwaiti order is placed by a real cash-on-delivery
 * checkout of the API and then printed from a request that names no storefront, which the Saudi one serves, as an administrator or a customer
 * reading their history does. Nothing in the setup loads a currency or writes one into the session.
 */
class OrderCurrencyOnEverySurfaceTest extends ApiAddressTestCase
{
    use BuildsOrderCurrencySurfaces;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const KUWAITI_PRODUCT = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildOrderCurrencySurfaceWorld();
    }

    /**
     * The customer's own invoice is the first money the template asks for, before any converter has run, so it is the one reader that finds
     * nothing in the session. getCurrencyCode() is declared to return a string and hands back what the session holds, so the whole document is
     * a 500 for a customer who opens their invoice without having been shown a price first.
     */
    public function test_a_customer_downloading_their_invoice_is_not_served_a_type_error(): void
    {
        $order = $this->placeOrderFrom(country: 'KW', productId: self::KUWAITI_PRODUCT);

        $this->asTheCustomerOnTheWebsite($order->customer);

        $this->callRouteThatWritesAFile(fn () => $this->get(route('generate-invoice', ['id' => $order->id])))->assertStatus(200);
    }
}
