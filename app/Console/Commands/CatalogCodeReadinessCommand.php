<?php

namespace App\Console\Commands;

use App\Services\Catalog\ProductCodeIndex;
use App\Services\Catalog\ProductCodeIndexBlocked;
use App\Services\Catalog\ProductCodeReadiness;
use App\Services\Catalog\ProductCodeScope;
use Illuminate\Console\Command;

class CatalogCodeReadinessCommand extends Command
{
    private const LIST_LIMIT = 50;

    /** The runbook order, in one sentence, so every refusal of --apply can name it. */
    public const RUNBOOK_ORDER = 'The order is: run this command until it reports Ready, then turn GCC_COUNTRY_SCOPED_CODES on, then run it again with --apply.';

    protected $signature = 'gcc:catalog:code-readiness {--apply : create the unique indexes the report describes, when the switch is on and the catalogue reports Ready}';

    protected $description = 'Report before the country-scoped SKU and barcode switch is turned on: products per country, codes held twice inside one country, codes shared by countries, and the sites that still read a code as one product. Read only unless --apply is given. Exits 1 when the catalogue is not ready';

    public function handle(ProductCodeReadiness $readiness, ProductCodeScope $scope): int
    {
        $report = $readiness->report();

        $this->line('Country-scoped codes: ' . ($report['flag'] ? 'on' : 'off') . ' (GCC_COUNTRY_SCOPED_CODES). Storefronts: ' . ($report['storefronts_enabled'] ? 'on' : 'off') . '.');

        if (!$report['column']) {
            $this->error('The products migration that adds products.storefront_country has not run: codes cannot be scoped to a country, and the switch acts as off.');
            $this->printSites($report['sites'], $report['survey']);

            return self::FAILURE;
        }

        $this->printCountries($report);
        $this->printEmptyCountry($report['empty_country']);
        $this->printHeldTwice($report['within_country']);
        $this->printShared($report['across_countries']);
        $this->printIndexes(indexes: $report['indexes'], switchIsOn: $scope->enabled());
        $this->printSites($report['sites'], $report['survey']);

        if ($this->option('apply') && !$this->applyIndexes(report: $report, scope: $scope)) {
            return self::FAILURE;
        }
        $this->line($report['ready'] ? 'Ready: nothing in the catalogue blocks the switch.' : 'Not ready: fix what is listed above, then run this again.');

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Creates the indexes the report has just described. It is how an installation that already ran the 2026_12_12_100000 migration while
     * the switch was off applies them: that migration is recorded and will not run again, and it is gated so that a catalogue holding a
     * duplicate cannot abort an upgrade.
     *
     * It refuses, before it writes anything, on each of the three things that make applying the index the wrong move:
     *
     * - **the switch is off.** `--apply` is the only path that applies the index on an installation that has already migrated, so without
     *   this it is the hole in the gate the migration carries: the schema would change under a feature that is still dormant. It asks
     *   `ProductCodeScope::enabled()`, the same answer the 2026_12_12_100000 migration gates `up()` on.
     * - **a product has an empty country.** The index would accept it — the rows of one empty country are compared only with each other —
     *   while a gate cannot tell which country the product belongs to, so the report calls the catalogue Not ready. Applying and then
     *   printing "Not ready" in the same run would change the structure and refuse at once.
     * - **a country holds one code twice.** Refused per kind, by `ProductCodeIndex::create()`, which is the invariant for every caller,
     *   this command and the migration alike, and whose message names how many values block it and where to list them.
     *
     * The second and third together are exactly the report's own `ready` verdict (`empty_country === 0 && within_country === []`), split
     * so that each refusal names its own cause and its own remedy. Nothing is applied while that verdict is Not ready, and no row is
     * changed to make an index fit in any of the three cases.
     *
     * @param array<string, mixed> $report
     */
    private function applyIndexes(array $report, ProductCodeScope $scope): bool
    {
        if (!$scope->enabled()) {
            $this->error(
                'Refusing to apply: country-scoped codes are off (' . ProductCodeScope::FLAG . '), and the index belongs to that feature. '
                . 'Applying it now would change the products table while the feature is dormant. ' . self::RUNBOOK_ORDER,
            );

            return false;
        }

        if ($report['empty_country'] > 0) {
            $this->error(sprintf(
                'Refusing to apply: %d product(s) have an empty country, so this run reports Not ready. Give them a country first. '
                . 'Nothing has been changed. %s',
                $report['empty_country'],
                self::RUNBOOK_ORDER,
            ));

            return false;
        }

        foreach (ProductCodeIndex::kinds() as $kind) {
            if (ProductCodeIndex::applied($kind)) {
                $this->line("  {$kind}: already applied.");

                continue;
            }

            try {
                ProductCodeIndex::create(kind: $kind);
            } catch (ProductCodeIndexBlocked $blocked) {
                $this->error($blocked->getMessage());

                return false;
            }

            $this->line("  {$kind}: applied.");
        }

        return true;
    }
    /** @param array<string, mixed> $report */
    private function printCountries(array $report): void
    {
        $this->line('Products per country:');
        foreach ($report['countries'] as $row) {
            $this->line(sprintf('  %-6s %8d', $row['country'], $row['products']));
        }
        foreach ($report['unconfigured_countries'] as $row) {
            $this->line(sprintf('  %-6s %8d  (not a configured country: no storefront shows these products)', $row['country'], $row['products']));
        }
    }

    private function printEmptyCountry(int $count): void
    {
        $this->line(match ($count) {
            0 => 'No product has an empty country.',
            1 => '1 product has an empty country: a gate cannot tell where it belongs and checks it against every country.',
            default => "{$count} products have an empty country: a gate cannot tell where they belong and checks them against every country.",
        });
    }

    /** @param list<array{kind: string, country: string, value: string, products: int}> $rows */
    private function printHeldTwice(array $rows): void
    {
        if ($rows === []) {
            $this->line('No code or barcode is held twice inside one country.');

            return;
        }

        $this->line('Held twice inside one country (the switch refuses these, and every lookup meets an ambiguity):');
        $this->printLimited(array_map(static fn (array $row): string => sprintf('  %-8s %-4s %s (%d products)', $row['kind'], $row['country'], $row['value'], $row['products']), $rows));
    }

    /** @param list<array{kind: string, value: string, countries: list<string>}> $rows */
    private function printShared(array $rows): void
    {
        if ($rows === []) {
            $this->line('No code or barcode is shared by two countries.');

            return;
        }

        $this->line('Shared by more than one country (expected none before the switch is on; it is what the switch allows):');
        $this->printLimited(array_map(static fn (array $row): string => sprintf('  %-8s %s (%s)', $row['kind'], $row['value'], implode(', ', $row['countries'])), $rows));
    }

    /**
     * Whether the database itself could refuse a duplicate. The index leaves out the rows that have no value, so those never block it;
     * what blocks it is one country holding one real value twice, and no row is ever changed here to make one fit.
     *
     * The line only invites `--apply` while the switch is on, because `--apply` refuses while it is off: a report that asked for it
     * anyway would be sending an operator to a refusal, and before this gate existed it sent them to a schema change under a dormant
     * feature. With the switch off the line names the next step of the runbook instead.
     *
     * @param list<array{kind: string, name: string, applied: bool, blocking_groups: int, absent_values: int}> $indexes
     */
    private function printIndexes(array $indexes, bool $switchIsOn): void
    {
        $this->line('The unique index the database would refuse duplicates with:');
        foreach ($indexes as $index) {
            $this->line(sprintf(
                '  %-8s %-34s %s (%d row(s) have no %s and are outside the index)',
                $index['kind'],
                $index['name'],
                $index['applied']
                    ? 'applied'
                    : ($index['blocking_groups'] === 0
                        ? 'not applied; the data would accept it ' . ($switchIsOn
                            ? '(run this command again with --apply)'
                            : '(the switch is off, so --apply refuses: turn ' . ProductCodeScope::FLAG . ' on first)')
                        : 'not applied; ' . $index['blocking_groups'] . ' value(s) held twice inside one country block it — an owner decision, listed above'),
                $index['absent_values'],
                $index['kind'],
            ));
        }
    }

    /**
     * @param list<array{id: string, where: string, status: string, note: string}> $sites
     * @param array{covered: int, labelled: int, id_keyed: int, open: int} $survey
     */
    private function printSites(array $sites, array $survey): void
    {
        $this->line(sprintf(
            'Survey of %d lookup sites: %d covered, %d labelled (a picker names the country), %d not needed (id-keyed), %d still open.',
            array_sum($survey),
            $survey['covered'],
            $survey['labelled'],
            $survey['id_keyed'],
            $survey['open'],
        ));
        if ($sites === []) {
            $this->line('Every site of the survey is covered, labelled or id-keyed: none still reads a code as one product.');

            return;
        }
        $this->line(count($sites) . ' site(s) still read a code as one product, wholly or in part, or wait for the owner:');
        foreach ($sites as $site) {
            $this->line(sprintf('  %-4s %-12s %s: %s', $site['id'], str_replace('_', ' ', $site['status']), $site['where'], $site['note']));
        }
    }

    /** @param list<string> $lines */
    private function printLimited(array $lines): void
    {
        foreach (array_slice($lines, 0, self::LIST_LIMIT) as $line) {
            $this->line($line);
        }
        if (count($lines) > self::LIST_LIMIT) {
            $this->line('  and ' . (count($lines) - self::LIST_LIMIT) . ' more');
        }
    }
}
