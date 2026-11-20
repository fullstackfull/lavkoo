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
    /** The business settings that hold the currency a storefront trades in. */
    public const CURRENCY_SETTINGS = ['system_default_currency', 'currency_model', 'decimal_point_settings'];

    /**
     * The business settings that hold an amount of money: the minimum order, the free-delivery thresholds and the limits of a wallet top-up.
     * The platform's own rows are typed in the platform's money, so a storefront with its own currency may not read them; it reads what an
     * operator gave it (STOREFRONT_AMOUNTS) or, when it has been given nothing, the neutral value.
     */
    public const AMOUNT_SETTINGS = ['minimum_order_amount', 'free_delivery_over_amount', 'free_delivery_over_amount_seller', 'minimum_add_fund_amount', 'maximum_add_fund_amount'];

    /** The amounts an operator may give a storefront (gcc:storefront:money). The others answer the neutral value while its money is in force. */
    public const STOREFRONT_AMOUNTS = ['minimum_order_amount', 'free_delivery_over_amount'];

    /**
     * The settings that open the wallet. A customer has one balance, kept in the platform's money, until the balances are per market (GCC-15), so nothing may be
     * paid from it or put into it in the currency of a storefront that trades in its own: both answer closed there.
     */
    public const CLOSED_SETTINGS = ['wallet_status', 'add_funds_to_wallet'];

    /** The business settings a storefront answers for itself while it trades in its own currency. */
    public const OVERRIDDEN_SETTINGS = [...self::CURRENCY_SETTINGS, ...self::AMOUNT_SETTINGS, ...self::CLOSED_SETTINGS];

    /** No minimum, no free delivery, no top-up (a maximum of zero refuses every amount) and a closed wallet: a value that cannot be in the wrong currency. */
    public const NEUTRAL_AMOUNT = '0';

    /** The shipping type a storefront with its own currency uses: the cost typed on each product, in that storefront's currency. */
    public const PRODUCT_WISE_SHIPPING = 'product_wise';

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

        $storefront = $this->inForce();

        if ($storefront === null) {
            return null;
        }

        return match ($setting) {
            'system_default_currency' => (int) $storefront['currency']->id,
            'currency_model' => self::SINGLE_CURRENCY_MODEL,
            'decimal_point_settings' => $this->decimalsOf($storefront['currency']->code),
            'minimum_order_amount', 'free_delivery_over_amount' => $this->amountOf(country: $storefront['country'], setting: $setting, currencyCode: $storefront['currency']->code),
            default => self::NEUTRAL_AMOUNT,
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

    /**
     * The shipping type the request prices shipping with. The platform's shipping tables (a cost for each method, each category and each zone) are typed in
     * the platform's money, so a storefront that trades in its own currency prices shipping from the product alone: the cost typed on the product, in
     * that storefront's currency, and nothing from the tables. Everywhere else the stored type stands.
     */
    public function shippingTypeInForce(?string $stored): ?string
    {
        return $this->currencyInForce() === null ? $stored : self::PRODUCT_WISE_SHIPPING;
    }

    /**
     * Runs the callback in the money of an order: the storefront its record names, or the default storefront for an order that has none (one placed
     * before the records, or with the switches off), so that an edit in a panel, which sees every country, never takes the money of whichever
     * storefront the editor's own request happens to be served from. The panel edit compares an order's amounts with the minimum order and the
     * free-delivery threshold and prices its shipping, and those are read in the order's own currency.
     */
    public function withinOrder(object|array $order, Closure $callback): mixed
    {
        $country = data_get($order, 'storefront_country');

        return $this->within(
            country: is_string($country) && trim($country) !== '' ? $country : StorefrontResolver::fromConfig()->defaultCountry(),
            callback: $callback,
        );
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

    /**
     * What an operator gave a storefront, as the readers see it, for the readiness report: the two amounts (null when none is given, so the neutral
     * value stands) and the currency a row is tagged with when that is not the currency the storefront trades in, which makes the whole row ignored.
     *
     * @return array{minimum_order_amount: ?string, free_delivery_over_amount: ?string, ignored_tag: ?string}
     */
    public function amountsGiven(string $country): array
    {
        $row = getWebConfig(self::amountRowName($country));
        $resolver = StorefrontResolver::fromConfig();
        $currencyCode = in_array(strtoupper($country), $resolver->configuredCountries(), true) ? $resolver->currencyOf(strtoupper($country)) : '';

        if (!is_array($row)) {
            return ['minimum_order_amount' => null, 'free_delivery_over_amount' => null, 'ignored_tag' => null];
        }

        $tag = (string) ($row['currency'] ?? '');

        if (strcasecmp($tag, $currencyCode) !== 0) {
            return ['minimum_order_amount' => null, 'free_delivery_over_amount' => null, 'ignored_tag' => $tag === '' ? '(none)' : $tag];
        }

        return [
            'minimum_order_amount' => $this->plainAmount($row['minimum_order_amount'] ?? null),
            'free_delivery_over_amount' => $this->plainAmount($row['free_delivery_over_amount'] ?? null),
            'ignored_tag' => null,
        ];
    }

    /** The name of the business setting that holds the amounts an operator gave one storefront (a JSON object tagged with its currency). */
    public static function amountRowName(string $country): string
    {
        return 'storefront_money_' . strtolower($country);
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
        return $this->inForce()['currency'] ?? null;
    }

    /** @return array{country: string, currency: Currency}|null the storefront whose money is in force and its currency, or null while the platform's settings stand */
    private function inForce(): ?array
    {
        if (!(bool) config('gcc.storefronts.currency.enabled')) {
            return null;
        }

        $resolver = StorefrontResolver::fromConfig();
        $country = $this->market ?? StorefrontCatalog::country();

        if ($country === null || $country === $resolver->defaultCountry() || !$resolver->isEnabled() || !in_array($country, $resolver->configuredCountries(), true)) {
            return null;
        }

        $currency = $this->activeCurrency($resolver->currencyOf($country));

        return $currency === null ? null : ['country' => $country, 'currency' => $currency];
    }

    /**
     * What an operator gave one storefront for an amount, verbatim, or the neutral value. The row counts only while it is tagged with the currency the
     * storefront is configured to trade in (a row written for another currency is ignored, never converted) and the amount is a plain number that is not
     * negative; anything else reads as nothing given. getWebConfig reads the row through the settings cache like any other setting, and the row's name is
     * not in OVERRIDDEN_SETTINGS, so it cannot ask itself.
     */
    private function amountOf(string $country, string $setting, string $currencyCode): string
    {
        $row = getWebConfig(self::amountRowName($country));

        return is_array($row) && strcasecmp((string) ($row['currency'] ?? ''), $currencyCode) === 0
            ? $this->plainAmount($row[$setting] ?? null) ?? self::NEUTRAL_AMOUNT
            : self::NEUTRAL_AMOUNT;
    }

    /** The amount as text when it is a plain number that is not negative, else null. */
    private function plainAmount(mixed $amount): ?string
    {
        $text = is_int($amount) || is_float($amount) ? (string) $amount : (is_string($amount) ? $amount : '');

        return preg_match('/^\d+(\.\d+)?$/', $text) === 1 ? $text : null;
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
