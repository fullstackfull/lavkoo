<?php

namespace App\Http\Controllers\Vendor\Marketplace;

use App\Http\Controllers\BaseController;
use App\Services\Marketplace\SellerScorecardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The seller's own performance scorecard (Phase 3, Stage A). Same numbers the admin sees, for the
 * authenticated seller only — identity always from auth('seller').
 */
class SellerScorecardController extends BaseController
{
    public function __construct(private readonly SellerScorecardService $scorecards)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        return view('vendor-views.marketplace.seller-scorecard', [
            'card' => $this->scorecards->scorecard(auth('seller')->id()),
        ]);
    }
}
