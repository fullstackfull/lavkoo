<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerCenterService;
use App\Services\Marketplace\SellerScorecardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller Center for the mobile app — the same verification / performance /
 * finance / SLA composition the web hub shows, served over the v3 seller API.
 * Thin by design: every number comes from the marketplace services the web
 * controllers already use, so both surfaces always agree.
 */
class SellerCenterController extends Controller
{
    public function __construct(
        private readonly SellerCenterService    $center,
        private readonly SellerScorecardService $scorecards,
    )
    {
    }

    #[ApiDoc(
        summary: "One seller's verification, performance, finance and SLA standing",
        description: 'Composed from the same marketplace services the vendor web hub uses, so both surfaces always agree. '
            . 'Identity comes from the seller token; there is no id parameter to change.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function overview(Request $request): JsonResponse
    {
        return response()->json($this->center->overview($request->seller->id), 200);
    }

    #[ApiDoc(
        summary: 'The performance scorecard and derived health tier for the signed-in seller',
        description: 'Fulfilment, cancellation, return and refund rates, rating, moderation strikes and the '
            . 'derived tier (new / good / watch / at_risk) — the same numbers the admin scorecard shows.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function scorecard(Request $request): JsonResponse
    {
        return response()->json($this->scorecards->scorecard($request->seller->id), 200);
    }
}
