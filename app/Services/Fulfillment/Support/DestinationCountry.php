<?php

namespace App\Services\Fulfillment\Support;

use App\Services\Storefront\OrderStorefrontSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The market a shipment is going to, which is the market its order was sold in (C-15).
 *
 * A provider is told two things that depend on where the parcel is going: the country of the delivery address, and the phone number in the
 * national form of that country. Both were the Saudi answer, because an order could only be Saudi. An order now records its market
 * ({@see OrderStorefrontSnapshot}), and that record is the destination: the storefront a customer bought from is the country they are in.
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
