<?php

namespace App\Services\Fulfillment;

use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\ProductCountry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The storefront country whose catalogue a fulfilment provider's connection works against (GCC-14b slice 3, B-05).
 *
 * A warehouse holds the boxes of one country. Until this class, every provider's items were matched against the default storefront's products,
 * whichever country the connection actually served — so a Kuwaiti warehouse's SKU could be suggested as a Saudi product, and the same code in
 * both countries would have resolved to the wrong one.
 *
 * What a connection's country is, in order:
 *
 * 1. its own `storefront_country` setting, when that names a configured country;
 * 2. the country setting the provider already had (M5AZN's `country_code`, which it sends to its own API), when that names a configured one;
 * 3. the default storefront's, which is what every connection used before.
 *
 * A connection whose settings name a country the platform has **no storefront for** serves no catalogue of ours: nothing is suggested for it,
 * because every suggestion would be another country's product. That is `servesNoCatalogueOfOurs()`, and it reads the **same chain** as the list
 * above, `country_code` included — read only the first setting there and an Egyptian warehouse's SKU would go on being matched against the Saudi
 * catalogue, which is the thing this class exists to prevent.
 *
 * Dormant with the country-scoped codes switch: with it off every answer is null and nothing is narrowed, exactly as before.
 */
final class ProviderStorefrontCountry
{
    private const TABLE = 'integration_settings';

    /** The setting a connection keeps its storefront country under, and the provider's own country setting to fall back on. */
    private const OWN_SETTING = 'storefront_country';

    private const FALLBACK_SETTINGS = ['country_code'];

    public function __construct(private readonly ProductCodeScope $codeScope)
    {
    }

    /** The country to narrow a provider's product lookups to, or null while the scope does not apply. */
    public function of(?string $provider): ?string
    {
        $default = $this->codeScope->countryOfProviderProducts();
        if ($default === null) {
            return null;
        }

        return self::configuredCountryOf($this->settingsOf($provider)) ?? $default;
    }

    /**
     * Whether the connection says it serves a country the platform has no storefront for, so no product of ours can be its item.
     *
     * It asks the same settings in the same order as `of()`, so the two cannot disagree about what a connection serves. A connection that
     * names nothing serves the default storefront's country and is not cut off.
     */
    public function servesNoCatalogueOfOurs(?string $provider): bool
    {
        if ($this->codeScope->countryOfProviderProducts() === null) {
            return false;
        }

        $settings = $this->settingsOf($provider);

        return self::namedCountries($settings) !== [] && self::configuredCountryOf($settings) === null;
    }

    /** @param array<string, mixed> $settings */
    private static function configuredCountryOf(array $settings): ?string
    {
        foreach (self::namedCountries($settings) as $named) {
            if (in_array($named, ProductCountry::options(), true)) {
                return $named;
            }
        }

        return null;
    }

    /** @return array<string, mixed> the connection's stored settings, read once however many of them a question needs */
    private function settingsOf(?string $provider): array
    {
        if ($provider === null || $provider === '' || !Schema::hasTable(self::TABLE)) {
            return [];
        }

        $settings = json_decode((string) DB::table(self::TABLE)->where('provider', $provider)->value('settings'), true);

        return is_array($settings) ? $settings : [];
    }

    /**
     * The countries the connection's settings name, in the order they are read: its own storefront country first, then the provider's own
     * country setting. A setting that is absent or empty names nothing and is skipped, so the next one still decides.
     *
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private static function namedCountries(array $settings): array
    {
        $named = [];
        foreach ([self::OWN_SETTING, ...self::FALLBACK_SETTINGS] as $setting) {
            $country = isset($settings[$setting]) && is_scalar($settings[$setting]) ? strtoupper(trim((string) $settings[$setting])) : '';
            if ($country !== '') {
                $named[] = $country;
            }
        }

        return $named;
    }
}
