<?php

namespace App\Services\SalesChannels\Support;

use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;

/**
 * The currency a channel account trades in, and how many decimals that currency's money has (H2-08).
 *
 * **Decimals.** The publisher and all three connectors rounded every price to TWO decimals, which is right for the riyal, the dirham
 * and the Qatari riyal and wrong for the dinar and the rial: KWD, BHD and OMR have three (ISO 4217), so a Kuwaiti price of 2.375 was
 * published as 2.38 and the listing showed a figure the store never set. The decimals are not a second list here -- they are the
 * platform's own, `gcc.storefronts.currency.decimals`, read through {@see StorefrontCurrency::decimalsOf()}, so the channels and the
 * storefronts can never disagree about what a dinar is. A currency nothing names keeps two decimals, which is what every price
 * published until now was rounded to, so a Saudi account's payload is byte for byte what it was.
 *
 * **Currency.** An Amazon price patch fell back to `SAR` when neither the update nor the account named a currency, which is a guess
 * for an account that sells in Dubai -- and a wrong currency on a right figure is a price change of 2.6x. The account's own country
 * is read first (`ChannelAccountCountry`: Trendyol names its storefront, noon its country, an Amazon account its marketplaces), and
 * the currency of that country is the platform's configured currency for it, which is a fact somebody wrote down rather than a guess.
 * Only an account that names NO country and no currency is left to its caller's fallback, and that is every account of the
 * one-market platform this runs on today.
 *
 * A country that is configured but not active still answers: a channel account is prepared, and its catalogue priced, before its
 * storefront opens.
 */
final class ChannelMoney
{
    /**
     * The currency this update should be published in: the one the update carries, then the one on the account, then the currency
     * of the country the account's own settings name. Null when none of the three says anything.
     */
    public static function currencyOf(SalesChannelAccount $account, ?string $named = null): ?string
    {
        foreach ([$named, $account->currency] as $candidate) {
            $code = strtoupper(trim((string) $candidate));

            if ($code !== '') {
                return $code;
            }
        }

        return self::currencyOfTheAccountsCountry($account);
    }

    /** How many decimals this currency's money has: the platform's own answer, and two for a currency nothing names. */
    public static function decimalsOf(?string $currency): int
    {
        $code = strtoupper(trim((string) $currency));

        return $code === '' ? Money::DISPLAY_SCALE : app(StorefrontCurrency::class)->decimalsOf($code);
    }

    /** An amount at the decimals its currency has, which is how the channel will read it. */
    public static function round(Money $amount, ?string $currency): Money
    {
        return $amount->round(scale: self::decimalsOf($currency));
    }

    /** The configured currency of the country the account sells in, or null when it names none or the platform has no such storefront. */
    private static function currencyOfTheAccountsCountry(SalesChannelAccount $account): ?string
    {
        $country = app(ChannelAccountCountry::class)->of($account);
        $resolver = StorefrontResolver::fromConfig();

        return $country !== null && in_array($country, $resolver->configuredCountries(), true)
            ? $resolver->currencyOf($country)
            : null;
    }
}
