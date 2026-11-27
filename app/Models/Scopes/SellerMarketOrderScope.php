<?php

namespace App\Models\Scopes;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Storefront\OrderStorefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * An order query of a VENDOR WEB PANEL request that was narrowed to the markets the shop was granted only reaches the orders of those
 * markets (K-09, M2-03): the order list, its counters, its detail, its exports and the reports over it all go through this one scope.
 *
 * What an order's market is, is OrderStorefront's rule and is not restated here -- an order with no recorded market belongs to the default
 * one, as the whole platform reads it, so a grant that holds the default market also holds the orders from before the snapshot, and before
 * the orders migration has run a grant of the default market holds all of them.
 *
 * It does nothing for a request the panel did not bind, and nothing for a panel request of a shop with no grant -- every shop while the
 * market scopes are off. It reads the panel's own grant and never the seller API's, which narrows query by query in its controllers.
 */
final class SellerMarketOrderScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $grant = app(SellerMarketScope::class)->panelGrant();

        if ($grant !== null) {
            app(OrderStorefront::class)->narrowToGrant(orders: $builder, grant: $grant);
        }
    }
}
