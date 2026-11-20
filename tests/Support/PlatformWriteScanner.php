<?php

namespace Tests\Support;

/**
 * Reads PHP source for what it writes, and answers two questions with the one reader.
 *
 * Which TABLES a piece of code writes (`writesIn`), for the guards that keep one module's tables out of another's: the query builder (a table named in a string, a constant, a
 * variable, an alias, `from()`, a builder kept in a variable and written on a later statement, a join update), raw SQL (a table named, or interpolated), the schema builder, and the
 * platform's models (made, bound, fetched and saved on another statement, or written through a relation). It reads text, not behaviour: what it cannot resolve it reports as "(a table named in a variable)", which
 * the guard's list has to name.
 *
 * Which writes of a COLUMN's value a piece of code makes (`columnWritesIn`), for the guards that keep a column's writers to a known few. A change to the shape of a table is not a
 * write of a value, so the two are told apart: see the method.
 */
final class PlatformWriteScanner
{
    public const UNKNOWN_TABLE = '(a table named in a variable)';

    private const BUILDER_WRITES = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    private const MODEL_WRITES = 'create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    private const SCHEMA_WRITES = 'table|create|drop|dropIfExists|dropColumns|rename';

    /** The first word of a SQL statement that changes rows. A statement that changes the shape of a table (ALTER, CREATE, DROP, RENAME, TRUNCATE) names a column without writing anybody's value. */
    private const SQL_ROW_VERBS = 'update|insert|replace|with';

    private const CONSTANT_NAME = '[A-Z][A-Z0-9_]*';

    private const CONSTANT_REFERENCE = '(?:self|static|parent|[A-Z]\\w*)::';

