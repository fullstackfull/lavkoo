<?php

namespace Tests\Feature\Storefront;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsStorefrontLegalDocuments;
use Tests\TestCase;

/**
 * The routes that start a new order or payment carry the storefront sale gate (GCC-11). Refunds, cancellations, callbacks, status
 * checks and payments on orders already placed do not, so they stay open for a storefront that may not sell. Both lists are pinned here.
 */
class StorefrontSalesRoutesTest extends TestCase
{
    use BuildsStorefrontLegalDocuments;

    private const SALE_GATE = 'storefront.sellable';

    private const NEW_SALE_ACTIONS = [
        'OrderController@place_order',
        'OrderController@placeOrderByOfflinePayment',
        'OrderController@placeOrderByWallet',
        'OrderController@order_again',
        'PaymentController@payment',
        'PaymentController@customer_add_to_fund_request',
    ];

    private const OPEN_ACTIONS = [
        'OrderController@order_cancel',
        'OrderController@refund_request',
        'OrderController@store_refund',
        'OrderEditController@duePaymentByDigitalPayment',
        'OrderEditController@duePaymentByWallet',
        'OrderEditController@duePaymentByCod',
        'OrderEditController@duePaymentByOfflinePayment',
        'MyFatoorahStatusController@status',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
    }

    private function routeRunning(string $action): RoutingRoute
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (RoutingRoute $candidate) => str_ends_with((string) $candidate->getActionName(), $action));

        $this->assertNotNull($route, "No route runs [{$action}].");

        return $route;
    }

    public function test_every_route_that_starts_a_new_sale_carries_the_sale_gate(): void
    {
        foreach (self::NEW_SALE_ACTIONS as $action) {
            $this->assertContains(self::SALE_GATE, $this->routeRunning($action)->gatherMiddleware(), "{$action} must carry the sale gate.");
        }
    }

    public function test_refunds_cancellations_callbacks_and_obligations_on_placed_orders_stay_open(): void
    {
        foreach (self::OPEN_ACTIONS as $action) {
            $this->assertNotContains(self::SALE_GATE, $this->routeRunning($action)->gatherMiddleware(), "{$action} must stay open.");
        }
    }

    public function test_a_new_digital_payment_is_refused_for_a_storefront_without_approved_legal_texts(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $route = $this->routeRunning('PaymentController@payment');

        $this->postJson('/' . $route->uri(), ['guest_id' => 'guest-1'], ['X-Storefront' => 'AE'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'storefront_not_sellable');
    }
}
