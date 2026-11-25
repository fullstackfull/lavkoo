<?php

namespace App\Services\SalesChannels\Support;

use App\Models\SalesChannelAccount;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontSellingGate;

/**
 * Whether the market a channel account sells in has been stopped (H2-12, I2-09).
 *
 * Until this, a country could be paused -- or never opened for sale at all -- and its marketplace accounts went on talking to Amazon,
 * Trendyol and noon: publishing prices, pushing stock, confirming shipments. The only stops were GLOBAL (`SALES_CHANNELS_ENABLED`) and
 * PER ACCOUNT (the account's own switches), and neither is the thing an operator reaches for when one market has to stop selling. Pausing
 * a country that keeps selling on three marketplaces is not a pause.
 *
 * The market of an account is {@see ChannelAccountCountry}'s answer -- read from the account's own vendor settings, which is what the
 * platform has today -- and whether that market may sell is {@see StorefrontSellingGate}'s, the same answer the website's checkout and the
 * payment paths ask. One reading, so a market cannot be shut for a customer and open for a marketplace.
 *
 * **An account whose market cannot be determined is NOT stopped.** That is the honest direction here and the opposite of the usual one: a
 * market that has not been identified has not been paused either, and stopping such an account would stop every Turkish Trendyol account
 * and every account whose settings name nothing the moment the first country is paused -- a platform-wide outage caused by a per-market
 * action. What such an account needs is a country on it, which is H2-06's own criterion.
 *
 * It is dormant in the only sense that matters: while storefronts are off `StorefrontSellingGate` refuses nothing, so no account is ever
 * stopped by this and the Saudi platform is untouched.
 */
final class ChannelAccountMarketStop
{
    public function __construct(
        private readonly ChannelAccountCountry $accountCountry,
        private readonly StorefrontSellingGate $gate,
    ) {
    }

    /** The market this account sells in, or null when its settings name none the platform has a storefront for. */
    public function marketOf(SalesChannelAccount $account): ?string
    {
        return $this->accountCountry->of($account);
    }

    public function isStopped(SalesChannelAccount $account): bool
    {
        return $this->reasonFor($account) !== null;
    }

    /** Why this account may not call out, in words an operator can act on, or null when its market is selling. */
    public function reasonFor(SalesChannelAccount $account): ?string
    {
        $market = $this->marketOf($account);

        if ($market === null) {
            return null;
        }

        $resolver = StorefrontResolver::fromConfig();

        // A market the resolver does not configure has no currency to ask the gate about, and `currencyOf()` throws rather than guess
        // one. Such a market is not a paused market: it is one nobody has set up, which is the same "cannot determine" case as above.
        if (!in_array($market, $resolver->configuredCountries(), true)) {
            return null;
        }

        if ($this->gate->maySell(country: $market, currencyCode: $resolver->currencyOf($market))) {
            return null;
        }

        return "The {$market} storefront is not selling (paused, or not open for sale yet), so its channel accounts make no calls; nothing was sent.";
    }
}
