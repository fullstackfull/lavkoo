<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a table is and what is in it, asked of the database itself: the table's own facts, every column with the facts a migration could
 * change, every index, and every row.
 *
 * A column list put through `sort()` sees names and nothing else, so a backfill, a default, a widened type, an added index and a column
 * inserted in the middle of the table all pass it. These read the facts instead, and the columns keep the order the table holds them in
 * (`information_schema.columns` is read by ordinal position and `pragma_table_xinfo` by cid), so a column added anywhere but the end is a
 * different answer. The reads are MarketAccountExpandMigrationTest's own, lifted here when the sharing-key guard needed the same snapshot.
 *
 * THE TABLE'S OWN FACTS are read because a column snapshot cannot see them: a migration that switched a money table to an engine with no
 * transactions and no row locks, or changed its charset, or hung a comment on a column, changed every table in the sense that matters and
 * nothing in a per-column read. `Schema::getTables()` answers with the engine, the charset and the comment on MySQL and MariaDB and with
 * null for each on SQLite, which cannot have them; the table's SIZE is deliberately not read, growing as rows are written and saying
 * nothing a migration decided.
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
                'collation' => $column['collation'] ?? null,
                'comment' => $column['comment'] ?? null,
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
     * The engine, the charset and the comment of every table of THIS connection, by name, read in one query.
     *
     * The schema is named rather than left to the driver: with none, `Schema::getTables()` asks `information_schema` for every table of
     * every database on the server, so on a shared test server another run's table of the same name would answer for this one.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function tableFactsOfTheConnection(): array
    {
        $facts = [];

        foreach (Schema::getTables(Schema::getCurrentSchemaListing()) as $table) {
            $facts[(string) $table['name']] = [
                'engine' => $table['engine'] ?? null,
                'collation' => $table['collation'] ?? null,
                'comment' => $table['comment'] ?? null,
            ];
        }

        return $facts;
    }

    /**
     * @param list<string> $tables
     * @return array<string, mixed> what each table is, the indexes on it and every row in it, and null for a table that is not there
     */
    protected function stateOf(array $tables): array
    {
        $facts = $this->tableFactsOfTheConnection();
        $state = [];

        foreach ($tables as $table) {
            $state[$table] = Schema::hasTable($table)
                ? [
                    'table' => $facts[$table] ?? null,
                    'columns' => $this->columnsOf($table),
                    'indexes' => $this->indexesOf($table),
                    'rows' => $this->rowsOf($table),
                ]
                : null;
        }

        return $state;
    }
}
