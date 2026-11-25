<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderCity;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\Support\ArabicText;
use App\Services\Fulfillment\Support\DestinationCountry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * H1-11, criterion 2: the country and the phone number a carrier is given follow the DELIVERY ADDRESS, not the market that sold the order.
 *
 * `DestinationCountry::ofOrder()` is the market an order was sold in, and its own documentation took that for the destination -- "the storefront a
 * customer bought from is the country they are in". True of a domestic sale; false of the cross-border sale H1-06 made possible. A Saudi shop selling to
 * Kuwait would have told the carrier the parcel was going to Saudi Arabia and checked the customer's Kuwaiti mobile against the Saudi rule, which refuses
 * every Kuwaiti number -- so a correct address and a correct number would have held the parcel, with the hold saying the phone was wrong.
 *
 * `TorodAdapterTest` already proves a KUWAITI order to a Kuwaiti address, where both answers agree. These are the cross-border cases, where they do not.
 * Nothing was broken in production, because an order could not cross a border until the engine shipped; it would have broken on the day it was switched on.
 */
class WhereTheParcelIsGoingTest extends TorodTestCase
{
    public function test_a_saudi_order_asks_and_answers_exactly_as_before(): void
    {
        $this->ordersCanRecordTheirMarket();
        $fulfillment = $this->torodFulfillment(['storefront_country' => 'SA']);

        $this->assertSame('SA', DestinationCountry::deliveryOf($fulfillment->order_id), 'where the parcel goes');
        $this->assertSame('SA', DestinationCountry::ofOrder($fulfillment->order_id), 'and the market that sold it: the same country');

        $this->fakeOrderAccepted();
        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertSent(fn (Request $request): bool => $request->data()['receiverPhone'] === '00966551234567');
    }

    public function test_a_cross_border_order_tells_the_carrier_the_country_the_parcel_is_going_to(): void
    {
        $fulfillment = $this->saudiShopSellingIntoKuwait(orderId: 7401);

        $this->assertSame('KW', DestinationCountry::deliveryOf($fulfillment->order_id), 'where the parcel goes');
        $this->assertSame('SA', DestinationCountry::ofOrder($fulfillment->order_id), 'and, separately, the market that sold it');
    }

    public function test_the_phone_of_a_cross_border_order_is_read_by_the_destinations_rule(): void
    {
        $fulfillment = $this->saudiShopSellingIntoKuwait(orderId: 7402);
        $this->fakeOrderAccepted();

        $this->submitAsClaimed($this->adapter(), $fulfillment);

        Http::assertSent(
            fn (Request $request): bool => $request->data()['receiverPhone'] === '0096551234567'
                && $request->data()['receiverAddress']['cityId'] === '50001',
        );
    }

    /** The hold the old reading would have produced, and the one this test exists to prevent. */
    public function test_a_cross_border_order_with_a_good_local_number_is_not_held_for_its_phone(): void
    {
        $preflight = $this->adapter()->preflight($this->saudiShopSellingIntoKuwait(orderId: 7403));

        $this->assertTrue($preflight->ok, 'held for: ' . ($preflight->holdReason ?? '') . ' ' . ($preflight->detail ?? ''));
    }

    /** The right rule, not no rule: a number that is of no market at all still holds the parcel. */
    public function test_a_number_of_no_market_still_holds_the_parcel(): void
    {
        $preflight = $this->adapter()->preflight($this->saudiShopSellingIntoKuwait(orderId: 7404, phone: '12'));

        $this->assertFalse($preflight->ok, 'two digits is nobody\'s mobile number');
    }

    public function test_an_order_whose_address_names_no_country_falls_back_to_the_market_that_sold_it(): void
    {
        $fulfillment = $this->saudiShopSellingIntoKuwait(orderId: 7405, country: '');

        $this->assertSame(
            'SA',
            DestinationCountry::deliveryOf($fulfillment->order_id),
            'which is the answer every shipment of this platform has used',
        );
    }

    /** One order of a Saudi shop, delivered in Kuwait City: the market is SA, the address is KW, and the two must not be confused. */
    private function saudiShopSellingIntoKuwait(int $orderId, string $phone = '+965 5123 4567', string $country = 'KW'): ProviderFulfillment
    {
        $this->ordersCanRecordTheirMarket();

        ProviderCity::firstOrCreate(
            ['provider' => 'torod_point', 'external_id' => '50001'],
            [
                'name_ar' => 'الكويت', 'name_en' => 'Kuwait City', 'region_external_id' => '5000',
                'normalized_ar' => ArabicText::normalize('الكويت'), 'normalized_en' => ArabicText::normalize('Kuwait City'),
            ],
        );

        return $this->torodFulfillment([
            'storefront_country' => 'SA',
            'shipping_address_data' => json_encode([
                'contact_person_name' => 'Ali Al-Sabah', 'phone' => $phone, 'address' => 'Salem Al Mubarak St 4',
                'city' => 'الكويت', 'country' => $country, 'email' => 'ali@example.test',
            ]),
        ], orderId: $orderId);
    }
}
