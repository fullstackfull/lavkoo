<?php

namespace App\Http\Middleware;

use App\Services\Storefront\StorefrontCurrency;
use Brian2694\Toastr\Facades\Toastr;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to pay an order from the wallet while the request is served from a storefront that trades in its own currency (GCC-05, slice 5). The checkout handlers
 * of the wallet compare the balance with the amount and nothing else, and the balance is one number kept in the platform's money until the balances are per market
 * (GCC-15), so a dinar order would be paid with riyals. It runs after the storefront middleware, so it judges the storefront the request was bound to. Everywhere
 * else, and while the storefront currencies are off, the request goes on as it always did.
 */
class EnsureWalletIsOpenInStorefront
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(StorefrontCurrency::class)->currencyCodeInForce() === null) {
            return $next($request);
        }

        $message = translate('the_wallet_is_not_available_in_the_currency_of_this_storefront');

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], 403);
        }

        Toastr::error($message);

        return redirect()->back();
    }
}
