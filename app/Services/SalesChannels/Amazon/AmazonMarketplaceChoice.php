<?php

namespace App\Services\SalesChannels\Amazon;

use App\Services\SalesChannels\Support\ChannelAccountCountry;
use Illuminate\Container\Attributes\Singleton;

/**
 * Which ONE of an Amazon account's marketplaces a write goes to (H2-07).
 *
 * Amazon's writes each take a single marketplace: a price or quantity patch, a `putListingsItem`, a content patch and a shipment
 * confirmation all change the listing or the order in the marketplace they name and in no other. Reads go through the account's
 * marketplaces one after another (`AmazonConnector::marketplaceAt`); the writes have always taken the FIRST id of the list, which is right
 * for an account of one country and wrong for an account of two: a Saudi price went into the Emirati catalogue, or the Emirati one never
 * changed at all, with nothing on the account saying so. It was a declared limit of the module (docs/sales-channels/operations.md).
 *
 * What this settles, and what it deliberately does not:
 *
 *  - a SHIPMENT CONFIRMATION names an order, and an Amazon order records the marketplace it was bought in
 *    (`AmazonOrderMapper` keeps it as `snapshot.marketplace_id`). So the right marketplace is a READ of a fact the order already holds,
 *    and that is what `forOrderWrite()` returns. Nothing new is stored, because two records of one fact can disagree;
 *  - a LISTING write names one of OUR products, and nothing on the listing row says which marketplace it is in: the row is keyed
 *    `(account_id, listing_key)`, so one Amazon SKU sold in two marketplaces of one account is ONE row. There is no fact to read, and
 *    `forListingWrite()` therefore answers null -- a refusal for the caller to report -- rather than guessing at the first id. Making a
 *    listing row per marketplace is a change to that unique key and to the one currency an account carries, which is the owner's decision.
 *
 * While `gcc.sales_channels.amazon.marketplace_of_the_write` is off, every answer is the first id of the account's list: today's platform,
 * to the request. No answer can be null then except for an account with no marketplace at all, which the connector already refuses.
 */
#[Singleton]
final class AmazonMarketplaceChoice
{
    /** The reason a refused write reports. One sentence, no account or seller id in it: it is written into a publish result and a log. */
    public const CANNOT_BE_DECIDED = 'This Amazon account sells in more than one country and nothing on the listing says which marketplace to write to. Give each country its own account.';

    public function enabled(): bool
    {
        return (bool) config('gcc.sales_channels.amazon.marketplace_of_the_write', false);
    }

    /**
     * The marketplace a write that names only one of OUR listings goes to, or null when it cannot be decided.
     *
     * @param list<string> $marketplaceIds the account's own list, in its own order
     */
    public function forListingWrite(array $marketplaceIds): ?string
    {
        if (!$this->enabled() || !$this->spansMoreThanOneCountry($marketplaceIds)) {
            return $marketplaceIds[0] ?? null;
        }

        return null;
    }

    /**
     * The marketplace a write about ONE ORDER goes to: the marketplace that order was bought in when the account holds it, in the account's
     * own spelling, so the id sent is one the account was set up with and not one a payload happened to spell differently.
     *
     * @param list<string> $marketplaceIds the account's own list, in its own order
     * @param string|null $orderMarketplaceId what the order itself recorded, or null for an order that recorded none
     */
    public function forOrderWrite(array $marketplaceIds, ?string $orderMarketplaceId): ?string
    {
        if (!$this->enabled()) {
            return $marketplaceIds[0] ?? null;
        }

        $named = strtoupper(trim((string) $orderMarketplaceId));
        foreach ($marketplaceIds as $marketplaceId) {
            if (strtoupper(trim($marketplaceId)) === $named && $named !== '') {
                return $marketplaceId;
            }
        }

        // The order names no marketplace, or one this account does not sell in. Either way it decides nothing, so the listing rule answers.
        return $this->forListingWrite($marketplaceIds);
    }

    /**
     * Whether the list names marketplaces of more than one COUNTRY. A marketplace this platform does not know counts as its own answer
     * (an empty country), because an account that mixes a known marketplace with an unknown one is exactly as undecided as one that mixes
     * two known ones.
     *
     * @param list<string> $marketplaceIds
     */
    public function spansMoreThanOneCountry(array $marketplaceIds): bool
    {
        $countries = array_unique(array_map(
            static fn (string $marketplaceId): string => ChannelAccountCountry::countryOfMarketplace($marketplaceId),
            $marketplaceIds,
        ));

        return count($countries) > 1;
    }
}
