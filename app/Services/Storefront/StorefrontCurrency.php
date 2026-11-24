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

    /**
     * The settings an order's own record answers while that order's figures are printed. The platform's default currency is deliberately not one
     * of them: loadCurrency() reads it and writes its answer into the session, so a reader who opened a Kuwaiti invoice would be left looking at
     * the rest of the site in dinars. The model and the decimals are all the converters and the formatters need; the code and the symbol come
     * from {@see currencyCodeInForce()} and {@see symbolInForce()}, which write nothing.
     */
    private const ORDER_CURRENCY_SETTINGS = ['currency_model', 'decimal_point_settings'];

    /** No minimum, no free delivery, no top-up (a maximum of zero refuses every amount) and a closed wallet: a value that cannot be in the wrong currency. */
    public const NEUTRAL_AMOUNT = '0';

    /** The shipping type a storefront with its own currency uses: the cost typed on each product, in that storefront's currency. */
    public const PRODUCT_WISE_SHIPPING = 'product_wise';

    private const SINGLE_CURRENCY_MODEL = 'single_currency';

    /**
     * What loadCurrency() keeps in the session about the money in force. A market switch re-fills them from the default currency setting the
     * market overrides, so they are put back with the market: localToDefaultCurrency() and loyaltyPointToLocalCurrency() read the rate out of
     * the session without reloading it, and would otherwise answer one storefront's rate for the rest of the visitor's session.
     */
    private const MONEY_KEPT_IN_THE_SESSION = ['system_default_currency_info', 'currency_code', 'currency_symbol', 'currency_exchange_rate', 'usd', 'default'];

    private const DEFAULT_DECIMALS = 2;

    private ?bool $currenciesTableExists = null;

    private ?string $market = null;

    /** The currency an order was sold in while its own figures are being printed; nothing else may answer for those figures. */
    private ?Currency $orderCurrency = null;

    /** @var array<string, Currency|false> the currency row of a code, false when there is none: an order's record is read once per document, not once per figure */
    private array $currencyOfCode = [];

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

        if ($this->orderCurrency !== null && in_array($setting, self::ORDER_CURRENCY_SETTINGS, true)) {
            return $this->orderSettingOf(setting: $setting, currency: $this->orderCurrency);
        }

        $storefront = $this->inForce();

        if ($storefront === null) {
            return null;
        }

        return match ($setting) {
            'system_default_currency', 'currency_model', 'decimal_point_settings' => $this->currencySettingOf(setting: $setting, currency: $storefront['currency']),
            'minimum_order_amount', 'free_delivery_over_amount' => $this->amountOf(country: $storefront['country'], setting: $setting, currencyCode: $storefront['currency']->code),
            default => self::NEUTRAL_AMOUNT,
        };
    }

    /**
     * What a setting answers while an ORDER's own currency is in force: the single-currency model, so a stored amount is printed as it was
     * stored, and the decimals of that currency only where ISO 4217 names them. For every other currency the operator's own
     * decimal_point_settings stands, because that is the number of decimals every surface has always printed this order's amounts with, and
     * forcing two would drop the third decimal off an amount that was charged with three: a riyal invoice of a shop set to three decimals
     * would understate 10.555 as 10.55, since usdToDefaultCurrency() floors at whatever decimal count is in force.
     *
     * A storefront's own money (above) keeps answering decimalsOf(), so the behaviour already shipped for B-17 does not move.
     */
    private function orderSettingOf(string $setting, Currency $currency): string|int|null
    {
        return $setting === 'currency_model' ? self::SINGLE_CURRENCY_MODEL : $this->configuredDecimalsOf($currency->code);
    }

    /** What the three currency settings answer while one currency is in force: that currency, shown as it is stored, with its own decimals. */
    private function currencySettingOf(string $setting, Currency $currency): string|int
    {
        return match ($setting) {
            'system_default_currency' => (int) $currency->id,
            'currency_model' => self::SINGLE_CURRENCY_MODEL,
            default => $this->decimalsOf($currency->code),
        };
    }

    /**
     * Runs the callback in the money an order was sold in, as the order's own record names it, so a document of that order states the figures
     * that were charged and the currency they were charged in: the invoice, the order e-mail and the exports read this.
     *
     * The record is the authority, not the request: the e-mail is written by a queue worker that has no storefront and no session at all, and
     * an invoice or an export is read in a panel that sees every country, so neither can be allowed to re-convert a sold amount at today's rate
     * and label it with today's currency. It is not read through the storefront switch either — an order charged in dinars was charged in
     * dinars whatever the settings say afterwards — and the currency row is read whether or not it is still active, because whether a currency
     * may still be sold in says nothing about what an order was already sold for.
     *
     * An order that records no currency (every order placed before the snapshot columns, and the default storefront on a platform that converts
     * from a base currency), and a row whose order has gone, run the callback untouched: every such document is what it was, byte for byte.
     */
    public function withinOrderCurrency(object|array|null $order, Closure $callback): mixed
    {
        $currency = $this->currencyRecordedOn($order);

        if ($currency === null) {
            return $callback();
        }

        $previous = $this->orderCurrency;
        $this->orderCurrency = $currency;

        try {
            return $callback();
        } finally {
            $this->orderCurrency = $previous;
        }
    }

    /**
     * The currency an order's own figures are in, from the order's record, or null when that record cannot be honoured.
     *
     * currency_rate is the rate that turns a stored amount into that currency. Every order recorded so far holds its amounts in its own
     * currency, so its rate is one, and that is the only rate a document is printed under: what any other rate means for a stored amount is an
     * open decision, and an order is not re-scaled by a guess. An unknown currency code is left alone for the same reason.
     */
    private function currencyRecordedOn(object|array|null $order): ?Currency
    {
        $code = data_get($order, 'currency_code');
        $rate = data_get($order, 'currency_rate');

        if (!is_string($code) || trim($code) === '' || !is_numeric($rate) || abs((float) $rate - 1.0) > 0.0) {
            return null;
        }

        return $this->currencyOf(strtoupper(trim($code)));
    }

    /** The currency row of a code, active or not, or null when the platform has no such currency. */
    private function currencyOf(string $currencyCode): ?Currency
    {
        $this->currenciesTableExists ??= Schema::hasTable('currencies');

        if (!$this->currenciesTableExists) {
            return null;
        }

        $this->currencyOfCode[$currencyCode] ??= Currency::query()->whereRaw('UPPER(code) = ?', [$currencyCode])->first() ?? false;

        return $this->currencyOfCode[$currencyCode] ?: null;
    }

    /**
     * Runs the callback with the money of one market in force, whatever the request is narrowed to: an admin screen that works on the rows of one
     * country, whose amounts are in that country's currency, reads and writes them as they are stored. An unknown country, a market that is the
     * default one, and a time while the switch is off change nothing. The market in force before is put back, even when the callback throws, and
     * with it what the money helpers keep in the session, so a switch of market is as invisible to a later reader as it is to the market itself.
     */
    public function within(?string $country, Closure $callback): mixed
    {
        $previous = $this->market;
        $this->market = $country === null || trim($country) === '' ? null : strtoupper(trim($country));
        $moneyMoves = $this->isEnabled() && $this->market !== $previous;
        $money = $moneyMoves ? session()->only(self::MONEY_KEPT_IN_THE_SESSION) : [];

        try {
            return $callback();
        } finally {
            $this->market = $previous;

            if ($moneyMoves) {
                session()->forget(self::MONEY_KEPT_IN_THE_SESSION);
                session()->put($money);
            }
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

    /**
     * The storefront other than the default one that the request, or the market a panel runs a callback in, is served from, whatever the state of its money: the
     * tax follows it even while the currency switch is off or the storefront has no active currency, because the platform's tax is the Saudi one and is never a
     * substitute for another country's. Null for the default storefront, for a request that serves none and while storefronts are off.
     */
    public function storefrontInForce(): ?string
    {
        $resolver = StorefrontResolver::fromConfig();
        $country = $this->market ?? StorefrontCatalog::country();

        if ($country === null || $country === $resolver->defaultCountry() || !$resolver->isEnabled() || !in_array($country, $resolver->configuredCountries(), true)) {
            return null;
        }

        return $country;
    }

    /**
     * The currency a purchase is made in: the storefront's own while it trades in its own currency, and otherwise the code the caller would
     * have used anyway — the visitor's pick, the field the client sent, or the platform's default, each exactly as that surface reads it today.
     *
     * The checkout page, the order that is written and the payment that is taken all ask this one question, so none of them can name a currency
     * another of them is not in: a pick a visitor made on one market cannot label an order of a market that trades in different money. While no
     * storefront trades in its own currency the answer is the caller's own value, unchanged, including when that value is nothing.
     */
    public function purchaseCurrencyCode(?string $picked): ?string
    {
        return $this->currencyCodeInForce() ?? $picked;
    }

    /** The symbol of that currency, or null while the platform's own settings stand. Read by getCurrencySymbol() so no surface prints another storefront's sign. */
    public function symbolInForce(): ?string
    {
        return $this->currencyInForce()?->symbol;
    }

    /**
     * The one answer to "what money is this amount, in the storefront serving this request": the number every surface shows, the currency it is
     * in, that currency's decimals, its symbol and the formatted text. The product page, the listing, the filter facets, the price filter, the
     * JSON-LD offer, the sitemap and the customer API all read this one method, so none of them can state a different number or a different
     * currency from another.
     *
     * It reports today's answer rather than inventing one: the number and the text come from the money helpers the storefront has always used,
     * which read the three currency settings through getWebConfig() and so through {@see override()}. While the switch is off, and on the default
     * storefront, every field is exactly what the page prints today. Nothing stored is re-scaled or re-rounded here.
     *
     * @return array{amount: float|string, currency: string, decimals: int, symbol: string, formatted: string}
     */
    public function money(string|int|float|null $amount = 0): array
    {
        loadCurrency();
        $currencyCode = getCurrencyCode(type: 'web');

        return [
            'amount' => webCurrencyConverterOnlyDigit(amount: $amount),
            'currency' => $currencyCode,
            'decimals' => $this->decimalsInForce(),
            'symbol' => (string) getCurrencySymbol(currencyCode: $currencyCode, type: 'web'),
            'formatted' => webCurrencyConverter(amount: $amount),
        ];
    }

    /**
     * The money block an API answer carries while the storefront serving it trades in its own currency: the currency, its symbol, the
     * decimals that currency is written with and where the symbol goes, and, when an amount is given, that amount and its text.
     *
     * Nothing at all otherwise, so a client of the platform as it stands reads byte for byte the answer it reads today, and a client
     * that does read this never has to work a storefront's currency out from a numeric setting.
     *
     * @return array{storefront_money?: array<string, mixed>}
     */
    public function apiMoney(string|int|float|null $amount = null): array
    {
        if ($this->currencyCodeInForce() === null) {
            return [];
        }

        $money = $this->money(amount: $amount ?? 0);

        return ['storefront_money' => [
            'currency' => $money['currency'],
            'symbol' => $money['symbol'],
            'decimals' => $money['decimals'],
            'symbol_position' => getWebConfig('currency_symbol_position') ?? 'right',
        ] + ($amount === null ? [] : ['amount' => $money['amount'], 'formatted' => $money['formatted']])];
    }

    /** How many decimals the money of this request is shown with: the currency's own while a storefront trades in it, the platform's setting otherwise. */
    public function decimalsInForce(): int
    {
        $decimals = getWebConfig('decimal_point_settings');

        return is_numeric($decimals) ? (int) $decimals : self::DEFAULT_DECIMALS;
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
        return $this->configuredDecimalsOf($currencyCode) ?? self::DEFAULT_DECIMALS;
    }

    /** The decimals the configuration names for a currency (ISO 4217 gives the dinar and the rial three), or null where it names none. */
    private function configuredDecimalsOf(string $currencyCode): ?int
    {
        $decimals = config('gcc.storefronts.currency.decimals')[strtoupper($currencyCode)] ?? null;

        return $decimals === null ? null : (int) $decimals;
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
        return $this->orderCurrency ?? $this->inForce()['currency'] ?? null;
    }

    /** @return array{country: string, currency: Currency}|null the storefront whose money is in force and its currency, or null while the platform's settings stand */
    private function inForce(): ?array
    {
        if (!(bool) config('gcc.storefronts.currency.enabled')) {
            return null;
        }

        $country = $this->storefrontInForce();

        if ($country === null) {
            return null;
        }

        $currency = $this->activeCurrency(StorefrontResolver::fromConfig()->currencyOf($country));

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
