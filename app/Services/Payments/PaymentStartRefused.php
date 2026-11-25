<?php

namespace App\Services\Payments;

/**
 * A payment start that will not go ahead, carried back to the surface that asked so each surface can say
 * no in its own vocabulary (D2-06).
 *
 * `PaymentController::payment()` answers a refusal two ways: a phone gets 403 with an `errors` list it
 * already knows how to read, and a browser gets a message and a page. Every refusal the controller
 * decides at its entry point does both. The refusals decided DEEPER IN, inside
 * `getCustomerPaymentRequest()`, could not: that method's return value is a URL to hand the shopper to,
 * so a refusal could only be expressed as "the URL of the checkout page" -- and the app branch, which
 * cannot tell that URL from a gateway's, answered 200 with `redirect_link` pointing at the website's
 * checkout. The phone was told the payment had started.
 *
 * So a refusal is now a value and not a URL. Nothing is guessed from the string: a URL means the shopper
 * is being handed on, and this means they are not.
 */
final class PaymentStartRefused
{
    public function __construct(
        /** The `errors.*.code` the app reads, in the vocabulary its sibling refusals already use. */
        public readonly string $code,
        /** What the customer is told, already translated. */
        public readonly string $message,
        /** The named route a browser is sent back to. */
        public readonly string $route,
    ) {
    }
}
