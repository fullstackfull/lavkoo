<?php

namespace Tests\Support;

/**
 * Reads the code of the cost and sales-channel modules for every way it could write a table that is not its own: the query builder (a table named in a string, a constant, a
 * variable, an alias, `from()`, a builder kept in a variable and written on a later statement, a join update), raw SQL (a table named, or interpolated), the schema builder, and the
 * platform's models (made, bound, fetched and saved on another statement, or written through a relation). It reads text, not behaviour: what it cannot resolve it reports as "(a table named in a variable)", which
 * the guard's list has to name.
 *
 * `namesColumnIn()` asks the same question of one column name instead of a table, with the same resolution and the same report of what it cannot read, for a guard that has to show
 * that nothing in the application reads a column.
 */
final class PlatformWriteScanner
{
    public const UNKNOWN_TABLE = '(a table named in a variable)';

    public const UNKNOWN_COLUMN = '(a column named where this scan cannot read it)';

    public const COLUMN_READ_OR_WRITTEN = '(the column named where a query takes the column to read or write)';

    /**
     * Where a query builder, or a model, is handed one column name or a list of them AS ITS FIRST ARGUMENT: the places a name READS or
     * WRITES that column rather than merely appearing. A name arriving at any of these as a variable or a call is a name this scan cannot
     * read; the same name spelled out is a read or a write of it.
     *
     * `find()` and `paginate()` are deliberately absent: their first argument is a key or a page size and only their second is a column
     * list, so watching them here would report every `->find($id)` of an account and say nothing about a column. A name reaching one of
     * them is instead seen where it is built, or where the result it returns is indexed.
     */
    private const COLUMN_POSITIONS = 'value|select|addSelect|selectRaw|pluck|get|first|firstOrFail|sole'
        . '|where|whereNot|whereNull|whereNotNull|whereIn|whereNotIn|whereRaw|whereColumn|whereBetween|whereNotBetween'
        . '|whereDate|whereTime|whereYear|whereMonth|whereDay|whereLike'
        . '|orWhere|orWhereNot|orWhereNull|orWhereNotNull|orWhereIn|orWhereNotIn|orWhereRaw|orWhereColumn|orWhereBetween|orWhereLike'
        . '|orderBy|orderByDesc|orderByRaw|latest|oldest|reorder|groupBy|groupByRaw|having|havingRaw'
        . '|sum|avg|min|max|increment|decrement|incrementEach|decrementEach'
        . '|getAttribute|setAttribute|getOriginal|getRawOriginal|offsetGet|offsetSet';

    /** Where a column name is a key of an array. The array itself may be a variable — then the keys are in the variable, not here. */
    private const COLUMN_KEY_POSITIONS = 'update|updateOrInsert|insert|insertGetId|insertOrIgnore|upsert';

    /**
     * Every way a whole statement of SQL is handed to the database. Read both for the table such a statement writes and for a column name
     * built into one, so the two questions cannot drift apart: the narrower list the column question once kept of its own was how
     * `DB::select` and `DB::update` with an interpolated column name went unseen.
     */
    private const RAW_SQL_STATEMENTS = 'DB::(?:statement|unprepared|insert|update|delete|select|selectOne|scalar|affectingStatement)\(|->(?:statement|unprepared|affectingStatement)\(';

    /** Raw SQL handed to a query builder as a fragment rather than a whole statement, which is the other way a column name reaches the database. */
    private const RAW_SQL_FRAGMENTS = 'DB::raw\(|->raw\(|\w*Raw\(';

    private const BUILDER_WRITES = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    private const MODEL_WRITES = 'create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    private const SCHEMA_WRITES = 'table|create|drop|dropIfExists|dropColumns|rename';

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
            if ($tables !== [] && !$writes && preg_match('/^\s*(\$\w+)\s*=/', $statement, $variable) === 1) {
                $builders[$variable[1]] = $tables[0];
            }
            if (preg_match('/(\$\w+)\s*->(?:' . self::BUILDER_WRITES . ')\(/', $statement, $variable) === 1 && isset($builders[$variable[1]])) {
                array_push($written, ...$builders[$variable[1]]);
            }

