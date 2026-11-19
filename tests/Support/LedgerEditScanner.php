<?php

namespace Tests\Support;

use PhpToken;

/**
 * Reads PHP source for every statement that could UPDATE or DELETE a row of a ledger table, and reports it with the way it is made. Inserting is what a ledger is for and is never reported.
 *
 * It follows a call chain from where the ledger enters it to the call that edits. The ledger enters through: a ledger model class (`WalletTransaction::where(...)->delete()`, `::destroy()`, `::truncate()`); a query on a
 * ledger table by `table()`, `from()` or `join()` (an alias, a schema name or `DB::connection()` in front change nothing, and the table may be named by a string or by a constant that is the whole argument);
 * the schema builder (`drop`, `dropIfExists`, `rename`); a relation to the ledger by name; a model or a repository that a class holds in a typed property or takes as a typed parameter; a model or a repository made by
 * `new`, `app()`, `resolve()` or `->make()` (`App::make()`, `$this->app->make()`, `app()->make()`), chained at once or kept in a variable. A variable that holds a ledger row, many rows, a query, a new model or a
 * repository (assigned, assigned from itself, looped over, or the first parameter of an `each` closure) is followed to the later statement that updates, deletes or saves it; a repository's `getFirstWhere()` returns a
 * row. Raw SQL is read from the string literals (UPDATE, DELETE, TRUNCATE, REPLACE INTO, DROP TABLE, an upsert, an UPDATE that joins the ledger), with literals joined across a `.`. Comments are never read.
 *
 * It reads text, not behaviour, and does not see: a table named in a variable (`DB::table($table)`), by a constant it does not know (it knows `NAME`, `self::NAME`, `static::NAME` and `Class::NAME` by the constants of
 * the file and of the files it is told about, see `constantsDeclaredIn()`) or by a constant joined to other text; raw SQL built from pieces that are not literals (a constant or a variable in the middle of the
 * string); a ledger model or repository assigned to a property that has no ledger type (`$this->model = new WalletTransaction()`) and used on a later statement; a closure parameter that is not the first parameter
 * of an `each`/`map`/`chunk` callback; a model that reaches a statement through an array, a property or a return value; a `save()` on a model that was handed in (it may be a new one); a model that edits itself
 * (`$this->update()`, `self::where()->delete()`) inside its own class; a typed property declared in a file the scan is not told about (see `propertiesDeclaredIn()`); a ledger repository reached through a type that
 * is not a ledger one (the generic `RepositoryInterface`); and a call made through a variable method name, `call_user_func` or a container call that does not name the class (`app($class)`).
 */
final class LedgerEditScanner
{
    public const MODEL_CLASS = 'a ledger model class';

    public const QUERY_BUILDER = 'a query on a ledger table';

    public const RAW_SQL = 'raw SQL';

    public const SCHEMA = 'the schema builder';

    public const RELATION = 'a relation to the ledger';

    public const HELD_ROW = 'a ledger row or query held in a variable';

    public const INJECTED_MODEL = 'a ledger model held in a property of a class';

    public const PARAMETER_MODEL = 'a ledger model handed in as a parameter';

    public const REPOSITORY = 'a ledger repository';

    /** Calls that change or remove rows that exist, on a query, a model class, a relation or a model. None of them is ever an insert. */
    private const EDITS = [
        'update', 'updateOrCreate', 'updateOrInsert', 'upsert', 'delete', 'forceDelete', 'destroy', 'truncate', 'increment', 'decrement', 'incrementEach', 'decrementEach',
        'touch', 'restore', 'updateQuietly', 'deleteQuietly', 'forceDeleteQuietly', 'incrementQuietly', 'decrementQuietly',
    ];

    /** Calls that write the model they are called on: an insert for a new model, an update for one that was fetched. */
    private const SAVES = ['save', 'saveQuietly', 'push'];

    /** Calls that return one row that exists: the query builder's, and `getFirstWhere()` of the repository contract. */
    private const FETCHES = ['find', 'findOrFail', 'findMany', 'first', 'firstOrFail', 'firstWhere', 'getFirstWhere', 'sole', 'last'];

    /** Calls that return many rows that exist. */
    private const FETCH_MANY = ['get', 'all', 'cursor', 'lazy'];

