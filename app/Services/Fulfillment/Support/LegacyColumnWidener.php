<?php

namespace App\Services\Fulfillment\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Makes a column of one of the platform's own tables wider (a short varchar, or a varchar turned into TEXT) and changes nothing else
 * about it: not its nullability, its default, its character set and collation, its comment or the indexes that cover it. Nothing is
 * written to any row.
 *
 * Those tables come from the installation dump, so their exact definition is only known by reading it. This reads it from
 * information_schema, builds the one ALTER TABLE ... MODIFY COLUMN that says the same thing with a wider type, and checks afterwards that
 * only the width changed. A definition it does not fully understand (a generated column, an expression default, an index that would
 * need a prefix length) is refused with a message, before anything is altered, instead of being guessed at.
 *
 * MySQL and MariaDB differ in how they report a default (MySQL: the bare value; MariaDB: the SQL literal, quotes included, and the word
 * NULL spelled out): both are handled. On any other database engine there is nothing to do.
 *
 * @phpstan-type Definition array{data_type: string, column_type: string, length: ?int, nullable: bool, default: ?string, extra: string, generated: bool, charset: ?string, collation: ?string, comment: string, index_count: int}
 */
final class LegacyColumnWidener
{
    public const WIDENED = 'widened';

    public const ALREADY_WIDE = 'already_wide';

    /** Another database engine, or the table or the column is not there. */
    public const NOT_APPLICABLE = 'not_applicable';

    /** Types that already hold any text this change could put in them (a native JSON column included). */
    private const TEXT_TYPES = ['tinytext', 'text', 'mediumtext', 'longtext', 'json'];

    /** How long the change waits for the table's metadata lock behind a transaction that has not finished, before it gives up with nothing altered. */
    private const LOCK_WAIT_SECONDS = 15;

    /** @return string WIDENED, ALREADY_WIDE or NOT_APPLICABLE */
    public static function widenString(string $table, string $column, int $length): string
    {
        return self::change($table, $column, $length);
    }

    /** @return string WIDENED, ALREADY_WIDE or NOT_APPLICABLE */
    public static function convertToText(string $table, string $column): string
    {
        return self::change($table, $column, null);
    }

    /**
     * What widening the column (a varchar of $length, or TEXT when $length is null) would do, found out without altering anything: the
     * statement that would run, or none when the column is wide enough already or is not there.
     *
     * @return array{outcome: string, statement: ?string}
     * @throws RuntimeException when the definition cannot be restated safely, with the reason
     */
    public static function preview(string $table, string $column, ?int $length): array
    {
        $plan = self::plan($table, $column, $length);

        return ['outcome' => $plan['outcome'], 'statement' => $plan['statement']];
    }

    /** @return string WIDENED, ALREADY_WIDE or NOT_APPLICABLE */
    private static function change(string $table, string $column, ?int $length): string
    {
        $plan = self::plan($table, $column, $length);
        if ($plan['statement'] === null) {
            return $plan['outcome'];
        }

        Log::info("Fulfillment compatibility: changing {$table}.{$column} from {$plan['before']['column_type']} to " . self::targetType($length) . '.');
        self::run($plan['statement']);
        self::confirm($table, $column, $plan['before'], $length);

        return self::WIDENED;
    }

    /**
     * The one decision both the preview and the change are made from.
     *
     * @return array{outcome: string, statement: ?string, before: ?Definition}
     */
    private static function plan(string $table, string $column, ?int $length): array
    {
        if (DB::connection()->pretending()) {
            // A pretended query answers with nothing, so there is no definition to read and no statement to show.
            return ['outcome' => self::NOT_APPLICABLE, 'statement' => null, 'before' => null];
        }

        $before = self::describe($table, $column);
        if ($before === null) {
            if (self::isMysqlOrMariaDb() && self::answersSelect($table, $column)) {
                throw self::refused($table, $column, 'the database does not show its definition although the column is there (information_schema lists only what the user holds privileges on)');
            }

            return ['outcome' => self::NOT_APPLICABLE, 'statement' => null, 'before' => null];
        }

        $isText = in_array($before['data_type'], self::TEXT_TYPES, true);
        if ($isText || ($length !== null && $before['data_type'] === 'varchar' && (int) $before['length'] >= $length)) {
            return ['outcome' => self::ALREADY_WIDE, 'statement' => null, 'before' => $before];
        }
        if ($before['data_type'] !== 'varchar') {
            throw self::refused($table, $column, "it is a {$before['column_type']} column, not a varchar");
        }
        if (preg_match('/^varchar\(\d+\)$/D', $before['column_type']) !== 1) {
            throw self::refused($table, $column, "its type is {$before['column_type']}, which says more than a width, and rewriting the column would drop the rest");
        }
        self::refuseWhenACheckConstraintMentions($table, $column);
        if ($length === null) {
            self::requireRestatableAsText($table, $column, $before);
        }

        return [
            'outcome' => self::WIDENED,
            'statement' => self::statement($table, $column, $length === null ? 'TEXT' : "VARCHAR({$length})", $before, withDefault: $length !== null),
            'before' => $before,
        ];
    }

