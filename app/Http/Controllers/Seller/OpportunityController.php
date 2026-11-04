<?php

namespace App\Http\Controllers\Seller;

use App\Services\SellerCenter\Automation\Opportunities;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Improvements, kept deliberately apart from problems (handoff 08 A5).
 *
 * An opportunity is not a low-severity issue. It has no severity at all, no due date and no
 * escalation — it is a fact about the shop with one thing the seller could do about it, and the
 * screen renders nothing that is not derived from the shop's own data.
 */
class OpportunityController extends SellerCenterController
{
    public function __construct(private readonly Opportunities $opportunities)
    {
    }

    public function __invoke(Request $request): View
    {
        $found = $this->opportunities->for($this->sellerId($request));

        return view('seller-views.opportunities.index', [
            'opportunities' => $found,
            'windowDays' => $this->opportunities->windowDays(),
            'state' => $found === [] ? 'empty' : 'normal',
        ]);
    }
}
