<?php

namespace App\Services\Shipping;

use App\Models\Cart;
use App\Models\ShippingAddress;
use App\Services\Marketplace\ShippingRateService;
use Illuminate\Support\Facades\DB;
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
    public function forCartGroup(string|int $cartGroupId, float $methodCost, int|string|null $addressId, int|string|null $methodId = null): float
    {
        return $this->quoteFor($cartGroupId, $methodCost, $addressId, $methodId)['cost'];
    }

    /**
     * The cost AND the rule that produced it, as `zone:{id}` or `method:{id}`.
     *
     * The charge alone cannot be explained afterwards: a zone's rate and a method's own cost are different decisions that land in the same
     * column, and once the zones change nobody can tell which one a past order was charged under. So whichever decided it says so, and the
     * order keeps that beside the figure.
     *
     * @param int|string|null $addressId the destination address, or null while none has been chosen
     * @param int|string|null $methodId the shipping method the customer chose, for the source when no zone applies
     * @return array{cost: float, source: ?string}
     */
    public function quoteFor(string|int $cartGroupId, float $methodCost, int|string|null $addressId, int|string|null $methodId = null): array
    {
        $fromTheMethod = ['cost' => $methodCost, 'source' => $methodId === null ? null : 'method:' . $methodId];

        if (!self::isOn() || $addressId === null) {
            return $fromTheMethod;
        }

        $address = Schema::hasTable('shipping_addresses') ? ShippingAddress::find($addressId) : null;

        if ($address === null) {
            return $fromTheMethod;
        }

        $rate = $this->rates->rateFor(
            $address->country,
            $this->basketWeightInKilograms($cartGroupId),
            $this->basketValue($cartGroupId),
            $address->state,
        );

        return $rate === null
            ? $fromTheMethod
            : ['cost' => (float) $rate['cost'], 'source' => 'zone:' . $rate['zone']->id];
    }

    /**
     * What the basket weighs, in kilograms, out of the products' own recorded weights.
     *
     * Every caller passed 0 before this, so a zone's `per_kg_cost` was a setting the platform collected and then ignored: a shop that
     * wrote one charged the base cost and nothing else, for ever, with no sign that its rule was not being applied.
     *
     * It is dormant without being switched off, which is the honest kind of dormant here. `products.weight_grams` is null for every
     * product that exists (H1-03 added the column empty), so the weight is 0 and the cost is what it was; the first shop to record a
     * weight is a shop asking for its per-kilogram rule to apply. Grams, because a carrier's rate table is banded in grams and a float
     * weight rounds a parcel into the wrong band -- the division happens once, here, at the end.
     */
    private function basketWeightInKilograms(string|int $cartGroupId): float
    {
        if (!Schema::hasTable('carts') || !Schema::hasTable('products') || !Schema::hasColumn('products', 'weight_grams')) {
            return 0.0;
        }

        $grams = (int) DB::table('carts')
            ->join('products', 'products.id', '=', 'carts.product_id')
            ->where('carts.cart_group_id', $cartGroupId)
            ->sum(DB::raw('COALESCE(products.weight_grams, 0) * carts.quantity'));

        return $grams / 1000;
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
