<?php

namespace App\Services\Storefront;

use App\Services\Notification\NotificationTypeRegistry;

/**
 * Whether a marketing send to the customers of a storefront is skipped (I1-07). A storefront that may not sell (StorefrontSellingGate: not active, paused in any mode, or without what it needs to sell)
 * is not told about carts, offers or campaigns, because the message would invite a purchase it cannot take. Only a marketing type is skipped: the registry says which, and a necessary one (the state of an
 * order, a refund, a payment, a code) is never held back. A send that is tied to no storefront is never skipped, and nothing is read while storefronts are off. The skip is made before the send's own
 * record is written, so what was skipped is sent when the storefront sells again.
 */
final class StorefrontMarketingStop
{
    public function __construct(private readonly StorefrontSellingGate $sellingGate)
    {
    }

    public function skips(string $channel, string $type, ?string $country): bool
    {
        if ($country === null || !NotificationTypeRegistry::isMarketing(channel: $channel, type: $type)) {
            return false;
        }

        $context = StorefrontResolver::fromConfig()->contextOfRecordedCountry(countryCode: $country);

        return $context !== null && !$this->sellingGate->maySell(country: $context->country, currencyCode: $context->currency);
    }

    /**
     * The cart groups whose storefront, the one their products belong to, is not to be sent this type: the storefront is asked once for all the carts it holds.
     *
     * @param iterable<string> $cartGroupIds
     * @return list<string>
     */
    public function stoppedCartGroups(string $channel, string $type, iterable $cartGroupIds): array
    {
        $skipsCountry = [];
        $stopped = [];

        foreach ($cartGroupIds as $cartGroupId) {
            $country = StorefrontCatalog::countryOfCartGroup(cartGroupId: (string) $cartGroupId);

            if ($country === null) {
                continue;
            }

            $skipsCountry[$country] ??= $this->skips(channel: $channel, type: $type, country: $country);

            if ($skipsCountry[$country]) {
                $stopped[] = (string) $cartGroupId;
            }
        }

        return $stopped;
    }
}
