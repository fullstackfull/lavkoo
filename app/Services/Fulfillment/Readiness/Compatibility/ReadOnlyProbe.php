<?php

namespace App\Services\Fulfillment\Readiness\Compatibility;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The only way the compatibility command touches the database, and it can only read.
 *
 * Three layers, so that no single mistake can write to the database being inspected:
 *  1. the session itself is switched to read-only while the probe is in use (MySQL: `SET SESSION TRANSACTION READ ONLY`,
 *     SQLite: `PRAGMA query_only`), so the database refuses an INSERT, UPDATE, DELETE or DDL statement even if one were
 *     issued by mistake;
 *  2. this class offers no way to write: its methods build SELECT statements from table and column names the command
 *     itself defines, never from input;
 *  3. every statement the connection executes meanwhile is recorded, and the report states how many there were and that
 *     none was a write.
 *
 * Reading a column's STRUCTURE (type, length, nullability) is always allowed; reading values is limited to counts, to the
 * distinct values of enumeration columns, and to a bounded number of recent rows that are examined in memory and never
 * printed (see the sections).
 */
final class ReadOnlyProbe
{
    /** Stand-in for a NULL in a distribution, so that it can be a key. */
    public const NULL_KEY = '(null)';

    private const PAGE_SIZE = 500;

    private const READ_STATEMENT = '/^\s*(select|show|describe|desc|explain|with\b[\s\S]*\bselect)\b/i';

    private const SESSION_STATEMENT = '/^\s*(set\s+session\s+transaction\s+read\s+(only|write)|pragma\s+query_only\b)/i';

    /** @var list<string> */
    private array $statements = [];

    private bool $recording = false;

    private bool $locked = false;

    public function __construct(private readonly Connection $connection)
    {
        $this->connection->listen(function (QueryExecuted $query): void {
            if ($this->recording) {
                $this->statements[] = $query->sql;
            }
        });
    }

    /**
     * Runs the work with the session locked read-only, and unlocks it afterwards even if the work throws.
     *
     * @template T
     * @param Closure(self): T $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        $this->recording = true;

        try {
            $this->connection->statement($this->lockStatement(readOnly: true));
            $this->locked = true;

            return $work($this);
        } finally {
            if ($this->locked) {
                $this->connection->statement($this->lockStatement(readOnly: false));
            }
            $this->locked = false;
            $this->recording = false;
        }
    }

    public function driver(): string
    {
        return $this->connection->getDriverName();
    }

    public function databaseName(): string
    {
        return (string) $this->connection->getDatabaseName();
    }

    public function sessionLocked(): bool
    {
        return $this->locked;
    }

    /** @return list<string> every statement executed while the probe was in use */
    public function statements(): array
    {
        return $this->statements;
    }

