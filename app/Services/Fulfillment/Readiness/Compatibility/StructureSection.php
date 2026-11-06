<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use App\Services\Fulfillment\Support\LegacyColumnWidener;
use RuntimeException;

/**
 * Are the columns the integration relies on stored as it assumes? Presence is the schema check's job (it FAILs a missing
 * column); this section looks at the TYPE of each column that is there: an amount in an integer column loses cents, a
 * JSON address in a short varchar is truncated, an id held as text cannot be joined.
 */
final class StructureSection implements CompatibilitySection
{
    private const SECTION = 'structure';

    public function checks(ReadOnlyProbe $probe, CompatibilityOptions $options): array
    {
        $checks = [];
        foreach ($this->expectationsByTable() as $table => $expectations) {
            $columns = $probe->columns($table);

            $checks[] = $columns === []
                ? $this->missingTable($table, $expectations[0])
                : $this->tableCheck($table, $expectations, $columns);
        }

        $plan = $this->columnChanges($probe);
        if ($plan !== null) {
            $checks[] = $plan;
        }

        return $checks;
    }

    /**
     * The ALTER TABLE statements the compatibility migrations would run on this database, found out by reading its definitions and
     * nothing else, so that they can be looked at before they are run. A column the migration would refuse to touch is a warning here.
     */
    private function columnChanges(ReadOnlyProbe $probe): ?CompatibilityCheck
    {
        if (!in_array($probe->driver(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $lines = [];
        $refused = 0;
        foreach (CompatibilitySpec::WIDENED_COLUMNS as $table => $columns) {
            if ($probe->columns($table) === []) {
                continue;
            }
            foreach ($columns as $column => $width) {
                try {
                    $change = LegacyColumnWidener::preview($table, $column, $width);
                    $lines[] = "{$table}.{$column}: " . match (true) {
                        $change['statement'] !== null => $change['statement'],
                        $change['outcome'] === LegacyColumnWidener::ALREADY_WIDE => 'already wide enough, nothing to change',
                        default => 'not there, nothing to change',
                    };
                } catch (RuntimeException $refusal) {
                    $refused++;
                    $lines[] = "{$table}.{$column}: THE MIGRATION WOULD REFUSE: " . $refusal->getMessage();
                }
            }
        }

        return $lines === [] ? null : new CompatibilityCheck(
            section: self::SECTION,
            name: 'column changes the migrations would make',
            status: $refused === 0 ? CompatibilityCheck::INFO : CompatibilityCheck::WARN,
            detail: implode(' | ', $lines),
            facts: ['columns' => count($lines), 'refused' => $refused],
        );
    }

    /** @return array<string, list<ColumnExpectation>> */
    private function expectationsByTable(): array
    {
        $byTable = [];
        foreach (CompatibilitySpec::columns() as $expectation) {
            $byTable[$expectation->table][] = $expectation;
        }

        return $byTable;
    }

    private function missingTable(string $table, ColumnExpectation $first): CompatibilityCheck
    {
        return $first->optionalTable
            ? new CompatibilityCheck(self::SECTION, "{$table} column types", CompatibilityCheck::WARN, "the table does not exist. {$first->reason}")
            : new CompatibilityCheck(self::SECTION, "{$table} column types", CompatibilityCheck::INFO, 'not checked: the table is missing (the schema check reports it)');
    }

    /**
     * @param list<ColumnExpectation> $expectations
     * @param array<string, ColumnFacts> $columns
     */
    private function tableCheck(string $table, array $expectations, array $columns): CompatibilityCheck
    {
        $checked = 0;
        $problems = [CompatibilityCheck::FAIL => [], CompatibilityCheck::WARN => []];

        foreach ($expectations as $expectation) {
            $facts = $columns[$expectation->column] ?? null;
            if ($facts === null) {
                continue;
            }

            $checked++;
            $problem = $expectation->problem($facts);
            if ($problem !== null) {
                $problems[$expectation->severity][] = "{$table}.{$expectation->column} {$problem}";
            }
        }

        $status = match (true) {
            $problems[CompatibilityCheck::FAIL] !== [] => CompatibilityCheck::FAIL,
            $problems[CompatibilityCheck::WARN] !== [] => CompatibilityCheck::WARN,
            default => CompatibilityCheck::PASS,
        };
        $all = [...$problems[CompatibilityCheck::FAIL], ...$problems[CompatibilityCheck::WARN]];

        return new CompatibilityCheck(
            section: self::SECTION,
            name: "{$table} column types",
            status: $status,
            detail: $all === [] ? "{$checked} columns are stored as the integration expects" : implode(' | ', $all),
            facts: ['columns_checked' => $checked, 'mismatches' => count($all)],
        );
    }
}
