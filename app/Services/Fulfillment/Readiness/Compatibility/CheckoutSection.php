<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

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

    private const MODEL_SINGLE = 'single_currency';
    private const MODEL_MULTI = 'multi_currency';

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        return array_values(array_filter([
            $probe->hasTable('business_settings') && $probe->hasTable('currencies') ? $this->currency($probe) : null,
            $probe->hasTable('admins') && $probe->hasTable('admin_roles') ? $this->adminAccess($probe) : null,
        ]));
    }

    private function currency(ReadOnlyProbe $probe): CompatibilityCheck
    {
        $settings = $this->settings($probe);
        $model = (string) ($settings['currency_model'] ?? self::MODEL_SINGLE);
        $target = strtoupper((string) config('fulfillment.currency', 'SAR'));
        $decimals = is_numeric($settings['decimal_point_settings'] ?? null) ? (int) $settings['decimal_point_settings'] : 2;

        [$convertible, $explanation] = $model === self::MODEL_MULTI
            ? $this->multiCurrency($probe, $target)
            : $this->singleCurrency($probe, $settings['system_default_currency'] ?? null, $target);

        return new CompatibilityCheck(
            section: self::SECTION,
            name: 'currency conversion',
            status: $convertible ? CompatibilityCheck::PASS : CompatibilityCheck::WARN,
            detail: $explanation,
            facts: ['currency_model' => $this->printable($model), 'provider_currency' => $target, 'convertible' => $convertible, 'decimals' => $decimals],
        );
    }

    /** @return array{0: bool, 1: string} */
    private function multiCurrency(ReadOnlyProbe $probe, string $target): array
    {
        $usd = $this->rate($probe, 'USD');
        $provider = $this->rate($probe, $target);

        return $usd > 0 && $provider > 0
            ? [true, "multi-currency store: amounts are stored in a USD-based unit and converted to {$target} with the currency table's rates, as the storefront does"]
            : [false, "multi-currency store, but the currency table has no usable rate for USD or {$target}: every provider shipment would be held as 'currency unconvertible' instead of being sent with a guessed figure"];
    }

    /** @return array{0: bool, 1: string} */
    private function singleCurrency(ReadOnlyProbe $probe, mixed $defaultCurrencyId, string $target): array
    {
        $code = is_numeric($defaultCurrencyId) ? $this->defaultCode($probe, (int) $defaultCurrencyId) : null;

        return $code !== null && strtoupper($code) === $target
            ? [true, "single-currency store whose default currency is {$target}: stored amounts are sent to providers as they are"]
            : [false, "single-currency store whose default currency is " . ($code !== null && preg_match('/^[A-Za-z]{2,10}$/', $code) === 1 ? strtoupper($code) : 'not known') . ", not {$target}: every provider shipment would be held as 'currency unconvertible'. Set FULFILLMENT_CURRENCY to the store's currency if providers accept it, or keep SAR as the default"];
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

    /** @return array<string, mixed> setting => decoded value, for the allow-listed settings only */
    private function settings(ReadOnlyProbe $probe): array
    {
        $settings = [];
        $probe->eachRecent(
            table: 'business_settings',
            columns: ['type', 'value'],
            limit: count(self::READABLE_SETTINGS) * 4,
            each: static function (object $row) use (&$settings): void {
                $settings[$row->type] ??= JsonShape::decode($row->value)['value'] ?? $row->value;
            },
            where: static fn (Builder $query) => $query->whereIn('type', self::READABLE_SETTINGS),
        );

        return $settings;
    }

    private function rate(ReadOnlyProbe $probe, string $code): float
    {
        $rate = 0.0;
        $probe->eachRecent(
            table: 'currencies',
            columns: ['exchange_rate'],
            limit: 1,
            each: static function (object $row) use (&$rate): void {
                $rate = (float) $row->exchange_rate;
            },
            where: static fn (Builder $query) => $query->where('code', $code),
        );

        return $rate;
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
