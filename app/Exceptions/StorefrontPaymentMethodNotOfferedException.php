<?php

namespace App\Exceptions;

use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An order or a payment that asks for a way to pay its storefront has switched off (requirement D2-V2). It refuses only that way to pay, before anything is written:
 * the other ways the storefront offers, and every order and payment that already exists, are not affected.
 *
 * Its message is the customer's own, in the language of the request, because a caller that catches whatever stopped a payment from starting shows that message as
 * it is (the due payment of an edited order does). The storefront and the way to pay go to the log as context.
 */
class StorefrontPaymentMethodNotOfferedException extends RuntimeException
{
    public function __construct(public readonly string $method, public readonly string $countryCode)
    {
        parent::__construct(translate('this_payment_method_is_not_available_in_this_storefront'));
    }

    /** @return array{storefront: string, payment_method: string} */
    public function context(): array
    {
        return ['storefront' => $this->countryCode, 'payment_method' => $this->method];
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['code' => 'storefront_payment_method_not_offered', 'message' => $this->getMessage()], 422);
        }

        Toastr::error($this->getMessage());

        return back()->with('error', $this->getMessage());
    }
}