    /** Calls that hand each row to a closure. */
    private const ITERATORS = ['each', 'map', 'chunk', 'chunkById', 'lazy', 'filter', 'reject', 'transform', 'walk'];

    /** Calls that name a table to a query builder. */
    private const TABLE_CALLS = ['table', 'from', 'join', 'leftJoin', 'rightJoin', 'crossJoin'];

    private const SCHEMA_DROPS = ['drop', 'dropIfExists', 'rename'];

    /** What a variable can hold, by the name the scan keeps it under. */
    private const HOLDS_ROW = 'row';

    private const HOLDS_ROWS = 'rows';

    private const HOLDS_QUERY = 'query';

    private const HOLDS_REPOSITORY = 'repository';

    /** Functions that take a class from the container. */
    private const CONTAINER_CALLS = ['app', 'resolve', 'make'];

    /** Methods that take a class from the container: `App::make()`, `$this->app->make()`, `app()->make()`, `$container->get()`. */
    private const CONTAINER_METHODS = ['make', 'makeWith', 'resolve', 'get'];

    private string $source = '';

    /** @var list<string> the ledger models by the name this file uses: their own, and the alias of a `use ... as ...` */
    private array $modelNames = [];

    /** @var array<string, string> the table by name of the string constants this file declares and of the ones the scan is told about */
    private array $knownConstants = [];

    /**
     * @param list<string> $tables ledger tables, such as `wallet_transactions`
     * @param list<string> $models ledger models by class name, such as `WalletTransaction`
     * @param list<string> $repositories repositories and repository interfaces of the ledger, by class name
     * @param list<string> $relations names of the relations (methods of other models) that return ledger rows, see relationsTo()
     * @param array<string, string> $properties properties declared in other files (a trait, a parent class) that hold a ledger model or repository, kind by name, see propertiesDeclaredIn()
     * @param array<string, string> $constants constants declared in other files whose value is a ledger table, table by name, see constantsDeclaredIn()
     */
    public function __construct(
        private readonly array $tables,
        private readonly array $models,
        private readonly array $repositories = [],
        private readonly array $relations = [],
        private readonly array $properties = [],
        private readonly array $constants = [],
    ) {
    }

    /**
     * The relations to a ledger model that a file defines: `public function walletTransactions() { return $this->hasMany(WalletTransaction::class); }`.
     *
     * @param list<string> $models
     * @return list<string> method names
     */
    public static function relationsTo(array $models, string $code): array
    {
        $names = implode('|', array_map(static fn (string $model): string => preg_quote($model, '/'), $models));
        $pattern = '/function\s+(\w+)\s*\([^)]*\)\s*(?::\s*[\w\\\\|?]+\s*)?\{\s*return\s+\$this->(?:hasMany|hasOne|hasManyThrough|hasOneThrough|morphMany|morphOne|belongsToMany|morphToMany|belongsTo|morphTo)\(\s*[\'"]?\\\\?(?:App\\\\+Models\\\\+)?(?:' . $names . ')(?:::class|[\'"])/';
        preg_match_all($pattern, $code, $found);

        return array_values(array_unique($found[1]));
    }

    /**
     * The properties a file declares that hold a ledger model or a ledger repository, by name: `private readonly WalletTransactionRepository $walletTransactionRepo`. A class that uses a trait reaches
     * them as `$this->walletTransactionRepo` without declaring them, so the scan is told the names of the whole tree.
     *
     * @return array<string, string> kind by property name
     */
    public function propertiesDeclaredIn(string $code): array
    {
        $tokens = $this->tokensOf(code: $code);
        $this->modelNames = [...$this->models, ...$this->aliasesIn(tokens: $tokens)];

        return $this->declarationsIn(tokens: $tokens)['properties'];
    }

    /**
     * The string constants a file declares whose value is a ledger table, by name: `private const LEDGER = 'wallet_transactions';`. A class that names its table `Tables::WALLET` reaches it from another file, so
     * the scan is told the names of the whole tree.
     *
     * @return array<string, string> table by constant name
     */
    public function constantsDeclaredIn(string $code): array
    {
        return array_filter($this->constantsIn(tokens: $this->tokensOf(code: $code)), fn (string $table): bool => $this->isLedgerTable(name: $table));
    }

