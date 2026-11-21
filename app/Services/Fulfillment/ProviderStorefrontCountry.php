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
 * A connection whose `storefront_country` names a country the platform has **no storefront for** serves no catalogue of ours: nothing is
 * suggested for it, because every suggestion would be another country's product. That is `servesNoCatalogueOfOurs()`.
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

        return $this->configuredCountryOf($provider) ?? $default;
    }

    /** Whether the connection says it serves a country the platform has no storefront for, so no product of ours can be its item. */
    public function servesNoCatalogueOfOurs(?string $provider): bool
    {
        if ($this->codeScope->countryOfProviderProducts() === null) {
            return false;
        }

        $named = self::namedCountry($this->settingsOf($provider), self::OWN_SETTING);

        return $named !== '' && !in_array($named, ProductCountry::options(), true);
    }

    private function configuredCountryOf(?string $provider): ?string
    {
        $settings = $this->settingsOf($provider);
        foreach ([self::OWN_SETTING, ...self::FALLBACK_SETTINGS] as $setting) {
            $named = self::namedCountry($settings, $setting);
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

    /** @param array<string, mixed> $settings */
    private static function namedCountry(array $settings, string $setting): string
    {
        return isset($settings[$setting]) && is_scalar($settings[$setting]) ? strtoupper(trim((string) $settings[$setting])) : '';
    }
}