    /** How the SQL of this project wraps an identifier: MySQL backticks, ANSI double quotes, or nothing. */
    private const SQL_IDENTIFIER = '[`"]?';

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
     * Every write of one column's value in a piece of PHP source: an assignment to the attribute, an array key given a value that is not a string (a cast or a name map is one, an
     * update or a fill is not), an increment or a decrement, setAttribute, and SQL that sets the column or inserts it.
     *
     * A change to the shape of a table is not a write of a value, and is told from one by the form of the write, not by a list of files that are allowed to make it: `ALTER TABLE
     * users MODIFY wallet_balance DECIMAL(40,20)`, `$table->decimal('wallet_balance')->change()` and `dropColumn('wallet_balance')` are none of the forms above, and the SQL side
     * reads only the statements whose verb changes rows.
     *
     * The names the code writes indirectly are read before the forms are looked for, so that a write cannot hide behind the spelling of its column: a constant, a concatenation, an
     * escape sequence, and the variable a loop binds walking a list of column names.
     *
     * @return list<string> the kind of each write found
     */
    public static function columnWritesIn(string $code, string $column): array
    {
        $literals = self::withLiteralsRead($code);
        $read = self::withConstantsRead($literals);
        $name = preg_quote($column, '/');
        $held = self::variablesHolding($read, $column);
        // A group that cannot match, so that the code that binds no variable to the name is read by the name alone.
        $heldName = $held === [] ? '(?!)' : implode('|', array_map(static fn (string $variable): string => preg_quote($variable, '/'), $held));
        $named = '(?:[\'"]' . $name . '[\'"]|' . $heldName . ')';

        $patterns = [
            'assignment' => '/->\{?[\'"]?(?:' . $name . '|' . $heldName . ')[\'"]?\}?\s*(?:[-+*\/.]|\?\?)?=(?![=>])/',
            'array key given a value' => '/' . $named . '\s*=>\s*(?![\s\'"])/',
            'increment or decrement' => '/(?:increment|decrement)(?:Quietly)?\(\s*' . $named . '/',
            'setAttribute' => '/setAttribute\(\s*' . $named . '/',
        ];

        $writes = [];
        foreach ($patterns as $kind => $pattern) {
            $writes = array_merge($writes, array_fill(0, preg_match_all($pattern, $read), $kind));
        }
        foreach (self::stringsIn($literals) as $string) {
            $writes = array_merge($writes, self::sqlWritesOf($string, $column));
        }

        return $writes;
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
        foreach (self::tokensOf($code) as $token) {
            if ($token === ';') {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= is_array($token) ? $token[1] : $token;
            }
        }
        $statements[] = $current;

        return $statements;
    }

    /**
     * The code's tokens with the open tag and the comments left out, so that no guard reads a write that is commented out.
     *
     * @return list<array{int, string, int}|string>
     */
    private static function tokensOf(string $code): array
    {
        $tokens = token_get_all(str_starts_with(ltrim($code), '<?php') ? $code : '<?php ' . $code);

        return array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /** @return array<string, string> the string constants a file defines, by name */
    private function constantsIn(string $code): array
    {
        preg_match_all('/\bconst\s+(' . self::CONSTANT_NAME . ')\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', $code, $found, PREG_SET_ORDER);

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
        if (preg_match('/DB::(?:statement|unprepared|insert|update|delete|affectingStatement)\(|->(?:statement|unprepared|affectingStatement)\(/', $statement) !== 1) {
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

    /**
     * The code with its string literals read as PHP reads them: the comments left out, the escape sequences of the double-quoted strings and heredocs read, and the concatenated
     * literals joined. `"wallet\x5fbalance"` and `'wallet' . '_balance'` both become `'wallet_balance'`. The constants are left alone here, so that the SQL of a statement is read
     * once however many times the code names the constant that holds it.
     */
    private static function withLiteralsRead(string $code): string
    {
        return self::withConcatenationJoined(self::withEscapeSequencesRead($code));
    }

    /** The code rebuilt from its tokens, the comments left out, with the escape sequences PHP reads inside a double-quoted string or a heredoc read the same way and the quotes of a string left escaped so that it is still a string. */
    private static function withEscapeSequencesRead(string $code): string
    {
        $read = '';
        foreach (self::tokensOf($code) as $token) {
            $read .= match (true) {
                !is_array($token) => $token,
                $token[0] === T_CONSTANT_ENCAPSED_STRING && str_starts_with($token[1], '"') => '"' . self::unescaped(substr($token[1], 1, -1)) . '"',
                $token[0] === T_ENCAPSED_AND_WHITESPACE => self::unescaped($token[1]),
                default => $token[1],
            };
        }

        return $read;
    }

    private static function unescaped(string $string): string
    {
        return str_replace('"', '\"', stripcslashes($string));
    }

    /** The code with its concatenated string literals joined, which the name of a column may be written as. */
    private static function withConcatenationJoined(string $code): string
    {
        do {
            $code = (string) preg_replace('/([\'"])([^\'"\\\\]*)\1\s*\.\s*([\'"])([^\'"\\\\]*)\3/', '\'$2$4\'', $code, -1, $joined);
        } while ($joined > 0);

        return $code;
    }

    /** The code with the string and list constants it defines standing for their values wherever it names them, so that a column named `self::WALLET` is named plainly. */
    private static function withConstantsRead(string $code): string
    {
        preg_match_all('/\bconst\s+(' . self::CONSTANT_NAME . ')\s*=\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])\s*;/', $code, $defined, PREG_SET_ORDER);

        foreach ($defined as [, $name, $value]) {
            $code = (string) preg_replace_callback('/' . self::CONSTANT_REFERENCE . $name . '\b/', static fn (): string => $value, $code);
        }

        return $code;
    }

    /**
     * The variables that may hold the column's name: one it is given to, and the one a loop binds walking a list that holds it (the list written out, or a constant read above).
     *
     * @return list<string>
     */
    private static function variablesHolding(string $code, string $column): array
    {
        $quoted = '[\'"]' . preg_quote($column, '/') . '[\'"]';
        $listWithTheName = '\[[^\]]*' . $quoted . '[^\]]*\]';
        $holders = [];
        $lists = [$listWithTheName];

        preg_match_all('/(\$\w+)\s*=\s*' . $quoted . '\s*;/', $code, $given, PREG_SET_ORDER);
        foreach ($given as [, $variable]) {
            $holders[] = $variable;
        }

        preg_match_all('/(\$\w+)\s*=\s*' . $listWithTheName . '\s*;/', $code, $assigned, PREG_SET_ORDER);
        foreach ($assigned as [, $variable]) {
            $lists[] = preg_quote($variable, '/');
        }

        preg_match_all('/foreach\s*\(\s*(?:' . implode('|', $lists) . ')\s+as\s+(?:\$\w+\s*=>\s*)?(\$\w+)\s*\)/', $code, $looped, PREG_SET_ORDER);
        foreach ($looped as [, $variable]) {
            $holders[] = $variable;
        }

        return array_values(array_unique($holders));
    }

    /** @return list<string> the text of every string literal in the code, the heredocs among them */
    private static function stringsIn(string $code): array
    {
        $strings = [];
        foreach (self::tokensOf($code) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = substr($token[1], 1, -1);
            }
            if ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
                $strings[] = $token[1];
            }
        }

        return $strings;
    }

    /**
     * The writes of a column in a piece of SQL, one for each statement that makes one: a statement whose verb changes rows and which assigns the column (`SET wallet_balance = 0`,
     * with the identifier wrapped or qualified or not), or which lists it among the columns it inserts. A statement that changes the shape of a table names the column and writes
     * nobody's value, so its verb is not read.
     *
     * @return list<string>
     */
    private static function sqlWritesOf(string $sql, string $column): array
    {
        $name = '(?<!\w)' . self::SQL_IDENTIFIER . preg_quote($column, '/') . self::SQL_IDENTIFIER;
        $writes = [];

        foreach (explode(';', $sql) as $statement) {
            if (preg_match('/^\s*(?:' . self::SQL_ROW_VERBS . ')\b/i', $statement) !== 1) {
                continue;
            }
            if (preg_match('/' . $name . '\s*=(?!=)/i', $statement) === 1) {
                $writes[] = 'raw SQL set';
            } elseif (preg_match('/\b(?:insert|replace)\s+(?:ignore\s+)?into\b[^(]*\([^)]*' . $name . '/i', $statement) === 1) {
                $writes[] = 'raw SQL insert';
            }
        }

        return $writes;
    }
}
