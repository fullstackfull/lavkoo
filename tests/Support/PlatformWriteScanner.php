<?php

namespace Tests\Support;

/**
 * Reads PHP for every way it could write a table that is not its own — the cost and sales-channel modules, and the migrations of the GCC chain against the tables that hold the Saudi
 * platform's customers (`tests/Feature/Storefront/CustomerTablesAreNotRewrittenTest`): the query builder (a table named in a string, a constant, a variable, a property, an alias,
 * `from()`, half a string, a builder kept until a later statement, a join update), raw SQL (a table named, qualified by its database, or interpolated), the SQL of a string handed to
 * anything at all, the schema builder, and the platform's models (made, bound, fetched and saved on another statement, imported under another name, or written through a relation).
 * It reads text, not behaviour: what it cannot resolve it reports as "(a table named in a variable)", which the guard's list has to name.
 */
final class PlatformWriteScanner
{
    public const UNKNOWN_TABLE = '(a table named in a variable)';

    private const BUILDER_WRITES = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    private const MODEL_WRITES = 'create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    private const SCHEMA_WRITES = 'table|create|drop|dropIfExists|dropColumns|rename';

    private const RAW_WRITE_VERBS = 'update|insert|delete|replace|truncate|alter|drop';

    /**
     * The start of a variable taking the value of the rest of the statement. A statement is what lies between two semicolons, so an assignment that is the first thing a method does
     * comes with the braces and the signature above it: anchoring this to the start of the statement alone made the scanner blind to a builder or a model held by such an
     * assignment, which is most of them. The brace has to be the thing before it, so that `as $key => $value` is not read as one.
     */
    private const ASSIGNMENT = '(?:^|[{}])\s*(\$\w+(?:->\w+)*)\s*=';

    /** @param list<string> $platformModels the platform's models whose save, create, update or delete would write one of its tables */
    public function __construct(private readonly array $platformModels)
    {
    }

    /**
     * Every table (or platform model) the code writes, whatever the way.
     *
     * @return list<string> sorted
     */
    public function writesIn(string $code): array
    {
        return $this->writtenIn($code, throughTheSchemaBuilderToo: true);
    }

    /**
     * Every table (or platform model) whose rows the code writes: the query builder, a builder kept in a variable, the platform's models, and raw SQL (whatever that SQL does — a raw
     * statement against a table is past what reading text can judge). What the schema builder does is not one of them, because a column or an index added with it leaves every row
     * as it was; a caller that has to see those asks `writesIn()` instead.
     *
     * @return list<string> sorted
     */
    public function rowWritesIn(string $code): array
    {
        return $this->writtenIn($code, throughTheSchemaBuilderToo: false);
    }

    /**
     * Which of the given tables one of the code's own strings names in a write, whatever that string is handed to: `whereRaw`, `selectRaw`, `DB::raw`, a PDO call, a string built on
     * one line and run on another. `rowWritesIn()` reads the SQL of the calls it knows; this reads the SQL wherever it is, because a scanner cannot see who ends up running a string.
     * Only the code's strings are read, so a sentence in a comment is not one, and the tables have to be named because free SQL text does not say which of its words is a table.
     *
     * @param list<string> $tables
     * @return list<string> sorted
     */
    public function tablesAStringWrites(string $code, array $tables): array
    {
        $written = [];

        foreach ($this->stringsOf($code) as $string) {
            foreach ($tables as $table) {
                if (preg_match('/\b(?:' . self::RAW_WRITE_VERBS . ')\b[^;]*\b' . preg_quote($table, '/') . '\b/i', $string) === 1) {
                    $written[] = $table;
                }
            }
        }

        $written = array_values(array_unique($written));
        sort($written);

        return $written;
    }

    /** @return list<string> the text of every string the code holds, interpolated or not */
    private function stringsOf(string $code): array
    {
        $strings = [];
        foreach (token_get_all('<?php ' . $code) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $strings[] = $token[1];
            }
        }

