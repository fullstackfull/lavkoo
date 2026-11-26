<?php

namespace App\Services\Fulfillment\Support;

/**
 * Which source fulfils a market's OWN goods -- the products that name no carrier of their own (C-03, finding E11-F08).
 *
 * In-house is an implicit NULL: `products.fulfillment_provider` empty means "the shop packs it", and that is right for the one country
 * this platform has served, where the shop and the country are the same thing. For a second country it is a guess dressed as a default:
 * a Kuwaiti product that names no carrier would be packed by the Saudi shop, because nothing anywhere says what Kuwait's own goods are
 * fulfilled by. The card's words are "each non-Saudi country has an explicit default source or location for its in-house products", and
 * this is the SOURCE half; the location half waits on C-05's ownership decision.
 *
 * It is a map of market code to carrier code, and it ships EMPTY, so every market keeps the implicit in-house default and a Saudi
 * checkout writes exactly the rows it writes today. A market that names a source has its unsourced lines frozen with that source and
 * routed to it, like a product that names one itself; the product's own source always wins, because a product that names a carrier has
 * been assigned to it on purpose.
 *
 * A code no carrier answers for is NOT read as in-house here: it is an unknown source and follows {@see WhatAnUnknownSourceDoes}, whose
 * default is today's platform. So a misspelt entry cannot stop a checkout, and it cannot quietly send a parcel somewhere either.
 *
 * {@see anyMarketNamesOne()} exists for the cost rather than the rule: the snapshotter asks a line's market only when some market names
 * a source, so a platform that has configured none makes not one query it did not make before.
 */
final class WhatAMarketsOwnGoodsAreFulfilledBy
{
    /** Whether any market at all names a source, which is what decides if a line's market is worth looking up. */
    public static function anyMarketNamesOne(): bool
    {
        return self::named() !== [];
    }

    /** The carrier code this market's own goods are fulfilled by, or null for a market that names none -- which is every market as it ships. */
    public static function forMarket(?string $market): ?string
    {
        $wanted = strtoupper(trim((string) $market));

        if ($wanted === '') {
            return null;
        }

        return self::named()[$wanted] ?? null;
    }

    /**
     * The configured map, by market code in upper case.
     *
     * An entry whose market or source is not a non-empty string names nothing and is dropped, so a half-written setting leaves the
     * other markets' entries standing.
     *
     * @return array<string, string>
     */
    private static function named(): array
    {
        $configured = config('fulfillment.market_own_goods_source');

        if (!is_array($configured)) {
            return [];
        }

        $map = [];
        foreach ($configured as $market => $source) {
            $code = is_string($market) ? strtoupper(trim($market)) : '';
            $named = is_string($source) ? trim($source) : '';

            if ($code !== '' && $named !== '') {
                $map[$code] = $named;
            }
        }

        return $map;
    }
}
