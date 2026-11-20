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
 * Which writes of a COLUMN's value a piece of code makes (`columnWritesIn`), for the guards that keep a column's writers to a known few: an assignment, the array access a model
 * also answers, an array key handed to something that writes it, an increment, setAttribute, and SQL that sets or inserts the column. A change to the shape of a table is not a
 * write of a value, so the two are told apart: see the method.
 *
 * Both sides read text, not behaviour, and a name the file never spells -- one that arrives as an argument, out of config() or off a database row -- is past them. What the file
 * does spell they read however it is spelled: through a constant, a list a constant holds, a concatenation, an escape sequence, a property, or the variable a loop binds.
 */
final class PlatformWriteScanner
{
    public const UNKNOWN_TABLE = '(a table named in a variable)';

    private const BUILDER_WRITES = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    private const MODEL_WRITES = 'create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    private const SCHEMA_WRITES = 'table|create|drop|dropIfExists|dropColumns|rename';

    /** The first word of a SQL statement that changes rows. A statement that changes the shape of a table (ALTER, CREATE, DROP, RENAME, TRUNCATE) names a column without writing anybody's value. */
    private const SQL_ROW_VERBS = 'update|insert|replace|with';

    /**
     * Everything that takes an array of column => value and writes it. The two vocabularies above answer "does this statement write the table"; this one answers "is this array
     * a write", which is what tells a write from a cast or a name map, so it is those two plus setRawAttributes, which writes attributes and no table.
     */
    private const ARRAY_WRITES = self::BUILDER_WRITES . '|' . self::MODEL_WRITES . '|setRawAttributes';

    /** PHP's compound assignment operators, so that a balance rewritten with one of the rarer ones is still a write. */
    private const COMPOUND_ASSIGNMENT = '\*\*|<<|>>|\?\?|[-+*\/.%|&^]';

    /** A parameter passed by name, which this project's own coding standard asks for: `increment(column: 'wallet_balance')` names the column as plainly as `increment('wallet_balance')`. */
    private const NAMED_ARGUMENT = '(?:\w+\s*:\s*)?';

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
        $constants = self::constantsIn($code);
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
     * Every write of one column's value in a piece of PHP source: an assignment to the attribute (through `->`, or through the array access a model also answers), an array key
     * given a value, an increment or a decrement, setAttribute, and SQL that sets the column or inserts it.
     *
     * A change to the shape of a table is not a write of a value, and is told from one by the form of the write, not by a list of files that are allowed to make it: `ALTER TABLE
     * users MODIFY wallet_balance DECIMAL(40,20)`, `$table->decimal('wallet_balance')->change()` and `dropColumn('wallet_balance')` are none of the forms above, and the SQL side
     * reads only the statements whose verb changes rows, and only the part of them that assigns.
     *
     * A cast and a name map also give the column to an array key (`'wallet_balance' => 'float'`), and are told from a write by what the array is handed to and not by whether the
     * value is quoted: `['wallet_balance' => '0']` is an ordinary update, and a database writes 0 for it. See arrayKeyWrites().
     *
     * The names the code spells indirectly are written out before the forms are looked for, so that a write cannot hide behind the spelling of its column: a constant, a list a
     * constant holds read at one of its keys, a concatenation, an escape sequence, a property, and the variable a loop binds walking a list of column names.
     *
     * @return list<string> the kind of each write found
     */
    public static function columnWritesIn(string $code, string $column): array
    {
        $literals = self::withLiteralsRead($code);
        $read = self::withNamesSpelledOut($code);
        $name = preg_quote($column, '/');
        $held = self::expressionsHolding($read, $column);
        // A group that cannot match, so that the code that binds no name to the column is read by the column's own name alone.
        $heldName = $held === [] ? '(?!)' : implode('|', array_map(static fn (string $expression): string => preg_quote($expression, '/'), $held));
        $named = '(?:[\'"]' . $name . '[\'"]|' . $heldName . ')';
        // An update may qualify the column with the table or the alias it belongs to: `['users.wallet_balance' => 0]`.
        $key = '(?:[\'"](?:[a-z0-9_]+\.)?' . $name . '[\'"]|' . $heldName . ')';

        $patterns = [
            'assignment' => '/->\s*\{?[\'"]?(?:' . $name . '|' . $heldName . ')[\'"]?\}?\s*(?:' . self::COMPOUND_ASSIGNMENT . ')?=(?![=>])/',
            'array access assignment' => '/\[\s*' . $named . '\s*\]\s*(?:' . self::COMPOUND_ASSIGNMENT . ')?=(?![=>])/',
            'increment or decrement' => '/(?:increment|decrement)(?:Quietly)?\(\s*' . self::NAMED_ARGUMENT . $named . '/',
            'setAttribute' => '/(?:setAttribute|offsetSet)\(\s*' . self::NAMED_ARGUMENT . $named . '/',
        ];

        $writes = [];
        foreach ($patterns as $kind => $pattern) {
            $writes = array_merge($writes, array_fill(0, preg_match_all($pattern, $read), $kind));
        }
        $writes = array_merge($writes, array_fill(0, self::arrayKeyWrites($read, $key), 'array key given a value'));
        foreach (self::stringsIn($literals) as $string) {
            $writes = array_merge($writes, self::sqlWritesOf($string, $column, $heldName));
        }

        return $writes;
    }

