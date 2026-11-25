<?php

namespace App\Services\SalesChannels\Support;

use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\ProductCountry;

/**
 * The storefront country a channel account sells in. It is the one the account DECLARES (`ChannelSettings::MARKET`) when it declares one --
 * an explicit answer, validated when it is saved against the platform's storefronts and against the account's own marketplace, so an Amazon
 * account of the Emirati marketplace cannot claim the Saudi storefront (M-744). While storefronts are off no account declares anything and
 * the answer is DERIVED, exactly as it always was:
 *
 * Trendyol names its storefront and noon its country. An Amazon
 * account names a region and marketplace ids: it has a country when every one of its marketplaces is the one Amazon lists for that country (the
 * Gulf marketplaces it lists are Saudi Arabia and the United Arab Emirates), and none when it has no marketplace, one this class does not know, or
 * marketplaces of two countries. An account whose setting is empty, or names a country the platform has no storefront for (the
 * Turkish marketplace, Egypt), has none either. An account without a country is never narrowed: a product code two countries share stays
 * ambiguous for it.
 */
final class ChannelAccountCountry
{
    /** The setting each channel keeps the country of an account under. */
    private const SETTINGS = ['trendyol' => 'storefront_code', 'noon' => 'country_code'];

    /**
     * The marketplace ids Amazon publishes, by the country each one sells in. The two Gulf ones are the countries this platform has storefronts
     * for; the rest are listed so that an account of one of them is recognised as selling somewhere we have no catalogue, rather than read as
     * "country unknown" and matched against every country's products.
     */
    private const AMAZON_MARKETPLACES = [
        'A17E79C6D8DWNP' => 'SA',
        'A2VIGQ35RCS4UG' => 'AE',
        'ARBP9OOSHTCHU' => 'EG',
        'A33AVAJ2PDY3EV' => 'TR',
        'ATVPDKIKX0DER' => 'US',
        'A2EUQ1WTGCTBG2' => 'CA',
        'A1AM78C64UM0Y8' => 'MX',
        'A2Q3Y263D00KWC' => 'BR',
        'A1F83G8C2ARO7P' => 'GB',
        'A1PA6795UKMFR9' => 'DE',
        'A13V1IB3VIYZZH' => 'FR',
        'APJ6JRA9NG5V4' => 'IT',
        'A1RKKUPIHCS9HS' => 'ES',
        'A1805IZSGTT6HS' => 'NL',
        'A2NODRKZP88ZB9' => 'SE',
        'A1C3SOZRARQ6R3' => 'PL',
        'A21TJRUUN4KGV' => 'IN',
        'A1VC38T7YXB528' => 'JP',
        'A19VAU5U5O7RUS' => 'SG',
        'A39IBJ37TRP1C6' => 'AU',
    ];

    public function __construct(private readonly ProductCodeScope $codeScope)
    {
    }

    /** The country one Amazon marketplace id sells in, or empty when this platform does not know that marketplace. */
    public static function countryOfMarketplace(string $marketplaceId): string
    {
        return self::AMAZON_MARKETPLACES[strtoupper(trim($marketplaceId))] ?? '';
    }

    public function of(SalesChannelAccount $account): ?string
    {
        $code = $this->countryOf($account);

        return in_array($code, ProductCountry::options(), true) ? $code : null;
    }

    /**
     * Whether the account says it sells in a country the platform has no storefront for — an Amazon marketplace outside the Gulf, a Turkish
     * Trendyol storefront, a noon country we do not serve. Nothing of ours can be its listing, so it is narrowed to no product at all rather
     * than left to match every country's.
     */
    public function sellsWhereWeHaveNoCatalogue(SalesChannelAccount $account): bool
    {
        if (!$this->codeScope->enabled()) {
            return false;
        }

        $code = $this->countryOf($account);

        return $code !== '' && !in_array($code, ProductCountry::options(), true);
    }

    /** The country the account's own settings name, whether or not this platform has a storefront for it. Empty when they name none, or two. */
    private function countryOf(SalesChannelAccount $account): string
    {
        $declared = $this->declaredCountry($account);

        if ($declared !== '') {
            return $declared;
        }

        return $account->channel === SalesChannel::Amazon->value ? $this->amazonCountry($account) : $this->settingCountry($account);
    }

    /** The country the account SAYS it sells in. Empty while storefronts are off, and empty for every account that declares none. */
    private function declaredCountry(SalesChannelAccount $account): string
    {
        return ChannelSettings::marketMayBeDeclared((string) $account->channel)
            ? strtoupper(trim((string) $account->setting(ChannelSettings::MARKET, '')))
            : '';
    }

    private function settingCountry(SalesChannelAccount $account): string
    {
        $setting = self::SETTINGS[$account->channel] ?? null;

        return $setting === null ? '' : strtoupper(trim((string) $account->setting($setting, '')));
    }

    /** The country all the marketplaces of the account name; empty when they name none or more than one. */
    private function amazonCountry(SalesChannelAccount $account): string
    {
        $countries = array_unique(array_map(
            static fn (mixed $marketplace): string => self::countryOfMarketplace((string) $marketplace),
            $this->marketplaceIdsOf($account),
        ));

        return count($countries) === 1 ? (string) reset($countries) : '';
    }

    /** @return list<mixed> the ids the connectors read (the account service keeps that column in step with the settings), else the ones the settings hold */
    private function marketplaceIdsOf(SalesChannelAccount $account): array
    {
        return array_values((array) ($account->marketplace_ids ?: $account->setting('marketplace_ids', [])));
    }
}
