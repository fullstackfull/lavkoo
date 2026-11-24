<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\Fulfillment\Support\ProviderRouteSupport;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * H1-02: a carrier declares the routes it serves, and a shipment outside them is held rather than sent.
 *
 * Nothing compared an order's destination with the carrier's coverage. The preflight checked the mapping, the stock, the address, the
 * phone, the city and the cash-on-delivery setting -- so a parcel addressed to a country the carrier does not serve would have been sent,
 * refused by the carrier, and paid for by the customer first.
 *
 * A route nobody declared is NOT served. That is the only safe default for a fact that lives in a courier contract and in no repository:
 * declaring coverage a carrier does not have is how a parcel is sold and never delivered. Which carrier really serves which country is the
 * owner's Q-33; this holds the answer, it does not invent one.
 *
 * The declaration ships as exactly what the platform does today -- the Kingdom to the Kingdom -- so the Saudi shipments this suite is full
 * of are unchanged, which is what the first test says.
 */
class ProviderRouteSupportTest extends FulfillmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ordersCanRecordTheirMarket();
    }

    private function declareRoutes(array $routes, ?array $cashRoutes = null): void
    {
        config(['fulfillment.providers.torod_point.routes' => $routes]);

        if ($cashRoutes !== null) {
            config(['fulfillment.providers.torod_point.cash_on_delivery_routes' => $cashRoutes]);
        }
    }

    public function test_what_ships_today_is_the_kingdom_to_the_kingdom_for_both_carriers(): void
    {
        foreach (['torod_point', 'm5azn'] as $provider) {
            $this->assertSame([['SA', 'SA']], ProviderRouteSupport::routesOf($provider), "{$provider} declares the Saudi route and no other");
            $this->assertTrue(ProviderRouteSupport::serves($provider, 'SA', 'SA'));
            $this->assertFalse(ProviderRouteSupport::serves($provider, 'SA', 'KW'), 'and nothing abroad until somebody writes the coverage down');
        }
    }

    /** A carrier that declares nothing at all serves the platform's own market, which is what every carrier does today. */
    public function test_a_carrier_that_declares_nothing_serves_the_platforms_own_market(): void
    {
        config(['fulfillment.providers.torod_point.routes' => null]);

        $this->assertTrue(ProviderRouteSupport::serves('torod_point', 'SA', 'SA'));
        $this->assertFalse(ProviderRouteSupport::serves('torod_point', 'SA', 'AE'));
    }

    public static function routeAnswers(): array
    {
        return [
            'the declared route' => [['SA:KW'], 'SA', 'KW', true],
            'its reverse, which is a different arrangement' => [['SA:KW'], 'KW', 'SA', false],
            'a destination nobody declared' => [['SA:KW'], 'SA', 'BH', false],
            'any destination from one source' => [['SA:*'], 'SA', 'OM', true],
            'any source to one destination' => [['*:SA'], 'QA', 'SA', true],
            'everything, for a carrier that really covers everything' => [['*:*'], 'OM', 'BH', true],
            'a pair that is not a pair' => [['SA'], 'SA', 'SA', false],
            'an empty declaration serves nothing' => [[], 'SA', 'SA', false],
            'lower case and spacing are read' => [[' sa : kw '], 'sa', 'kw', true],
        ];
    }

    #[DataProvider('routeAnswers')]
    public function test_a_route_is_served_only_when_it_is_declared(array $declared, string $from, string $to, bool $served): void
    {
        $this->declareRoutes($declared);

        $this->assertSame($served, ProviderRouteSupport::serves('torod_point', $from, $to));
    }

    public function test_a_route_with_no_source_or_no_destination_is_not_served(): void
    {
        $this->declareRoutes(['*:*']);

        $this->assertFalse(ProviderRouteSupport::serves('torod_point', null, 'KW'), 'nothing to compare is not a match');
        $this->assertFalse(ProviderRouteSupport::serves('torod_point', 'SA', null));
        $this->assertFalse(ProviderRouteSupport::serves('torod_point', 'SA', ' '));
    }

    /**
     * Collecting cash abroad is a separate arrangement from carrying a parcel abroad: a courier that cannot bank the money in the
     * destination country will carry the parcel and hand the cash to nobody.
     */
    public function test_cash_on_delivery_is_declared_separately_from_the_route(): void
    {
        $this->declareRoutes(['SA:SA', 'SA:KW'], ['SA:SA']);

        $this->assertTrue(ProviderRouteSupport::collectsCashOn('torod_point', 'SA', 'SA'));
        $this->assertFalse(ProviderRouteSupport::collectsCashOn('torod_point', 'SA', 'KW'), 'the parcel goes, the cash does not');
    }

    /** A carrier that says nothing about cash collects it at home and nowhere else, which is the Saudi arrangement that runs today. */
    public function test_a_carrier_silent_about_cash_collects_it_only_at_home(): void
    {
        config(['fulfillment.providers.torod_point.cash_on_delivery_routes' => null]);
        $this->declareRoutes(['SA:SA', 'SA:KW']);

        $this->assertTrue(ProviderRouteSupport::collectsCashOn('torod_point', 'SA', 'SA'));
        $this->assertFalse(ProviderRouteSupport::collectsCashOn('torod_point', 'SA', 'KW'));
    }

    public function test_cash_is_never_collected_on_a_route_the_carrier_does_not_serve(): void
    {
        $this->declareRoutes(['SA:SA'], ['*:*']);

        $this->assertFalse(ProviderRouteSupport::collectsCashOn('torod_point', 'SA', 'KW'), 'a cash declaration cannot open a route');
    }

    public function test_a_route_that_leaves_its_country_is_cross_border(): void
    {
        $this->assertTrue(ProviderRouteSupport::isCrossBorder('SA', 'KW'));
        $this->assertFalse(ProviderRouteSupport::isCrossBorder('SA', 'SA'));
        $this->assertFalse(ProviderRouteSupport::isCrossBorder('SA', null), 'a destination nobody knows is not a border crossing');
    }

    /** And the whole point of the declaration: the dispatcher holds a shipment whose destination the carrier does not serve. */
    public function test_a_shipment_to_an_undeclared_destination_is_held_and_nothing_is_sent(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, orderOverrides: ['storefront_country' => 'KW'], orderId: 4100);

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $held = ProviderFulfillment::findOrFail($fulfillment->id);
        $this->assertSame(HoldReason::ROUTE_UNSUPPORTED, $held->hold_reason);
        $this->assertSame([], $this->torod->submitted, 'nothing left for the carrier');
    }

    /**
     * H1-06 at dispatch: a shipment whose PRODUCT permission is missing is held even though its carrier serves the route. A permission
     * withdrawn while a parcel waited must stop the parcel, which is why the decision kept on the order is a record and not a licence.
     */
    public function test_a_waiting_shipment_is_held_when_the_product_may_not_go_there(): void
    {
        config(['gcc.shipping.cross_border.enabled' => true]);
        (require database_path('migrations/2026_12_20_100000_create_product_destination_permissions_table.php'))->up();
        \App\Services\Shipping\ProductDestinationPermission::forget();
        $this->declareRoutes(['SA:SA', 'SA:KW']);
        $this->enableProvider(ProviderCode::TorodPoint);

        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, orderOverrides: [
            'storefront_country' => 'KW',
            'shipping_address_data' => json_encode(['country' => 'KW', 'city' => 'Kuwait City', 'phone' => '51234567', 'address' => 'Salem St 1', 'contact_person_name' => 'Ali']),
        ], orderId: 4104);
        // The products of this world carry no market column, so their market reads as the default one -- which is the Saudi product this
        // shipment is really made of, and the case that matters: it is going to Kuwait with no permission to.

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertSame(HoldReason::ROUTE_UNSUPPORTED, ProviderFulfillment::findOrFail($fulfillment->id)->hold_reason);
        $this->assertSame([], $this->torod->submitted, 'nothing left for the carrier');
    }

    /** The control: the Saudi route every shipment takes today is dispatched exactly as before. */
    public function test_a_saudi_shipment_is_not_held_for_its_route(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, orderOverrides: ['storefront_country' => 'SA'], orderId: 4101);

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertNotSame(HoldReason::ROUTE_UNSUPPORTED, ProviderFulfillment::findOrFail($fulfillment->id)->hold_reason);
    }

    /** An order that records no market at all is the platform's own, which is every order placed before markets existed. */
    public function test_an_order_with_no_market_recorded_is_not_held_for_its_route(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, orderId: 4102);

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertNotSame(HoldReason::ROUTE_UNSUPPORTED, ProviderFulfillment::findOrFail($fulfillment->id)->hold_reason);
    }

    /** Once the carrier declares the route, the same Kuwaiti shipment is no longer held for it: the declaration is what opens it. */
    public function test_declaring_the_route_stops_the_hold(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint);
        $this->declareRoutes(['SA:SA', 'SA:KW']);
        $fulfillment = $this->routeSingleProviderOrder(ProviderCode::TorodPoint, orderOverrides: ['storefront_country' => 'KW'], orderId: 4103);

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertNotSame(HoldReason::ROUTE_UNSUPPORTED, ProviderFulfillment::findOrFail($fulfillment->id)->hold_reason);
    }
}
