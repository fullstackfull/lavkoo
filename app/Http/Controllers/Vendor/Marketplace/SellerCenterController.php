<?php

namespace App\Http\Controllers\Vendor\Marketplace;

use App\Http\Controllers\BaseController;
use App\Services\Marketplace\SellerCenterService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The Seller Center hub (Phase 3, Stage A) — one page assembling the seller's verification,
 * performance, finance and SLA standing. Identity always from auth('seller').
 */
class SellerCenterController extends BaseController
{
    public function __construct(private readonly SellerCenterService $center)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        return view('vendor-views.marketplace.seller-center', [
            'overview' => $this->center->overview(auth('seller')->id()),
        ]);
    }
}
