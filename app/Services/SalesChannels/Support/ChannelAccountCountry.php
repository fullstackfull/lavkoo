<?php

namespace App\Services\SalesChannels\Support;

use App\Models\SalesChannelAccount;
use App\Services\Storefront\ProductCountry;

/**
 * The storefront country a channel account sells in, when its own settings say so. Trendyol names its storefront and noon its country; an
 * Amazon account names a region and marketplace ids, which are not countries, so it has none, and neither has an account whose setting is
 * empty or names a country the platform has no storefront for (the Turkish marketplace, Egypt). An account without a country is never
 * narrowed: a product code two countries share stays ambiguous for it.
 */
final class ChannelAccountCountry
{
    /** The setting each channel keeps the country of an account under. */
    private const SETTINGS = ['trendyol' => 'storefront_code', 'noon' => 'country_code'];

    public function of(SalesChannelAccount $account): ?string
    {
        $setting = self::SETTINGS[$account->channel] ?? null;
        $code = $setting === null ? '' : strtoupper(trim((string) $account->setting($setting, '')));

        return in_array($code, ProductCountry::options(), true) ? $code : null;
    }
}
