<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a table is and what is in it, asked of the database itself: every column with the facts a migration could change, every index, and
 * every row.
 *
 * A column list put through `sort()` sees names and nothing else, so a backfill, a default, a widened type, an added index and a column
 * inserted in the middle of the table all pass it. These read the facts instead, and the columns keep the order the table holds them in
 * (`information_schema.columns` is read by ordinal position and `pragma_table_xinfo` by cid), so a column added anywhere but the end is a
 * different answer. The reads are MarketAccountExpandMigrationTest's own, lifted here when the sharing-key guard needed the same snapshot.
 */
trait CapturesTableState
{
    /** @return array<string, array<string, mixed>> every column by name, in the order the table holds them */
    protected function columnsOf(string $table): array
    {
        $facts = [];

        foreach (Schema::getColumns($table) as $column) {
            $facts[$column['name']] = [
                'type' => $column['type'],
                'nullable' => $column['nullable'],
                'default' => $column['default'],
                'auto_increment' => $column['auto_increment'] ?? false,
            ];
        }

        return $facts;
    }

    /** @return array<string, array{columns: list<string>, unique: bool, primary: bool}> every index by name, sorted, so that one added is seen */
    protected function indexesOf(string $table): array
    {
        $indexes = [];

        foreach (Schema::getIndexes($table) as $index) {
            $indexes[(string) $index['name']] = [
                'columns' => $index['columns'],
                'unique' => (bool) $index['unique'],
                'primary' => (bool) $index['primary'],
            ];
        }

        ksort($indexes);

        return $indexes;
    }

    /** @return list<array<string, mixed>> */
    protected function rowsOf(string $table): array
    {
        return array_map(static fn (object $row): array => (array) $row, DB::table($table)->orderBy('id')->get()->all());
    }

    /**
     * @param list<string> $tables
     * @return array<string, mixed> the shape of each table, the indexes on it and every row in it, and null for a table that is not there
     */
    protected function stateOf(array $tables): array
    {
        $state = [];

        foreach ($tables as $table) {
            $state[$table] = Schema::hasTable($table)
                ? ['columns' => $this->columnsOf($table), 'indexes' => $this->indexesOf($table), 'rows' => $this->rowsOf($table)]
                : null;
        }

        return $state;
    }
}
