<?php

namespace App\Services\Fulfillment\Readiness\Staging;

use Closure;

/** A table that holds what identifies a real person, and the value each such column is given on a staging copy. */
final readonly class ScrubTable
{
    /** @param array<string, Closure(SqlDialect): string> $columns column => SQL expression every row is given (it may use the row's `id`) */
    public function __construct(public string $table, public array $columns)
    {
    }

    /** @param list<string> $present the columns the real table has */
    public function statement(SqlDialect $sql, array $present): ?string
    {
        $assignments = $this->assignments($sql, $present);

        return $assignments === [] ? null : "UPDATE {$this->table} SET " . implode(', ', $assignments);
    }

    /** @param list<string> $present */
    public function verification(SqlDialect $sql, array $present): ?string
    {
        $differences = [];
        foreach ($this->expressions($sql, $present) as $column => $expression) {
            $differences[] = $sql->differs($column, $expression);
        }

        return $differences === [] ? null : "SELECT '{$this->table}' AS target, COUNT(*) AS rows_not_scrubbed FROM {$this->table} WHERE " . implode(' OR ', $differences);
    }

    /** @param list<string> $present @return list<string> the columns of this table the real table has */
    public function columnsPresent(array $present): array
    {
        return array_values(array_intersect(array_keys($this->columns), $present));
    }

    /** @param list<string> $present @return list<string> */
    private function assignments(SqlDialect $sql, array $present): array
    {
        $assignments = [];
        foreach ($this->expressions($sql, $present) as $column => $expression) {
            $assignments[] = "{$column} = {$expression}";
        }

        return $assignments;
    }

    /** @param list<string> $present @return array<string, string> */
    private function expressions(SqlDialect $sql, array $present): array
    {
        $expressions = [];
        foreach ($this->columns as $column => $expression) {
            if (in_array($column, $present, true)) {
                $expressions[$column] = $expression($sql);
            }
        }

        return $expressions;
    }
}
