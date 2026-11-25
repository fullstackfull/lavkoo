<?php

namespace App\Services\Shipping;

use App\Models\Cart;
use App\Models\ShippingAddress;
use App\Services\Marketplace\ShippingRateService;
use Illuminate\Support\Facades\Schema;

/**
 * What a basket is charged for shipping to its destination (H1-08), for whichever surface is asking.
 *
 * The website put the chosen method's cost through the destination's zone rule and the REST API wrote the method's own cost with no zone
 * anywhere near it, so with `zone_wise_shipping` on the same basket going to the same address was charged one figure in a browser and
 * another in the app. A customer quoted one and charged the other has been quoted wrongly in one of them, and nothing in the platform
 * said which. One service answers both now.
 *
 * It only ever REPLACES the method's cost where a zone serves the destination: no zone, no address, or the setting off, and the method's
 * own cost stands exactly as it does today. So a shop that has never made a zone is unchanged by this, which is every shop.
 *
 * The value it rates against is the basket's own rows -- price less discount, times quantity -- because a free-over threshold is about
 * what the customer is spending on goods.
 */
final class DestinationShippingCost
{
    public function __construct(private readonly ShippingRateService $rates = new ShippingRateService())
    {
    }

    public static function isOn(): bool
    {
        return (bool) getWebConfig(name: 'zone_wise_shipping');
    }

    /**
     * The cost to write for this cart group, given the method the customer chose and the address the order is going to.
     *
     * @param int|string|null $addressId the destination address, or null while none has been chosen
     */
    public function forCartGroup(string|int $cartGroupId, float $methodCost, int|string|null $addressId): float
    {
        if (!self::isOn() || $addressId === null) {
            return $methodCost;
        }

        $address = Schema::hasTable('shipping_addresses') ? ShippingAddress::find($addressId) : null;

        if ($address === null) {
            return $methodCost;
        }

        return $this->rates->checkoutCost(
            $methodCost,
            $address->country,
            0.0,
            $this->basketValue($cartGroupId),
            $address->state,
        );
    }

    /** What the basket is worth in goods: price less discount, times quantity, over its rows. */
    private function basketValue(string|int $cartGroupId): float
    {
        if (!Schema::hasTable('carts')) {
            return 0.0;
        }

        return (float) Cart::where('cart_group_id', $cartGroupId)->get()
            ->sum(fn ($item) => ((float) $item->price - (float) $item->discount) * (int) $item->quantity);
    }
}
