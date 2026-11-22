<?php

namespace App\Services\SalesChannels\Support;

use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\Storefront\ProductCountry;

/**
 * The storefront country a channel account sells in, when its own settings say so. Trendyol names its storefront and noon its country. An Amazon
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

    /** The marketplace ids Amazon publishes for the Gulf countries it sells in. */
    private const AMAZON_MARKETPLACES = ['A17E79C6D8DWNP' => 'SA', 'A2VIGQ35RCS4UG' => 'AE'];

    public function of(SalesChannelAccount $account): ?string
    {
        $code = $account->channel === SalesChannel::Amazon->value ? $this->amazonCountry($account) : $this->settingCountry($account);

        return in_array($code, ProductCountry::options(), true) ? $code : null;
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
            static fn (mixed $marketplace): string => self::AMAZON_MARKETPLACES[strtoupper(trim((string) $marketplace))] ?? '',
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
