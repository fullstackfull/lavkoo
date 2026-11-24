<?php

namespace App\Console\Commands;

use App\Services\Storefront\LegacyModeSafety;
use App\Services\Storefront\StorefrontResolver;
use Illuminate\Console\Command;

/**
 * Whether the platform is in a state it must not be operated in (A1-02), for an operator and for a deploy to ask before it acts.
 *
 * There is one such state, and it is the owner's decision of 2026-11-24 (`docs/gcc/DECISIONS.md`) that makes it one: the master storefront
 * switch is off while another market already holds rows. Nothing narrows the catalogue while that switch is off, so those rows are served as
 * the default storefront's own. The switch is therefore not a way to close a market once a second one is live; the country's own settings and
 * its lifecycle are.
 *
 * Read-only, and it exits 1 while the platform is in that state, so a deploy step can gate on it. It exits 1 as well when the question
 * cannot be answered -- a table that cannot be read is not an all-clear -- and says which table and why, because the two call for different
 * things: rows of another market mean "turn the switch back on and close the country instead", an unreadable table means "find out why
 * before you open anything".
 */
class StorefrontSafetyCommand extends Command
{
    protected $signature = 'gcc:storefront:safety {--json : print the report as JSON}';

    protected $description = 'Says whether the storefronts switch is off while another market holds rows, which is a state the platform must not be operated in. Exits 1 when it is';

    public function handle(LegacyModeSafety $safety): int
    {
        $report = [
            'legacy_mode' => $safety->isLegacyMode(),
            'default_market' => StorefrontResolver::fromConfig()->defaultCountry(),
            'other_markets_hold' => $safety->rowsOfOtherMarkets(),
            'tables_that_could_not_be_read' => $safety->tablesThatCouldNotBeRead(),
            'refusal' => $safety->refusalOfAnActionThatOpensAMarket(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['refusal'] === null ? self::SUCCESS : self::FAILURE;
        }

        return $this->print(report: $report);
    }

    /** @param array<string, mixed> $report */
    private function print(array $report): int
    {
        if ($report['legacy_mode'] && $report['tables_that_could_not_be_read'] !== []) {
            $this->error('The question could not be answered, so this is not an all-clear.');
            $this->line((string) $report['refusal']);
            $this->newLine();
            $this->table(['table', 'why it could not be read'], array_map(
                static fn (string $table, string $because): array => [$table, $because],
                array_keys($report['tables_that_could_not_be_read']),
                array_values($report['tables_that_could_not_be_read']),
            ));

            return self::FAILURE;
        }

        if ($report['refusal'] !== null) {
            $this->error('The platform is in a state it must not be operated in.');
            $this->line($report['refusal']);
            $this->newLine();
            $this->table(['market', 'table', 'rows'], $this->rows($report['other_markets_hold']));

            return self::FAILURE;
        }

        if ($report['tables_that_could_not_be_read'] !== []) {
            // Worth saying, and not a reason to stop: with the switch on there is no state to be unsure about, because every market is
            // served as itself whatever any table holds.
            $this->warn('Some tables could not be read. The switch is on, so there is no state to be unsure about, but this is worth looking at:');
            $this->table(['table', 'why it could not be read'], array_map(
                static fn (string $table, string $because): array => [$table, $because],
                array_keys($report['tables_that_could_not_be_read']),
                array_values($report['tables_that_could_not_be_read']),
            ));
        }

        $this->info('Nothing to report: the platform is not in a state it must not be operated in.');
        $this->line($report['legacy_mode']
            ? sprintf('The storefronts switch is off, and no market other than %s holds a row of its own.', $report['default_market'])
            : 'The storefronts switch is on, so every market is served as itself.');

        if ($report['other_markets_hold'] !== []) {
            $this->table(['market', 'table', 'rows'], $this->rows($report['other_markets_hold']));
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, array<string, int>> $held
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function rows(array $held): array
    {
        $rows = [];

        foreach ($held as $market => $tables) {
            foreach ($tables as $table => $count) {
                $rows[] = [$market, $table, $count];
            }
        }

        return $rows;
    }
}
