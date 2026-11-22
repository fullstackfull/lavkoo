<?php

namespace App\Console\Commands;

use App\Models\BusinessSetting;
use App\Services\Storefront\StorefrontCurrency;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorefrontMoneyCommand extends Command
{
    protected $signature = 'gcc:storefront:money
        {country : the storefront, for example KW}
        {setting : minimum_order_amount or free_delivery_over_amount}
        {amount? : the amount in the currency of that storefront, as its customers read it, for example 5.000 for five dinars}
        {--unset : remove the amount, so that the storefront has no minimum (or no free delivery) again}';

    protected $description = 'Gives a storefront that trades in its own currency its minimum order or its free-delivery threshold, in that currency. The platform\'s own amounts are typed in the platform\'s money and are never read by such a storefront. Works while the switches are off, and then nothing reads the value yet';

    public function handle(StorefrontCurrency $storefrontCurrency): int
    {
        $country = strtoupper(trim((string) $this->argument('country')));
        $setting = (string) $this->argument('setting');
        $amount = $this->argument('amount');
        $resolver = StorefrontResolver::fromConfig();
        $currencyCode = in_array($country, $resolver->configuredCountries(), true) ? $resolver->currencyOf($country) : '';
        $normalised = is_string($amount) ? $this->normalised(amount: trim($amount), decimals: $storefrontCurrency->decimalsOf($currencyCode)) : null;

        $refusal = $this->refusal(
            country: $country,
            setting: $setting,
            amountGiven: $amount !== null,
            amountValid: $normalised !== null,
            decimals: $storefrontCurrency->decimalsOf($currencyCode),
            currencyCode: $currencyCode,
            defaultCountry: $resolver->defaultCountry(),
        );

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        [$before, $after] = $this->write(country: $country, setting: $setting, currencyCode: $currencyCode, amount: $normalised);

        $this->info(sprintf('%s %s: %s -> %s (%s).', $country, $setting, $before ?? 'not set', $after ?? 'not set', $currencyCode));
        $this->line($storefrontCurrency->isEnabled()
            ? 'The storefront reads this value now: it is the amount in its own currency, with no conversion.'
            : 'The storefront currency switches are off, so nothing reads this value yet.');

        return self::SUCCESS;
    }

    private function refusal(string $country, string $setting, bool $amountGiven, bool $amountValid, int $decimals, string $currencyCode, string $defaultCountry): ?string
    {
        return match (true) {
            $country === $defaultCountry => "{$country} is the default storefront: its amounts are the platform's, set in the admin panel.",
            $currencyCode === '' => "{$country} is not a configured storefront.",
            in_array($setting, StorefrontCurrency::AMOUNT_SETTINGS, true) && !in_array($setting, StorefrontCurrency::STOREFRONT_AMOUNTS, true) => "{$setting} is not set per storefront: the wallet limits belong to the per-market wallets (GCC-15), and the seller threshold is the Saudi sellers'.",
            !in_array($setting, StorefrontCurrency::STOREFRONT_AMOUNTS, true) => "{$setting} is not an amount a storefront can be given. Use " . implode(' or ', StorefrontCurrency::STOREFRONT_AMOUNTS) . '.',
            $this->option('unset') && $amountGiven => 'Give an amount or --unset, not both.',
            !$this->option('unset') && !$amountGiven => 'Give the amount in ' . $currencyCode . ', or --unset to remove it.',
            $amountGiven && !$amountValid => "The amount must be a plain number that is not negative, with at most {$decimals} decimals ({$currencyCode}).",
            !Schema::hasTable('business_settings') => 'There is no business_settings table.',
            default => null,
        };
    }

    /** The amount with exactly the decimals of the currency, or null when it is not a plain non-negative number that fits them. */
    private function normalised(string $amount, int $decimals): ?string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $parts) !== 1) {
            return null;
        }

        $fraction = $parts[2] ?? '';

        if (strlen($fraction) > $decimals) {
            return null;
        }

        $whole = ltrim($parts[1], '0');
        $whole = $whole === '' ? '0' : $whole;

        return $decimals > 0 ? $whole . '.' . str_pad($fraction, $decimals, '0') : $whole;
    }

    /**
     * Changes one amount of the storefront's row inside a transaction that locks it, writes the row back tagged with the storefront's currency
     * (or deletes it when nothing is left in it), and drops the settings cache. A row that is tagged with another currency is started over.
     *
     * @return array{0: ?string, 1: ?string} the amount before and after
     */
    private function write(string $country, string $setting, string $currencyCode, ?string $amount): array
    {
        $name = StorefrontCurrency::amountRowName($country);

        $result = DB::transaction(function () use ($name, $setting, $currencyCode, $amount): array {
            $row = BusinessSetting::query()->where('type', $name)->lockForUpdate()->first();
            $stored = json_decode((string) $row?->value, true);
            $stored = is_array($stored) && strcasecmp((string) ($stored['currency'] ?? ''), $currencyCode) === 0 ? $stored : [];
            $before = isset($stored[$setting]) ? (string) $stored[$setting] : null;

            if ($amount === null) {
                unset($stored[$setting]);
            } else {
                $stored[$setting] = $amount;
            }

            $amounts = array_intersect_key($stored, array_flip(StorefrontCurrency::STOREFRONT_AMOUNTS));

            if ($amounts === []) {
                BusinessSetting::query()->where('type', $name)->delete();
            } else {
                BusinessSetting::query()->updateOrInsert(['type' => $name], ['value' => json_encode(['currency' => strtoupper($currencyCode)] + $amounts), 'updated_at' => now()]);
            }

            return [$before, $amount];
        });

        clearWebConfigCacheKeys();

        return $result;
    }
}
