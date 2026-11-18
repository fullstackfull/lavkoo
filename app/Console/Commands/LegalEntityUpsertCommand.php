<?php

namespace App\Console\Commands;

use App\Models\LegalEntity;
use App\Services\Storefront\LegalEntityRegistry;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Command;

/**
 * Registers or changes the company that sells in one storefront country. It writes the registry only: an order that was already stamped keeps the frozen
 * copy it carries, and nothing is stamped until legal entities are switched on. The facts (the legal name, the tax and commercial registration, the
 * invoice series) come from the owner and counsel; this command takes them as they are given and invents none.
 */
class LegalEntityUpsertCommand extends Command
{
    protected $signature = 'gcc:legal-entity:upsert
        {country : the storefront country the company sells in (one of the configured countries, for example KW)}
        {--legal-name= : the registered name of the company (required when the country has none yet)}
        {--trade-name= : the name it trades under}
        {--tax-number= : the tax (VAT) registration number}
        {--registration-number= : the commercial registration number}
        {--invoice-series= : the key of its invoice series: up to 16 letters, digits or dashes}
        {--address= : the registered address}
        {--activate : make the company active, so that new orders of the country are stamped with it}
        {--deactivate : make the company inactive: new orders of the country carry none, the orders already stamped keep theirs}';

    protected $description = 'Register or change the company (legal entity) that sells in one storefront country';

    private const TEXT_OPTIONS = [
        'legal-name' => ['column' => 'legal_name', 'limit' => 191],
        'trade-name' => ['column' => 'trade_name', 'limit' => 191],
        'tax-number' => ['column' => 'tax_registration_number', 'limit' => 64],
        'registration-number' => ['column' => 'commercial_registration_number', 'limit' => 64],
        'invoice-series' => ['column' => 'invoice_series_key', 'limit' => 16],
        'address' => ['column' => 'address', 'limit' => 1000],
    ];

    public function handle(LegalEntityRegistry $registry): int
    {
        $country = strtoupper(trim((string) $this->argument('country')));

        $error = $this->refusal(registry: $registry, country: $country);
        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $values = $this->givenValues();
        $existing = LegalEntity::query()->where('country', $country)->first();

        if ($existing === null && !isset($values['legal_name'])) {
            $this->error("{$country} has no company yet: --legal-name is required.");

            return self::FAILURE;
        }

        $entity = $existing ?? new LegalEntity(['country' => $country, 'status' => true]);
        $entity->fill($values);
        if ($this->option('activate') || $this->option('deactivate')) {
            $entity->status = (bool) $this->option('activate');
        }
        $entity->save();
        $registry->flush();

        $this->line(sprintf('%s %s: %s (%s).', $existing === null ? 'Registered' : 'Updated', $country, $entity->legal_name, $entity->status ? 'active' : 'inactive'));

        return self::SUCCESS;
    }

    private function refusal(LegalEntityRegistry $registry, string $country): ?string
    {
        return match (true) {
            !in_array($country, StorefrontResolver::fromConfig()->configuredCountries(), true) => "{$country} is not one of the configured storefront countries (" . implode(', ', StorefrontResolver::fromConfig()->configuredCountries()) . ').',
            !$registry->isMigrated() => 'The legal_entities table does not exist yet: run the migrations first.',
            (bool) $this->option('activate') && (bool) $this->option('deactivate') => '--activate and --deactivate cannot be given together.',
            default => $this->invalidOption(),
        };
    }

    /** The first option that is empty or longer than its column, or null. */
    private function invalidOption(): ?string
    {
        foreach (self::TEXT_OPTIONS as $name => $rule) {
            $value = $this->option($name);

            if ($value === null) {
                continue;
            }
            if (trim((string) $value) === '' && $name === 'legal-name') {
                return '--legal-name cannot be empty.';
            }
            if (mb_strlen((string) $value) > $rule['limit']) {
                return "--{$name} is longer than {$rule['limit']} characters.";
            }
        }

        $series = $this->option('invoice-series');
        if ($series !== null && $series !== '' && preg_match('/^[A-Za-z0-9-]{1,16}$/', (string) $series) !== 1) {
            return '--invoice-series takes letters, digits and dashes only.';
        }

        return null;
    }

    /** @return array<string, string|null> the columns the operator gave, an empty value clearing an optional one */
    private function givenValues(): array
    {
        $values = [];

        foreach (self::TEXT_OPTIONS as $name => $rule) {
            $value = $this->option($name);

            if ($value !== null) {
                $values[$rule['column']] = trim((string) $value) === '' ? null : trim((string) $value);
            }
        }

        return $values;
    }
}
