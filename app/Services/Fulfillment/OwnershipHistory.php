<?php

namespace App\Services\Fulfillment;

use App\Models\ProviderFulfillmentItem;
use App\Services\Storefront\StorefrontMarketRows;

/**
 * How an ownership snapshot that names no market and no carrier account is read (C-04).
 *
 * The two columns `2027_01_02_100000` adds are nullable, and NOTHING rewrites a row written before they existed -- a snapshot is
 * immutable, and that emptiness is the fact which tells an order placed before markets existed from one placed in Saudi Arabia
 * afterwards. So the platform holds two kinds of snapshot and has to answer one question about both: which market, and which account?
 *
 * **The written rule, and it is a rule rather than a guess:** a snapshot with no market belongs to the DEFAULT storefront, and a snapshot
 * with no account ran against that carrier's platform-wide account. Both follow from the platform's own history -- there has only ever
 * been one storefront, and `integration_settings` held exactly one row per carrier until `2026_12_23_100000` gave it a country -- so an
 * order placed before the columns existed was, necessarily, placed in Saudi Arabia against the only account there was.
 *
 * The market half of that rule is {@see StorefrontMarketRows}'s and is not restated here: the shipping methods, the category costs, the
 * redirects and the inventory locations all read an unmarked row as the default storefront's, and a second spelling of "which market is
 * the default" is how two tables come to disagree. What this class adds is the account half and the vocabulary of this one table.
 *
 * It does NOT write and it does NOT backfill.
 */
final class OwnershipHistory
{
    /** The market the line was sold in, spelt as every other market-owning table spells it. */
    public const MARKET = StorefrontMarketRows::COLUMN;

    /** The `integration_settings` row the line's shipments run against. */
    public const ACCOUNT = 'fulfillment_account_id';

    public const TABLE = 'provider_fulfillment_items';

    public function __construct(private readonly StorefrontMarketRows $rows)
    {
    }

    /** The market this line counts towards: its own, or the default storefront's for a row written before markets existed. */
    public function marketOf(ProviderFulfillmentItem $item): string
    {
        return self::marketNamedBy($item) ?? $this->rows->defaultCountry();
    }

    /**
     * Whether this line names its own market.
     *
     * The one thing a backfill would take away, and the reason there is no backfill: an operator asking why a Saudi figure includes a
     * line needs to be able to tell "this line says SA" from "this line says nothing, and the rule reads nothing as SA".
     */
    public static function namesItsOwnMarket(ProviderFulfillmentItem $item): bool
    {
        return self::marketNamedBy($item) !== null;
    }

    /**
     * The carrier account this line's shipments ran against, or null for a row that recorded none.
     *
     * Null is not "no account": it is the carrier's platform-wide row, which is what {@see ProviderConnectionManager::config()} answers a
     * caller that names no country, and is therefore what the shipment actually ran against. Answering the id of today's platform-wide row
     * instead would invent a fact the snapshot does not hold, since that row can since have been given a country or replaced.
     */
    public static function accountIdOf(ProviderFulfillmentItem $item): ?int
    {
        $named = $item->getAttribute(self::ACCOUNT);

        return is_numeric($named) && (int) $named > 0 ? (int) $named : null;
    }

    /** The market the row itself names, upper case as the column is compared, or null where it names none. */
    private static function marketNamedBy(ProviderFulfillmentItem $item): ?string
    {
        $named = strtoupper(trim((string) ($item->getAttribute(self::MARKET) ?? '')));

        return $named === '' ? null : $named;
    }
}
