<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Contracts\Repositories\AttributeRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributeController extends Controller
{
    public function __construct(private readonly AttributeRepositoryInterface $attributeRepo)
    {
    }

    /**
     * The variation attributes a caller may use. Two different questions, and only the caller knows which one it is asking (B-12).
     *
     * With no `storefront_country`, **what this storefront offers**: narrowed by the global scope to the storefront the request is
     * served from. That is the answer this endpoint has always given, and it is the right one for a list a customer browses by — the
     * customer app's compare screen filters the products of the storefront it is already showing.
     *
     * With a `storefront_country`, **what that country's catalogue offers**: for a form that authors a product in one country while
     * the request itself is served from another storefront. This is the asymmetry `ProductCountry::countryOfForm()` already resolves
     * for the panels' four product forms; `api/v1` is a storefront path, so without this the list is narrowed by the storefront of
     * the request instead of by the country of the product. The named country is the one answer, not an extra condition on the
     * request's own storefront.
     *
     * Only a storefront the caller can already be served from may be named, so a named answer is exactly the answer the same caller
     * gets from that storefront and a market that is not open yet keeps its catalogue to itself. Anything else — a market that is not
     * open, a code that is not configured, a blank — answers the unnamed question, so a stale code never empties the list.
     */
    public function get_attributes(Request $request): JsonResponse
    {
        $country = $this->namedStorefront(request: $request);

        if ($country === null) {
            return response()->json($this->attributeRepo->getList(dataLimit: 'all'), 200);
        }

        return response()->json($this->attributeRepo->getListOfferedIn(storefrontCountry: $country, dataLimit: 'all'), 200);
    }

    /** The storefront whose catalogue the caller named, or null when it named none it could be served from. */
    private function namedStorefront(Request $request): ?string
    {
        $requested = $request->query(StorefrontCatalog::COLUMN);
        $country = is_string($requested) ? strtoupper(trim($requested)) : '';

        return in_array($country, StorefrontResolver::fromConfig()->reachableCountries(), true) ? $country : null;
    }
}
