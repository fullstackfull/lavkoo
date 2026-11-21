<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerMarketScope;
use App\Services\Marketplace\SellerPrincipal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hold a seller request to the markets its principal was granted (K-09).
 *
 * Declared once, on the authenticated seller group, so it covers every route inside it — including one added tomorrow. A route
 * cannot forget it, which is the same reason the API key scope check lives on the auth middleware rather than on each route.
 *
 * **A market a request names is a request, not a grant.** The grant is the rows of `seller_market_scopes` and nothing else, read
 * by SellerMarketScope; this middleware compares the two and refuses what the rows do not hold. So a caller who can set
 * `X-Storefront`, or send `storefront` in a body, cannot reach a market they were not granted: the header widens nothing, and the
 * only way to widen a grant is to write a row, which no route does.
 *
 * It refuses with 403 and a body that carries a code and a message, never the data of the market that was asked for and never a
 * hint of what the grant holds: a caller learns that they may not have it, not who may.
 *
 * Dormant by two conditions: while the switch is off or the grant table is absent nothing is read and nothing is bound, and a
 * principal with no grant row is unrestricted. A Saudi-only seller therefore gets byte-identical answers.
 */
class EnforceSellerMarketScope
{
    public function __construct(private readonly SellerMarketScope $markets)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);

        if (!$principal instanceof SellerPrincipal) {
            return $next($request);
        }

        [$grant, $workingMarket] = $this->markets->bind(request: $request, principal: $principal);

        if ($grant === null) {
            return $next($request);
        }

        $named = $this->markets->requestedMarket($request);

        if ($named !== null && !in_array($named, $grant, true)) {
            return $this->refuse($request);
        }

        $request->attributes->set(
            SellerApiAuthMiddleware::PRINCIPAL,
            $workingMarket === null
                ? $principal->withPermissions($this->markets->permissionsAcross(principal: $principal, grant: $grant))
                : $principal->withPermissions($this->markets->permissionsIn(principal: $principal, market: $workingMarket)),
        );

        return $next($request);
    }

    /**
     * 403, not 404: the seller's own client must be able to tell "you were not granted this market" from "there is nothing here",
     * and this reveals nothing about another shop or another market. No data of any kind travels with it.
     */
    private function refuse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['errors' => [
                ['code' => 'market_scope', 'message' => translate('your_account_does_not_work_in_that_market')],
            ]], 403);
        }

        return response()->view('seller-views.permission-denied', ['permissions' => []], 403);
    }
}
