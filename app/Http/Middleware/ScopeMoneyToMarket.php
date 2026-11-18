<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontCurrency;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs an admin screen that works on the products of one country with that country's money in force (GCC-05), so an amount typed for Kuwait is
 * stored as dinars and an amount read from a Kuwaiti product is shown as dinars, whatever the panel's own currency settings say. The market is the
 * country the form names (a new product, a list filtered by country), else the stored country of the product the route names. A screen that names
 * neither, and a request while storefront currencies are off, read the platform's settings as they always did.
 */
class ScopeMoneyToMarket
{
    private const COUNTRY_INPUT = 'storefront_country';

    public function handle(Request $request, Closure $next): Response
    {
        return app(StorefrontCurrency::class)->within(country: $this->marketOf($request), callback: fn (): Response => $next($request));
    }

    private function marketOf(Request $request): ?string
    {
        if (!config('gcc.storefronts.currency.enabled')) {
            return null;
        }

        $named = $request->input(self::COUNTRY_INPUT);

        if (is_string($named) && $named !== '') {
            return $named;
        }

        $productId = $request->route('id');

        if ($productId === null || !StorefrontCatalog::tracksProductCountry()) {
            return null;
        }

        return DB::table('products')->where('id', $productId)->value(StorefrontCatalog::COLUMN);
    }
}
