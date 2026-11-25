<?php

namespace App\Services\Loyalty;

use App\Services\Storefront\LedgerOrigin;
use App\Services\Storefront\StorefrontCurrency;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * What an order earns in points, read in the market the ORDER was sold in (E-02, J-V2).
 *
 * The rate and the switch are per-market settings already: `StorefrontCurrency::LOYALTY_SETTINGS` puts all four of them in the overridden
 * list, `getWebConfig` routes them, and the customer app reads the rate the server publishes. What was missing is the market to read them
 * FOR. The two writers that credit an order's points are both reached from a panel or a seller API -- the admin order page, the vendor order
 * page and the seller API's three status changes -- and `StorefrontCatalog::country()` answers null for all of those, correctly: a panel sees
 * every market at once. So both earned at the PLATFORM rate whatever market the order was sold in, and a market whose programme was switched
 * off earned anyway.
 *
 * The order is the only thing that can answer this. The request cannot: the staff member who marks a Kuwaiti order delivered may be looking
 * at a Saudi storefront, at every market, or at none. So the market is taken from `orders.storefront_country`, which is what
 * `StorefrontCurrency::withinOrder()` reads, and the settings are read inside it.
 *
 * **An order that records no market reads the platform's own settings**, because `withinOrder` falls back to the default market and the
 * default market overrides nothing. That is every order placed before the snapshot columns and every order on a platform with storefronts
 * off, so the Saudi platform earns exactly what it has always earned -- which is what the first test asserts.
 *
 * The order is read with the query builder and not the model, deliberately: the model carries the staff country scope, and a restricted
 * employee marking an order delivered would find no row through it and fall back to the platform rate without anything saying so.
 */
#[Singleton]
final class LoyaltyEarning
{
    public function __construct(private readonly StorefrontCurrency $currency)
    {
    }

    /** Whether the loyalty programme is on for this movement: in the market of its order, or under the platform's own settings when it has none. */
    public function programmeIsOn(?LedgerOrigin $origin = null): bool
    {
        return (int) $this->inTheMarketOfTheOrder(
            origin: $origin,
            read: static fn (): mixed => getWebConfig(name: 'loyalty_point_status'),
        ) === 1;
    }

    /**
     * The points an order of this amount earns, at the rate of the market it was sold in, rounded down as this platform has always rounded
     * them.
     */
    public function pointsForAnOrder(float|int $amount, ?LedgerOrigin $origin = null): int
    {
        return (int) $this->inTheMarketOfTheOrder(
            origin: $origin,
            read: static fn (): int => (int) ($amount * (float) getWebConfig(name: 'loyalty_point_item_purchase_point') / 100),
        );
    }

    /** Runs [read] with the settings of the market the origin's order was sold in, and untouched when the origin names no order. */
    private function inTheMarketOfTheOrder(?LedgerOrigin $origin, Closure $read): mixed
    {
        $order = $this->orderOf($origin);

        return $order === null ? $read() : $this->currency->withinOrder(order: $order, callback: $read);
    }

    private function orderOf(?LedgerOrigin $origin): ?object
    {
        if ($origin?->sourceType !== LedgerOrigin::ORDER || $origin->subject === null) {
            return null;
        }

        return DB::table('orders')->where('id', $origin->subject)->first();
    }
}
