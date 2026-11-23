<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Marketplace\SellerPrincipal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The actions that take the whole shop, not the work of one market (K-V2).
 *
 * Deleting the account, changing the owner's own record, redirecting where the money is paid and managing who may sign in are not
 * operations inside a market: they cover every market the shop sells in at once. So they are scoped by market as well as by owner
 * — a principal granted some of the platform's markets holds authority in those markets and not over the shop, and is refused.
 *
 * Only the platform owner sees every market, and they do not come through this API: a seller principal reaches a shop-wide action
 * only while no grant limits them, which is every seller while the switch is off and every seller granted every market there is.
 *
 * It is declared beside `seller_owner`, never instead of it: a staff member is still refused by that one however generous their
 * role, and this adds the market half on top.
 */
class EnsureSellerHoldsEveryMarket
{
    public function __construct(private readonly SellerMarketScope $markets)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        if (!$principal instanceof SellerPrincipal) {
            return response()->json(['errors' => [
                ['code' => 'auth-001', 'message' => translate('Your existing session token does not authorize you any more')],
            ]], 401);
        }

        if ($this->markets->holdsEveryMarket()) {
            return $next($request);
        }

        return response()->json(['errors' => [
            ['code' => 'market_scope', 'message' => translate('this_action_covers_every_market_of_the_shop_and_your_account_does_not')],
        ]], 403);
    }
}