            array_push($written, ...$this->rawSqlWrites($statement), ...$this->schemaWrites($statement, $constants));
            array_push($written, ...$this->modelWrites($statement, $models));
        }

        $written = array_values(array_unique($written));
        sort($written);

        return $written;
    }

    /**
     * Every way a piece of code names one column.
     *
     * The name is resolved the way a table name is: a string constant of the file stands for its value, a string written in pieces
     * (`'sharing' . '_group'`) is read whole, and the case the database ignores in a column name is ignored here too. Comments are not code,
     * so prose that explains the column does not count, while a quoted string, an identifier (`->sharing_group`), raw SQL and the markup of a
     * Blade file all do, each being a way to read or write it.
     *
     * A name this scan CANNOT read is reported as UNKNOWN_COLUMN rather than passing silently, so the guard's list has to name the file and a
     * person has to read it: a file that reaches one of `$tables` and puts a variable, a call, a dynamic array key or a property read by name
     * where a column name goes. An array handed to `insert()` whole is not that — its keys are in the array, and the file spells them there.
     *
     * @param bool $asPhp false for a file that is not PHP (JavaScript, JSON, SQL, a template): it is read as text, since there are no tokens to read
     * @param list<string> $tables the tables, and the models of them, whose column names matter
     * @return list<string> the column where the code names it, UNKNOWN_COLUMN where this scan cannot read the name, sorted
     */
    public function namesColumnIn(string $code, string $column, bool $asPhp, array $tables = []): array
    {
        $resolved = $this->withPiecesReadWhole($code);
        $named = [];

        if (stripos($resolved, $column) !== false && (!$asPhp || $this->namesItOutsideAComment($resolved, $column))) {
            $named[] = $column;

            if ($asPhp && $this->columnPositionsHold($resolved, column: $column)) {
                $named[] = self::COLUMN_READ_OR_WRITTEN;
            }
        }

        if ($asPhp && $this->reaches($resolved, $tables) && $this->aColumnNameIsBuilt($resolved)) {
            $named[] = self::UNKNOWN_COLUMN;
        }

        sort($named);

        return $named;
    }

    /** The code with its string constants standing for their values, the escape sequences of its strings read, and its concatenated string literals joined, which a column name may be written as. */
    private function withPiecesReadWhole(string $code): string
    {
        $read = $code;

        foreach ($this->constantsIn($code) as $name => $value) {
            $read = (string) preg_replace('/(?:self|static|[A-Z]\w*)::' . preg_quote($name, '/') . '\b/', "'" . $value . "'", $read);
        }

        $read = $this->withEscapeSequencesRead($read);
        $read = $this->withLiteralPiecesJoined($read);

        do {
            $read = (string) preg_replace('/([\'"])([^\'"\\\\]*)\1\s*\.\s*([\'"])([^\'"\\\\]*)\3/', '$1$2$4$1', $read, -1, $joined);
        } while ($joined > 0);

        return $read;
    }

    /**
     * The code with the escape sequences PHP reads inside a double-quoted string or a heredoc read the same way, so that `"sharing\x5fgroup"`
     * is the name it spells once the engine sees it. A single-quoted string is left alone, PHP reading no escapes in one. The folding happens
     * before literals are joined, so a name written in pieces across an escape is read whole as well.
     *
     * A literal is rewritten in place and quoted again, so what comes back is still the code this scan tokenizes. A single-quoted string
     * and a nowdoc are left exactly as they are, because PHP reads no escape in either: `'sharing\x5fgroup'` names a column of that literal
     * name, which no table has, and reporting it would be a finding about nothing.
     */
    private function withEscapeSequencesRead(string $code): string
    {
        $read = (string) preg_replace_callback(
            '/"((?:[^"\\\\]|\\\\.)*)"/',
            static fn (array $literal): string => '"' . addcslashes(stripcslashes($literal[1]), '"\\\\') . '"',
            $code,
        );

        return (string) preg_replace_callback(
            '/(<<<\s*"?[A-Za-z_]\w*"?\R)(.*?)(\R\s*[A-Za-z_]\w*\b)/s',
            static fn (array $heredoc): string => $heredoc[1] . stripcslashes($heredoc[2]) . $heredoc[3],
            $read,
        );
    }

    /**
     * The code with `implode('_', ['sharing', 'group'])` standing for the string it makes, which is the same thing as `'sharing' . '_group'`
     * written another way and the way a name is hidden in practice. Only a literal separator over a list of literals is read: anything the
     * pieces are computed from is a name this scan cannot read, and is reported as one where it reaches an account table.
     */
    private function withLiteralPiecesJoined(string $code): string
    {
        return (string) preg_replace_callback(
            '/\b(?:implode|join)\s*\(\s*(?:([\'"])([^\'"]*)\1\s*,\s*)?\[\s*((?:[\'"][^\'"]*[\'"]\s*,\s*)*[\'"][^\'"]*[\'"])\s*,?\s*\]\s*\)/',
            static function (array $call): string {
                preg_match_all('/[\'"]([^\'"]*)[\'"]/', $call[3], $pieces);

                return "'" . implode($call[2] ?? '', $pieces[1]) . "'";
            },
            $code,
        );
    }

    /** Whether PHP code names the column as code rather than in a comment. Only asked of a file that names it, so the tokens are read for a handful of files. */
    private function namesItOutsideAComment(string $code, string $column): bool
    {
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) && stripos($token[1], $column) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tables */
    private function reaches(string $code, array $tables): bool
    {
        foreach ($tables as $table) {
            if (stripos($code, $table) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Whether the code puts something other than a written-out name where a column name goes: a variable, a call, a dynamic array key or a property read by name. */
    private function aColumnNameIsBuilt(string $code): bool
    {
        return $this->columnPositionsHold($code, column: null);
    }

    /**
     * Whether any place a query takes the column to read or write holds the name: `$column` spelled out, or — for `$column` null — a name
     * this scan cannot read. One method answers both, so the list of places cannot answer one question and not the other: a narrower list
     * kept for the unreadable-name question alone was how `DB::select` and `DB::update` with an interpolated column name went unseen.
     *
     * Each shape of position is asked in the same two forms. A name handed to a builder (`->where(NAME`), a name that is a key of an array
     * handed to a write (`->update([NAME =>`), a name built into raw SQL, a name read off an object as a property (`->NAME` against
     * `->{$built}`) and a name indexing a result (`getAttributes()[NAME]`).
     */
    private function columnPositionsHold(string $code, ?string $column): bool
    {
        $quoted = $column === null ? null : preg_quote($column, '/');
        $name = $quoted === null ? '(?:\$|\[\s*\$|[A-Za-z_]\w*(?:::|\s*\())' : '[\'"]' . $quoted . '[\'"]';
        $key = $quoted === null ? '(?:[^\]]*?,)?\s*\$[^\s=]*\s*=>' : '[^\]]*?' . $name . '\s*=>';
        $inRawSql = $quoted ?? '\$';
        $property = $quoted === null ? '->\{' : '->' . $quoted . '\b';
        $index = $quoted === null ? '\)\s*\[\s*\$' : '(?:\w|\)|\]|\})\s*\[\s*' . $name . '\s*\]';

        foreach ($this->statementsOf($code) as $statement) {
            $holds = preg_match('/->(?:' . self::COLUMN_POSITIONS . ')\(\s*(?:\[\s*)?' . $name . '/i', $statement) === 1
                || preg_match('/->(?:' . self::COLUMN_KEY_POSITIONS . ')\(\s*\[' . $key . '/i', $statement) === 1
                || preg_match('/(?:' . self::RAW_SQL_STATEMENTS . '|' . self::RAW_SQL_FRAGMENTS . ')[^;]*' . $inRawSql . '/i', $statement) === 1
                || preg_match('/' . $property . '/i', $statement) === 1
                || preg_match('/' . $index . '/i', $statement) === 1;

            if ($holds) {
                return true;
            }
        }

        return false;
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
        preg_match_all('/(?:DB::table|->table|->from|->join|->leftJoin|->rightJoin|->crossJoin|->joinSub)\(\s*(?:([\'"])([^\'"]+)\1|(?:self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)|(\$))/', $statement, $found, PREG_SET_ORDER);

        $tables = [];
        foreach ($found as $match) {
            $tables[] = match (true) {
                ($match[2] ?? '') !== '' => [strtolower(preg_split('/\s+as\s+/i', trim($match[2]))[0])],
                ($match[3] ?? '') !== '' => isset($constants[$match[3]]) ? [$constants[$match[3]]] : [strtolower($match[3]), self::UNKNOWN_TABLE],
                default => [self::UNKNOWN_TABLE],
            };
        }

        return $tables;
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
        if (preg_match('/' . self::RAW_SQL_STATEMENTS . '/', $statement) !== 1) {
            return [];
        }
        preg_match_all('/\b(?:insert\s+(?:ignore\s+)?into|replace\s+into|update|delete\s+from|truncate\s+table|alter\s+table|drop\s+table|create\s+table|rename\s+table)\s+[`"\']?(\{?\$\w+\}?|[a-z0-9_]+)/i', $statement, $found);

        return array_map(static fn (string $table): string => str_contains($table, '$') ? self::UNKNOWN_TABLE : strtolower($table), $found[1]);
    }

    /**
     * @param array<string, string> $constants
     * @return list<string>
     */
    private function schemaWrites(string $statement, array $constants): array
    {
        preg_match_all('/Schema::(?:' . self::SCHEMA_WRITES . ')\(\s*(?:([\'"])([a-z0-9_]+)\1|(?:self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)|(\$))/', $statement, $found, PREG_SET_ORDER);

        return array_map(static fn (array $match): string => ($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? ($constants[$match[3]] ?? self::UNKNOWN_TABLE) : self::UNKNOWN_TABLE), $found);
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

        if (preg_match('/^\s*(\$\w+)\s*=\s*.*\b(' . $names . ')\s*::/s', $statement, $held) === 1 || preg_match('/foreach\s*\(.*\b(' . $names . ')\s*::.*\bas\s+(?:\$\w+\s*=>\s*)?(\$\w+)\)/s', $statement, $loop) === 1) {
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
