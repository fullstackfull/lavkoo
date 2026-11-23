<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use ReflectionClass;

/**
 * Reads PHP source for what it writes, and answers two questions with the one reader.
 *
 * Which TABLES a piece of code writes (`writesIn`, and `rowWritesIn` for the rows alone), for the guards that keep one module's tables out of another's -- the cost and
 * sales-channel modules, and the migrations of the GCC chain against the tables that hold the Saudi platform's customers
 * (`tests/Feature/Storefront/CustomerTablesAreNotRewrittenTest`): the query builder (a table named in a string, a constant, a variable, a property, an alias, `from()`, half a
 * string, a builder kept until a later statement, a join update), raw SQL (a table named, qualified by its database, or interpolated), the SQL of a string handed to anything at
 * all (`tablesAStringWrites`), the schema builder, and the platform's models (made, bound, fetched and saved on another statement, imported under another name, handed on to a
 * second variable, or written through a relation of one of them). It reads text, not behaviour: what it cannot resolve it reports as "(a table named in a variable)", which the
 * guard's list has to name, and an expression it does not recognise at all counts as unresolved -- `DB::table(config('x'))` and `DB::unprepared(sprintf(...))` name a table this
 * scanner cannot read, exactly as `DB::table($name)` does. It also reads the framework for the blueprint methods that add and drop a column, so that half of the question is not
 * a list that goes stale either.
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
 *
 * ONE PLACE WHERE THE FAILING OPEN STOPS, measured and declared rather than left to be found: a value that is a WORD is taken for no amount, and this application runs MySQL
 * non-strict (`config/database.php`, `'strict' => false`), where `update users set wallet_balance = 'float'` stores 0.00000000000000000000 rather than being refused -- proved
 * on MariaDB 10.11.14 both ways, refused under this server's own STRICT_TRANS_TABLES and writing 0 under `sql_mode=''`. So an array whose value is a word AND whose handing
 * this reader cannot follow is a money write it does not report. It needs both: the same word in a SET clause is reported (sqlWritesOf looks at where the column is, never at
 * the value), and the same array handed to a write it CAN follow is reported whatever the value. Closing it means reporting every word-valued key wherever it stands, which is
 * the fifteen non-writes of this project -- four cast lines, two name maps, two Blade tone maps, seven translation lines -- so it is a limit and not an oversight.
 */
final class PlatformWriteScanner
{
    public const UNKNOWN_TABLE = '(a table named in a variable)';

    private const BUILDER_WRITES = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    private const MODEL_WRITES = 'create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    private const SCHEMA_WRITES = 'table|create|drop|dropIfExists|dropColumns|rename';

    private const RAW_WRITE_VERBS = 'update|insert|delete|replace|truncate|alter|drop|load\s+data';

    /** The calls that run SQL the scanner has to read: what is handed to one of them is a write unless its text says otherwise. */
    private const RAW_SQL_CALL = 'DB::(?:statement|unprepared|insert|update|delete|affectingStatement)\(|->(?:statement|unprepared|affectingStatement)\(';

    /** @var array<string, string>|null the body of every method of the schema builder's blueprint, read once */
    private static ?array $blueprintBodies = null;

    /**
     * The start of a variable taking the value of the rest of the statement. A statement is what lies between two semicolons, so an assignment that is the first thing a method does
     * comes with the braces and the signature above it: anchoring this to the start of the statement alone made the scanner blind to a builder or a model held by such an
     * assignment, which is most of them. The brace has to be the thing before it, so that `as $key => $value` is not read as one.
     */
    private const ASSIGNMENT = '(?:^|[{}])\s*(\$\w+(?:->\w+)*)\s*=';

    /** The builder calls that change or remove rows that are already there. `insert` and its kin are missing on purpose: appending is not editing. */
    private const BUILDER_EDITS = 'update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    /** The blueprint calls that take data off a table that stays: a dropped or renamed column loses every value in it. */
    private const COLUMN_DESTROYERS = 'dropColumn|dropColumns|renameColumn|dropMorphs|dropSoftDeletes|dropTimestamps|dropRememberToken';

    /** The same for a model. `create`, `forceCreate`, `firstOrCreate` and `insert` are missing on purpose; `save` is here because a model fetched and saved again is an edit. */
    private const MODEL_EDITS = 'update|updateOrCreate|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    /** The SQL verbs that change or remove rows that are already there; `replace into` is one of them, because it deletes the row it replaces. */
    private const RAW_EDITS = 'update|delete\\s+from|truncate\\s+table|replace\\s+into|alter\\s+table|drop\\s+table|rename\\s+table';

