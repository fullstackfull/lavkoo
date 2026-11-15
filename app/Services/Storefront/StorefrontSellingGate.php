<?php

namespace App\Services\Storefront;

use App\Exceptions\StorefrontNotSellableException;

/**
 * Decides whether a request may start a new order or a payment in its storefront (GCC-11). Every order-creating and payment-initiating
 * path asks it first. While storefronts are off nothing is refused, so the Saudi storefront keeps its behaviour, and the Saudi storefront
 * is grandfathered, so it is never refused while they are on.
 */
final class StorefrontSellingGate
{
    public function __construct(private readonly StorefrontLegalReadiness $legalReadiness)
    {
    }

    public function assertMaySell(StorefrontContext $context): void
    {
        if (!StorefrontResolver::fromConfig()->isEnabled() || $this->legalReadiness->isReady($context->country)) {
            return;
        }

        throw new StorefrontNotSellableException(countryCode: $context->country);
    }
}
