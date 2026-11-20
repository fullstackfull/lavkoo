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
 *
 * THE COLUMN SIDE FAILS OPEN, and that is the one rule of it. A string of SQL or an array that names the column is REPORTED unless this class can prove it moves no money,
 * because the proof is what it can check and the absence of one is not. Gating it the other way round -- reporting only what could be followed into a write -- is how this
 * class lost 21 money writes in one change: the SQL side was gated on a whitelist of receiver methods (statement, unprepared, insert, update, delete, affectingStatement),
 * and `DB::select` and a PDO `exec` run arbitrary SQL on MySQL and MariaDB and are not on it, nor is any list of names ever complete. So the gate is gone: every string the
 * file holds is read as SQL (sqlStringsIn) and every array key is read where it stands (arrayKeyWrites), and what tells a write from a read is the STATEMENT -- is the column
 * in the clause that assigns, or only in a WHERE, a CTE, a JOIN condition, an ORDER BY or a window expression (sqlWritesOf) -- and the VALUE -- is it a cast name, a name map,
 * a translation, a setting, a class or a list rather than an amount (isProvablyNotAnAmount). Where the column appears in a statement and what the statement is handed to are
 * two questions, and answering the first is what buys the quiet on the second.
 *
 * The price of failing open is a false alarm a person can read: a message that quotes a money write (`Log::info("update users set wallet_balance = 0 was refused")`) and an
 * array of amounts built and handed on to a name this file never spells are both reported. That is the cheaper mistake, and the guards' lists name what is reported.
 *
 * The column side's limits are declared where they are taken: an array reaches a resolvable write in one hop and no more (arraysHandedToAWrite) -- which now only ever ADDS
 * reports, so a hop it cannot follow costs nothing -- and a model attribute written through array access is read under the column's own name and not under a name a variable
 * or a constant holds (columnWritesIn).
 */
final class PlatformWriteScanner
{
    public const UNKNOWN_TABLE = '(a table named in a variable)';

    private const BUILDER_WRITES = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    private const MODEL_WRITES = 'create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    private const SCHEMA_WRITES = 'table|create|drop|dropIfExists|dropColumns|rename';

    /**
     * Every verb this scanner reads a SQL statement by: its first word, the words between that and the table it names, and whether it changes rows rather than the shape of a
     * table. One vocabulary, read two ways by the two sides -- the table side wants the verb and what follows it, so that the next word is the table it writes; the column side
     * wants the first word alone, and only of the verbs that change rows, because a statement that changes the shape of a table (ALTER, CREATE, DROP, RENAME, TRUNCATE) names a
     * column without writing anybody's value. Read twice out of two lists is how the two drifted apart.
     *
     * @var array<string, array{table: string|null, rows: bool}> the first word => the words before the table it names (null when none follows), and whether it changes rows
     */
    private const SQL_VERBS = [
        'insert' => ['table' => '\s+(?:ignore\s+)?into', 'rows' => true],
        'replace' => ['table' => '\s+into', 'rows' => true],
        'update' => ['table' => '', 'rows' => true],
        'delete' => ['table' => '\s+from', 'rows' => true],
        'with' => ['table' => null, 'rows' => true],
        'truncate' => ['table' => '\s+table', 'rows' => false],
        'alter' => ['table' => '\s+table', 'rows' => false],
        'drop' => ['table' => '\s+table', 'rows' => false],
        'create' => ['table' => '\s+table', 'rows' => false],
        'rename' => ['table' => '\s+table', 'rows' => false],
    ];

    /** The keywords that open a clause giving a column a value: `SET`, and the clause MySQL appends to an upsert. */
    private const SQL_ASSIGNING_CLAUSES = '\bset\b|\bon\s+duplicate\s+key\s+update\b';

    /** The keywords that close it. What follows one of them filters, orders or limits the rows the statement writes, and assigns to nothing. */
    private const SQL_READING_CLAUSES = '\bwhere\b|\border\s+by\b|\blimit\b|\breturning\b';

    /**
     * Everything that takes an array of column => value and writes it. The two vocabularies above answer "does this statement write the table"; this one answers "is this array
     * a write", which is what tells a write from a cast or a name map, so it is those two plus setRawAttributes, which writes attributes and no table.
     */
    private const ARRAY_WRITES = self::BUILDER_WRITES . '|' . self::MODEL_WRITES . '|setRawAttributes';

    /** PHP's compound assignment operators, so that a balance rewritten with one of the rarer ones is still a write. */
    private const COMPOUND_ASSIGNMENT = '\*\*|<<|>>|\?\?|[-+*\/.%|&^]';

