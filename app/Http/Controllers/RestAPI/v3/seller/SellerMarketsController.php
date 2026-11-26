<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Services\Marketplace\SellerMarketListing;
use Illuminate\Http\JsonResponse;

/**
 * The markets the signed-in seller may put a product in (B-09).
 *
 * The seller app's market picker could not be built because nothing told it what the choice was: the grant lived in
 * `seller_market_scopes` and the only way a seller learnt of it was a create being refused. This is the read side, and it is the whole of
 * this controller's work -- nothing here writes a market, and where a seller product is saved is unchanged.
 *
 * Thin by design: the answer is composed by `SellerMarketListing` out of what `SellerMarketScope` has already decided, so this surface and
 * the create guard in `ProductController` cannot disagree about which markets a seller holds.
 */
class SellerMarketsController extends Controller
{
    public function __construct(private readonly SellerMarketListing $markets)
    {
    }

    #[ApiDoc(
        summary: 'The markets the signed-in seller may put a product in, and which one this request works in',
        description: 'Composed from the seller market grants. While the grants are switched off -- which is every installation today -- '
            . 'the answer is the one default market and `enabled` is false, so an app can leave the picker out. A grant of several markets '
            . 'with none named answers `may_create` false with the refusal that asks for one, as the product create does.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::BETA,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function index(): JsonResponse
    {
        return response()->json($this->markets->payload(), 200);
    }
}
