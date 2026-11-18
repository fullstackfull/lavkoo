<?php

namespace App\Services\Storefront;

use App\Models\Currency;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Schema;

/**
 * The money of a storefront (GCC-05). A storefront other than the default one trades in its own currency: the prices of its catalog are
 * entered and shown in that currency, and nothing is converted from another storefront's amounts. While it is switched on and a request is
 * served from such a storefront, the three business settings the money helpers read answer for that storefront alone: the platform's default
 * currency becomes the storefront's currency, the currency model becomes the single-currency model (an amount is shown as it is stored), and
 * the decimals become those of the currency (three for the dinar and the rial). Every converter, symbol and formatter that asks getWebConfig()
 * then follows, without a change of its own.
 *
 * Switched off, and for the default storefront, nothing changes: the platform's own settings are read as they always were. A storefront whose
 * currency is missing or inactive keeps the platform's settings, so it never shows another storefront's rate, and it may not sell.
 */
#[Singleton]
final class StorefrontCurrency
{
    /** The business settings a storefront answers for itself while it trades in its own currency. */
    public const OVERRIDDEN_SETTINGS = ['system_default_currency', 'currency_model', 'decimal_point_settings'];

    private const SINGLE_CURRENCY_MODEL = 'single_currency';

    private const DEFAULT_DECIMALS = 2;

    private ?bool $currenciesTableExists = null;

    private ?string $market = null;

    public function isEnabled(): bool
    {
        return (bool) config('gcc.storefronts.currency.enabled') && StorefrontResolver::fromConfig()->isEnabled();
    }

    /**
     * The value a storefront gives to a business setting, or null while the platform's own value stands. The storefront is the one whose
     * products the request sees (StorefrontCatalog::country()), so a price is never shown in a currency other than its catalog's: a panel,
     * a seller API, a queue and a console command, which see every country, read the platform settings.
     */
    public function override(string $setting): string|int|null
    {
        if (!in_array($setting, self::OVERRIDDEN_SETTINGS, true)) {
            return null;
        }

        $currency = $this->currencyInForce();

        if ($currency === null) {
            return null;
        }

        return match ($setting) {
            'system_default_currency' => (int) $currency->id,
            'currency_model' => self::SINGLE_CURRENCY_MODEL,
            'decimal_point_settings' => $this->decimalsOf($currency->code),
        };
    }

    /**
     * Runs the callback with the money of one market in force, whatever the request is narrowed to: an admin screen that works on the rows of one
     * country, whose amounts are in that country's currency, reads and writes them as they are stored. An unknown country, a market that is the
     * default one, and a time while the switch is off change nothing. The market in force before is put back, even when the callback throws.
     */
    public function within(?string $country, Closure $callback): mixed
    {
        $previous = $this->market;
        $this->market = $country === null || trim($country) === '' ? null : strtoupper(trim($country));

        try {
            return $callback();
        } finally {
            $this->market = $previous;
        }
    }

    /** The code of the currency the request's storefront trades in, or null while the platform's own settings stand. */
    public function currencyCodeInForce(): ?string
    {
        return $this->currencyInForce()?->code;
    }

    /** Whether a storefront has the currency it trades in. Every storefront is ready while the switch is off, and so is the default one. */
    public function isReady(string $country, string $currencyCode): bool
    {
        return !$this->isEnabled() || !$this->tradesInOwnCurrency($country) || $this->activeCurrency($currencyCode) !== null;
    }

    /**
     * The currency the amounts of an order of this storefront are in, or null when it cannot be told: the storefront has no active currency,
     * or it is the default storefront on a platform that converts from a base currency, where the currency an order is shown in is the
     * visitor's own choice. The default storefront is read from the platform settings, so call this from a request that is not served from
     * another storefront.
     */
    public function currencyOfSales(string $country): ?Currency
    {
        $resolver = StorefrontResolver::fromConfig();
        $code = strtoupper($country);

        if ($code !== $resolver->defaultCountry()) {
            return in_array($code, $resolver->configuredCountries(), true) ? $this->activeCurrency($resolver->currencyOf($code)) : null;
        }

        if (getWebConfig('currency_model') !== self::SINGLE_CURRENCY_MODEL) {
            return null;
        }

        return Currency::query()->find((int) getWebConfig('system_default_currency'));
    }

    /**
     * What each configured storefront has of its currency, for the readiness command.
     *
     * @return list<array{country: string, active: bool, currency: string, decimals: int, status: string}>
     */
    public function report(): array
    {
        $rows = [];

        foreach ((array) config('gcc.storefronts.countries') as $country => $storefront) {
            $currencyCode = (string) ($storefront['currency'] ?? '');

            $rows[] = [
                'country' => $country,
                'active' => ($storefront['active'] ?? false) === true,
                'currency' => $currencyCode,
                'decimals' => $this->decimalsOf($currencyCode),
                'status' => $this->statusOf(country: $country, currencyCode: $currencyCode),
            ];
        }

        return $rows;
    }

    public function decimalsOf(string $currencyCode): int
    {
        return (int) (config('gcc.storefronts.currency.decimals')[strtoupper($currencyCode)] ?? self::DEFAULT_DECIMALS);
    }

    private function statusOf(string $country, string $currencyCode): string
    {
        if (!$this->tradesInOwnCurrency($country)) {
            return 'default storefront: the platform currency settings stand';
        }

        $this->currenciesTableExists ??= Schema::hasTable('currencies');

        if (!$this->currenciesTableExists) {
            return 'missing: there is no currencies table';
        }

        $row = Currency::query()->whereRaw('UPPER(code) = ?', [strtoupper($currencyCode)])->first();

        return match (true) {
            $row === null => 'missing: add the currency in the admin panel',
            !$row->status => 'inactive: switch the currency on in the admin panel',
            default => 'ready',
        };
    }

    private function currencyInForce(): ?Currency
    {
        if (!(bool) config('gcc.storefronts.currency.enabled')) {
            return null;
        }

        $resolver = StorefrontResolver::fromConfig();
        $country = $this->market ?? StorefrontCatalog::country();

        if ($country === null || $country === $resolver->defaultCountry() || !$resolver->isEnabled() || !in_array($country, $resolver->configuredCountries(), true)) {
            return null;
        }

        return $this->activeCurrency($resolver->currencyOf($country));
    }

    private function tradesInOwnCurrency(string $country): bool
    {
        return strtoupper($country) !== StorefrontResolver::fromConfig()->defaultCountry();
    }

    private function activeCurrency(string $currencyCode): ?Currency
    {
        $this->currenciesTableExists ??= Schema::hasTable('currencies');

        if (!$this->currenciesTableExists) {
            return null;
        }

        return getActiveCurrencies()->first(fn (Currency $currency): bool => strcasecmp($currency->code, $currencyCode) === 0);
    }
}