    /** Every SQL verb the scanner reads, writes and edits together. */
    private const RAW_WRITES = 'insert\\s+(?:ignore\\s+)?into|replace\\s+into|update|delete\\s+from|truncate\\s+table|alter\\s+table|drop\\s+table|create\\s+table|rename\\s+table';

    /** The schema calls that destroy a whole table. */
    private const SCHEMA_DESTROYS_TABLE = 'drop|dropIfExists|rename';

    /** The calls whose first argument names the table a query starts from. A join is not one: its other arguments hold columns, not tables. */
    private const TABLE_ARGUMENT_CALLS = 'DB::table|->table|->from';

    /** Where a column name is a key of an array. The array itself may be a variable — then the keys are in the variable, not here. */
    private const COLUMN_KEY_POSITIONS = 'update|updateOrInsert|insert|insertGetId|insertOrIgnore|upsert';

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

    public const COLUMN_READ_OR_WRITTEN = '(the column named where a query takes the column to read or write)';

    /** Raw SQL handed to a query builder as a fragment rather than a whole statement, which is the other way a column name reaches the database. */
    private const RAW_SQL_FRAGMENTS = 'DB::raw\(|->raw\(|\w*Raw\(';

    /**
     * Every way a whole statement of SQL is handed to the database. Read both for the table such a statement writes and for a column name
     * built into one, so the two questions cannot drift apart: the narrower list the column question once kept of its own was how
     * `DB::select` and `DB::update` with an interpolated column name went unseen.
     */
    private const RAW_SQL_STATEMENTS = 'DB::(?:statement|unprepared|insert|update|delete|select|selectOne|scalar|affectingStatement)\(|->(?:statement|unprepared|affectingStatement)\(';

    public const UNKNOWN_COLUMN = '(a column named where this scan cannot read it)';

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
    public static function tablesAStringWrites(string $code, array $tables): array
    {
        $written = [];

        foreach (self::stringsOf($code) as $string) {
            $sql = self::withoutSqlComments($string);
            foreach ($tables as $table) {
                if (preg_match('/\b(?:' . self::RAW_WRITE_VERBS . ')\b[^;]*\b' . preg_quote($table, '/') . '\b/i', $sql) === 1) {
                    $written[] = $table;
                }
            }
        }

        $written = array_values(array_unique($written));
        sort($written);

        return $written;
    }

    /**
     * A string with its SQL comments taken out, so that a `;` written inside one cannot stand between a write and the table it writes: an engine reads `UPDATE -- why;` + newline
     * + `users SET ...` as a write of `users`, and so does this. A comment ends at a newline, which in the text of a double-quoted string is the two characters `\n`.
     */
    private static function withoutSqlComments(string $sql): string
    {
        return (string) preg_replace(['/(?:--|\#)(?:(?!\\\\[nr])[^\n\r])*/', '#/\*.*?\*/#s'], ' ', $sql);
    }

    /** The source without its comments: what a check reads, so that a sentence about a write is not mistaken for one. */
    public static function codeOf(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
        }

