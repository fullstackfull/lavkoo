<?php

namespace App\Services\Fulfillment\Readiness\Staging;

use Closure;

/**
 * One table of a restored database that can still reach the outside world (or hold what identifies a real person), the
 * test for whether it does, and the statement that makes it harmless. The same declaration drives the read-only audit (count
 * the rows that are still armed) and the plan generator (print the statement and the check that must then count zero).
 */
final readonly class SinkTarget
{
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const EMPTY = 'empty';

    /**
     * @param list<string> $columns the columns this target needs; the target is left out when the table lacks any of them
     * @param Closure(SqlDialect): string $armed SQL condition that is true for a row that can still act
     * @param Closure(SqlDialect): string|null $scope SQL condition that selects the rows the statement touches (all rows when null)
     * @param Closure(SqlDialect): string|null $set the assignments of an UPDATE
     */
    public function __construct(
        public string $table,
        public array $columns,
        public Closure $armed,
        public string $action = self::UPDATE,
        public ?Closure $set = null,
        public ?Closure $scope = null,
    ) {
    }

    /**
     * Why this target cannot apply to the database being looked at, or null when it can.
     *
     * @param list<string> $present the columns of the table in the real database (empty when the table is not there)
     */
    public function unavailableReason(array $present): ?string
    {
        if ($present === []) {
            return "table {$this->table} is not present";
        }

        $missing = array_values(array_diff($this->columns, $present));

        return $missing === [] ? null : "table {$this->table} lacks " . implode(', ', $missing);
    }

    /** False for a target no portable SQL can fix (an UPDATE without assignments): the audit still counts it, the plan names it. */
    public function canSink(): bool
    {
        return $this->action !== self::UPDATE || $this->set !== null;
    }

    /** What the audit counts: rows in scope that are still armed. */
    public function armedCondition(SqlDialect $sql): string
    {
        $armed = ($this->armed)($sql);

        return $this->scope === null ? $armed : '(' . ($this->scope)($sql) . ') AND (' . $armed . ')';
    }

    public function statement(SqlDialect $sql): string
    {
        $where = $this->scope === null ? '' : ' WHERE ' . ($this->scope)($sql);

        return match ($this->action) {
            self::EMPTY => $sql->emptyTable($this->table),
            self::DELETE => "DELETE FROM {$this->table}{$where}",
            default => "UPDATE {$this->table} SET " . ($this->set)($sql) . $where,
        };
    }

    /** The statement that must count zero once the sink has run, labelled so that a list of them reads on its own. */
    public function verification(SqlDialect $sql, string $label): string
    {
        $label = str_replace("'", "''", $label);

        return "SELECT '{$label}' AS target, COUNT(*) AS rows_still_armed FROM {$this->table} WHERE " . $this->armedCondition($sql);
    }
}
