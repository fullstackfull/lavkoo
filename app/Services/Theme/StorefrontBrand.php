<?php

namespace App\Services\Theme;

use App\Services\Storefront\StorefrontContext;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Container\Attributes\Singleton;
use JsonException;

/**
 * The marketing identity one storefront shows (F-13, GCC-10).
 *
 * The owner's decision is recorded: **LAVKOO's identity is global by default, and a market may override its marketing name, logos and
 * colours.** So this is a fallback resolver and not a second store of brand values: a field a market has not overridden is read from the one
 * global `business_settings` row it has always been read from, and a platform with no override rows is the platform of today, field for
 * field.
 *
 * **The default market has no record of its own, by design.** The global values ARE the default market's brand -- that is what they have
 * always been -- so giving it a second record would be two writers of one field, and there would be no rule for which of them wins. The same
 * reasoning the shared notification account follows (`NotificationProfiles::PLATFORM_WIDE`).
 *
 * **What this does NOT touch is the LEGAL identity.** The name on an invoice, a contract or a tax record is the selling company's
 * ({@see \App\Services\Storefront\LegalEntityRegistry}, F-14), never the marketing brand, and the two must not be read through one
 * accessor -- which is why this class names neither and answers only for the fields below. A brand override is also legally sensitive: whose
 * trademark may appear in which country is the owner's open question on F-13, and nothing here decides it.
 */
#[Singleton]
final class StorefrontBrand
{
    /** The `business_settings` type one market's overrides are stored under, with the market's code appended. */
    public const SETTING_PREFIX = 'storefront_brand_';

    /**
     * The fields a market may override, each named by the global setting it falls back to -- so a reader asks for the name it already asks
     * `getWebConfig()` for and nothing has to learn a second vocabulary.
     *
     * @var list<string>
     */
    public const FIELDS = ['company_name', 'company_web_logo', 'company_mobile_logo', 'company_fav_icon', 'company_footer_logo'];

    /** @var array<string, array<string, string>> the overrides of each market, for the life of this request */
    private array $overrides = [];

    /** Whether a market can override its brand at all. Nothing is read while storefronts are off. */
    public function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.enabled', false);
    }

    /**
     * One brand field for one market: the market's own override where it has one, else the global value.
     *
     * The answer has the SAME SHAPE the global setting has, which is the whole reason the override stores raw bytes: `getWebConfig()` runs a
     * logo setting through `setWebConfigCache()`, which turns an image name into a storage link with a `path` key, and the website's head
     * reads `$web_config['fav_icon']['path']`. An override that answered a bare file name would take the favicon off every page of that
     * market. So a market's record holds exactly what the global row would hold, and it is shaped by the same function.
     *
     * @param string|null $country the market, or null for the one in force
     */
    public function of(string $field, ?string $country = null): mixed
    {
        $shaped = $this->shapedOverridesOf($country);

        return array_key_exists($field, $shaped) ? $shaped[$field] : getWebConfig(name: $field);
    }

    /**
     * Every field of one market's brand, shaped as the global settings are, so that a reader with a whole array of settings to dress can
     * apply them in one step. Only the fields the market has actually overridden are in it, so an empty answer means "change nothing",
     * which is every market today.
     *
     * @return array<string, mixed>
     */
    public function shapedOverridesOf(?string $country = null): array
    {
        $shaped = [];

        foreach ($this->overridesOf($country) as $field => $raw) {
            $shaped[$field] = setWebConfigCache($field, ['value' => $raw]);
        }

        return $shaped;
    }

    /**
     * What one market's record holds, field by field, as raw as the global `business_settings` row would hold it.
     *
     * @return array<string, string>
     */
    public function overridesOf(?string $country = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $market = $this->marketKey($country);

        if ($market === null) {
            return [];
        }

        return $this->overrides[$market] ??= $this->read($market);
    }

    /** Whether this market shows a brand of its own at all: what a reader asks before it does any work. */
    public function overridesAnything(?string $country = null): bool
    {
        return $this->overridesOf($country) !== [];
    }

    /**
     * The website's shared settings array, dressed in the market's own brand (F-13).
     *
     * It exists because `AppServiceProvider` builds `$web_config` and shares it with every view AT BOOT, which is before the `storefront`
     * middleware has run -- so the brand baked into it is always the default market's, whatever market the request turns out to be for.
     * Overriding `getWebConfig()` cannot fix that: the array was already built. A view composer re-dresses it when the view is rendered,
     * which is after the middleware, and this is the part of that worth testing without rendering anything.
     *
     * A market with no override gets the array back UNCHANGED, which is every market today and every market while storefronts are off.
     *
     * @param array<string, mixed> $webConfig
     * @return array<string, mixed>
     */
    public function dress(array $webConfig): array
    {
        $brand = $this->shapedOverridesOf();

        if ($brand === []) {
            return $webConfig;
        }

        // The settings array's own names for these fields, which are not the `business_settings` names: the website has called them this
        // since before storefronts existed and renaming them would touch every theme template.
        foreach (['company_name' => 'company_name', 'company_web_logo' => 'web_logo', 'company_mobile_logo' => 'mob_logo',
                  'company_fav_icon' => 'fav_icon', 'company_footer_logo' => 'footer_logo'] as $setting => $key) {
            if (array_key_exists($setting, $brand)) {
                $webConfig[$key] = $brand[$setting];
            }
        }

        if (array_key_exists('company_name', $brand)) {
            // `name` is the same value under a second key, and `meta_title` is built from it twice. Both are rebuilt here rather than left
            // showing the platform's name beside the market's, which is the kind of half-applied brand that reads as a mistake.
            $name = (string) $brand['company_name'];
            $webConfig['name'] = $name;
            $webConfig['meta_title'] = $name . ' ' . translate('Online_Shopping') . ' | ' . $name . ' ' . translate('ecommerce');
        }

        return $webConfig;
    }

    /** Forgets what was read, for a test that writes an override after something has already asked. */
    public function flush(): void
    {
        $this->overrides = [];
    }

    /**
     * The market whose record to read: the one asked for, or the one in force. Null for the default market and for anything that is not a
     * configured market, because the global values are the default market's brand and a record for it would be a second writer of one field.
     */
    private function marketKey(?string $country): ?string
    {
        $resolver = StorefrontResolver::fromConfig();
        $code = strtoupper(trim((string) ($country ?? StorefrontContext::current()->country)));

        return $code !== $resolver->defaultCountry() && in_array($code, $resolver->configuredCountries(), true) ? $code : null;
    }

    /**
     * @return array<string, string> only the fields this market has overridden with a non-empty value
     */
    private function read(string $market): array
    {
        $stored = getWebConfig(name: self::SETTING_PREFIX . $market);

        if (is_string($stored)) {
            try {
                $stored = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // A record that cannot be read is treated as no override rather than as an error: a market must not lose its whole website
                // head because one setting row was written badly.
                return [];
            }
        }

        if (!is_array($stored)) {
            return [];
        }

        $brand = [];

        foreach (self::FIELDS as $field) {
            $value = $stored[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $brand[$field] = trim($value);
            }
        }

        return $brand;
    }
}