    /** Runs the statement, giving up after LOCK_WAIT_SECONDS if another transaction holds the table (nothing is altered then, and it can be repeated). */
    private static function run(string $statement): void
    {
        $previous = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
        DB::statement('SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);

        try {
            DB::statement($statement);
        } finally {
            DB::statement('SET SESSION lock_wait_timeout = ' . $previous);
        }
    }

    /** @param Definition $before */
    private static function requireRestatableAsText(string $table, string $column, array $before): void
    {
        if ($before['index_count'] > 0) {
            throw self::refused($table, $column, 'an index covers it, and an index on a TEXT column needs a prefix length, which is a decision for a person');
        }

        [$default, $refusal] = self::defaultClause($before['default'], $before['nullable'], self::isMariaDb(), static fn (string $value): string => DB::getPdo()->quote($value));
        if ($refusal !== null || ($default !== null && $default !== 'DEFAULT NULL')) {
            throw self::refused($table, $column, $refusal ?? 'it has a default value, which a TEXT column cannot keep on every MySQL version');
        }
    }

    /** The column answers a SELECT although information_schema does not list it: its definition cannot be read, which is not the same as it not being there. */
    private static function answersSelect(string $table, string $column): bool
    {
        try {
            DB::select('SELECT ' . self::identifier($column) . ' FROM ' . self::identifier($table) . ' LIMIT 0');
        } catch (QueryException $failure) {
            return !in_array((int) ($failure->errorInfo[1] ?? 0), [1146, 1054], true);
        }

        return true;
    }

    /** MariaDB drops a column-level CHECK when the column is redefined, and a rule must not disappear unnoticed: a person decides. */
    private static function refuseWhenACheckConstraintMentions(string $table, string $column): void
    {
        $create = (string) (((array) DB::selectOne('SHOW CREATE TABLE ' . self::identifier($table)))['Create Table'] ?? '');
        foreach (preg_split('/\R/', $create) ?: [] as $line) {
            if (stripos($line, 'CHECK') !== false && preg_match('/[`"]' . preg_quote($column, '/') . '[`"]/', $line) === 1) {
                throw self::refused($table, $column, 'a CHECK constraint mentions it, and redefining the column could drop that rule without a word');
            }
        }
    }

    private static function targetType(?int $length): string
    {
        return $length === null ? 'TEXT' : "varchar({$length})";
    }

    /**
     * What the database says about a column, or null when this is not MySQL or MariaDB or the table or column is not there.
     *
     * @return Definition|null
     */
    public static function describe(string $table, string $column): ?array
    {
        if (!self::isMysqlOrMariaDb()) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, CHARACTER_MAXIMUM_LENGTH AS length, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS column_default, '
            . 'EXTRA AS extra, GENERATION_EXPRESSION AS generation, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation, COLUMN_COMMENT AS comment '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
        if ($row === null) {
            return null;
        }

        $indexes = DB::selectOne(
            'SELECT COUNT(*) AS used FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        return [
            'data_type' => strtolower((string) $row->data_type),
            'column_type' => strtolower((string) $row->column_type),
            'length' => $row->length === null ? null : (int) $row->length,
            'nullable' => strtoupper((string) $row->nullable) === 'YES',
            'default' => $row->column_default === null ? null : (string) $row->column_default,
            'extra' => strtolower(trim((string) $row->extra)),
            'generated' => trim((string) ($row->generation ?? '')) !== '',
            'charset' => $row->charset === null ? null : (string) $row->charset,
            'collation' => $row->collation === null ? null : (string) $row->collation,
            'comment' => (string) $row->comment,
            'index_count' => (int) $indexes->used,
        ];
    }

    /**
     * The ALTER TABLE that restates the column exactly, with only its type replaced.
     *
     * @param Definition $before
     */
    public static function statement(string $table, string $column, string $type, array $before, bool $withDefault = true, ?bool $mariaDb = null): string
    {
        if ($before['generated'] || $before['extra'] !== '') {
            throw self::refused($table, $column, 'it is a generated, auto-increment or self-updating column (EXTRA: ' . ($before['extra'] === '' ? 'generated' : $before['extra']) . ')');
        }

        $parts = [$type];
        if ($before['charset'] !== null) {
            foreach ([$before['charset'], (string) $before['collation']] as $name) {
                if (preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
                    throw self::refused($table, $column, 'its character set or collation has an unusual name');
                }
            }
            $parts[] = "CHARACTER SET {$before['charset']} COLLATE {$before['collation']}";
        }
        $parts[] = $before['nullable'] ? 'NULL' : 'NOT NULL';

        [$default, $refusal] = self::defaultClause($before['default'], $before['nullable'], $mariaDb ?? self::isMariaDb(), static fn (string $value): string => DB::getPdo()->quote($value));
        if ($refusal !== null) {
            throw self::refused($table, $column, $refusal);
        }
        if ($default !== null && $withDefault) {
            $parts[] = $default;
        }
        if ($before['comment'] !== '') {
            $parts[] = 'COMMENT ' . DB::getPdo()->quote($before['comment']);
        }

        return sprintf('ALTER TABLE %s MODIFY COLUMN %s %s', self::identifier($table), self::identifier($column), implode(' ', $parts));
    }

    /**
     * The DEFAULT clause that says what the column's default says now.
     *
     * @param callable(string): string $quote quotes a string literal for the connection
     * @return array{0: ?string, 1: ?string} the clause (null for none) and, when the default cannot be restated safely, why not
     */
    public static function defaultClause(?string $raw, bool $nullable, bool $mariaDb, callable $quote): array
    {
        if ($raw === null) {
            return [$nullable ? 'DEFAULT NULL' : null, null];
        }

        if (!$mariaDb) {
            // MySQL reports the bare value; an expression default is told apart by EXTRA, which statement() has already refused.
            return ['DEFAULT ' . $quote($raw), null];
        }

        // MariaDB reports a default as SQL text: NULL, 'pending', 12, 1.5, or an expression such as current_timestamp().
        $text = trim($raw);

        return match (true) {
            strcasecmp($text, 'NULL') === 0 => ['DEFAULT NULL', null],
            str_contains($text, '\\') => [null, "its default ({$text}) contains a backslash, which is written differently in different SQL modes"],
            preg_match("/^'(?:[^'\\\\]|\\\\.|'')*'$/sD", $text) === 1, preg_match('/^-?\d+(?:\.\d+)?$/D', $text) === 1 => ['DEFAULT ' . $text, null],
            default => [null, "its default ({$text}) is an expression"],
        };
    }

    private static function isMysqlOrMariaDb(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private static function isMariaDb(): bool
    {
        return str_contains(strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version), 'mariadb');
    }

    /**
     * Checks that the column is now what it should be and is otherwise as it was.
     *
     * @param Definition $before
     */
    private static function confirm(string $table, string $column, array $before, ?int $length): void
    {
        $after = self::describe($table, $column);
        $isWide = $after !== null && ($length === null
            ? in_array($after['data_type'], self::TEXT_TYPES, true)
            : $after['data_type'] === 'varchar' && (int) $after['length'] >= $length);
        $unchanged = $after !== null
            && $after['nullable'] === $before['nullable']
            && $after['default'] === $before['default']
            && $after['charset'] === $before['charset']
            && $after['collation'] === $before['collation']
            && $after['comment'] === $before['comment']
            && $after['index_count'] === $before['index_count'];

        if (!$isWide || !$unchanged) {
            throw new RuntimeException("{$table}.{$column} was altered but does not read back as " . strtolower(self::targetType($length)) . " with its other properties unchanged: check it with SHOW CREATE TABLE {$table}.");
        }
    }

    private static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
            throw new RuntimeException("'{$name}' is not a plain table or column name.");
        }

        return '`' . $name . '`';
    }

    private static function refused(string $table, string $column, string $reason): RuntimeException
    {
        return new RuntimeException("{$table}.{$column} was not changed: {$reason}. Nothing was altered; widen the column by hand (ALTER TABLE {$table} MODIFY COLUMN {$column} ...) after looking at SHOW CREATE TABLE {$table}.");
    }
}