    /** Whether a file names a ledger at all, by a model, a table, a repository, a relation, a property or a constant: a file that does not cannot edit one. */
    public function mentionsTheLedger(string $code): bool
    {
        $names = array_map(static fn (string $name): string => preg_quote($name, '/'), [...$this->models, ...$this->tables, ...$this->repositories, ...$this->relations, ...array_keys($this->properties), ...array_keys($this->constants)]);

        return preg_match('/\b(?:' . implode('|', $names) . ')\b/', $code) === 1;
    }

    /**
     * Every edit of a ledger row in a piece of PHP, with the way it is made.
     *
     * @return list<array{kind: string, line: int, statement: string}> in the order of the source
     */
    public function editsIn(string $code): array
    {
        $tokens = $this->tokensOf(code: $code);
        $this->modelNames = [...$this->models, ...$this->aliasesIn(tokens: $tokens)];
        $this->knownConstants = $this->constantsIn(tokens: $tokens) + $this->constants;
        $declared = $this->declarationsIn(tokens: $tokens);
        $declared['properties'] += $this->properties;

        $found = [];
        $held = [];
        $this->scan(tokens: $tokens, from: 0, to: count($tokens), held: $held, declared: $declared, found: $found);
        array_push($found, ...$this->rawSqlEditsIn(tokens: $tokens));
        usort($found, static fn (array $first, array $second): int => $first['line'] <=> $second['line']);

        return $found;
    }

    /** @return list<PhpToken> the tokens of the code without comments and whitespace */
    private function tokensOf(string $code): array
    {
        $this->source = str_starts_with(ltrim($code), '<?php') ? $code : '<?php ' . $code;

        return array_values(array_filter(PhpToken::tokenize($this->source), static fn (PhpToken $token): bool => !$token->isIgnorable()));
    }

    /**
     * @param list<PhpToken> $tokens
     * @param array<string, string> $held variables that hold ledger rows (HOLDS_ROW), many of them (HOLDS_ROWS), a ledger query or a new model (HOLDS_QUERY) or a ledger repository (HOLDS_REPOSITORY)
     * @param array{properties: array<string, string>, parameters: array<string, string>} $declared
     * @param list<array{kind: string, line: int, statement: string}> $found
     */
    private function scan(array $tokens, int $from, int $to, array &$held, array $declared, array &$found): void
    {
        for ($index = $from; $index < $to; $index++) {
            $token = $tokens[$index];

            if ($token->id === T_FUNCTION && ($tokens[$index + 1]->id ?? null) === T_STRING) {
                $held = [];
            } elseif ($token->id === T_FOREACH) {
                $this->bindLoopVariable(tokens: $tokens, foreach: $index, held: $held, declared: $declared);
            } elseif ($token->id === T_VARIABLE && ($tokens[$index + 1]->text ?? '') === '=') {
                $this->bindAssignedVariable(tokens: $tokens, variable: $index, held: $held, declared: $declared);
            }

            $chain = $this->chainAt(tokens: $tokens, start: $index);
            if ($chain === null) {
                continue;
            }

            $entry = $this->entryOf(chain: $chain, tokens: $tokens, held: $held, declared: $declared);
            $kind = $entry === null ? null : $this->editKindOf(chain: $chain, entry: $entry);
            if ($kind !== null) {
                $found[] = ['kind' => $kind, 'line' => $tokens[$index]->line, 'statement' => $this->textOf(tokens: $tokens, from: $index, to: $chain['end'])];
            }

            foreach ($chain['arguments'] as $argument) {
                $inner = $held;
                if ($entry !== null && in_array($argument['name'], self::ITERATORS, true)) {
                    $this->bindClosureParameter(tokens: $tokens, from: $argument['from'], to: $argument['to'], held: $inner);
                }
                $this->scan(tokens: $tokens, from: $argument['from'], to: $argument['to'], held: $inner, declared: $declared, found: $found);
            }
            $index = $chain['end'] - 1;
        }
    }

