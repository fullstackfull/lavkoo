<?php

namespace App\Http\Controllers\Seller;

use App\Services\Marketplace\SellerAuditTrailService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The shop's own history, on a browser.
 *
 * The Seller Center's navigation has reserved `seller.audit.index` since Wave 1, the route never
 * existed, and the route-existence filter dropped the menu item without a word — so a seller could
 * see what had happened in their shop only from the phone app, and the app's model dropped the
 * before/after values, which is the half worth reading.
 *
 * Scoped by SellerAuditTrailService, which decides what belongs to a shop: rows this seller, their
 * staff or their API keys wrote, plus rows the platform recorded ABOUT the shop. There is no filter
 * a request can pass to widen that.
 */
class AuditController extends SellerCenterController
{
    private const PER_PAGE = 50;

    public function index(Request $request, SellerAuditTrailService $trail): View
    {
        $action = $request->query('action');
        $history = $trail->recent(
            sellerId: $this->sellerId($request),
            limit: self::PER_PAGE,
            action: is_string($action) ? $action : null,
        );

        return view('seller-views.audit.index', [
            'entries' => $history['entries'],
            // Shown, not hidden: the trail is capped, and a seller who cannot page further back
            // needs to know that rather than believe their history begins here.
            'total' => $history['total'],
            'shown' => count($history['entries']),
            'action' => is_string($action) ? $action : '',
            'state' => $history['entries'] === [] ? 'empty' : 'normal',
        ]);
    }
}
