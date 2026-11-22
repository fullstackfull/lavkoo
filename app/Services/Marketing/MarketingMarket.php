<?php

namespace App\Services\Marketing;

use App\Models\ShippingAddress;
use App\Services\Address\AddressCountry;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * Which market a consent decision belongs to.
 *
 * THE HONEST LIMIT: main has no storefront layer, so this is NOT the storefront a person was standing in. What main
 * can answer is the MARKET: a customer's own address names a country, and AddressCountry turns every spelling of it
 * into an ISO code. A subject with no address at all is filed under the home market, which is the one market the store
 * sells in today.
 *
 * When a storefront layer lands, the storefront it names belongs on the consent row's own `storefront` column and this
 * resolver becomes the fallback for a subject that has no storefront.
 */
final class MarketingMarket
{
    /**
     * The market a subject is filed under when nothing else names one.
     *
     * A configured value that is not one of the six markets is IGNORED rather than passed on. `market` is char(2):
     * 'Saudi Arabia' in the environment was stored as 'SA' by a non-strict MySQL and then never matched again, so a
     * recorded withdrawal stopped being honoured and two clicks wrote two rows — measured on MariaDB 10.11.14 by an
     * independent review, and it raises 1406 under the strict-mode job. One typed setting is not allowed to do that.
     */
    public static function homeMarket(): string
    {
        $configured = Config::get('marketing.consent.home_market');

        return is_string($configured) && MarketingConsent::isMarket($configured) ? MarketingConsent::market($configured) : 'SA';
    }

    /** The market of a customer's newest address, or the home market when they have none that names a market. */
    public static function forCustomer(?int $customerId, bool $isGuest = false): string
    {
        if (!$customerId || !Schema::hasTable('shipping_addresses')) {
            return self::homeMarket();
        }

        $countries = ShippingAddress::query()
            ->where('customer_id', $customerId)
            ->where('is_guest', $isGuest ? 1 : 0)
            ->orderByDesc('id')
            ->pluck('country');

        foreach ($countries as $country) {
            $code = AddressCountry::isoCode(is_string($country) ? $country : null);
            if ($code !== null) {
                return $code;
            }
        }

        return self::homeMarket();
    }
}