        return $strings;
    }

    /** @return list<string> sorted */
    private function writtenIn(string $code, bool $throughTheSchemaBuilderToo): array
    {
        $constants = $this->constantsIn($code);
        $written = [];
        $builders = [];
        $models = [];

        foreach ($this->statementsOf($code) as $statement) {
            $tables = $this->tablesNamedIn($statement, $constants);
            $writes = preg_match('/->(?:' . self::BUILDER_WRITES . ')\(/', $statement) === 1;

            if ($tables !== [] && $writes) {
                array_push($written, ...$this->tablesWritten($statement, $tables));
            }
            if ($tables !== [] && !$writes && preg_match('/' . self::ASSIGNMENT . '[^=]/', $statement, $variable) === 1) {
                $builders[$variable[1]] = $tables[0];
            }
            if (preg_match('/(\$\w+(?:->\w+)*)\s*->(?:' . self::BUILDER_WRITES . ')\(/', $statement, $variable) === 1 && isset($builders[$variable[1]])) {
                array_push($written, ...$builders[$variable[1]]);
            }

            array_push($written, ...$this->rawSqlWrites($statement));
            if ($throughTheSchemaBuilderToo) {
                array_push($written, ...$this->schemaWrites($statement, $constants));
            }
            array_push($written, ...$this->modelWrites($statement, $models));
        }

        $written = array_values(array_unique($written));
        sort($written);

        return $written;
    }

    /**
     * The code split at its semicolons, with the comments left out. A semicolon inside a string (several SQL statements in one) does not split it.
     *
     * @return list<string>
     */
    private function statementsOf(string $code): array
    {
        $statements = [];
        $current = '';
        foreach (token_get_all('<?php ' . $code) as $token) {
            if ($token === ';') {
                $statements[] = $current;
                $current = '';
            } elseif (!is_array($token)) {
                $current .= $token;
            } elseif (!in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT], true)) {
                $current .= $token[1];
            }
        }
        $statements[] = $current;

        return $statements;
    }

    /** @return array<string, string> the string constants a file defines, by name */
    private function constantsIn(string $code): array
    {
        preg_match_all('/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $code, $found, PREG_SET_ORDER);

        return array_column($found, 2, 1);
    }

    /**
     * The tables a statement names to a query builder: `DB::table('x')`, `->table('x')`, `->from('x')`, `->join('x')`, with an alias or not, by a constant or by a variable. One entry for
     * each place a table is named, in the order they are named: the first is the table written, the others are joined. A constant defined elsewhere is both the table its name says
     * (`self::PRODUCTS` is `products`) and a table named in a variable, because the file does not say.
     *
     * @param array<string, string> $constants
     * @return list<list<string>> the names each place may stand for
     */
    private function tablesNamedIn(string $statement, array $constants): array
    {
        preg_match_all('/(?:DB::table|->table|->from|->join|->leftJoin|->rightJoin|->crossJoin|->joinSub)\(\s*(?:([\'"])([^\'"]+)\1|(?:self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)|(\$))(\s*\.)?/', $statement, $found, PREG_SET_ORDER);

        $tables = [];
        foreach ($found as $match) {
            $names = match (true) {
                ($match[2] ?? '') !== '' => [strtolower(preg_split('/\s+as\s+/i', trim($match[2]))[0])],
                ($match[3] ?? '') !== '' => isset($constants[$match[3]]) ? [$constants[$match[3]]] : [strtolower($match[3]), self::UNKNOWN_TABLE],
                default => [self::UNKNOWN_TABLE],
            };

            $tables[] = $this->withWhatIsMissing($names, joinedToWhatFollows: ($match[5] ?? '') !== '');
        }

        return $tables;
    }

    /**
     * The names a place may stand for, with "a table named in a variable" among them when only part of the name is in the code: a string with a variable in it, or a string joined to
     * whatever follows it. Half a name is no name — `DB::table('us' . 'ers')` spells `us` — and what is missing could make it any table there is.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function withWhatIsMissing(array $names, bool $joinedToWhatFollows): array
    {
        if (in_array(self::UNKNOWN_TABLE, $names, true) || (!$joinedToWhatFollows && !str_contains(implode(' ', $names), '$'))) {
            return $names;
        }

        return [...$names, self::UNKNOWN_TABLE];
    }

    /**
     * The tables a statement with a builder write writes: the one it starts from, and a joined one when the update names a column of it ('alias.column').
     *
     * @param list<list<string>> $tables
     * @return list<string>
     */
    private function tablesWritten(string $statement, array $tables): array
    {
        $written = $tables[0];
        preg_match_all('/(?:->(?:table|join|leftJoin|rightJoin)|DB::table)\(\s*([\'"])([a-z0-9_]+)(?:\s+as\s+([a-z0-9_]+))?\1/i', $statement, $named, PREG_SET_ORDER);
        $aliases = [];
        foreach ($named as $match) {
            $aliases[strtolower($match[3] ?? $match[2])] = strtolower($match[2]);
        }
        preg_match_all('/[\'"]([a-z0-9_]+)\.[a-z0-9_]+[\'"]\s*=>/i', $statement, $qualified);
        foreach ($qualified[1] as $alias) {
            if (isset($aliases[strtolower($alias)])) {
                $written[] = $aliases[strtolower($alias)];
            }
        }

        return $written;
    }

    /** @return list<string> */
    private function rawSqlWrites(string $statement): array
    {
        if (preg_match('/DB::(?:statement|unprepared|insert|update|delete|affectingStatement)\(|->(?:statement|unprepared|affectingStatement)\(/', $statement) !== 1) {
            return [];
        }
        preg_match_all('/\b(?:insert\s+(?:ignore\s+)?into|replace\s+into|update|delete\s+from|truncate\s+table|alter\s+table|drop\s+table|create\s+table|rename\s+table)\s+(?:[`"\']?[a-z0-9_]+[`"\']?\s*\.\s*)?[`"\']?(\{?\$\w+\}?|[a-z0-9_]+)/i', $statement, $found);

        return array_map(static fn (string $table): string => str_contains($table, '$') ? self::UNKNOWN_TABLE : strtolower($table), $found[1]);
    }

    /**
     * @param array<string, string> $constants
     * @return list<string>
     */
    private function schemaWrites(string $statement, array $constants): array
    {
        preg_match_all('/Schema::(?:' . self::SCHEMA_WRITES . ')\(\s*(?:([\'"])([a-z0-9_]+)\1|(?:self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)|(\$))(\s*\.)?/', $statement, $found, PREG_SET_ORDER);

        $written = [];
        foreach ($found as $match) {
            $name = match (true) {
                ($match[2] ?? '') !== '' => $match[2],
                ($match[3] ?? '') !== '' => $constants[$match[3]] ?? self::UNKNOWN_TABLE,
                default => self::UNKNOWN_TABLE,
            };

            array_push($written, ...$this->withWhatIsMissing([$name], joinedToWhatFollows: ($match[5] ?? '') !== ''));
        }

        return $written;
    }

    /**
     * The platform's models a statement writes: made, bound to the container or called statically with a write on the same statement, and a model fetched on one statement and written
     * on a later one (kept in a variable, or the variable of a loop).
     *
     * @param array<string, string> $models the variables that hold a platform model, by variable name (filled as the statements go by)
     * @return list<string>
     */
    private function modelWrites(string $statement, array &$models): array
    {
        $names = implode('|', $this->platformModels);
        $written = [];

        if (preg_match('/new\s+(' . $names . ')\b/', $statement, $new) === 1) {
            $written[] = 'model ' . $new[1];
        } elseif (preg_match('/\b(' . $names . ')\s*::(?:class\b|[a-zA-Z_]+\()/', $statement, $model) === 1
            && preg_match('/(?:->|::)(?:' . self::MODEL_WRITES . ')\(/', $statement) === 1) {
            $written[] = 'model ' . $model[1];
        }

        if (preg_match('/' . self::ASSIGNMENT . '\s*.*\b(' . $names . ')\s*::/s', $statement, $held) === 1 || preg_match('/foreach\s*\(.*\b(' . $names . ')\s*::.*\bas\s+(?:\$\w+\s*=>\s*)?(\$\w+)\)/s', $statement, $loop) === 1) {
            $models[$held[1] ?? $loop[2]] = $held[2] ?? $loop[1];
        }
        if (preg_match('/->\s*(' . implode('|', array_map('lcfirst', $this->platformModels)) . ')(?:\(\))?\s*->\s*(?:' . self::MODEL_WRITES . ')\(/', $statement, $relation) === 1) {
            $written[] = 'model ' . ucfirst($relation[1]);
        }
        foreach ($models as $variable => $model) {
            if (preg_match('/' . preg_quote($variable, '/') . '\s*->\s*(?:' . self::MODEL_WRITES . ')\(/', $statement) === 1 || preg_match('/' . preg_quote($variable, '/') . '\s*->\s*\w+\s*=[^=]/', $statement) === 1) {
                $written[] = 'model ' . $model;
            }
        }

        return $written;
    }
}
