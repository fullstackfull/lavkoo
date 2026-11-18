<?php

namespace App\Services\Storefront;

use App\Exceptions\StorefrontNotSellableException;

/**
 * Decides whether a request may start a new order or a payment in its storefront (GCC-11, GCC-05, D1-07, I2-07). Every order-creating and payment-initiating
 * path asks it first. A storefront may sell once its legal texts are approved and an independent legal review of them is on record (StorefrontLegalReview),
 * once, while storefront currencies are on, the currency it trades in exists, and once the tax its orders carry has been approved, or a decision that it
 * carries none has been recorded (StorefrontTaxApproval: the Saudi setup is never a stand-in for another country's). While storefronts are off nothing is
 * refused, so the Saudi storefront keeps its behaviour, and the Saudi storefront is grandfathered, so it is never refused while they are on.
 */
final class StorefrontSellingGate
{
    public function __construct(
        private readonly StorefrontLegalReadiness $legalReadiness,
        private readonly StorefrontLegalReview $legalReview,
        private readonly StorefrontCurrency $currency,
        private readonly StorefrontTaxApproval $taxApproval,
    ) {
    }

    public function assertMaySell(StorefrontContext $context): void
    {
        if (!$this->maySell(country: $context->country, currencyCode: $context->currency)) {
            throw new StorefrontNotSellableException(countryCode: $context->country);
        }
    }

    public function maySell(string $country, string $currencyCode): bool
    {
        return !StorefrontResolver::fromConfig()->isEnabled()
            || ($this->legalReadiness->isReady($country)
                && $this->legalReview->isRecorded($country)
                && $this->currency->isReady(country: $country, currencyCode: $currencyCode)
                && $this->taxApproval->isApproved($country));
    }
}
