<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * One order would have been invoiced for two countries' or two selling companies' rows (GCC-13, D1-02). No company may lawfully invoice
 * another company's sale, so the order is refused before anything is written rather than split or guessed at.
 *
 * The customer is told the same thing the cart says when it refuses another storefront's product, because it is the same fact: a product
 * that cannot be sold here. Each country's rows can still be checked out on their own, which is what the cart offers a storefront request.
 */
class OrderMixesSellingCompaniesException extends RuntimeException
{
    /** @param list<string> $sellingScopes the distinct selling scopes the checked rows named */
    public function __construct(public readonly array $sellingScopes)
    {
        parent::__construct('An order may name one selling company only; its rows named ' . implode(', ', $sellingScopes) . '.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = translate('Product_is_unavailable');

        // An API client is answered in JSON whether or not it asked for it: the apps place orders on `api/*`, and a redirect carrying a
        // flash message is no answer to one. Only a browser is sent back to the page it came from.
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['code' => 'order_mixes_selling_companies', 'message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
