<?php

namespace App\Console\Commands;

use App\Services\Storefront\StorefrontResolver;
use App\Services\Storefront\StorefrontTax;
use App\Services\Storefront\StorefrontTaxApproval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\TaxModule\app\Models\SystemTaxSetup;
use Modules\TaxModule\app\Models\Tax;

class StorefrontTaxCommand extends Command
{
    private const TYPES = ['order_wise', 'product_wise'];

    private const MAXIMUM_RATE = 100;

    protected $signature = 'gcc:storefront:tax
        {country : the storefront, for example AE}
        {--rate=* : a tax of the storefront as name:percent, for example VAT:5; repeat it for each tax}
        {--no-tax : the storefront charges no tax: an order-wise setup with no rate}
        {--type=order_wise : order_wise (the rates given here apply to the whole order) or product_wise (the rates are set on each product)}
        {--included : the prices of the storefront already include the tax}
        {--unset : switch the storefront\'s own setup off, so that its orders carry no tax}';

    protected $description = 'Gives a storefront the tax setup its orders are taxed with. A storefront with none is taxed with nothing and never with the platform\'s Saudi setup, and it cannot sell until its tax is approved (gcc:storefront:tax-approve). The rates are the owner\'s to give for each country; this only records them. Works while the switches are off, and then nothing reads the setup yet';

    public function handle(StorefrontTax $tax, StorefrontTaxApproval $approval): int
    {
        $country = strtoupper(trim((string) $this->argument('country')));
        $rates = $this->parsedRates();
        $type = (string) $this->option('type');

        $refusal = $this->refusal(country: $country, type: $type, rates: $rates, tax: $tax);

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if ($this->option('unset')) {
            $removed = $this->switchOff(country: $country);
            $this->info($removed ? "{$country}: its own tax setup is switched off: its orders carry no tax now, and it sells only on a recorded decision that it charges none (gcc:storefront:tax-approve). The orders already placed keep the rows they were placed with." : "{$country} has no tax setup of its own: nothing to switch off.");
            $this->approvalLine(approval: $approval, country: $country);

            return self::SUCCESS;
        }

        $setup = $this->write(country: $country, type: $type, rates: $rates ?? []);

        $this->info(sprintf('%s: its own tax setup is %s, %s%s.', $country, str_replace('_', ' ', $type), $this->option('included') ? 'tax included in the prices' : 'tax added to the prices', $this->describedRates(type: $type, rates: $rates ?? [])));
        $this->line(StorefrontResolver::fromConfig()->isEnabled()
            ? "The storefront reads this setup now: its orders are taxed with it (setup {$setup->id})."
            : 'The storefronts are switched off, so nothing reads this setup yet.');
        $this->approvalLine(approval: $approval, country: $country);

        return self::SUCCESS;
    }

    /** What the storefront's tax approval says once its tax has changed: a storefront whose tax is not approved cannot sell. */
    private function approvalLine(StorefrontTaxApproval $approval, string $country): void
    {
        $status = $approval->statusOf(country: $country)['status'];

        $this->line(match ($status) {
            StorefrontTaxApproval::APPROVED => "{$country}: the tax approval on record still describes it.",
            StorefrontTaxApproval::STALE => "{$country}: the tax approval on record no longer describes what it is taxed with, so it cannot sell until the tax is approved again (gcc:storefront:tax-approve {$country}).",
            default => "{$country}: it cannot sell until its tax is approved (gcc:storefront:tax-approve {$country} --decision=setup, or --decision=no_tax for a documented decision that it charges none).",
        });
    }

    /** @return list<array{name: string, rate: string}>|null null when the option was not used at all */
    private function parsedRates(): ?array
    {
        $given = (array) $this->option('rate');

        if ($given === []) {
            return null;
        }

        return array_map(static function (mixed $text): array {
            $text = (string) $text;
            $colon = strrpos($text, ':');

            return $colon === false ? ['name' => '', 'rate' => ''] : ['name' => trim(substr($text, 0, $colon)), 'rate' => trim(substr($text, $colon + 1))];
        }, $given);
    }

    /** @param list<array{name: string, rate: string}>|null $rates */
    private function refusal(string $country, string $type, ?array $rates, StorefrontTax $tax): ?string
    {
        $resolver = StorefrontResolver::fromConfig();
        $given = $rates !== null || $this->option('no-tax') || $type === 'product_wise';

        return match (true) {
            $country === $resolver->defaultCountry() => "{$country} is the default storefront: its tax is the platform's, set in the admin panel.",
            !in_array($country, $resolver->configuredCountries(), true) => "{$country} is not a configured storefront.",
            !in_array($type, self::TYPES, true) => 'The type must be ' . implode(' or ', self::TYPES) . '.',
            !$tax->tracksSetups() => 'The tax module has no system_tax_setups table.',
            (bool) $this->option('unset') && ($rates !== null || $this->option('no-tax') || $this->option('included')) => 'Give the options of a setup or --unset, not both.',
            (bool) $this->option('unset') => null,
            !$given => 'Give --rate name:percent for each tax, --no-tax, or --type=product_wise. Without one of them nothing would say what this storefront is taxed with.',
            $rates !== null && $this->option('no-tax') => 'Give --rate or --no-tax, not both.',
            $type === 'product_wise' && ($rates !== null || $this->option('no-tax')) => 'A product-wise setup has its rates on each product: give no --rate and no --no-tax with it.',
            default => $this->rateRefusal(rates: $rates ?? []),
        };
    }

    /** @param list<array{name: string, rate: string}> $rates */
    private function rateRefusal(array $rates): ?string
    {
        $seen = [];

        foreach ($rates as $rate) {
            if ($rate['name'] === '' || mb_strlen($rate['name']) > 191) {
                return 'Each --rate is name:percent, with a name of at most 191 characters, for example VAT:5.';
            }

            if (preg_match('/^\d{1,3}(\.\d{1,3})?$/', $rate['rate']) !== 1 || (float) $rate['rate'] > self::MAXIMUM_RATE) {
                return "The percent of {$rate['name']} must be a plain number from 0 to " . self::MAXIMUM_RATE . ' with at most three decimals.';
            }

            $key = mb_strtolower($rate['name']);

            if (isset($seen[$key])) {
                return "{$rate['name']} is given twice.";
            }

            $seen[$key] = true;
        }

        return null;
    }

    /**
     * Writes the storefront's setup in one transaction: the taxes it names (an active default tax of the same name and rate is reused, never duplicated) and the setup
     * row, changed in place when the storefront has one so that the orders placed under it keep naming it. The tax caches are dropped by the models' own events.
     *
     * @param list<array{name: string, rate: string}> $rates
     */
    private function write(string $country, string $type, array $rates): SystemTaxSetup
    {
        return DB::transaction(function () use ($country, $type, $rates): SystemTaxSetup {
            $taxIds = array_map(fn (array $rate): int => $this->taxOf(name: $rate['name'], rate: $rate['rate'])->id, $type === 'order_wise' ? $rates : []);
            $setup = $this->ownSetup(country: $country) ?? new SystemTaxSetup(['country_code' => $country, 'tax_payer' => StorefrontTax::PAYER]);
            $setup->fill([
                'tax_type' => $type,
                'tax_ids' => $taxIds,
                'is_included' => (bool) $this->option('included') ? 1 : 0,
                'is_default' => 1,
                'is_active' => 1,
            ])->save();

            return $setup;
        });
    }

    private function switchOff(string $country): bool
    {
        return DB::transaction(function () use ($country): bool {
            $setup = $this->ownSetup(country: $country);

            if ($setup === null || !$setup->is_active) {
                return false;
            }

            $setup->update(['is_active' => 0]);

            return true;
        });
    }

    /** The row the storefront's orders are taxed with if it has one, else its latest row. */
    private function ownSetup(string $country): ?SystemTaxSetup
    {
        return SystemTaxSetup::query()
            ->where('country_code', $country)
            ->where('tax_payer', StorefrontTax::PAYER)
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function taxOf(string $name, string $rate): Tax
    {
        $existing = Tax::query()->where('name', $name)->where('is_active', 1)->where('is_default', 1)->get()
            ->first(static fn (Tax $tax): bool => abs((float) $tax->tax_rate - (float) $rate) < 0.0005);

        return $existing ?? Tax::create(['name' => $name, 'tax_rate' => (float) $rate, 'is_default' => 1, 'is_active' => 1]);
    }

    /** @param list<array{name: string, rate: string}> $rates */
    private function describedRates(string $type, array $rates): string
    {
        if ($type === 'product_wise') {
            return ': the rates are set on each product';
        }

        return $rates === []
            ? ': no rate, so no tax is charged'
            : ': ' . implode(', ', array_map(static fn (array $rate): string => "{$rate['name']} {$rate['rate']}%", $rates));
    }
}
