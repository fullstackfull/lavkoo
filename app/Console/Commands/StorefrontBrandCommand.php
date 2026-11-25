<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontResolver;
use App\Services\Theme\StorefrontBrand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Reads and writes the marketing identity one storefront shows instead of the platform's (F-13), for an operator who has no admin screen for
 * it yet -- the same role `storefront:visibility` plays for the catalogue mapping.
 *
 * It writes ONE `business_settings` row per market and touches no global setting, so clearing a market's brand restores the platform's
 * identity there and nowhere else. The value stored is what the global row would store: a name for `company_name`, an image file name for a
 * logo or the favicon, which {@see StorefrontBrand} shapes through the same function `getWebConfig()` uses -- so a logo answers with the
 * `path` the website's head reads and not with a bare file name that would take the favicon off every page.
 *
 * It does NOT touch a legal identity. What a market's invoices and contracts say is the selling company's (F-14) and is not one of these
 * fields; whose trademark may appear in which country is the owner's open question on F-13 and nothing here decides it.
 */
class StorefrontBrandCommand extends Command
{
    protected $signature = 'storefront:brand
        {country : the market, which may not be the default one -- the global settings are its brand}
        {field? : company_name, company_web_logo, company_mobile_logo, company_fav_icon or company_footer_logo}
        {value? : what that market shows; omit it to clear the field and fall back to the platform\'s}
        {--show : print this market\'s brand and write nothing}';

    protected $description = 'Show or set the marketing name, logos and favicon one storefront shows instead of the platform\'s';

    public function handle(StorefrontBrand $brand): int
    {
        $resolver = StorefrontResolver::fromConfig();
        $country = strtoupper(trim((string) $this->argument('country')));

        if (!$brand->isEnabled()) {
            $this->error('Storefronts are switched off, so nothing reads a market\'s brand. Nothing is read or written.');

            return self::FAILURE;
        }

        if (!in_array($country, $resolver->configuredCountries(), true)) {
            $this->error(sprintf('Unknown market "%s": use %s.', $country, implode(', ', $resolver->configuredCountries())));

            return self::INVALID;
        }

        if ($country === $resolver->defaultCountry()) {
            $this->error(sprintf('%s is the default market, whose brand IS the platform\'s own settings. Change those instead; a second record for it would be two writers of one field.', $country));

            return self::INVALID;
        }

        if (!$this->option('show') && $this->argument('field') !== null && !$this->write(country: $country, brand: $brand)) {
            return self::INVALID;
        }

        $brand->flush();
        $this->report(country: $country, brand: $brand);

        return self::SUCCESS;
    }

    private function write(string $country, StorefrontBrand $brand): bool
    {
        $field = (string) $this->argument('field');

        if (!in_array($field, StorefrontBrand::FIELDS, true)) {
            $this->error(sprintf('Unknown field "%s": use %s.', $field, implode(', ', StorefrontBrand::FIELDS)));

            return false;
        }

        $value = $this->argument('value');
        $overrides = $brand->overridesOf(country: $country);

        if ($value === null || trim((string) $value) === '') {
            unset($overrides[$field]);
        } else {
            $overrides[$field] = trim((string) $value);
        }

        try {
            $stored = json_encode($overrides, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('That brand could not be stored.');

            return false;
        }

        $type = StorefrontBrand::SETTING_PREFIX . $country;
        $row = DB::table('business_settings')->where('type', $type);
        $row->exists()
            ? $row->update(['value' => $stored, 'updated_at' => now()])
            : DB::table('business_settings')->insert(['type' => $type, 'value' => $stored, 'created_at' => now(), 'updated_at' => now()]);
        clearWebConfigCacheKeys();

        return true;
    }

    private function report(string $country, StorefrontBrand $brand): void
    {
        $overrides = $brand->overridesOf(country: $country);

        if ($overrides === []) {
            $this->line(sprintf('%s: no brand of its own, so it shows the platform\'s identity.', $country));

            return;
        }

        $this->line(sprintf('%s shows its own:', $country));

        foreach ($overrides as $field => $value) {
            $this->line(sprintf('  %s = %s', $field, $value));
        }

        foreach (array_diff(StorefrontBrand::FIELDS, array_keys($overrides)) as $field) {
            $this->line(sprintf('  %s = (the platform\'s)', $field));
        }
    }
}