    /**
     * How many places the code gives the column as an array key that is written.
     *
     * A key whose value is a string is counted only where the array is handed to something that writes it, because that is what tells `['wallet_balance' => '0']`, an update, from
     * `['wallet_balance' => 'float']`, a cast, and from `['loyalty_point' => 'loyalty_point']`, a name map. A key whose value is anything else is counted wherever it is written,
     * because the array reaches the write in a variable as readily as in the call itself. The two sets overlap, so the count is their union.
     */
    private static function arrayKeyWrites(string $code, string $key): int
    {
        $handedToAWrite = implode("\n", self::argumentsOf($code, self::ARRAY_WRITES));
        $anyValue = '/' . $key . '\s*=>/';
        // The whitespace is in the class as well as in the quantifier: a quantifier that can give its spaces back would let `=> 'float'` match at the space in front of the quote.
        $valueThatIsNoCast = '/' . $key . '\s*=>\s*(?![\s\'"])/';

        return preg_match_all($anyValue, $handedToAWrite)
            + preg_match_all($valueThatIsNoCast, $code)
            - preg_match_all($valueThatIsNoCast, $handedToAWrite);
    }

    /**
     * The text of the arguments of every call to one of the named methods, the outermost ones only so that no call is read twice. It is taken from the tokens, so that a
     * parenthesis inside a string or a closure does not end the call early.
     *
     * @return list<string>
     */
    private static function argumentsOf(string $code, string $methods): array
    {
        $tokens = self::tokensOf($code);
        $called = '/^(?:' . $methods . ')$/i';
        $arguments = [];
        $count = count($tokens);

        for ($position = 0; $position < $count; $position++) {
            $token = $tokens[$position];
            if (!is_array($token) || $token[0] !== T_STRING || preg_match($called, $token[1]) !== 1) {
                continue;
            }
            $open = $position + 1;
            while ($open < $count && is_array($tokens[$open]) && $tokens[$open][0] === T_WHITESPACE) {
                $open++;
            }
            if ($open >= $count || $tokens[$open] !== '(') {
                continue;
            }
            [$text, $close] = self::textOfTheCallAt($tokens, $open);
            $arguments[] = $text;
            $position = $close;
        }

        return $arguments;
    }