    /** A parameter passed by name, which this project's own coding standard asks for: `increment(column: 'wallet_balance')` names the column as plainly as `increment('wallet_balance')`. */
    private const NAMED_ARGUMENT = '(?:\w+\s*:\s*)?';

    /**
     * What an array value may begin with and be no amount: a class, a list (validation rules, a nested map), a translation, or a setting. None of the four is a number a
     * database could store in a money column, whatever the array is handed to.
     */
    private const VALUE_THAT_IS_NO_AMOUNT = '[A-Za-z_\\\\][\w\\\\]*::class\b|\[|(?:translate|__|trans|trans_choice|env|config)\s*\(';

    /** How far past the arrow a value is read to be classified. A value is told by what it begins with, and the first token of one is never this long. */
    private const VALUE_LENGTH = 120;

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
     * A cast and a name map also give the column to an array key (`'wallet_balance' => 'float'`), and are told from a write by the VALUE and not by what the array is handed to:
     * `['wallet_balance' => '0']` is an ordinary update, and a database writes 0 for it. See arrayKeyWrites().
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
            'increment or decrement' => '/(?:increment|decrement)(?:Quietly)?\(\s*' . self::NAMED_ARGUMENT . $named . '/',
            'setAttribute' => '/(?:setAttribute|offsetSet)\(\s*' . self::NAMED_ARGUMENT . $named . '/',
        ];

        $writes = [];
        foreach ($patterns as $kind => $pattern) {
            $writes = array_merge($writes, array_fill(0, preg_match_all($pattern, $read), $kind));
        }
        // The subscript is read before the names are spelled out, so that the column's own name is what is written through the array access and a name merely HELD by a variable or a
        // constant is not: `$user['wallet_balance'] = 5` writes an attribute of a model, while `$totals[$column] = 0` and `$row[self::WALLET] = 0` subscript a plain array, which
        // carries no attributes. A model written under a held name goes through `->{$column} =`, which the assignment above reads.
        $subscript = '/\[\s*[\'"]' . $name . '[\'"]\s*\]\s*(?:' . self::COMPOUND_ASSIGNMENT . ')?=(?![=>])/';
        $writes = array_merge($writes, array_fill(0, preg_match_all($subscript, $literals), 'array access assignment'));
        $writes = array_merge($writes, array_fill(0, self::arrayKeyWrites($read, $key, $column), 'array key given a value'));
        foreach (self::sqlStringsIn($literals) as $string) {
            $writes = array_merge($writes, self::sqlWritesOf($string, $column, $heldName));
        }

        return $writes;
    }

    /**
     * How many places the code gives the column as an array key that is written. Two readings, and their union, because an array is a write for either of two reasons and a
     * guard that asked for both at once let one of them through:
     *
     * - WHEREVER IT STANDS, if its value could be an amount. This is the reading that fails open, and the one a round that kept only the other lost ten money writes to: a
     *   ternary-valued array, an array merged in with array_merge, a row appended with `$rows[] = [...]` and an arm of a match are each handed on to a write the reader cannot
     *   follow -- into a method of another file, through a second variable, past a name this file never spells -- and each moved money on MariaDB 10.11.14.
     * - WHATEVER ITS VALUE, if the array reaches a write this reader CAN follow. This is what tells `['wallet_balance' => '0']`, an ordinary update a database writes 0 for,
     *   from `['wallet_balance' => 'float']`, a cast, and from `['loyalty_point' => 'loyalty_point']`, a name map -- of which this project has fifteen, nine in its translation
     *   files. A string that is a word rather than a number is quiet on its own and reported as soon as something hands it to an update.
     *
     * The two sets overlap wherever a resolvable write is given an amount, so the count is their union and not their sum.
     *
     * @param string $key the column as an array key, as a regular expression
     * @param string $column the column itself, for the value that reads it straight back out
     */
    private static function arrayKeyWrites(string $code, string $key, string $column): int
    {
        $handedToAWrite = self::arraysHandedToAWrite($code);

        return preg_match_all('/' . $key . '\s*=>/', $handedToAWrite)
            + self::keysWhoseValueCouldBeAnAmount($code, $key, $column)
            - self::keysWhoseValueCouldBeAnAmount($handedToAWrite, $key, $column);
    }

    /**
     * How many places the code gives the column as an array key whose value this class cannot prove is no amount. The default is that it could be one: a number, a variable, a
     * property, a call, an arithmetic expression, a `DB::raw`, an arm of a ternary or a match, and a string that is empty, numeric or interpolated are all amounts a database
     * stores, and so is anything else this reader does not recognise.
     *
     * @param string $key the column as an array key, as a regular expression
     */
    private static function keysWhoseValueCouldBeAnAmount(string $code, string $key, string $column): int
    {
        preg_match_all('/' . $key . '\s*=>\s*/', $code, $found, PREG_OFFSET_CAPTURE);
        $amounts = 0;

        foreach ($found[0] as [$arrow, $offset]) {
            $value = substr($code, $offset + strlen($arrow), self::VALUE_LENGTH);
            if (!self::isProvablyNotAnAmount($value, $column)) {
                $amounts++;
            }
        }

        return $amounts;
    }

    /**
     * Whether a value written against the column is provably no amount, which is the only reason this class stays quiet about one: a class rather than a number
     * (`'wallet_balance' => Wallet::class`), a list (`=> ['required', 'numeric']`), a translation (`=> translate('Wallet')`), a setting (`=> env('WALLET_BALANCE', 0)`), a word
     * rather than a number (`=> 'float'`, a cast; `=> 'loyalty_point'`, a name map; `=> 'warning'`, a tone), or the column read straight back off the object whose column it is
     * (`=> $this->wallet_balance`, a toArray), which writes what is already there and moves nothing.
     *
     * A number spelled as a string is NOT one of them: `=> '0'`, `=> '9999'`, `=> ''` and `=> "{$amount}"` are amounts, and a database writes each of them as a number.
     */
    private static function isProvablyNotAnAmount(string $value, string $column): bool
    {
        return preg_match('/^(?:' . self::VALUE_THAT_IS_NO_AMOUNT . ')/', $value) === 1
            || preg_match('/^\$this->' . preg_quote($column, '/') . '\b/', $value) === 1
            || self::isAWordRatherThanANumber($value);
    }

    /** Whether a value begins with a string literal that holds no interpolation and reads as a word: not empty, and not a number a money column could take. */
    private static function isAWordRatherThanANumber(string $value): bool
    {
        if (preg_match('/^\'([^\'\\\\]*)\'/', $value, $literal) !== 1 && preg_match('/^"([^"\\\\${]*)"/', $value, $literal) !== 1) {
            return false;
        }

        return $literal[1] !== '' && !is_numeric($literal[1]);
    }

    /**
     * The text of every array the code hands to a write: the ones written out in the call, the ones it builds in a variable or a property first and hands over on a later statement,
     * the ones a method of the file hands back to one, and the ones written out inside another call that is itself an argument to the write (`array_merge($base, [...])`), all of
     * which are as much a write and which gating on the call alone would have let through.
     *
     * One hop, and one only, which is a declared limit and not an oversight: an array that reaches its write through a second variable, through a method of another file or through
     * a name this file never spells is past this, and a column written that way needs a static analyser rather than a reader of text.
     */
    private static function arraysHandedToAWrite(string $code): string
    {
        $statements = self::statementsOf($code);
        $returned = self::arraysReturnedIn($statements);
        $calls = self::argumentsOf($code, self::ARRAY_WRITES);
        $arrays = $calls;

        foreach ($calls as $call) {
            preg_match_all('/\$(?:this->)?(\w+)/', $call, $handed);
            foreach (array_unique($handed[1]) as $name) {
                preg_match_all('/\$(?:this->)?' . preg_quote($name, '/') . '\s*\+?=\s*(\[[^;]*\])\s*;/', $code, $built, PREG_SET_ORDER);
                foreach ($built as $assignment) {
                    $arrays[] = $assignment[1];
                }
            }
            preg_match_all('/(?:->|::)?(\w+)\s*\(/', $call, $called);
            foreach (array_unique($called[1]) as $method) {
                array_push($arrays, ...$returned[$method] ?? []);
            }
        }

        return implode("\n", $arrays);
    }

    /**
     * The arrays the code's methods hand back, by the name of the method that hands one back: `return ['wallet_balance' => '0'];` is an update as soon as something hands that
     * method to one. The method a return belongs to is the last one the statements opened, which is one pass over them and no second reading of the braces.
     *
     * @param list<string> $statements the code split at its semicolons, the comments left out
     * @return array<string, list<string>>
     */
    private static function arraysReturnedIn(array $statements): array
    {
        $returned = [];
        $method = null;

        foreach ($statements as $statement) {
            if (preg_match('/\bfunction\s+(\w+)\s*\(/', $statement, $opened) === 1) {
                $method = $opened[1];
            }
            if ($method !== null && preg_match('/\breturn\s*(\[.*\])\s*$/s', $statement, $array) === 1) {
                $returned[$method][] = $array[1];
            }
        }

        return $returned;
    }

    /**
     * EVERY string the code holds, read as SQL, plus the statement each variable or property is built out of across more than one statement.
     *
     * No gate on what the string is handed to, deliberately, and this is the design the regression taught. Gating the SQL side on a whitelist of receiver methods
     * (`statement|unprepared|insert|update|delete|affectingStatement`) read `DB::select("update users set wallet_balance = 0")` as no write, and `DB::select` runs that statement
     * on MySQL and MariaDB and sets the balance to zero; so does `DB::getPdo()->exec(...)`, `DB::selectOne`, `DB::scalar`, `DB::cursor`, a prepared statement executed, and any
     * method of any other file the SQL is handed to. No list of names answers "does this string change a row" -- the string does, and sqlWritesOf() reads it.
     *
     * A variable is joined only when MORE THAN ONE statement puts a piece of SQL in it, because a single piece is already read as the string it is. That is what reads a
     * statement assembled by appending as the one statement the database gets: `$sql = "update users"; $sql .= " set wallet_balance = 0";` is a verb in one piece and an
     * assignment in the other, each harmless alone. Joining is the safe direction: pieces that never meet at run time read as one statement and are reported.
     *
     * @return list<string>
     */
    private static function sqlStringsIn(string $code): array
    {
        return array_merge(self::stringsIn($code), self::sqlAssembledInAVariable(self::statementsOf($code)));
    }

    /**
     * The SQL each variable or property is assembled out of across several statements, its pieces joined in the order the statements build them, for the variables that take
     * more than one piece.
     *
     * @param list<string> $statements the code split at its semicolons, the comments left out
     * @return list<string>
     */
    private static function sqlAssembledInAVariable(array $statements): array
    {
        $pieces = [];

        foreach ($statements as $statement) {
            if (preg_match('/\$(?:this->)?(\w+)\s*\.?=(?!=)/', $statement, $built) !== 1) {
                continue;
            }
            $strings = self::stringsIn($statement);
            if ($strings !== []) {
                $pieces[$built[1]][] = implode('', $strings);
            }
        }

        return array_values(array_map(
            static fn (array $parts): string => implode('', $parts),
            array_filter($pieces, static fn (array $parts): bool => count($parts) > 1),
        ));
    }

    /**
     * The text of the arguments of every call to one of the named methods ON A RECEIVER -- `$user->update(...)`, `DB::update(...)` -- the outermost ones only so that no call is
     * read twice. It is taken from the tokens, so that a parenthesis inside a string or a closure does not end the call early.
     *
     * The receiver is required because every method named here is a method: a plain function that happens to share one of their names (`create([...])`, `update([...])`, a view
     * helper named `push`) writes no column, and reading its argument as a write made the array of anything so named count.
     *
     * @param string $methods the method names, as a regular expression alternation
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
            if (!is_array($token) || $token[0] !== T_STRING || preg_match($called, $token[1]) !== 1 || !self::isCalledOnAReceiver($tokens, $position)) {
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
     * Whether the name at this position is called on something: `->name(`, `?->name(` or `::name(`.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function isCalledOnAReceiver(array $tokens, int $position): bool
    {
        for ($before = $position - 1; $before >= 0; $before--) {
            if (is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) {
                continue;
            }

            return is_array($tokens[$before])
                && in_array($tokens[$before][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        }

        return false;
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
    private static function statementsOf(string $code): array
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
        preg_match_all('/\b(?:' . self::sqlVerbsBeforeATable() . ')\s+[`"\']?(\{?\$\w+\}?|[a-z0-9_]+)/i', $statement, $found);

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
            if (preg_match('/^\s*(?:' . self::sqlVerbsThatChangeRows() . ')\b/i', $statement) !== 1) {
                continue;
            }
            if (preg_match('/' . $name . '\s*=(?!=)/i', self::assigningClausesOf($statement)) === 1) {
                $writes[] = 'raw SQL set';
            } elseif (preg_match('/\b(?:insert|replace)\s+(?:ignore\s+)?into\b[^(]*\([^)]*' . $name . '/i', $statement) === 1) {
                $writes[] = 'raw SQL insert';
            }
        }

        return $writes;
    }

    /** The first word of every verb that changes rows, for the side that reads a statement by the verb it starts with. */
    private static function sqlVerbsThatChangeRows(): string
    {
        return implode('|', array_keys(array_filter(self::SQL_VERBS, static fn (array $verb): bool => $verb['rows'])));
    }

    /** Every verb the table it names follows, for the side that reads which table a statement writes. `WITH` is not one: what follows it is the name of a result, not of a table. */
    private static function sqlVerbsBeforeATable(): string
    {
        $verbs = array_filter(self::SQL_VERBS, static fn (array $verb): bool => $verb['table'] !== null);

        return implode('|', array_map(static fn (string $word, array $verb): string => $word . $verb['table'], array_keys($verbs), $verbs));
    }

    /**
     * The clauses of a row-changing statement that ASSIGN: each `SET` (or the `ON DUPLICATE KEY UPDATE` of an upsert) up to the first clause keyword after it that belongs to the
     * statement itself.
     *
     * Cutting the read clause away instead -- everything from the first `where` -- took the assignment with it whenever a `where` came first: inside a join subquery, inside a
     * subselect of the SET clause, or inside a quoted literal that spells the word. Eight money writes read as none that way, each of them proved to write on MariaDB 10.11.14.
     * Isolating the assignment also tells a write from the clauses that only READ the column, which subtracting never did: a JOIN condition, an `ORDER BY`, a window expression
     * and the join of an `INSERT .. SELECT` assign nothing.
     *
     * Belonging to the statement itself means outside quotes and outside parentheses: the `where` of `set x = (select 1 from t where ok = 1), wallet_balance = 0` is the
     * subselect's, and the clause runs on past it.
     */
    private static function assigningClausesOf(string $statement): string
    {
        $assigning = '';
        $from = null;

        foreach (self::topLevelMatchesIn($statement, self::SQL_ASSIGNING_CLAUSES . '|' . self::SQL_READING_CLAUSES) as [$offset, $keyword]) {
            $opens = preg_match('/^(?:' . self::SQL_ASSIGNING_CLAUSES . ')$/i', $keyword) === 1;

            if ($opens && $from === null) {
                $from = $offset;
            } elseif (!$opens && $from !== null) {
                $assigning .= substr($statement, $from, $offset - $from);
                $from = null;
            }
        }

        return $from === null ? $assigning : $assigning . substr($statement, $from);
    }

    /** SQL without its comments, so that the verb a statement starts with is the verb and not a comment in front of it. A `--` that ends a line is a comment to the engine, and the space the pattern used to ask for after it let `"--\nupdate users set wallet_balance = 0"` read as a statement with no verb at all. */
    private static function withSqlCommentsStripped(string $sql): string
    {
        return (string) preg_replace(['/\/\*.*?\*\//s', '/(?:--(?=\s|$)|#)[^\n]*/'], '', $sql);
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
        $start = 0;

        foreach (self::topLevelMatchesIn($sql, ';') as [$offset]) {
            $statements[] = substr($sql, $start, $offset - $start);
            $start = $offset + 1;
        }
        $statements[] = substr($sql, $start);

        return $statements;
    }

    /**
     * Where a pattern matches the SQL outside every quoted literal and outside every parenthesis, in the order it matches.
     *
     * @param string $pattern as a regular expression, read without regard to case
     * @return list<array{int, string}> the offset of each match, and the text it matched
     */
    private static function topLevelMatchesIn(string $sql, string $pattern): array
    {
        preg_match_all('/' . $pattern . '/i', self::sqlWithNestingBlanked($sql), $found, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $match): array => [$match[1], $match[0]], $found[0]);
    }

    /**
     * The SQL with everything a quoted literal or a parenthesis holds replaced by spaces and the offsets kept, so that the pieces of a statement that belong to the statement
     * itself can be found by where they are in it. One walk over the text, and the only place this class tracks a quote: both the semicolon that ends a statement rather than a
     * literal, and the keyword that ends the clause that assigns rather than a subselect of it, are read off this.
     */
    private static function sqlWithNestingBlanked(string $sql): string
    {
        $blanked = '';
        $quote = null;
        $depth = 0;
        $length = strlen($sql);

        for ($position = 0; $position < $length; $position++) {
            $character = $sql[$position];

            if ($quote !== null) {
                $blanked .= ' ';
                if ($character === '\\' && $position + 1 < $length) {
                    $blanked .= ' ';
                    $position++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $blanked .= ' ';
                continue;
            }
            if ($character === '(' || $character === ')') {
                $depth = $character === '(' ? $depth + 1 : max($depth - 1, 0);
                $blanked .= ' ';
                continue;
            }
            $blanked .= $depth > 0 ? ' ' : $character;
        }

        return $blanked;
    }
}
