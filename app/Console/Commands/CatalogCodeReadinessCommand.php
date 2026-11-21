<?php

namespace App\Console\Commands;

use App\Services\Catalog\ProductCodeReadiness;
use Illuminate\Console\Command;

class CatalogCodeReadinessCommand extends Command
{
    private const LIST_LIMIT = 50;

    protected $signature = 'gcc:catalog:code-readiness';

    protected $description = 'Read-only report before the country-scoped SKU and barcode switch is turned on: products per country, codes held twice inside one country, codes shared by countries, and the sites that still read a code as one product. Exits 1 when the catalogue is not ready';

    public function handle(ProductCodeReadiness $readiness): int
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
        $this->printIndexes($report['indexes']);
        $this->printSites($report['sites'], $report['survey']);
        $this->line($report['ready'] ? 'Ready: nothing in the catalogue blocks the switch.' : 'Not ready: fix what is listed above, then run this again.');

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
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
     * @param list<array{kind: string, name: string, applied: bool, blocking_groups: int, absent_values: int}> $indexes
     */
    private function printIndexes(array $indexes): void
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
                        ? 'not applied; the data would accept it (run the 2026_12_12_100000 migration)'
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