    /**
     * The text from an opening parenthesis to the one that closes it, and the position of that one.
     *
     * @param list<array{int, string, int}|string> $tokens
     * @return array{string, int}
     */
    private static function textOfTheCallAt(array $tokens, int $open): array
    {
        $depth = 0;
        $text = '';
        $count = count($tokens);

        for ($position = $open; $position < $count; $position++) {
            $token = $tokens[$position];
            $text .= is_array($token) ? $token[1] : $token;
            if ($token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === ')' || $token === ']') {
                $depth--;
                if ($depth === 0) {
                    return [$text, $position];
                }
            }
        }

        return [$text, $count - 1];
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

    /**
     * Every constant a file defines, by name, with its value as the file writes it: a quoted string or a list. One definition, read two ways -- the table side wants the name a
     * scalar stands for, the column side substitutes the value wherever it is referenced -- so that a constant one of them reads is a constant the other reads.
     *
     * @return array<string, string>
     */
    private static function constantsDefinedIn(string $code): array
    {
        preg_match_all('/\bconst\s+(' . self::CONSTANT_NAME . ')\s*=\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/', $code, $defined, PREG_SET_ORDER);

        return array_column($defined, 2, 1);
    }

    /** @return array<string, string> the constants a file defines that hold a table's name, by name */
    private static function constantsIn(string $code): array
    {
        $tables = [];
        foreach (self::constantsDefinedIn($code) as $name => $value) {
            if (preg_match('/^([\'"])([a-z0-9_]+)\1$/', $value, $table) === 1) {
                $tables[$name] = $table[2];
            }
        }

        return $tables;
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
        foreach (self::constantsDefinedIn($code) as $name => $value) {
            $code = (string) preg_replace_callback('/' . self::CONSTANT_REFERENCE . $name . '\b/', static fn (): string => $value, $code);
        }

        return $code;
    }

    /**
     * The code with every name it spells indirectly written out: the escape sequences read, the concatenated literals joined, the constants read, and a list a constant holds read
     * at one of its keys. A guard that looks for a column's name in the source looks for it here, so that `'wallet' . '_balance'` is not a way past it.
     */
    public static function withNamesSpelledOut(string $code): string
    {
        return self::withIndexedListsRead(self::withConstantsRead(self::withLiteralsRead($code)));
    }

    /** A list read at one of its keys standing for the name it holds there, so that a writer cannot spell the column by indexing a map of its own: `self::MONEY['wallet']`. */
    private static function withIndexedListsRead(string $code): string
    {
        return (string) preg_replace_callback(
            '/\[([^\[\]]*)\]\s*\[\s*([\'"])([a-z0-9_]+)\2\s*\]/',
            static function (array $indexed): string {
                preg_match_all('/([\'"])([a-z0-9_]+)\1\s*=>\s*([\'"])([a-z0-9_]+)\3/', $indexed[1], $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    if ($pair[2] === $indexed[3]) {
                        return "'" . $pair[4] . "'";
                    }
                }

                return $indexed[0];
            },
            $code,
        );
    }

    /**
     * Every way the code may spell the column's name without writing it out: a name it is given to, the one a loop binds walking a list that holds it (the list written out, or a
     * constant read above), and -- because a writer keeps the name in a property as readily as in a local -- that same name read off `$this`, `self` or `static`.
     *
     * @return list<string>
     */
    private static function expressionsHolding(string $code, string $column): array
    {
        $quoted = '[\'"]' . preg_quote($column, '/') . '[\'"]';
        $listWithTheName = '\[[^\]]*' . $quoted . '[^\]]*\]';
        $bound = [];
        $lists = [$listWithTheName];

        preg_match_all('/(\$\w+)\s*=\s*' . $quoted . '\s*;/', $code, $given, PREG_SET_ORDER);
        foreach ($given as [, $variable]) {
            $bound[] = $variable;
        }

        preg_match_all('/(\$\w+)\s*=\s*' . $listWithTheName . '\s*;/', $code, $assigned, PREG_SET_ORDER);
        foreach ($assigned as [, $variable]) {
            $lists[] = preg_quote($variable, '/');
        }

        preg_match_all('/foreach\s*\(\s*(?:' . implode('|', $lists) . ')\s+as\s+(?:\$\w+\s*=>\s*)?(\$\w+)\s*\)/', $code, $looped, PREG_SET_ORDER);
        foreach ($looped as [, $variable]) {
            $bound[] = $variable;
        }

        $holders = $bound;
        foreach ($bound as $variable) {
            $property = substr($variable, 1);
            array_push($holders, '$this->' . $property, 'self::' . $variable, 'static::' . $variable);
        }

        return array_values(array_unique($holders));
    }

    /**
     * The text of every string in the code: a plain literal, a heredoc, and a double-quoted string put back together with the variables it interpolates where they stand, so that
     * the SQL of `"update users set {$column} = 0"` is read as the one statement it is and not as the two pieces around the variable.
     *
     * @return list<string>
     */
    private static function stringsIn(string $code): array
    {
        $strings = [];
        $interpolated = null;

        foreach (self::tokensOf($code) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $opens = $token === '"' || (is_array($token) && $token[0] === T_START_HEREDOC);
            $closes = $token === '"' || (is_array($token) && $token[0] === T_END_HEREDOC);

            if ($interpolated === null && $opens) {
                $interpolated = '';
            } elseif ($interpolated !== null && $closes) {
                $strings[] = $interpolated;
                $interpolated = null;
            } elseif ($interpolated !== null) {
                $interpolated .= $text;
            } elseif (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = substr($text, 1, -1);
            }
        }

        return $strings;
    }

    /**
     * The writes of a column in a piece of SQL, one for each statement that makes one: a statement whose verb changes rows and which assigns the column (`SET wallet_balance = 0`,
     * with the identifier wrapped or qualified or interpolated from a variable that holds the name, or not), or which lists it among the columns it inserts. A statement that
     * changes the shape of a table names the column and writes nobody's value, so its verb is not read.
     *
     * The verb is the first word of the statement, so the comments are stripped before it is read: a block comment or a `-- reset` line in front of the verb is not a way past this. A WHERE
     * clause filters on a column and assigns nothing, so it is cut away before the assignment is looked for: `update users set is_active = 0 where wallet_balance = 0` reads a
     * balance and writes none.
     *
     * A string anywhere in the file that spells a row-changing statement assigning the column is reported, whether or not that string is handed to the database on the same
     * statement, because SQL reaches the database in a variable as often as in the call. The cost is that a message quoting such a statement is reported too; a false alarm a
     * person can read is cheaper here than a write nobody sees.
     *
     * @param string $heldName the expressions that hold the column's name, as a regular expression
     * @return list<string>
     */
    private static function sqlWritesOf(string $sql, string $column, string $heldName): array
    {
        $name = '(?<!\w)(?:' . self::SQL_IDENTIFIER . preg_quote($column, '/') . self::SQL_IDENTIFIER . '|\{?(?:' . $heldName . ')\}?)';
        $writes = [];

        foreach (self::sqlStatementsOf(self::withSqlCommentsStripped($sql)) as $statement) {
            if (preg_match('/^\s*(?:' . self::SQL_ROW_VERBS . ')\b/i', $statement) !== 1) {
                continue;
            }
            $assigning = (string) preg_replace('/\bwhere\b.*$/is', '', $statement);
            if (preg_match('/' . $name . '\s*=(?!=)/i', $assigning) === 1) {
                $writes[] = 'raw SQL set';
            } elseif (preg_match('/\b(?:insert|replace)\s+(?:ignore\s+)?into\b[^(]*\([^)]*' . $name . '/i', $assigning) === 1) {
                $writes[] = 'raw SQL insert';
            }
        }

        return $writes;
    }

    /** SQL without its comments, so that the verb a statement starts with is the verb and not a comment in front of it. */
    private static function withSqlCommentsStripped(string $sql): string
    {
        return (string) preg_replace(['/\/\*.*?\*\//s', '/(?:--[ \t]|#)[^\n]*/'], '', $sql);
    }

    /**
     * SQL split into its statements. A semicolon inside a quoted literal belongs to the literal: `set remark = 'cleared; see ticket', wallet_balance = 0` is one statement, and
     * cutting it in two left the second piece starting with no verb at all.
     *
     * @return list<string>
     */
    private static function sqlStatementsOf(string $sql): array
    {
        $statements = [];
        $current = '';
        $quote = null;
        $length = strlen($sql);

        for ($position = 0; $position < $length; $position++) {
            $character = $sql[$position];

            if ($quote !== null) {
                $current .= $character;
                if ($character === '\\') {
                    $current .= $sql[++$position] ?? '';
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === ';') {
                $statements[] = $current;
                $current = '';
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            }
            $current .= $character;
        }
        $statements[] = $current;

        return $statements;
    }
}
