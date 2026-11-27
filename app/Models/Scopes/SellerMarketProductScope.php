<?php

namespace App\Models\Scopes;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A product query of a VENDOR WEB PANEL request that was narrowed to the markets the shop was granted only reaches the products of those
 * markets (K-09, M2-03): every list, counter, picker, filter, report, export and lookup by id of the panel goes through this one scope,
 * whether it is written with the repository, the model or a relation.
 *
 * It is a global scope for the same reason the admin panel's is ({@see StaffCountryProductScope}): the panel's reads are spread over
 * twenty-five controllers and a repository layer, so a per-call-site narrowing would be a list of places to remember and a screen added
 * tomorrow would be a hole. It wraps what the query already holds in parentheses before adding its own condition, so a query that ends in
 * an `or` condition -- the product search does -- can never let another market's product through.
 *
 * It does nothing for a request the panel did not bind, which is every request of the storefront, the admin panel, the seller APIs, a queue
 * and a console command, and every panel request of a shop with no grant -- every shop while the market scopes are off.
 *
 * It reads the panel's own grant and never the seller API's: the API narrows query by query and compares a narrowed query with an
 * unnarrowed one, which a global scope over both sides would silently answer "inside the grant".
 */
final class SellerMarketProductScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $grant = app(SellerMarketScope::class)->panelGrant();

        if ($grant === null) {
            return;
        }

        if ($grant === [] || !StorefrontCatalog::columnReady()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->qualifyColumn(StorefrontCatalog::COLUMN), $grant);
    }
}
