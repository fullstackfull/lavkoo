<?php

namespace App\Services\Storefront;

use App\Exceptions\StorefrontNotSellableException;

/**
 * Decides whether a request may start a new order or a payment in its storefront (GCC-11, GCC-05, D1-07, I2-07, I2-11). Every order-creating and payment-initiating
 * path asks it first. A storefront may sell once what it needs is met (StorefrontPreflight: its legal texts are approved and an independent legal review of them is on
 * record, the currency it trades in exists while storefront currencies are on, and the tax its orders carry has been approved or a decision that it carries none has been
 * recorded) and a person has made it active (StorefrontLifecycle), and not while it is paused. While storefronts are off nothing is refused, so the Saudi storefront keeps its
 * behaviour, and the default storefront is active without a record, so it is refused only while a person has paused it.
 */
final class StorefrontSellingGate
{
    public function __construct(
        private readonly StorefrontPreflight $preflight,
        private readonly StorefrontLifecycle $lifecycle,
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
            || ($this->lifecycle->isOpenForSale($country)
                && $this->preflight->isMet(country: $country, currencyCode: $currencyCode));
    }
}