    /** @return list<string> the statements that were neither a read nor the session lock itself: must always be empty */
    public function writeStatements(): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $sql): bool => preg_match(self::READ_STATEMENT, $sql) !== 1 && preg_match(self::SESSION_STATEMENT, $sql) !== 1,
        ));
    }

    public function hasTable(string $table): bool
    {
        return Schema::connection($this->connection->getName())->hasTable($table);
    }

    /** @return array<string, ColumnFacts> lower-case column name => facts, empty when the table does not exist */
    public function columns(string $table): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }

        $facts = [];
        foreach (Schema::connection($this->connection->getName())->getColumns($table) as $column) {
            $columnFacts = ColumnFacts::fromSchema($column);
            $facts[$columnFacts->name] = $columnFacts;
        }

        return $facts;
    }

    /** @param Closure(\Illuminate\Database\Query\Builder): mixed|null $where */
    public function count(string $table, ?Closure $where = null): int
    {
        $query = $this->connection->table($table);
        if ($where !== null) {
            $where($query);
        }

        return (int) $query->count();
    }

    /**
     * How many rows of a table satisfy a condition written in SQL. The condition must be built from constants of this code
     * base (the staging channel registry), never from input: it is passed to the database as it is.
     */
    public function countWhere(string $table, string $condition): int
    {
        return (int) $this->connection->table($table)->whereRaw($condition)->count();
    }

    /** @param Closure(\Illuminate\Database\Query\Builder): mixed|null $where */
    public function sum(string $table, string $column, ?Closure $where = null): float
    {
        $query = $this->connection->table($table);
        if ($where !== null) {
            $where($query);
        }

        return (float) $query->sum($column);
    }

    /**
     * How many rows hold each distinct value of a column, most frequent first.
     *
     * @return array<string, int> value (or NULL_KEY) => rows; at most $limit values
     */
    public function distribution(string $table, string $column, int $limit): array
    {
        $rows = $this->connection->table($table)
            ->select($column)
            ->selectRaw('count(*) as total')
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $distribution = [];
        foreach ($rows as $row) {
            $value = $row->{$column};
            $distribution[$value === null ? self::NULL_KEY : (string) $value] = (int) $row->total;
        }

        return $distribution;
    }

    /**
     * Hands the newest rows to the callback one by one, in pages, never more than $limit of them. The values are examined
     * in memory by the caller and are not meant to leave it.
     *
     * @param list<string> $columns
     * @param Closure(object): void $each
     * @param Closure(\Illuminate\Database\Query\Builder): mixed|null $where
     */
    public function eachRecent(string $table, array $columns, int $limit, Closure $each, string $key = 'id', ?Closure $where = null): int
    {
        $seen = 0;
        $before = null;
        $select = array_values(array_unique([$key, ...$columns]));

        while ($seen < $limit) {
            $query = $this->connection->table($table)->select($select);
            if ($where !== null) {
                $where($query);
            }
            $page = $query
                ->when($before !== null, static fn ($builder) => $builder->where($key, '<', $before))
                ->orderByDesc($key)
                ->limit(min(self::PAGE_SIZE, $limit - $seen))
                ->get();

            if ($page->isEmpty()) {
                break;
            }
            foreach ($page as $row) {
                $each($row);
            }

            $seen += $page->count();
            $before = $page->last()->{$key};
        }

        return $seen;
    }

    /**
     * Which of the given values occur in table.column. Used to look for rows that point at a row which is not there.
     *
     * @param list<int|string> $values
     * @return list<string>
     */
    public function existing(string $table, string $column, array $values): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($values)), self::PAGE_SIZE) as $chunk) {
            foreach ($this->connection->table($table)->whereIn($column, $chunk)->distinct()->pluck($column) as $value) {
                $found[] = (string) $value;
            }
        }

        return $found;
    }

    /**
     * How many distinct non-empty values of a column occur on more than one row (the values themselves are not returned).
     *
     * @param Closure(\Illuminate\Database\Query\Builder): mixed|null $where
     */
    public function duplicatedValues(string $table, string $column, ?Closure $where = null): int
    {
        $grouped = $this->connection->table($table)->select($column)->whereNotNull($column)->where($column, '<>', '')->groupBy($column)->havingRaw('count(*) > 1');
        if ($where !== null) {
            $where($grouped);
        }

        return (int) $this->connection->query()->fromSub($grouped, 'duplicated')->count();
    }

    /** @return list<string>|null the migrations the database has run, or null when it has no migrations table yet */
    public function migrationsRan(): ?array
    {
        return $this->hasTable('migrations') ? $this->connection->table('migrations')->pluck('migration')->all() : null;
    }

    /** @return array{0: string, 1: string} */
    private function lockStatements(): array
    {
        return match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => ['SET SESSION TRANSACTION READ ONLY', 'SET SESSION TRANSACTION READ WRITE'],
            'sqlite' => ['PRAGMA query_only = 1', 'PRAGMA query_only = 0'],
            default => throw new RuntimeException("Read-only enforcement is not implemented for the '{$this->connection->getDriverName()}' driver, so the compatibility check refuses to run on it."),
        };
    }

    private function lockStatement(bool $readOnly): string
    {
        return $this->lockStatements()[$readOnly ? 0 : 1];
    }
}
