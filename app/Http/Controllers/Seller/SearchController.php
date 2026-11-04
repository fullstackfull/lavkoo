<?php

namespace App\Http\Controllers\Seller;

use App\Services\SellerCenter\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The command palette's data source.
 *
 * Scoped to the caller's own shop by the principal, never by a parameter — a search endpoint that
 * took a seller id would be a way to read another shop's orders by guessing numbers.
 */
class SearchController extends SellerCenterController
{
    public function __construct(private readonly Search $search)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['groups' => []]);
        }

        return response()->json([
            'groups' => $this->search->find($this->principal($request), $query),
        ]);
    }
}
