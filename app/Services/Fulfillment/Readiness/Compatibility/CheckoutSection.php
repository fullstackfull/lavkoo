<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use App\Services\Fulfillment\Support\CurrencyCode;
use App\Services\Fulfillment\Support\ExchangeRate;
use Illuminate\Database\Query\Builder;

/**
 * What checkout and the amounts it stores depend on beyond the order tables: the currency configuration that decides
 * whether a provider can be told a cash-on-delivery figure at all, and who is able to open the Integration Center.
 *
 * Only three `business_settings` rows are ever read (see READABLE_SETTINGS): the table also holds payment-gateway and mail
 * credentials, and the compatibility check must never come near them.
 */
final class CheckoutSection implements CompatibilitySection
{
    private const SECTION = 'checkout';

    /** The only `business_settings` rows this section reads: none of them is a secret. */
    public const READABLE_SETTINGS = ['currency_model', 'system_default_currency', 'decimal_point_settings'];

    /** The decimal places of every amount a provider is sent (ProviderCurrency rounds to the halala). */
    private const PROVIDER_DECIMALS = 2;

    private const MODEL_SINGLE = 'single_currency';
    private const MODEL_MULTI = 'multi_currency';

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        return array_values(array_filter([
            $probe->hasTable('business_settings') && $probe->hasTable('currencies') ? $this->currency($probe) : null,
            $probe->hasTable('currencies') ? $this->exchangeRates($probe) : null,
            $probe->hasTable('admins') && $probe->hasTable('admin_roles') ? $this->adminAccess($probe) : null,
        ]));
    }

    private function currency(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $settings = $this->settings($probe);
        $model = (string) ($settings['currency_model'] ?? self::MODEL_SINGLE);
        $target = CurrencyCode::provider();
        $decimals = is_numeric($settings['decimal_point_settings'] ?? null) ? (int) $settings['decimal_point_settings'] : 2;

        [$convertible, $explanation] = $model === self::MODEL_MULTI
            ? $this->multiCurrency($probe, $target)
            : $this->singleCurrency($probe, $settings['system_default_currency'] ?? null, $target);
        $roundsLikeProviders = $decimals === self::PROVIDER_DECIMALS;

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'currency conversion',
            status: $convertible && $roundsLikeProviders ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            detail: $roundsLikeProviders ? $explanation : $explanation . ". The store rounds the amounts customers see to {$decimals} decimal place(s) but providers are sent amounts rounded to " . self::PROVIDER_DECIMALS . ': a cash-on-delivery figure can differ from the one the customer saw',
            facts: ['currency_model' => $this->printable($model), 'provider_currency' => $target, 'convertible' => $convertible, 'decimals' => $decimals],
        );
    }

    /** @return array{0: bool, 1: string} */
    private function multiCurrency(ReadOnlyProbe $probe, string $target): array
    {
        $usd = $this->rate($probe, 'USD');
        $provider = $this->rate($probe, $target);

        return $usd !== null && $provider !== null
            ? [true, "multi-currency store: amounts are stored in a USD-based unit and converted to {$target} with the currency table's rates, as the storefront does"]
            : [false, "multi-currency store, but the currency table has no usable rate (a plain positive number) for USD or {$target}: every provider shipment would be held as 'currency unconvertible' instead of being sent with a guessed figure"];
    }

    /** @return array{0: bool, 1: string} */
    private function singleCurrency(ReadOnlyProbe $probe, mixed $defaultCurrencyId, string $target): array
    {
        $code = is_numeric($defaultCurrencyId) ? $this->defaultCode($probe, (int) $defaultCurrencyId) : null;
        $printable = $code !== null && preg_match('/^[A-Za-z]{2,10}$/D', $code) === 1 ? strtoupper($code) : null;

        if (CurrencyCode::canonical($code) === $target) {
            return [true, $printable !== null && $printable !== $target
                ? "single-currency store whose default currency is stored as {$printable}, the store's spelling of {$target} (config fulfillment.currency_aliases): stored amounts are sent to providers as they are, labelled {$target}"
                : "single-currency store whose default currency is {$target}: stored amounts are sent to providers as they are"];
        }

        return [false, 'single-currency store whose default currency is ' . ($printable ?? 'not known') . ", not {$target}: every provider shipment would be held as 'currency unconvertible'. Add the store's code to config fulfillment.currency_aliases ONLY if it is the same money as {$target} under another code (check the currency's name and symbol in the admin panel): an alias for a different currency would send its amounts to providers as {$target}. Otherwise keep {$target} as the default currency"];
    }

    /**
     * The currency table keeps its rates as text, typed by hand. A rate is usable only as a plain positive decimal; one that is not is
     * treated as no rate at all (an amount that needs it is held, never converted with a guess). Counted, with the currency code of each
     * offender when it is a plain code, and never the value.
     */
    private function exchangeRates(ReadOnlyProbe $probe): ?CompatibilityCheck
    {
        $columns = $probe->columns('currencies');
        if (!isset($columns['exchange_rate'], $columns['code'])) {
            return null;
        }

        $total = $probe->count('currencies');
        $unusable = [];
        $probe->eachRecent(
            table: 'currencies',
            columns: ['code', 'exchange_rate'],
            limit: 500,
            each: static function (object $row) use (&$unusable): void {
                if (ExchangeRate::parse($row->exchange_rate) === null) {
                    $unusable[] = preg_match('/^[A-Za-z]{2,10}$/D', (string) $row->code) === 1 ? strtoupper((string) $row->code) : '(a code that is not plain letters)';
                }
            },
        );

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'exchange rates',
            status: $unusable === [] ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            detail: $unusable === []
                ? "all {$total} currency rates are plain positive numbers"
                : count($unusable) . " of {$total} currency rates are not a plain positive number (" . implode(', ', array_slice($unusable, 0, 20)) . '): in a multi-currency store an amount that needs one is held as currency unconvertible; a single-currency store never reads them',
            facts: ['currencies' => $total, 'unusable_rates' => count($unusable)],
        );
    }

    private function adminAccess(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $master = $probe->count('admins', static fn (Builder $query) => $query->where('admin_role_id', 1));
        $granted = $probe->count('admins', static fn (Builder $query) => $query
            ->join('admin_roles', 'admin_roles.id', '=', 'admins.admin_role_id')
            ->where('admin_roles.status', 1)
            ->where('admin_roles.module_access', 'like', '%"3rd_party_setup"%'));

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'who can open the Integration Center',
            status: $master + $granted > 0 ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            detail: $master + $granted > 0
                ? "{$master} master admin(s) and {$granted} admin(s) with the 3rd_party_setup permission can open it"
                : 'no admin is a master admin or holds the 3rd_party_setup permission: nobody could open the Integration Center',
            facts: ['master_admins' => $master, 'admins_with_3rd_party_setup' => $granted],
        );
    }

    /** @return array<string, mixed> setting => decoded value, for the allow-listed settings only; a setting stored twice counts once, as its first (oldest) row, as in the platform */
    private function settings(ReadOnlyProbe $probe): array
    {
        $settings = [];
        $probe->eachRecent(
            table: 'business_settings',
            columns: ['type', 'value'],
            limit: count(self::READABLE_SETTINGS) * 4,
            each: static function (object $row) use (&$settings): void {
                $settings[$row->type] = JsonShape::decode($row->value)['value'] ?? $row->value;
            },
            where: static fn (Builder $query) => $query->whereIn('type', self::READABLE_SETTINGS),
        );

        return $settings;
    }

    /** The usable rate of a currency, under the ISO code or else the store's spelling of it; null when there is none. */
    private function rate(ReadOnlyProbe $probe, string $code): ?float
    {
        $spellings = CurrencyCode::spellings($code);
        $found = [];
        $probe->eachRecent(
            table: 'currencies',
            columns: ['code', 'exchange_rate'],
            limit: 20,
            each: static function (object $row) use (&$found): void {
                // Newest first, so the last row seen is the first one created: the one the platform and the runtime use.
                $found[strtoupper(trim((string) $row->code))] = ExchangeRate::parse($row->exchange_rate);
            },
            where: static fn (Builder $query) => $query->whereIn('code', $spellings),
        );

        foreach ($spellings as $spelling) {
            if (array_key_exists($spelling, $found)) {
                return $found[$spelling];
            }
        }

        return null;
    }

    private function defaultCode(ReadOnlyProbe $probe, int $id): ?string
    {
        $code = null;
        $probe->eachRecent(
            table: 'currencies',
            columns: ['code'],
            limit: 1,
            each: static function (object $row) use (&$code): void {
                $code = (string) $row->code;
            },
            where: static fn (Builder $query) => $query->where('id', $id),
        );

        return $code;
    }

    private function printable(string $value): string
    {
        return Enumeration::isCodeWord($value) ? $value : '(not a code word)';
    }
}