        return $code;
    }

    /** @return list<string> the text of every string the code holds, interpolated or not */
    private static function stringsOf(string $code): array
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
        $code = $this->withTheFacadesUnaliased($code);
        $constants = $this->constantsIn($code);
        $written = [];
        $builders = [];
        $models = [];

        foreach (self::statementsOf($code) as $statement) {
            $tables = $this->tablesNamedIn($statement, $constants);
            $writes = preg_match('/->(?:' . self::BUILDER_WRITES . ')\(/', $statement) === 1;

            if ($tables !== [] && $writes) {
                array_push($written, ...$this->tablesWritten($statement, $tables));
            }
            if ($tables !== [] && !$writes && preg_match('/' . self::ASSIGNMENT . '[^=]/', $statement, $variable) === 1) {
                $builders[$variable[1]] = $tables[0];
            }
            $copy = $this->variableCopiedIn($statement);
            if ($copy !== null && isset($builders[$copy[1]])) {
                $builders[$copy[0]] = $builders[$copy[1]];
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
     * The code with the facades it imported under another name written as themselves: `use Illuminate\Support\Facades\DB as Database` makes `Database::table('users')->update()` a
     * write of `users`, and every pattern below names the facades as they are really called. The models are resolved the same way, by the guard that hands this scanner their names.
     */
    private function withTheFacadesUnaliased(string $code): string
    {
        preg_match_all('/\buse\s+\\\\?Illuminate\\\\Support\\\\Facades\\\\(DB|Schema)\s+as\s+(\w+)\s*;/i', $code, $found, PREG_SET_ORDER);

        foreach ($found as $import) {
            $code = (string) preg_replace('/\b' . preg_quote($import[2], '/') . '::/', $import[1] . '::', $code);
        }

        return $code;
    }

    /**
     * The two variables of a statement that is one variable taking the value of another and nothing else (`$target = $rows;`), the receiver first, or null when it is not that. A
     * builder or a model handed on this way is the same builder or the same model, so what the scanner knows about one it knows about the other.
     *
     * @return array{0: string, 1: string}|null
     */
    private function variableCopiedIn(string $statement): ?array
    {
        return preg_match('/' . self::ASSIGNMENT . '\s*(\$\w+(?:->\w+)*)\s*$/', trim($statement), $copy) === 1 ? [$copy[1], $copy[2]] : null;
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
     * Every method of the schema builder's blueprint that adds a column, read from the framework that is installed instead of listed here: a list written out by hand goes stale at
     * the next upgrade, and the one this replaced had no `mediumInteger`, `unsignedTinyInteger`, `year`, `ipAddress` or `binary` in it, each of which adds a column to a table as
     * surely as `string()` does. A method counts when its body reaches `addColumn()` through other methods of the blueprint, which is how `timestamps()` and `morphs()` work.
     *
     * @return list<string>
     */
    public static function blueprintMethodsThatAddAColumn(): array
    {
        return self::blueprintMethodsReaching('addColumn', 'addColumnDefinition');
    }

    /**
     * Every method of the blueprint that drops one, read the same way: `dropColumn()` is not the only one, and `dropRememberToken()`, `dropTimestamps()`, `dropSoftDeletes()` and
     * `dropMorphs()` take a column away from a table without naming it.
     *
     * @return list<string>
     */
    public static function blueprintMethodsThatDropAColumn(): array
    {
        return self::blueprintMethodsReaching('dropColumn');
    }

    /**
     * The methods of the blueprint that call one of the given methods, or call a method that does, the given ones among them.
     *
     * @return list<string>
     */
    private static function blueprintMethodsReaching(string ...$seeds): array
    {
        $reached = $seeds;

        do {
            $grown = false;
            foreach (self::blueprintBodies() as $method => $body) {
                if (in_array($method, $reached, true)) {
                    continue;
                }
                foreach ($reached as $called) {
                    if (str_contains($body, '$this->' . $called . '(')) {
                        $reached[] = $method;
                        $grown = true;
                        break;
                    }
                }
            }
        } while ($grown);

        sort($reached);

        return $reached;
    }

    /** @return array<string, string> the body of every method of the blueprint, by name */
    private static function blueprintBodies(): array
    {
        if (self::$blueprintBodies !== null) {
            return self::$blueprintBodies;
        }

        $source = (string) file_get_contents((string) (new ReflectionClass(Blueprint::class))->getFileName());
        preg_match_all('/\n    public function (\w+)\([^)]*\)[^{]*\{(.*?)\n    \}/s', $source, $found, PREG_SET_ORDER);

        return self::$blueprintBodies = array_column($found, 2, 1);
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
     * (`self::PRODUCTS` is `products`) and a table named in a variable, because the file does not say. Anything else in that place — a variable, a method call, `config()`, a model's
     * `getTable()` — is a table named in a variable too: the scanner's own rule is that the three spellings it resolves are the only ones it may claim to have read, and this is the
     * rule `tests/Unit/SalesChannels/StockNeverWritesPlatformTablesTest` has always applied to the same question.
     *
     * @param array<string, string> $constants
     * @return list<list<string>> the names each place may stand for
     */
    private function tablesNamedIn(string $statement, array $constants): array
    {
        preg_match_all('/(?:DB::table|->table|->from|->join|->leftJoin|->rightJoin|->crossJoin|->joinSub)\(\s*(?:(?<quote>[\'"])(?<literal>[^\'"]+)\k<quote>|(?:self|static|[A-Z]\w*)::(?<constant>[A-Z][A-Z0-9_]*)|)(?<joined>\s*\.)?/', $statement, $found, PREG_SET_ORDER);

        $tables = [];
        foreach ($found as $match) {
            $names = match (true) {
                ($match['literal'] ?? '') !== '' => [strtolower(preg_split('/\s+as\s+/i', trim($match['literal']))[0])],
                ($match['constant'] ?? '') !== '' => isset($constants[$match['constant']]) ? [$constants[$match['constant']]] : [strtolower($match['constant']), self::UNKNOWN_TABLE],
                default => [self::UNKNOWN_TABLE],
            };

            $tables[] = $this->withWhatIsMissing($names, joinedToWhatFollows: ($match['joined'] ?? '') !== '');
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

    /**
     * The tables the raw SQL of a statement writes, and "a table named in a variable" when the SQL itself is past reading: handed over as a variable or built by a call
     * (`sprintf()`, `str_replace()`), or begun by a string that is joined to something else. Half a statement names no table, and a statement assembled at run time could name any
     * of them, so neither is waved through.
     *
     * @return list<string>
     */
    private function rawSqlWrites(string $statement, bool $editsOnly = false): array
    {
        if (preg_match('/' . self::RAW_SQL_CALL . '/', $statement) !== 1) {
            return [];
        }
        // `into table` is ours and RAW_WRITES has no twin for it, so it is added to that list rather than replacing it.
        $verbs = $editsOnly ? self::RAW_EDITS : self::RAW_WRITES . '|into\s+table';
        preg_match_all('/\b(?:' . $verbs . ')\s+(?:[`"\']?[a-z0-9_]+[`"\']?\s*\.\s*)?[`"\']?(\{?\$\w+\}?|[a-z0-9_]+)/i', $statement, $found);
        $written = array_map(static fn (string $table): string => str_contains($table, '$') ? self::UNKNOWN_TABLE : strtolower($table), $found[1]);

        // Only on the WRITE path. On the edit path the same question is asked by unreadableNamesIn(), whose rule is
        // narrower on purpose -- it wants an edit verb beside the unreadable name -- and asking both would report a file
        // twice and report files that rule deliberately passes over, which is what the append-only guard's accounted-for
        // list is calibrated against.
        if (!$editsOnly && preg_match('/(?:' . self::RAW_SQL_CALL . ')\s*(?:[^\'"\s]|(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\.)/', $statement) === 1) {
            $written[] = self::UNKNOWN_TABLE;
        }

        return $written;
    }

    /**
     * @param array<string, string> $constants
     * @return list<string>
     */
    private function schemaWrites(string $statement, array $constants): array
    {
        preg_match_all('/Schema::(?:' . self::SCHEMA_WRITES . ')\(\s*(?:(?<quote>[\'"])(?<literal>[a-z0-9_]+)\k<quote>|(?:self|static|[A-Z]\w*)::(?<constant>[A-Z][A-Z0-9_]*)|)(?<joined>\s*\.)?/', $statement, $found, PREG_SET_ORDER);

        $written = [];
        foreach ($found as $match) {
            $name = match (true) {
                ($match['literal'] ?? '') !== '' => $match['literal'],
                ($match['constant'] ?? '') !== '' => $constants[$match['constant']] ?? self::UNKNOWN_TABLE,
                default => self::UNKNOWN_TABLE,
            };

            array_push($written, ...$this->withWhatIsMissing([$name], joinedToWhatFollows: ($match['joined'] ?? '') !== ''));
        }

        return $written;
    }

    /**
     * The platform's models a statement writes: made, bound to the container or called statically with a write on the same statement, and a model fetched on one statement and written
     * on a later one (kept in a variable, handed on to a second variable, or the variable of a loop). A write through a relation of a model the scanner holds counts as a write of that
     * model, whatever the relation is called: `$customer->orders()->delete()` and `$customer->addresses()->update()` are that customer's history, and the plural half of a relation is
     * the destructive half.
     *
     * @param array<string, string> $models the variables that hold a platform model, by variable name (filled as the statements go by)
     * @return list<string>
     */
    private function modelWrites(string $statement, array &$models, bool $editsOnly = false): array
    {
        $names = implode('|', $this->platformModels);
        // Default false keeps every caller that existed reading writes AND appends, exactly as before. True narrows to the
        // verbs that change or remove a row already there, which is what the append-only ledger guard asks for.
        $verbs = $editsOnly ? self::MODEL_EDITS : self::MODEL_WRITES;
        $written = [];

        if (!$editsOnly && preg_match('/new\s+(' . $names . ')\b/', $statement, $new) === 1) {
            $written[] = 'model ' . $new[1];
        } elseif (preg_match('/\b(' . $names . ')\s*::(?:class\b|[a-zA-Z_]+\()/', $statement, $model) === 1
            && preg_match('/(?:->|::)(?:' . $verbs . ')\(/', $statement) === 1) {
            $written[] = 'model ' . $model[1];
        }

        if (preg_match('/' . self::ASSIGNMENT . '\s*.*\b(' . $names . ')\s*::/s', $statement, $held) === 1 || preg_match('/foreach\s*\(.*\b(' . $names . ')\s*::.*\bas\s+(?:\$\w+\s*=>\s*)?(\$\w+)\)/s', $statement, $loop) === 1) {
            $models[$held[1] ?? $loop[2]] = $held[2] ?? $loop[1];
        }
        $copy = $this->variableCopiedIn($statement);
        if ($copy !== null && isset($models[$copy[1]])) {
            $models[$copy[0]] = $models[$copy[1]];
        }
        if (preg_match('/->\s*(' . implode('|', array_map('lcfirst', $this->platformModels)) . ')(?:\(\))?\s*->\s*(?:' . $verbs . ')\(/', $statement, $relation) === 1) {
            $written[] = 'model ' . ucfirst($relation[1]);
        }
        foreach ($models as $variable => $model) {
            $held = preg_quote($variable, '/');
            if (preg_match('/' . $held . '\s*->\s*(?:' . $verbs . ')\(/', $statement) === 1
                || preg_match('/' . $held . '\s*->\s*\w+\s*=[^=]/', $statement) === 1
                || preg_match('/' . $held . '\s*->\s*\w+(?:\(\))?\s*->\s*(?:' . $verbs . ')\(/', $statement) === 1) {
                $written[] = 'model ' . $model;
            }
        }

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
        // The SQL side reads the constants too, and the literals a substituted constant leaves adjacent are joined again: `"update users set " . self::WALLET . " = 0"` is one
        // statement, and reading the literals alone left the column's name out of it while the array side read the same constant. One normalisation for both sides, or the
        // spelling a writer picks decides which side sees it.
        $sql = self::withConcatenationJoined(self::withConstantsRead($literals));
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
            // The helpers that set an array key by name, where the name is the SECOND argument: `data_set($values, 'wallet_balance', 0)`. `data_get` and `Arr::get` read and are
            // not here. The array does not have to be followed into its write, as nothing above does either.
            'a key set by name through a helper' => '/(?:data_set|data_fill|Arr::set|Arr::add)\(\s*[^,()]+,\s*' . self::NAMED_ARGUMENT . $named . '/',
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
        $writes = array_merge($writes, array_fill(0, self::upsertUpdateColumnWrites($read, $key), "the column among an upsert's update columns"));
        foreach (self::sqlStringsIn($sql) as $string) {
            $writes = array_merge($writes, self::sqlWritesOf($string, $column, $heldName));
        }

        return $writes;
    }

    /**
     * How many places the code gives the column as an array key that is written. Two readings, and their union, because an array is a write for either of two reasons and a
     * guard that asked for both at once let one of them through:
     *
     * - WHEREVER IT STANDS, if its value could be an amount. This is the reading that fails open, and the one a round that kept only the other lost six money writes to: a
     *   ternary-valued array, an array merged in with array_merge, a row appended with `$rows[] = [...]`, an arm of a match, an array that reaches its write through a second
     *   variable and one handed to a method of another file are each handed on to a write the reader cannot follow, and each moved money on MariaDB 10.11.14.
     * - WHATEVER ITS VALUE, if the array reaches a write this reader CAN follow. This is what tells `['wallet_balance' => '0']`, an ordinary update a database writes 0 for,
     *   from `['wallet_balance' => 'float']`, a cast, and from `['loyalty_point' => 'loyalty_point']`, a name map -- of which this project has fifteen: four cast lines in two
     *   files, two name maps, two Blade tone maps and seven translation lines. A string that is a word rather than a number is quiet on its own and reported as soon as
     *   something hands it to an update.
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
     * How many upsert calls name the column ONLY among the columns to update, which is its third argument and a list of names rather than a map. MySQL compiles that to
     * `on duplicate key update wallet_balance = values(wallet_balance)`, and when the rows inserted do not carry the column, `values()` hands back its default:
     * `DB::table('users')->upsert([['id' => 1]], ['id'], ['wallet_balance'])` took a `decimal(40,20)` balance of 350.12345678901234567890 to NULL on MariaDB 10.11.14.
     *
     * Only where the column is not also a key of the call, because then the array reading above has already counted the one write.
     *
     * @param string $key the column as an array key, as a regular expression
     */
    private static function upsertUpdateColumnWrites(string $code, string $key): int
    {
        $named = 0;
        foreach (self::argumentsOf($code, 'upsert') as $call) {
            if (preg_match('/' . $key . '\s*=>/', $call) !== 1 && preg_match('/' . $key . '/', $call) === 1) {
                $named++;
            }
        }

        return $named;
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
     * The assignment is read as `=` or as `:=`, because MariaDB takes both in a SET clause: `update users set wallet_balance := 0` stores 0.00000000000000000000 over a
     * `decimal(40,20)` seeded at 350.12345678901234567890, measured on MariaDB 10.11.14, and reading only `=` let it past.
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
            if (preg_match('/' . $name . '\s*:?=(?!=)/i', self::assigningClausesOf($statement)) === 1) {
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

        foreach (self::statementsOf($code) as $statement) {
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

    /** The code with its string constants standing for their values, the escape sequences of its strings read, and its concatenated string literals joined, which a column name may be written as. */
    private function withPiecesReadWhole(string $code): string
    {
        $read = $code;

        foreach ($this->constantsIn($code) as $name => $value) {
            $read = (string) preg_replace('/(?:self|static|[A-Z]\w*)::' . preg_quote($name, '/') . '\b/', "'" . $value . "'", $read);
        }

        $read = $this->withEscapeSequencesReadInPlace($read);
        $read = $this->withLiteralPiecesJoined($read);

        do {
            $read = (string) preg_replace('/([\'"])([^\'"\\\\]*)\1\s*\.\s*([\'"])([^\'"\\\\]*)\3/', '$1$2$4$1', $read, -1, $joined);
        } while ($joined > 0);

        return $read;
    }

    /**
     * (The column-NAME side's reader; the column-VALUE side has withEscapeSequencesRead, which rebuilds the code from its tokens and drops the comments.)
     * The code with the escape sequences PHP reads inside a double-quoted string or a heredoc read the same way, so that `"sharing\x5fgroup"`
     * is the name it spells once the engine sees it. A single-quoted string is left alone, PHP reading no escapes in one. The folding happens
     * before literals are joined, so a name written in pieces across an escape is read whole as well.
     *
     * A literal is rewritten in place and quoted again, so what comes back is still the code this scan tokenizes. A single-quoted string
     * and a nowdoc are left exactly as they are, because PHP reads no escape in either: `'sharing\x5fgroup'` names a column of that literal
     * name, which no table has, and reporting it would be a finding about nothing.
     */
    private function withEscapeSequencesReadInPlace(string $code): string
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
     * The same statement with the labels off its named arguments: `DB::table(table: 'wallet_transactions', as: 'w')` reads as
     * `DB::table('wallet_transactions', 'w')`.
     *
     * This repository's own standards MANDATE named arguments, so the house style was the way past every rule here that reads a table name.
     * Taking the label off once, before anything looks at the statement, is why none of those rules had to learn about it. `::` is left
     * alone, or `Foo::class` inside a call would lose its class.
     */
    private function argumentLabelsStripped(string $statement): string
    {
        return (string) preg_replace('/([(,])\s*\w+\s*:(?!:)\s*/', '$1', $statement);
    }

    /**
     * Whatever a statement assigns out of a ledger model holds it too: `$movement = $this->walletTransaction->find($id)` hands the model to
     * `$movement`, so `$movement->credit = 0; $movement->save();` two statements later is an edit of a money row. Without this the oldest
     * trick in Eloquent -- fetch the row, change it, save it -- was invisible while the one-line `->where(...)->update(...)` was not.
     *
     * The assignment is looked for anywhere in the statement rather than at its start, because the first statement of a class file carries
     * the class and method headers in front of it: `modelWrites()` anchors at the start, so what it tracks depends on where in the file the
     * code sits, and a guard must not.
     *
     * @param array<string, string> $holders the holder as the code writes it => the model it holds; added to in place
     */
    private function followHolders(string $statement, array &$holders): void
    {
        foreach ($this->platformModels as $model) {
            $this->rememberWhatHoldsTheModel($statement, '\b' . $model . '\s*::', $model, $holders);
        }
        foreach (array_keys($holders) as $holder) {
            $this->rememberWhatHoldsTheModel($statement, preg_quote($holder, '/') . '\s*->', $holders[$holder], $holders);
        }
    }

    /**
     * The platform's models a statement edits through such a holder. The holder has to be followed by `->`, so that a repository whose name
     * merely starts like the property (`$this->walletTransactionRepo`) is not read as the model itself.
     *
     * @param array<string, string> $holders
     * @return list<string>
     */
    private function heldModelEdits(string $statement, array $holders): array
    {
        $edited = [];
        foreach ($holders as $holder => $model) {
            $held = preg_quote($holder, '/');
            if (preg_match('/' . $held . '\s*->/', $statement) !== 1) {
                continue;
            }
            if (preg_match('/->(?:' . self::MODEL_EDITS . '|' . self::BUILDER_EDITS . ')\(/', $statement) === 1 || preg_match('/' . $held . '\s*->\s*\w+\s*=[^=]/', $statement) === 1) {
                $edited[] = 'model ' . $model;
            }
        }

        return $edited;
    }

    /** `'wallet_' . 'transactions'` is one name, not two: adjacent string literals are joined before a table is looked for. */
    private function literalsFolded(string $statement): string
    {
        do {
            $statement = (string) preg_replace('/([\'"])([^\'"]*)\1\s*\.\s*([\'"])([^\'"]*)\3/', '$1$2$4$1', $statement, -1, $joined);
        } while ($joined > 0);

        return $statement;
    }

    /**
     * The variables and properties that hold one of the platform's models because their TYPE says so: a promoted constructor property, a
     * declared property or a parameter. Both ledger repositories reach their own table through `$this->walletTransaction` and
     * `$this->loyaltyPointTransaction`, where no statement ever names the model and nothing above can see it.
     *
     * @param list<string> $statements the code without its comments
     * @return array<string, string> the holder as the code writes it => the model it holds
     */
    private function modelHoldersIn(array $statements): array
    {
        $names = implode('|', $this->platformModels);
        $code = implode(';', $statements);
        $holders = [];

        preg_match_all('/\b(?:private|protected|public)(?:\s+readonly)?\s+\??(' . $names . ')\s+(\$)(\w+)/', $code, $properties, PREG_SET_ORDER);
        foreach ($properties as $match) {
            $holders['$this->' . $match[3]] = $match[1];
        }

        preg_match_all('/\??(' . $names . ')\s+(\$\w+)/', $code, $parameters, PREG_SET_ORDER);
        foreach ($parameters as $match) {
            $holders[$match[2]] ??= $match[1];
        }

        return $holders;
    }

    /** @param array<string, string> $holders added to in place, and never overwritten: a holder keeps the model it was first seen to hold */
    private function rememberWhatHoldsTheModel(string $statement, string $source, string $model, array &$holders): void
    {
        if (preg_match('/(\$\w+)\s*=(?!=)\s*[^=]*?' . $source . '/s', $statement, $assigned) === 1) {
            $holders[$assigned[1]] ??= $model;
        }
    }

    /**
     * Every table (or platform model) whose EXISTING rows the code changes or removes. An insert is not one: the question an append-only
     * ledger asks is not whether the code writes the table but whether anything goes back and edits what was written. A name the scan
     * cannot read is reported as UNKNOWN_TABLE, so a ledger reached through a name built at run time is never silently absent.
     *
     * @return list<string> sorted
     */
    public function rowsEditedIn(string $code): array
    {
        return $this->tablesTouchedIn($code, editsOnly: true);
    }

    /**
     * Every table whose SHAPE the code destroys: dropped or renamed outright, or named to `Schema::table()` where a column of it is dropped
     * or renamed. Dropping a table takes every row with it without editing one, so a row guard cannot see it; and the ledgers' own create
     * migrations drop them in their `down()`, which is why this is asked separately and answered against a list of the files allowed to.
     *
     * A column-destroying call is attributed to the last table named to the schema builder, so a closure spread over several statements is
     * read as being about the table its `Schema::table()` opened.
     *
     * @return list<string> sorted
     */
    public function schemaDestroyedIn(string $code): array
    {
        $constants = $this->constantsIn($code);
        $destroyed = [];
        $openTables = [];

        foreach (self::statementsOf($code) as $statement) {
            $statement = $this->literalsFolded($this->argumentLabelsStripped($statement));
            if (preg_match('/Schema::(?:' . self::SCHEMA_DESTROYS_TABLE . ')\s*\(/', $statement) === 1) {
                array_push($destroyed, ...$this->schemaTablesNamedIn($statement, $constants));
            }
            if (preg_match('/Schema::table\s*\(/', $statement) === 1) {
                $openTables = $this->schemaTablesNamedIn($statement, $constants);
            }
            if ($openTables !== [] && preg_match('/->\s*(?:' . self::COLUMN_DESTROYERS . ')\s*\(/', $statement) === 1) {
                array_push($destroyed, ...$openTables);
            }
        }

        $destroyed = array_values(array_unique($destroyed));
        sort($destroyed);

        return $destroyed;
    }

    /**
     * The tables a statement names to the schema builder, by a string, by a constant, or unreadably.
     *
     * @param array<string, string> $constants
     * @return list<string>
     */
    private function schemaTablesNamedIn(string $statement, array $constants): array
    {
        preg_match_all('/Schema::(?:' . self::SCHEMA_WRITES . ')\s*\(\s*(?:([\'"])([a-z0-9_]+)\\1|(?:self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)|(\$))/', $statement, $found, PREG_SET_ORDER);

        return array_map(static fn (array $match): string => match (true) {
            ($match[2] ?? '') !== '' => strtolower($match[2]),
            ($match[3] ?? '') !== '' => $constants[$match[3]] ?? self::UNKNOWN_TABLE,
            default => self::UNKNOWN_TABLE,
        }, $found);
    }

    /**
     * @param bool $editsOnly true to count only what changes or removes a row that is already there, false to count every write
     * @return list<string> sorted
     */
    private function tablesTouchedIn(string $code, bool $editsOnly): array
    {
        $constants = $this->constantsIn($code);
        $statements = self::statementsOf($code);
        $holders = $editsOnly ? $this->modelHoldersIn($statements) : [];
        $builderVerbs = $editsOnly ? self::BUILDER_EDITS : self::BUILDER_WRITES;
        $touched = [];
        $builders = [];
        $models = [];

        foreach ($statements as $statement) {
            // Folding, the argument labels, the holders and the report of a name that cannot be read are on the edit path only: the write
            // question's guards assert an exact list of what they find, and widening what they see belongs in a change of its own.
            $statement = $editsOnly ? $this->literalsFolded($this->argumentLabelsStripped($statement)) : $statement;
            $tables = $this->tablesNamedIn($statement, $constants);
            $writes = preg_match('/->(?:' . $builderVerbs . ')\(/', $statement) === 1;

            if ($tables !== [] && $writes) {
                array_push($touched, ...$this->tablesWritten($statement, $tables));
            }
            if ($tables !== [] && !$writes && preg_match('/^\s*(\$\w+)\s*=/', $statement, $variable) === 1) {
                $builders[$variable[1]] = $tables[0];
            }
            if (preg_match('/(\$\w+)\s*->(?:' . $builderVerbs . ')\(/', $statement, $variable) === 1 && isset($builders[$variable[1]])) {
                array_push($touched, ...$builders[$variable[1]]);
            }

            array_push($touched, ...$this->rawSqlWrites($statement, editsOnly: $editsOnly));
            if (!$editsOnly) {
                array_push($touched, ...$this->schemaWrites($statement, $constants));
            }
            array_push($touched, ...$this->modelWrites($statement, $models, editsOnly: $editsOnly));
            if ($editsOnly) {
                array_push($touched, ...$this->heldModelEdits($statement, $holders), ...$this->unreadableNamesIn($statement));
                $this->followHolders($statement, $holders);
            }
        }

        $touched = array_values(array_unique($touched));
        sort($touched);

        return $touched;
    }

    /**
     * UNKNOWN_TABLE when a statement edits rows through a name this scan cannot read: a table argument holding a variable, a call or half a
     * string, or SQL assembled from anything but one literal. A name built at run time is the way past every rule that reads a name, so it
     * is reported rather than skipped, and the guard's list has to account for it.
     *
     * @return list<string>
     */
    private function unreadableNamesIn(string $statement): array
    {
        if (preg_match('/->(?:' . self::BUILDER_EDITS . ')\(/', $statement) === 1
            && preg_match('/(?:' . self::TABLE_ARGUMENT_CALLS . ')\(\s*([^,)]*)/', $statement, $argument) === 1
            && preg_match('/[$(]|[\'"]\s*\.\s*$/', trim($argument[1])) === 1) {
            return [self::UNKNOWN_TABLE];
        }

        if (preg_match('/(?:' . self::RAW_SQL_CALL . ')\s*(?:[^\'"]|\'[^\']*\'|"[^"]*")*?\$/', $statement) === 1
            && preg_match('/\b(?:' . self::RAW_EDITS . ')\b/i', $statement) === 1) {
            return [self::UNKNOWN_TABLE];
        }

        return [];
    }
}
