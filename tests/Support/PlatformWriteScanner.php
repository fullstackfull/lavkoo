<?php

namespace Tests\Support;

/**
 * Reads the code of the cost and sales-channel modules for every way it could write a table that is not its own: the query builder (a table named in a string, a constant, a
 * variable, an alias, `from()`, a builder kept in a variable and written on a later statement, a join update), raw SQL (a table named, or interpolated), the schema builder, and the
 * platform's models (made, bound, fetched and saved on another statement, or written through a relation). It reads text, not behaviour: what it cannot resolve it reports as "(a table named in a variable)", which
 * the guard's list has to name.
 *
 * `rowsEditedIn()` asks the narrower question an append-only ledger needs: not what the code WRITES but what it goes back and CHANGES or
 * REMOVES. An insert is not an edit, so the six writers that append a money movement stay off its answer while an update or a delete of
 * one does not, however it is spelled — including through a model the code holds in a property, which is how both ledger repositories
 * reach their own table, through a model handed on from such a property into a variable, and with the call's arguments named, which is
 * this repository's own house style.
 *
 * `schemaDestroyedIn()` asks the third question, about SHAPE rather than rows: a table can keep every row it was ever given and still lose
 * them to a migration that drops the table or one of its columns. The two are deliberately separate, because the ledgers' own migrations
 * legitimately drop them in their `down()` and a row guard that flagged those would need them excused.
 */
final class PlatformWriteScanner
{
    public const UNKNOWN_TABLE = '(a table named in a variable)';

    private const BUILDER_WRITES = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    private const MODEL_WRITES = 'create|forceCreate|update|updateOrCreate|firstOrCreate|insert|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    private const SCHEMA_WRITES = 'table|create|drop|dropIfExists|dropColumns|rename';

    /** The builder calls that change or remove rows that are already there. `insert` and its kin are missing on purpose: appending is not editing. */
    private const BUILDER_EDITS = 'update|updateOrInsert|upsert|delete|truncate|increment|decrement|incrementEach|decrementEach';

    /** The same for a model. `create`, `forceCreate`, `firstOrCreate` and `insert` are missing on purpose; `save` is here because a model fetched and saved again is an edit. */
    private const MODEL_EDITS = 'update|updateOrCreate|upsert|delete|destroy|forceDelete|truncate|increment|decrement|save|saveQuietly|forceFill|fill|push|touch|updateQuietly|deleteQuietly|restore';

    /** The SQL verbs that change or remove rows that are already there; `replace into` is one of them, because it deletes the row it replaces. */
    private const RAW_EDITS = 'update|delete\\s+from|truncate\\s+table|replace\\s+into|alter\\s+table|drop\\s+table|rename\\s+table';

    /** Every SQL verb the scanner reads, writes and edits together. */
    private const RAW_WRITES = 'insert\\s+(?:ignore\\s+)?into|replace\\s+into|update|delete\\s+from|truncate\\s+table|alter\\s+table|drop\\s+table|create\\s+table|rename\\s+table';

    /** The calls that hand a whole statement of SQL to the database. */
    private const RAW_SQL_CALLS = 'DB::(?:statement|unprepared|insert|update|delete|affectingStatement)\\(|->(?:statement|unprepared|affectingStatement)\\(';

    /** The calls whose first argument names the table a query starts from. A join is not one: its other arguments hold columns, not tables. */
    private const TABLE_ARGUMENT_CALLS = 'DB::table|->table|->from';

    /** The schema calls that destroy a whole table. */
    private const SCHEMA_DESTROYS_TABLE = 'drop|dropIfExists|rename';

    /** The blueprint calls that take data off a table that stays: a dropped or renamed column loses every value in it. */
    private const COLUMN_DESTROYERS = 'dropColumn|dropColumns|renameColumn|dropMorphs|dropSoftDeletes|dropTimestamps|dropRememberToken';

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
        return $this->tablesTouchedIn($code, editsOnly: false);
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

        foreach ($this->statementsOf($code) as $statement) {
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
        $statements = $this->statementsOf($code);
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

    /**
     * @param bool $editsOnly true to read only the SQL verbs that change or remove a row that is already there
     * @return list<string>
     */
    private function rawSqlWrites(string $statement, bool $editsOnly): array
    {
        if (preg_match('/' . self::RAW_SQL_CALLS . '/', $statement) !== 1) {
            return [];
        }
        $verbs = $editsOnly ? self::RAW_EDITS : self::RAW_WRITES;
        preg_match_all('/\b(?:' . $verbs . ')\s+[`"\']?(\{?\$\w+\}?|[a-z0-9_]+)/i', $statement, $found);

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
    private function modelWrites(string $statement, array &$models, bool $editsOnly): array
    {
        $names = implode('|', $this->platformModels);
        $verbs = $editsOnly ? self::MODEL_EDITS : self::MODEL_WRITES;
        $written = [];

        if (!$editsOnly && preg_match('/new\s+(' . $names . ')\b/', $statement, $new) === 1) {
            $written[] = 'model ' . $new[1];
        } elseif (preg_match('/\b(' . $names . ')\s*::(?:class\b|[a-zA-Z_]+\()/', $statement, $model) === 1
            && preg_match('/(?:->|::)(?:' . $verbs . ')\(/', $statement) === 1) {
            $written[] = 'model ' . $model[1];
        }

        if (preg_match('/^\s*(\$\w+)\s*=\s*.*\b(' . $names . ')\s*::/s', $statement, $held) === 1 || preg_match('/foreach\s*\(.*\b(' . $names . ')\s*::.*\bas\s+(?:\$\w+\s*=>\s*)?(\$\w+)\)/s', $statement, $loop) === 1) {
            $models[$held[1] ?? $loop[2]] = $held[2] ?? $loop[1];
        }
        if (preg_match('/->\s*(' . implode('|', array_map('lcfirst', $this->platformModels)) . ')(?:\(\))?\s*->\s*(?:' . $verbs . ')\(/', $statement, $relation) === 1) {
            $written[] = 'model ' . ucfirst($relation[1]);
        }
        foreach ($models as $variable => $model) {
            if (preg_match('/' . preg_quote($variable, '/') . '\s*->\s*(?:' . $verbs . ')\(/', $statement) === 1 || preg_match('/' . preg_quote($variable, '/') . '\s*->\s*\w+\s*=[^=]/', $statement) === 1) {
                $written[] = 'model ' . $model;
            }
        }

        return $written;
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

    /** @param array<string, string> $holders added to in place, and never overwritten: a holder keeps the model it was first seen to hold */
    private function rememberWhatHoldsTheModel(string $statement, string $source, string $model, array &$holders): void
    {
        if (preg_match('/(\$\w+)\s*=(?!=)\s*[^=]*?' . $source . '/s', $statement, $assigned) === 1) {
            $holders[$assigned[1]] ??= $model;
        }
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

        if (preg_match('/(?:' . self::RAW_SQL_CALLS . ')\s*(?:[^\'"]|\'[^\']*\'|"[^"]*")*?\$/', $statement) === 1
            && preg_match('/\b(?:' . self::RAW_EDITS . ')\b/i', $statement) === 1) {
            return [self::UNKNOWN_TABLE];
        }

        return [];
    }
}