    /**
     * The call chain that starts at a token: where it starts (a variable, a class, a call of a function or a group in brackets), its segments (`->name` or `::name`, with the span of the arguments of a
     * call) and where it ends. Null when no chain starts there.
     *
     * @param list<PhpToken> $tokens
     * @return array{end: int, root: array{type: string, name: string, from: int, to: int}, segments: list<array{name: string, call: bool, from: int, to: int}>, arguments: list<array{name: string, from: int, to: int}>}|null
     */
    private function chainAt(array $tokens, int $start): ?array
    {
        $root = $this->rootAt(tokens: $tokens, start: $start);
        if ($root === null) {
            return null;
        }

        $segments = [];
        $arguments = in_array($root['type'], ['function', 'group'], true) ? [['name' => $root['name'], 'from' => $root['from'], 'to' => $root['to']]] : [];
        $position = $root['end'];
        while (in_array($tokens[$position]->text ?? '', ['->', '?->', '::'], true) && isset($tokens[$position + 1]) && $tokens[$position + 1]->is([T_STRING, T_VARIABLE, T_CLASS])) {
            $name = ltrim($tokens[$position + 1]->text, '$');
            $position += 2;
            if (($tokens[$position]->text ?? '') !== '(') {
                $segments[] = ['name' => $name, 'call' => false, 'from' => $position, 'to' => $position];
                continue;
            }

            $close = $this->closerOf(tokens: $tokens, open: $position);
            $segments[] = ['name' => $name, 'call' => true, 'from' => $position + 1, 'to' => $close];
            $arguments[] = ['name' => $name, 'from' => $position + 1, 'to' => $close];
            $position = $close + 1;
        }

        if ($segments === [] && !in_array($root['type'], ['function', 'group'], true)) {
            return null;
        }

        return ['end' => $position, 'root' => ['type' => $root['type'], 'name' => $root['name'], 'from' => $root['from'], 'to' => $root['to']], 'segments' => $segments, 'arguments' => $arguments];
    }

