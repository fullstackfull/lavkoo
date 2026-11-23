<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A payment that a gateway cannot take in the currency of the storefront it was started in (GCC-05). It refuses only the start of a payment:
 * a payment that was already started, its callbacks, refunds and every other way to pay are not affected.
 */
class StorefrontPaymentNotSupportedException extends RuntimeException
{
    public function __construct(public readonly string $gateway, public readonly string $currencyCode)
    {
        parent::__construct("Gateway [{$gateway}] cannot take a payment in [{$currencyCode}] in this storefront.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = translate('payment_method_not_available_in_the_currency_of_this_storefront');

        if ($request->expectsJson()) {
            return response()->json(['code' => 'storefront_payment_not_supported', 'message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
