<?php

namespace Tests\Feature\Storefront;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
        '\\OrderController@place_order',
        '\\OrderController@placeOrderByOfflinePayment',
        '\\OrderController@placeOrderByWallet',
        '\\OrderController@order_again',
        '\\Customer\\PaymentController@payment',
        '\\Customer\\PaymentController@customer_add_to_fund_request',
        '\\WebController@getCashOnDeliveryCheckoutComplete',
        '\\WebController@getOfflinePaymentCheckoutComplete',
        '\\WebController@checkout_complete_wallet',
        '\\Web\\CartController@orderAgain',
    ];

    private const OPEN_ACTIONS = [
        '\\OrderController@order_cancel',
        '\\OrderController@refund_request',
        '\\OrderController@store_refund',
        '\\OrderEditController@duePaymentByDigitalPayment',
        '\\OrderEditController@duePaymentByWallet',
        '\\OrderEditController@duePaymentByCod',
        '\\OrderEditController@duePaymentByOfflinePayment',
        '\\Customer\\PaymentController@customerOrderEditPayDueAmount',
        '\\MyFatoorahStatusController@status',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontLegalDocumentsTable();
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', function ($table) {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::dropIfExists('guest_users');
        Schema::create('guest_users', function ($table) {
            $table->id();
            $table->string('ip_address')->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });
    }

    /** @return list<RoutingRoute> every route that runs the action: the web and the API often share a controller method. */
    private function routesRunning(string $action): array
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $candidate) => str_ends_with((string) $candidate->getActionName(), $action))
            ->values()
            ->all();

        $this->assertNotEmpty($routes, "No route runs [{$action}].");

        return $routes;
    }

    private function routeRunning(string $action): RoutingRoute
    {
        return $this->routesRunning($action)[0];
    }

    public function test_every_route_that_starts_a_new_sale_carries_the_sale_gate(): void
    {
        foreach (self::NEW_SALE_ACTIONS as $action) {
            foreach ($this->routesRunning($action) as $route) {
                $this->assertContains(self::SALE_GATE, $route->gatherMiddleware(), "{$route->methods()[0]} {$route->uri()} ({$action}) must carry the sale gate.");
            }
        }
    }

    public function test_refunds_cancellations_callbacks_and_obligations_on_placed_orders_stay_open(): void
    {
        foreach (self::OPEN_ACTIONS as $action) {
            foreach ($this->routesRunning($action) as $route) {
                $this->assertNotContains(self::SALE_GATE, $route->gatherMiddleware(), "{$route->methods()[0]} {$route->uri()} ({$action}) must stay open.");
            }
        }
    }

    public function test_a_new_digital_payment_is_refused_for_a_storefront_without_approved_legal_texts(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);
        $route = $this->routeRunning('\\Customer\\PaymentController@payment');

        $this->postJson('/' . $route->uri(), ['guest_id' => 'guest-1'], ['X-Storefront' => 'AE'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'storefront_not_sellable');
    }

    public function test_a_web_checkout_post_is_refused_for_a_storefront_without_approved_legal_texts(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);

        $this->postJson('/checkout-complete?storefront=AE')
            ->assertStatus(422)
            ->assertJsonPath('code', 'storefront_not_sellable');
    }

    public function test_a_web_checkout_post_in_a_browser_goes_back_with_a_message(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.AE.active' => true]);

        $this->from('/shop-cart')->post('/checkout-complete?storefront=AE')
            ->assertRedirect('/shop-cart')
            ->assertSessionHas('error', translate('storefront_not_open_for_checkout_yet'));
    }
}
