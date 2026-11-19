<?php

namespace App\Services\Storefront;

use App\Services\Notification\NotificationTypeRegistry;

/**
 * Whether a marketing send to the customers of a storefront is skipped (I1-07). A storefront that may not sell (StorefrontSellingGate: not active, paused in any mode, or without what it needs to sell),
 * or that the configuration does not serve at all, is not told about carts, offers or campaigns, because the message would invite a purchase it cannot take and a visit nobody can make. Only a marketing
 * type is skipped: the registry says which, and a necessary one (the state of an order, a refund, a payment, a code) is never held back. A send that is tied to no storefront is never skipped, and nothing
 * is read while storefronts are off. The skip is made before the send's own record is written, so what was skipped is sent when the storefront sells again.
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

        $resolver = StorefrontResolver::fromConfig();

        if (!$resolver->isEnabled()) {
            return false;
        }

        $context = $resolver->contextOfRecordedCountry(countryCode: $country);

        if ($context === null) {
            return false;
        }

        return !$resolver->servesStorefront(countryCode: $context->country)
            || !$this->sellingGate->maySell(country: $context->country, currencyCode: $context->currency);
    }

    /**
     * The cart groups that are not to be sent this type, in the order they were given. A legacy cart group can hold the rows of two
     * countries (CartManager::cartGroupsSharedWithOtherStorefronts), and the send is one message about the whole group that cannot be half
     * made, so a group is stopped as soon as one of the storefronts its rows belong to may not sell, whichever row comes first: a message
     * that invites a purchase nobody can make is worse than one that waits. The countries of every cart are read in one query, and each
     * storefront is asked once for all the carts it holds.
     *
     * @param list<string> $cartGroupIds
     * @return list<string>
     */
    public function stoppedCartGroups(string $channel, string $type, array $cartGroupIds): array
    {
        $countries = StorefrontCatalog::countriesOfCartGroups(cartGroupIds: $cartGroupIds);
        $skipsCountry = [];
        $stopped = [];

        foreach ($cartGroupIds as $cartGroupId) {
            foreach ($countries[(string) $cartGroupId] ?? [] as $country) {
                if ($country === '') {
                    continue;
                }

                $skipsCountry[$country] ??= $this->skips(channel: $channel, type: $type, country: $country);

                if ($skipsCountry[$country]) {
                    $stopped[] = (string) $cartGroupId;

                    break;
                }
            }
        }

        return $stopped;
    }
}
