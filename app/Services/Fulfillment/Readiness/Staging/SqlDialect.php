<?php

namespace App\Services\Fulfillment\Readiness\Staging;

use RuntimeException;

/**
 * The few places where MySQL and SQLite spell the same thing differently, so that one declaration of "what is armed" and
 * "how to neutralise it" serves the real database (MySQL/MariaDB) and the throwaway test database (SQLite).
 *
 * Everything returned is SQL text built from constants of this code base; no value from the database or from a person is
 * ever interpolated into it.
 */
final readonly class SqlDialect
{
    private function __construct(private bool $mysql)
    {
    }

    public static function forDriver(string $driver): self
    {
        return match ($driver) {
            'mysql', 'mariadb' => new self(mysql: true),
            'sqlite' => new self(mysql: false),
            default => throw new RuntimeException("No SQL dialect for the '{$driver}' driver."),
        };
    }

    /** True for a column that holds something other than NULL or an empty string. */
    public function nonBlank(string $column): string
    {
        return "({$column} IS NOT NULL AND {$column} <> '')";
    }

    /** The text of a scalar inside a JSON column (NULL when the path is absent). */
    public function jsonText(string $column, string $path): string
    {
        return $this->mysql ? "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$path}'))" : "json_extract({$column}, '{$path}')";
    }

    /** True for a JSON column whose flag at the path is on (1 or true); a value that is not valid JSON counts as off. */
    public function jsonFlag(string $column, string $path): string
    {
        return "(CASE WHEN json_valid({$column}) THEN " . $this->jsonText($column, $path) . " END IN ('1', 'true'))";
    }

    /** True for a JSON column whose text at the path is present and not empty. */
    public function jsonNonBlank(string $column, string $path): string
    {
        return '(CASE WHEN json_valid(' . $column . ') THEN ' . $this->jsonText($column, $path) . " END <> '')";
    }

    /**
     * The JSON column with the given paths set (created when absent); a value that is not valid JSON is left as it is.
     *
     * @param array<string, string> $values path => SQL literal
     */
    public function jsonSet(string $column, array $values): string
    {
        return $this->jsonWrite('json_set', $column, $values);
    }

    /**
     * The JSON column with the given paths replaced, only where they already exist.
     *
     * @param array<string, string> $values path => SQL expression
     */
    public function jsonReplace(string $column, array $values): string
    {
        return $this->jsonWrite('json_replace', $column, $values);
    }

    /** A JSON boolean literal for jsonSet(). */
    public function jsonBoolean(bool $value): string
    {
        $word = $value ? 'true' : 'false';

        return $this->mysql ? $word : "json('{$word}')";
    }

    /** True where the column differs from the expression, treating NULL as a value (NULL differs from everything but NULL). */
    public function differs(string $column, string $expression): string
    {
        return $this->mysql ? "NOT ({$column} <=> {$expression})" : "({$column} IS NOT {$expression})";
    }

    public function concat(string ...$parts): string
    {
        return $this->mysql ? 'CONCAT(' . implode(', ', $parts) . ')' : '(' . implode(' || ', $parts) . ')';
    }

    /** A number written with leading zeros to a fixed width. */
    public function zeroPadded(string $expression, int $width): string
    {
        return $this->mysql ? "LPAD({$expression}, {$width}, '0')" : "printf('%0{$width}d', {$expression})";
    }

    /** Empties a table. SQLite has no TRUNCATE; its DELETE without a condition is the same thing for our purpose. */
    public function emptyTable(string $table): string
    {
        return $this->mysql ? "TRUNCATE TABLE {$table}" : "DELETE FROM {$table}";
    }

    /**
     * A statement that fails, and so stops a script run with `mysql` (default settings), unless the connected database has the
     * expected name. MySQL has no "abort script" statement; a scalar subquery that returns two rows is an error (1242).
     */
    public function guardDatabase(string $expected): string
    {
        $literal = "'" . str_replace("'", "''", $expected) . "'";

        return $this->mysql
            ? "SELECT IF(DATABASE() = {$literal}, 'database is the expected copy', (SELECT 1 UNION ALL SELECT 2)) AS guard"
            : 'SELECT 1 AS guard';
    }

    /** @param array<string, string> $values */
    private function jsonWrite(string $function, string $column, array $values): string
    {
        $pairs = [];
        foreach ($values as $path => $literal) {
            $pairs[] = "'{$path}', {$literal}";
        }

        return "CASE WHEN json_valid({$column}) THEN {$function}({$column}, " . implode(', ', $pairs) . ") ELSE {$column} END";
    }
}
