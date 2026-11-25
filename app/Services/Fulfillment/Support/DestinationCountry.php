<?php

namespace App\Services\Fulfillment\Support;

use App\Services\Shipping\ShippingEligibility;
use App\Services\Storefront\OrderStorefrontSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The market an order was sold in, and -- separately -- where its parcel is actually going (C-15, H1-11).
 *
 * A provider is told two things that depend on where the parcel is going: the country of the delivery address, and the phone number in the
 * national form of that country. Both were the Saudi answer, because an order could only be Saudi. An order now records its market
 * ({@see OrderStorefrontSnapshot}).
 *
 * That record was taken for the destination on the reasoning that "the storefront a customer bought from is the country they are in", which
 * is true of a domestic sale and FALSE of a cross-border one: a Saudi shop selling to Kuwait would have told the carrier the parcel was
 * going to Saudi Arabia, and checked the customer's Kuwaiti mobile against the Saudi phone rule, which refuses it -- so the parcel would
 * have been held for a number that is perfectly good. Nothing broke, because cross-border selling ships off; it would have broken on the
 * day it was switched on. `deliveryOf()` is the question a carrier is actually asking, and `ofOrder()` stays what it always was: the market
 * that SOLD the order, which is what a seller's earnings, an invoice and a market's figures are about.
 *
 * Null means *nothing is recorded*, not *Saudi Arabia*: the caller then uses the default storefront, which is what every shipment of a
 * Saudi-only installation does. Such an installation has the column once it has migrated and never a value in it, because the record is
 * only written while markets are on — so its shipments are the Saudi shipments they always were, phone rule included.
 *
 * Whether an order can hold a record at all is the question {@see OrderStorefrontSnapshot::columnReady()} already answers, and it is asked
 * through that service rather than cached here: a static answer would outlive the schema it describes.
 */
final class DestinationCountry
{
    /** The market of the order this shipment belongs to, or null when the platform records none. */
    public static function ofOrder(int|string|null $orderId): ?string
    {
        if ($orderId === null || !app(OrderStorefrontSnapshot::class)->columnReady()) {
            return null;
        }

        $recorded = DB::table('orders')->where('id', $orderId)->value(OrderStorefrontSnapshot::COLUMN);
        $code = strtoupper(trim((string) $recorded));

        return $code === '' ? null : $code;
    }

    /**
     * Where this order's parcel is going: the country of its delivery address, or, where nothing says, the market that sold it.
     *
     * The fallback is what every shipment of this platform has used until now, so a Saudi order -- whose address is in Saudi Arabia and whose
     * market is Saudi Arabia -- gets the same answer either way, and a shipment is never held because an address names no country.
     */
    public static function deliveryOf(int|string|null $orderId): ?string
    {
        return ShippingEligibility::deliveryCountryOf($orderId) ?? self::ofOrder($orderId);
    }

    /**
     * The name of a market as a provider should read it, or null when the configuration names none.
     *
     * The six names live beside the six markets in `gcc.storefronts.countries`, so a provider that wants a country in words and a provider
     * that wants a code read the same list.
     */
    public static function nameOf(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));
        $name = $code === '' ? null : (config('gcc.storefronts.countries')[$code]['name'] ?? null);

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
