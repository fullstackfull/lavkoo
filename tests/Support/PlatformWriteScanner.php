<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use ReflectionClass;

/**
 * Reads PHP for every way it could write a table that is not its own — the cost and sales-channel modules, and the migrations of the GCC chain against the tables that hold the Saudi
 * platform's customers (`tests/Feature/Storefront/CustomerTablesAreNotRewrittenTest`): the query builder (a table named in a string, a constant, a variable, a property, an alias,
 * `from()`, half a string, a builder kept until a later statement, a join update), raw SQL (a table named, qualified by its database, or interpolated), the SQL of a string handed to
 * anything at all, the schema builder, and the platform's models (made, bound, fetched and saved on another statement, imported under another name, handed on to a second
 * variable, or written through a relation of one of them). It reads text, not behaviour: what it cannot resolve it reports as "(a table named in a variable)", which the guard's list has to name, and an expression it does not
 * recognise at all counts as unresolved — `DB::table(config('x'))` and `DB::unprepared(sprintf(...))` name a table this scanner cannot read, exactly as `DB::table($name)` does.
 * It also reads the framework for the blueprint methods that add and drop a column, so that half of the question is not a list that goes stale either.
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

        foreach ($this->statementsOf($code) as $statement) {
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
    private function rawSqlWrites(string $statement): array
    {
        if (preg_match('/' . self::RAW_SQL_CALL . '/', $statement) !== 1) {
            return [];
        }
        preg_match_all('/\b(?:insert\s+(?:ignore\s+)?into|replace\s+into|update|delete\s+from|truncate\s+table|alter\s+table|drop\s+table|create\s+table|rename\s+table|into\s+table)\s+(?:[`"\']?[a-z0-9_]+[`"\']?\s*\.\s*)?[`"\']?(\{?\$\w+\}?|[a-z0-9_]+)/i', $statement, $found);
        $written = array_map(static fn (string $table): string => str_contains($table, '$') ? self::UNKNOWN_TABLE : strtolower($table), $found[1]);

        if (preg_match('/(?:' . self::RAW_SQL_CALL . ')\s*(?:[^\'"\s]|(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*\.)/', $statement) === 1) {
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
        $copy = $this->variableCopiedIn($statement);
        if ($copy !== null && isset($models[$copy[1]])) {
            $models[$copy[0]] = $models[$copy[1]];
        }
        if (preg_match('/->\s*(' . implode('|', array_map('lcfirst', $this->platformModels)) . ')(?:\(\))?\s*->\s*(?:' . self::MODEL_WRITES . ')\(/', $statement, $relation) === 1) {
            $written[] = 'model ' . ucfirst($relation[1]);
        }
        foreach ($models as $variable => $model) {
            $held = preg_quote($variable, '/');
            if (preg_match('/' . $held . '\s*->\s*(?:' . self::MODEL_WRITES . ')\(/', $statement) === 1
                || preg_match('/' . $held . '\s*->\s*\w+\s*=[^=]/', $statement) === 1
                || preg_match('/' . $held . '\s*->\s*\w+(?:\(\))?\s*->\s*(?:' . self::MODEL_WRITES . ')\(/', $statement) === 1) {
                $written[] = 'model ' . $model;
            }
        }

        return $written;
    }
}
