<?php

namespace App\Services\Storefront;

use Closure;

/**
 * A payment is finished in the storefront it was started in (GCC-02/03). The gateway reports back in a request of its own, with no
 * storefront prefix, header or query, so that request is served as the default storefront. The order is built from the cart, and the
 * default storefront's cart holds no product of another country: the customer would have paid and have no order.
 *
 * The storefront is recorded with the payment when it starts, and bound again while the order is built. While storefronts are off nothing
 * is recorded and nothing is bound, so a payment is finished exactly as before.
 */
final class PaymentStorefront
{
    public const KEY = 'storefront';

    /**
     * @param array<string, mixed> $additionalData what a payment starts with
     * @return array<string, mixed> the same, with the storefront the request is narrowed to
     */
    public static function record(array $additionalData): array
    {
        $country = StorefrontCatalog::country();

        if ($country === null || isset($additionalData[self::KEY])) {
            return $additionalData;
        }

        return $additionalData + [self::KEY => $country];
    }

    /**
     * Runs the callback in the storefront the payment was started in, then puts the request's own storefront back: a queue worker settles
     * one payment after another in one process, so a storefront left bound would narrow the next job.
     *
     * @param array<string, mixed> $additionalData what the payment started with
     */
    public static function within(array $additionalData, Closure $callback): mixed
    {
        $context = config('gcc.storefronts.enabled')
            ? StorefrontResolver::fromConfig()->contextOfRecordedCountry($additionalData[self::KEY] ?? null)
            : null;

        if ($context === null) {
            return $callback();
        }

        $requestContext = app()->bound(StorefrontContext::class) ? app(StorefrontContext::class) : null;
        app()->instance(StorefrontContext::class, $context);

        try {
            return $callback();
        } finally {
            $requestContext === null
                ? app()->forgetInstance(StorefrontContext::class)
                : app()->instance(StorefrontContext::class, $requestContext);
        }
    }
}
