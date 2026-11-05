<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

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

        return $checks;
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