    /**
     * What a chain starts from: `$variable->`, `Class::`, `function(...)` (a call of a function, not a declaration or a method) or `(...)` that is not the brackets of a call or of a keyword.
     *
     * @param list<PhpToken> $tokens
     * @return array{type: string, name: string, from: int, to: int, end: int}|null
     */
    private function rootAt(array $tokens, int $start): ?array
    {
        $token = $tokens[$start];
        $next = $tokens[$start + 1] ?? null;
        $previous = $tokens[$start - 1] ?? null;

        if ($token->id === T_VARIABLE && $next !== null && in_array($next->text, ['->', '?->'], true)) {
            return ['type' => 'variable', 'name' => ltrim($token->text, '$'), 'from' => 0, 'to' => 0, 'end' => $start + 1];
        }
        if ($this->isName(token: $token) && $next !== null && $next->text === '::') {
            return ['type' => 'static', 'name' => $this->shortName(name: $token->text), 'from' => 0, 'to' => 0, 'end' => $start + 1];
        }
        if ($token->id === T_STRING && $next !== null && $next->text === '(' && ($previous === null || !$previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST, T_ATTRIBUTE]))) {
            $close = $this->closerOf(tokens: $tokens, open: $start + 1);

            return ['type' => 'function', 'name' => $token->text, 'from' => $start + 2, 'to' => $close, 'end' => $close + 1];
        }
        if ($token->text === '(' && ($previous === null || !($previous->is([T_STRING, T_VARIABLE, T_FUNCTION, T_FN, T_NEW, T_ARRAY, T_ISSET, T_EMPTY, T_LIST, T_STATIC]) || in_array($previous->text, [')', ']'], true)))) {
            $close = $this->closerOf(tokens: $tokens, open: $start);
            $instantiated = ($tokens[$start + 1]->id ?? null) === T_NEW ? $this->shortName(name: $tokens[$start + 2]->text ?? '') : '';

            return ['type' => 'group', 'name' => $instantiated, 'from' => $start + 1, 'to' => $close, 'end' => $close + 1];
        }

        return null;
    }

    /**
     * Where the ledger enters a chain: the kind of way it is, the index of the first segment after it, and whether what it hands on is a row that exists. Null when the chain has nothing to do with a ledger.
     *
     * @param array<string, mixed> $chain
     * @param list<PhpToken> $tokens
     * @param array<string, string> $held
     * @param array{properties: array<string, string>, parameters: array<string, string>} $declared
     * @return array{kind: string, from: int, rows: bool}|null
     */
    private function entryOf(array $chain, array $tokens, array $held, array $declared): ?array
    {
        $root = $chain['root'];
        $kind = match ($root['type']) {
            'static', 'group' => $this->ledgerClassKind(name: $root['name']),
            'function' => in_array($root['name'], self::CONTAINER_CALLS, true) ? $this->ledgerClassKindNamedIn(tokens: $tokens, from: $root['from'], to: $root['to']) : null,
            default => null,
        };
        if ($kind !== null) {
            return ['kind' => $kind, 'from' => 0, 'rows' => false];
        }

        return $this->entryOfVariable(chain: $chain, held: $held, declared: $declared) ?? $this->entryOfSegment(chain: $chain, tokens: $tokens);
    }

    /**
     * @param array<string, mixed> $chain
     * @param array<string, string> $held
     * @param array{properties: array<string, string>, parameters: array<string, string>} $declared
     * @return array{kind: string, from: int, rows: bool}|null
     */
    private function entryOfVariable(array $chain, array $held, array $declared): ?array
    {
        $root = $chain['root'];
        $first = $chain['segments'][0] ?? null;
        if ($root['type'] !== 'variable') {
            return null;
        }

        return match (true) {
            $root['name'] === 'this' && $first !== null && !$first['call'] && isset($declared['properties'][$first['name']]) => ['kind' => $declared['properties'][$first['name']], 'from' => 1, 'rows' => false],
            isset($declared['parameters'][$root['name']]) => ['kind' => $declared['parameters'][$root['name']], 'from' => 0, 'rows' => false],
            ($held[$root['name']] ?? null) === self::HOLDS_REPOSITORY => ['kind' => self::REPOSITORY, 'from' => 0, 'rows' => false],
            isset($held[$root['name']]) => ['kind' => self::HELD_ROW, 'from' => 0, 'rows' => $held[$root['name']] === self::HOLDS_ROW],
            default => null,
        };
    }

    /**
     * The ledger enters in the middle of a chain: a segment that names a ledger table to the query builder, drops one with the schema builder, is a relation to the ledger, or takes a ledger class from the
     * container (`App::make(WalletTransaction::class)`, `$this->app->make(...)`, `app()->make(...)`).
     *
     * @param array<string, mixed> $chain
     * @param list<PhpToken> $tokens
     * @return array{kind: string, from: int, rows: bool}|null
     */
    private function entryOfSegment(array $chain, array $tokens): ?array
    {
        foreach ($chain['segments'] as $position => $segment) {
            $table = $segment['call'] ? $this->literalAt(tokens: $tokens, from: $segment['from'], to: $segment['to']) : null;
            if (in_array($segment['name'], self::TABLE_CALLS, true) && $this->isLedgerTable(name: $table)) {
                return ['kind' => self::QUERY_BUILDER, 'from' => $position + 1, 'rows' => false];
            }
            if ($chain['root']['name'] === 'Schema' && in_array($segment['name'], self::SCHEMA_DROPS, true) && $this->isLedgerTable(name: $table)) {
                return ['kind' => self::SCHEMA, 'from' => $position, 'rows' => false];
            }
            if (in_array($segment['name'], $this->relations, true)) {
                return ['kind' => self::RELATION, 'from' => $position + 1, 'rows' => false];
            }

            $made = $segment['call'] && in_array($segment['name'], self::CONTAINER_METHODS, true) ? $this->ledgerClassKindNamedIn(tokens: $tokens, from: $segment['from'], to: $segment['to']) : null;
            if ($made !== null) {
                return ['kind' => $made, 'from' => $position + 1, 'rows' => false];
            }
        }

        return null;
    }

    /**
     * A ledger model or repository made with `new`, and not chained on: `$transaction = new WalletTransaction();`.
     *
     * @param list<PhpToken> $tokens
     * @return array{kind: string, from: int, rows: bool}|null
     */
    private function entryOfNewInstance(array $tokens, int $start): ?array
    {
        $created = $tokens[$start] ?? null;
        $class = $tokens[$start + 1] ?? null;
        $kind = $created !== null && $created->id === T_NEW && $class !== null && $this->isName(token: $class) ? $this->ledgerClassKind(name: $this->shortName(name: $class->text)) : null;

        return $kind === null ? null : ['kind' => $kind, 'from' => 0, 'rows' => false];
    }

    /**
     * The kind of the first edit a chain makes after the point where the ledger enters it, or null. A save is an edit only of a row that was fetched.
     *
     * @param array<string, mixed> $chain
     * @param array{kind: string, from: int, rows: bool} $entry
     */
    private function editKindOf(array $chain, array $entry): ?string
    {
        if ($entry['kind'] === self::SCHEMA) {
            return self::SCHEMA;
        }

        $fetched = $entry['rows'];
        foreach (array_slice($chain['segments'], $entry['from']) as $segment) {
            if ($segment['call'] && (in_array($segment['name'], self::EDITS, true) || ($fetched && in_array($segment['name'], self::SAVES, true)))) {
                return $entry['kind'];
            }
            $fetched = $fetched || in_array($segment['name'], self::FETCHES, true);
        }

        return null;
    }

    /** @return string|null the kind of way a class name is a ledger: a model, or a repository of one */
    private function ledgerClassKind(string $name): ?string
    {
        return match (true) {
            in_array($name, $this->modelNames, true) => self::MODEL_CLASS,
            in_array($name, $this->repositories, true) => self::REPOSITORY,
            default => null,
        };
    }

    /** @param list<PhpToken> $tokens `app(WalletTransaction::class)`: the kind of the first ledger class named between two tokens */
    private function ledgerClassKindNamedIn(array $tokens, int $from, int $to): ?string
    {
        for ($position = $from; $position < $to; $position++) {
            $kind = $this->isName(token: $tokens[$position]) ? $this->ledgerClassKind(name: $this->shortName(name: $tokens[$position]->text)) : null;
            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * `$row = WalletTransaction::find($id);` makes `$row` a ledger row, `$query = DB::table('wallet_transactions')->where(...)` a ledger query, `$rows = ...->get()` many rows, `$model = new WalletTransaction()` or
     * `app(WalletTransaction::class)` a model that is used as a query, `$repository = app(WalletTransactionRepositoryInterface::class)` a repository. A variable that is assigned anything else is no longer held.
     *
     * @param list<PhpToken> $tokens
     * @param array<string, string> $held
     * @param array{properties: array<string, string>, parameters: array<string, string>} $declared
     */
    private function bindAssignedVariable(array $tokens, int $variable, array &$held, array $declared): void
    {
        $name = ltrim($tokens[$variable]->text, '$');
        $chain = $this->chainAt(tokens: $tokens, start: $variable + 2);
        $entry = $chain === null ? $this->entryOfNewInstance(tokens: $tokens, start: $variable + 2) : $this->entryOf(chain: $chain, tokens: $tokens, held: $held, declared: $declared);
        unset($held[$name]);
        if ($entry === null) {
            return;
        }

        $after = $chain === null ? [] : array_slice($chain['segments'], $entry['from']);
        $last = $after === [] ? null : end($after)['name'];
        $held[$name] = match (true) {
            $entry['kind'] === self::REPOSITORY && ($last === null || in_array($last, self::CONTAINER_METHODS, true)) => self::HOLDS_REPOSITORY,
            in_array($last, self::FETCHES, true) => self::HOLDS_ROW,
            in_array($last, self::FETCH_MANY, true) => self::HOLDS_ROWS,
            default => self::HOLDS_QUERY,
        };
    }

    /**
     * `foreach ($ledgerRows as $row)` and `foreach (WalletTransaction::where(...)->get() as $key => $row)` make `$row` a ledger row.
     *
     * @param list<PhpToken> $tokens
     * @param array<string, string> $held
     * @param array{properties: array<string, string>, parameters: array<string, string>} $declared
     */
    private function bindLoopVariable(array $tokens, int $foreach, array &$held, array $declared): void
    {
        $open = $foreach + 1;
        $close = $this->closerOf(tokens: $tokens, open: $open);
        $as = null;
        for ($position = $open + 1; $position < $close && $as === null; $position++) {
            if ($tokens[$position]->id === T_AS) {
                $as = $position;
            } elseif (in_array($tokens[$position]->text, ['(', '[', '{'], true)) {
                $position = $this->closerOf(tokens: $tokens, open: $position);
            }
        }
        if ($as === null || $tokens[$close - 1]->id !== T_VARIABLE) {
            return;
        }

        $subject = $this->chainAt(tokens: $tokens, start: $open + 1);
        $isLedger = $subject !== null
            ? $this->entryOf(chain: $subject, tokens: $tokens, held: $held, declared: $declared) !== null
            : ($as === $open + 2 && $tokens[$open + 1]->id === T_VARIABLE && isset($held[ltrim($tokens[$open + 1]->text, '$')]));
        if ($isLedger) {
            $held[ltrim($tokens[$close - 1]->text, '$')] = self::HOLDS_ROW;
        }
    }

    /**
     * `->each(function ($row) { $row->delete(); })` and `->each(fn ($row) => $row->delete())`: the first parameter of the closure is a ledger row inside it.
     *
     * @param list<PhpToken> $tokens
     * @param array<string, string> $held
     */
    private function bindClosureParameter(array $tokens, int $from, int $to, array &$held): void
    {
        for ($position = $from; $position < $to - 1; $position++) {
            if (!$tokens[$position]->is([T_FUNCTION, T_FN]) || ($tokens[$position + 1]->text ?? '') !== '(') {
                continue;
            }

            $close = $this->closerOf(tokens: $tokens, open: $position + 1);
            for ($parameter = $position + 2; $parameter < $close; $parameter++) {
                if ($tokens[$parameter]->id === T_VARIABLE) {
                    $held[ltrim($tokens[$parameter]->text, '$')] = self::HOLDS_ROW;

                    return;
                }
            }
        }
    }

    /**
     * `use App\Models\WalletTransaction as Ledger;` names the ledger model `Ledger` in this file.
     *
     * @param list<PhpToken> $tokens
     * @return list<string>
     */
    private function aliasesIn(array $tokens): array
    {
        $aliases = [];
        foreach ($tokens as $index => $token) {
            $imported = $tokens[$index - 1] ?? null;
            if ($token->id === T_AS && $imported !== null && $this->isName(token: $imported) && in_array($this->shortName(name: $imported->text), $this->models, true) && ($tokens[$index + 1]->id ?? null) === T_STRING) {
                $aliases[] = $tokens[$index + 1]->text;
            }
        }

        return $aliases;
    }

    /**
     * The typed properties and parameters that are a ledger model or a ledger repository: `private readonly WalletTransaction $walletTransaction` is reached as `$this->walletTransaction`, a parameter
     * `WalletTransaction $transaction` as `$transaction`.
     *
     * @param list<PhpToken> $tokens
     * @return array{properties: array<string, string>, parameters: array<string, string>}
     */
    private function declarationsIn(array $tokens): array
    {
        $declared = ['properties' => [], 'parameters' => []];
        foreach ($tokens as $index => $token) {
            $type = $tokens[$index - 1] ?? null;
            if ($token->id !== T_VARIABLE || $type === null || !$this->isName(token: $type)) {
                continue;
            }

            $kind = $this->ledgerClassKind(name: $this->shortName(name: $type->text));
            if ($kind === null) {
                continue;
            }

            $isProperty = false;
            for ($before = $index - 2; $before >= max(0, $index - 4); $before--) {
                $isProperty = $isProperty || $tokens[$before]->is([T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY, T_VAR]);
            }
            $declared[$isProperty ? 'properties' : 'parameters'][ltrim($token->text, '$')] = match (true) {
                $kind !== self::MODEL_CLASS => $kind,
                $isProperty => self::INJECTED_MODEL,
                default => self::PARAMETER_MODEL,
            };
        }

        return $declared;
    }

    /**
     * @param list<PhpToken> $tokens
     * @return list<array{kind: string, line: int, statement: string}>
     */
    private function rawSqlEditsIn(array $tokens): array
    {
        $patterns = $this->sqlEditPatterns();
        $found = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            if (!$tokens[$index]->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                continue;
            }

            $sql = $this->stringOf(token: $tokens[$index]);
            while (($tokens[$index + 1]->text ?? '') === '.' && ($tokens[$index + 2]->id ?? null) === T_CONSTANT_ENCAPSED_STRING) {
                $sql .= $this->stringOf(token: $tokens[$index + 2]);
                $index += 2;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $sql, $match) === 1) {
                    $found[] = ['kind' => self::RAW_SQL, 'line' => $tokens[$index]->line, 'statement' => trim((string) preg_replace('/\s+/', ' ', $match[0]))];
                    break;
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function sqlEditPatterns(): array
    {
        $table = '(?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?(?:' . implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $this->tables)) . ')[`"\]]?(?!\w)';

        return [
            '/\bupdate\s+(?:low_priority\s+|ignore\s+)*' . $table . '/i',
            '/\bdelete\s+(?:low_priority\s+|quick\s+|ignore\s+)*(?:\w+\s+)?from\s+' . $table . '/i',
            '/\btruncate\s+(?:table\s+)?' . $table . '/i',
            '/\b(?:replace|insert\s+or\s+replace)\s+(?:low_priority\s+|delayed\s+)*into\s+' . $table . '/i',
            '/\binsert\s+(?:ignore\s+)?into\s+' . $table . '[^;]*?\bon\s+(?:duplicate\s+key\s+update|conflict\b[^;]*?\bdo\s+update)/i',
            '/\bdrop\s+table\s+(?:if\s+exists\s+)?' . $table . '/i',
            '/\bupdate\b[^;]*?\bjoin\s+' . $table . '/i',
        ];
    }

    private function stringOf(PhpToken $token): string
    {
        return $token->id === T_CONSTANT_ENCAPSED_STRING ? substr($token->text, 1, -1) : $token->text;
    }

    /** @param list<PhpToken> $tokens the first argument between two tokens, when it is a string literal, or a constant that holds one and is the whole argument */
    private function literalAt(array $tokens, int $from, int $to): ?string
    {
        if ($from >= $to) {
            return null;
        }

        return $tokens[$from]->id === T_CONSTANT_ENCAPSED_STRING
            ? $this->stringOf(token: $tokens[$from])
            : ($this->knownConstants[$this->constantNameAt(tokens: $tokens, from: $from, to: $to) ?? ''] ?? null);
    }

    /** @param list<PhpToken> $tokens the name of the constant that is the whole first argument between two tokens: `NAME`, `self::NAME`, `static::NAME` or `Class::NAME` */
    private function constantNameAt(array $tokens, int $from, int $to): ?string
    {
        $qualified = ($tokens[$from + 1]->id ?? null) === T_DOUBLE_COLON && $from + 2 < $to && ($tokens[$from + 2]->id ?? null) === T_STRING && in_array($tokens[$from + 3]->text ?? '', [',', ')'], true);
        $bare = $tokens[$from]->id === T_STRING && in_array($tokens[$from + 1]->text ?? '', [',', ')'], true);

        return match (true) {
            $qualified => $tokens[$from + 2]->text,
            $bare => $tokens[$from]->text,
            default => null,
        };
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array<string, string> the string constants a file declares, by name: `private const LEDGER = 'wallet_transactions';`
     */
    private function constantsIn(array $tokens): array
    {
        $constants = [];
        foreach ($tokens as $index => $token) {
            if ($token->id !== T_CONST) {
                continue;
            }

            for ($position = $index + 1; isset($tokens[$position + 1]) && $tokens[$position]->text !== ';'; $position++) {
                if ($tokens[$position]->text === '=' && $tokens[$position - 1]->id === T_STRING && $tokens[$position + 1]->id === T_CONSTANT_ENCAPSED_STRING) {
                    $constants[$tokens[$position - 1]->text] = $this->stringOf(token: $tokens[$position + 1]);
                }
            }
        }

        return $constants;
    }

    private function isLedgerTable(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        $table = strtolower(trim(preg_split('/\s+as\s+/i', trim($name))[0]));

        return in_array(trim(substr(strrchr('.' . $table, '.'), 1), '`"[]'), $this->tables, true);
    }

    private function isName(PhpToken $token): bool
    {
        return $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]);
    }

    private function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /** @param list<PhpToken> $tokens the index of the bracket that closes the one at $open */
    private function closerOf(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($position = $open; $position < $count; $position++) {
            if (in_array($tokens[$position]->text, ['(', '[', '{'], true) || $tokens[$position]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($tokens[$position]->text, [')', ']', '}'], true) && --$depth === 0) {
                return $position;
            }
        }

        return $count - 1;
    }

    /** @param list<PhpToken> $tokens the source of the tokens from $from up to (not including) $to, on one line */
    private function textOf(array $tokens, int $from, int $to): string
    {
        $last = $tokens[$to - 1];

        return trim((string) preg_replace('/\s+/', ' ', substr($this->source, $tokens[$from]->pos, $last->pos + strlen($last->text) - $tokens[$from]->pos)));
    }
}
