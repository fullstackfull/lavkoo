<?php

namespace App\Http\Middleware;

use App\Services\Storefront\CouponCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontCurrency;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs an admin screen that works on the rows of one country with that country's money in force (GCC-05), so an amount typed for Kuwait is
 * stored as dinars and an amount read from a Kuwaiti row is shown as dinars, whatever the panel's own currency settings say. A screen that names
 * no country, and a request while storefront currencies are off, read the platform's settings as they always did.
 *
 * The rows are products unless the route says otherwise (`money.market:coupon`). For a product the market is the country the form names (a new
 * product, a list filtered by country), else the stored country of the product the route names. For a coupon it is the stored country of the coupon
 * the route names, whatever a form says, because a coupon's amounts are typed in one currency and its country never changes: a form that names
 * another country cannot re-read or re-type them in it. A coupon route with no id (a new coupon) takes the country the form names.
 */
class ScopeMoneyToMarket
{
    private const COUNTRY_INPUT = 'storefront_country';

    private const PRODUCTS = 'product';

    private const COUPONS = 'coupon';

    public function handle(Request $request, Closure $next, string $rows = self::PRODUCTS): Response
    {
        return app(StorefrontCurrency::class)->within(country: $this->marketOf($request, $rows), callback: fn (): Response => $next($request));
    }

    private function marketOf(Request $request, string $rows): ?string
    {
        if (!config('gcc.storefronts.currency.enabled')) {
            return null;
        }

        $named = $request->input(self::COUNTRY_INPUT);
        $named = is_string($named) && $named !== '' ? $named : null;

        if ($rows === self::COUPONS) {
            $couponId = $request->route('id');

            return $couponId === null ? $named : CouponCountry::countryOf($couponId);
        }

        if ($named !== null) {
            return $named;
        }

        $productId = $request->route('id');

        if ($productId === null || !StorefrontCatalog::tracksProductCountry()) {
            return null;
        }

        return DB::table('products')->where('id', $productId)->value(StorefrontCatalog::COLUMN);
    }
}
