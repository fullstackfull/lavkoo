<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Http\Middleware\ScopeMarketplaceToStaffCountries;
use App\Models\Seller;
use App\Services\Marketplace\SellerScorecardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin seller performance scorecard (Phase 3, Stage A).
 *
 * Lists sellers with their derived quality metrics and health tier. The scorecard is computed for the
 * sellers on the CURRENT page only — bounded work per request — because the tier is derived, not
 * stored. A store that outgrows per-page computation would add a refreshed snapshot table; that is a
 * scale optimisation, not a correctness one, and is called out rather than pretended away.
 */
class SellerScorecardController extends BaseController
{
    public function __construct(private readonly SellerScorecardService $scorecards)
    {
        // Slice 5 of the staff country scopes (GCC-14c): every figure on this page is a seller's across the whole platform, read with the
        // query builder, and which market's orders count towards a seller's tier is nobody's decision yet -- so a restricted employee is
        // answered like for a page that does not exist rather than shown a platform total. Inert while the switch is off.
        $this->middleware(ScopeMarketplaceToStaffCountries::class);
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $sellers = collect();
        $paginator = null;

        if (Schema::hasTable('sellers')) {
            $paginator = Seller::where('status', 'approved')->orderByDesc('id')->paginate(15)->withQueryString();
            $sellers = collect($paginator->items());
        }

        // Compute only for this page's sellers — bounded per request.
        $cards = [];
        foreach ($sellers as $seller) {
            $cards[$seller->id] = $this->scorecards->scorecard($seller->id);
        }

        return view('admin-views.marketplace.seller-scorecard', [
            'paginator' => $paginator,
            'sellers' => $sellers,
            'cards' => $cards,
        ]);
    }
}
