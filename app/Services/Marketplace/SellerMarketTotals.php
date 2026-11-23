<?php

namespace App\Services\Marketplace;

/**
 * Totals of a seller view that spans markets, and the one thing such a view must never do (K-04).
 *
 * **A sum of riyals and dinars is not a number.** It is a figure an operator can read as money, act on, and be wrong about — so
 * where the figures of a view are in more than one currency this refuses to produce the single total and says why, in the answer,
 * where the reader is. It is the reasoning SettlementCurrency already applies to the seller ledger ("the net is kept by currency so
 * the page never adds two", E-17.1) and ABalanceCannotBeLeftUnknown applies to a settlement of a mixed ledger; this is the same
 * rule for the screens the seller app draws.
 *
 * Counts are a different kind of figure and are deliberately treated differently: a unit on a shelf in Riyadh and a unit on a
 * shelf in Dubai are two units, so `counts()` adds them. Only money is refused.
 *
 * While a view is in one currency — every seller today, and every seller of a single market — the combined figure is the figure the
 * screen always printed, and `refused_because` is null. Nothing changes until a second currency is actually present.
 */
final class SellerMarketTotals
{
    /** Why there is no one figure. A language key, so the app can show the reason rather than an empty space. */
    public const CURRENCIES_DIFFER = 'totals_of_different_currencies_are_not_added';

    /**
     * Money figures grouped by the currency they are in, and the combined figures when, and only when, there is one currency.
     *
     * @param  array<string, array<string, float|int>>  $byCurrency  currency code => named money figures
     * @return array{by_currency: list<array<string, mixed>>, combined: array<string, float|int>|null, refused_because: string|null}
     */
    public function money(array $byCurrency): array
    {
        $groups = [];

        foreach ($byCurrency as $currency => $figures) {
            $groups[] = ['currency_code' => (string) $currency] + $figures;
        }

        $oneCurrency = count($byCurrency) === 1;

        return [
            'by_currency' => $groups,
            'combined' => $oneCurrency ? reset($byCurrency) : null,
            'refused_because' => $oneCurrency || $byCurrency === [] ? null : self::CURRENCIES_DIFFER,
        ];
    }

    /**
     * Counts grouped by market, and their sum. A count has no currency, so it is added: anything else would withhold a figure that
     * is correct.
     *
     * @param  array<string, array<string, int>>  $byMarket  market code => named counts
     * @return array{by_market: list<array<string, mixed>>, combined: array<string, int>}
     */
    public function counts(array $byMarket): array
    {
        $groups = [];
        $combined = [];

        foreach ($byMarket as $market => $counts) {
            $groups[] = ['storefront_country' => (string) $market] + $counts;

            foreach ($counts as $name => $count) {
                $combined[$name] = ($combined[$name] ?? 0) + $count;
            }
        }

        return ['by_market' => $groups, 'combined' => $combined];
    }
}
