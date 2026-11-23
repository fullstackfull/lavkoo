<?php

namespace App\Exceptions;

use App\Services\Storefront\StorefrontLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A new sale in a storefront that may not sell yet (GCC-11). It refuses only the start of an order or a payment. Refunds, cancellations,
 * payment callbacks and payments for orders already placed are not refused, so an existing obligation is always served.
 */
class StorefrontNotSellableException extends RuntimeException
{
    public function __construct(public readonly string $countryCode)
    {
        parent::__construct("Storefront [{$countryCode}] is not sellable yet.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = app(StorefrontLifecycle::class)->customerMessageOf($this->countryCode) ?? translate('storefront_not_open_for_checkout_yet');

        if ($request->expectsJson()) {
            return response()->json(['code' => 'storefront_not_sellable', 'message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
