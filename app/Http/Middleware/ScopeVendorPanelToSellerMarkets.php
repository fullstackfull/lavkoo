<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerMarketScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hold a vendor web panel request to the markets its shop was granted (K-09, M2-03).
 *
 * Declared once, on the authenticated vendor group, so it covers every screen inside it -- including one added tomorrow. The panel's
 * WRITES were already held to the grant (a new product takes the shop's one market, and a shop with several is told to name one); its
 * READS were not, because `seller_market` is declared on the seller API group and resolves a principal the API's token middleware put on
 * the request, which a session request never has. A seller granted Dubai alone could read, edit, price and discount every product the shop
 * held in Riyadh, and see every Riyadh order.
 *
 * It binds the grant and nothing else. What that narrows is the global scopes of the models, so the panel's lists, counters, pickers,
 * reports, exports and lookups by id are narrowed wherever they are written, and a screen cannot forget it.
 *
 * Dormant by two conditions: while the switch is off or the grant table is absent nothing is read and nothing is bound, and a shop with no
 * grant row is unrestricted. A Saudi-only seller therefore gets byte-identical screens.
 */
class ScopeVendorPanelToSellerMarkets
{
    public function __construct(private readonly SellerMarketScope $markets)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->markets->bindPanel(request: $request);

        return $next($request);
    }
}
