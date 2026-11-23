<?php

namespace Tests\Support;

/**
 * Reads PHP source for the code that makes a row of `orders`, and for the code that calls a method of a class that does. It reads text, not behaviour, and only the spellings below: a creating
 * method called on the Order model (by its name, by a name imported with `as`, by `self::`, `static::` and `parent::` inside it or inside a class that extends it), on a builder of it, on a
 * variable or property the file declares as an Order or assigns from the container, on the container's Order, or on a relation named order or orders; `new Order`; the query builder's inserts on
 * `DB::table('orders')`, kept in a variable or not; and raw SQL that inserts into the table. A call to a method is found the same way, and also as `$this->method()` inside the class that has it or
 * one that extends it. What it cannot resolve (a table named by a constant or a variable, a class named by a string, a receiver typed only in a docblock or in another file) it does not
 * report: OrderWriterScannerTest keeps the list.
 *
 * Every hit is named by what contains it: see TokenStream.
 */
final class OrderWriterScanner
{
    public const CREATING = ['create', 'forceCreate', 'createQuietly', 'forceCreateQuietly', 'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'firstOrCreate', 'updateOrCreate', 'firstOrNew', 'updateOrInsert', 'make', 'replicate', 'newInstance', 'newModelInstance'];

    public const BUILDER_INSERTS = ['insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'updateOrInsert'];

    public const RELATION_WRITES = ['create', 'forceCreate', 'createMany', 'firstOrCreate', 'updateOrCreate', 'save', 'saveMany', 'insert'];

    /** After one of these the call is made on a model or on a result, so a create that follows belongs to a relation of the order and not to the order. */
    public const LEAVES_THE_BUILDER = ['find', 'findOrFail', 'findMany', 'findOr', 'first', 'firstOrFail', 'firstWhere', 'sole', 'get', 'all', 'cursor', 'lazy', 'paginate', 'simplePaginate', 'cursorPaginate', 'pluck', 'value', 'count', 'sum', 'avg', 'min', 'max', 'exists', 'doesntExist', 'toArray', 'toSql', 'chunk', 'each', 'delete', 'update', 'increment', 'decrement', 'truncate', 'restore', 'forceDelete'];

    /**
     * What a variable or a property declared as an Order may be asked for before a creating method: it may hold a fetched order, and then the next call is a relation of it (`$order->details()->create()`),
     * so only a call that gives a builder of the model goes on to the creating method.
     */
    public const BUILDER_STEPS = ['query', 'newQuery', 'newModelQuery', 'newQueryWithoutScopes', 'newQueryWithoutScope', 'newQueryWithoutRelationships', 'on', 'onWriteConnection', 'withoutGlobalScopes', 'withoutGlobalScope', 'withGlobalScope', 'withTrashed', 'withoutTrashed', 'onlyTrashed', 'where', 'orWhere', 'whereNot', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween', 'whereDate', 'whereKey', 'whereHas', 'whereDoesntHave', 'whereRaw', 'whereColumn', 'whereExists', 'has', 'doesntHave', 'with', 'withCount', 'without', 'lockForUpdate', 'sharedLock', 'latest', 'oldest', 'orderBy', 'orderByDesc', 'select', 'selectRaw', 'distinct', 'from', 'join', 'leftJoin', 'groupBy', 'limit', 'take', 'skip', 'offset', 'when', 'unless', 'tap', 'scopes', 'toBase', 'getQuery'];

    /** What a file must contain for any of the writer spellings to be in it: reading the tokens of every file that has none of these would only cost time. */
    private const MIGHT_WRITE = '/\bOrder\b|[\'"]orders|->\s*orders?\s*\(|(?i:\binto\s+[`"\']?(?:\w+\.)?orders\b)/';

    /**
     * @param array<string, array{0: list<string>, 1: string}> $callerQueries the receiver types and the method of each call to find, by a key of the caller's choosing
     * @return array{writers: array<string, list<string>>, callers: array<string, list<string>>} what makes a row of orders, by the code that contains it; and who calls each query's method, by query
     */
    public function analyse(string $code, string $file, array $callerQueries = []): array
    {
        $queries = array_filter($callerQueries, static fn (array $query): bool => array_filter($query[0], static fn (string $type): bool => str_contains($code, $type)) !== []);
        $callers = array_map(static fn (array $query): array => [], $callerQueries);
        $mightWrite = preg_match(self::MIGHT_WRITE, $code) === 1;

        if (!$mightWrite && $queries === []) {
            return ['writers' => [], 'callers' => $callers];
        }

        $stream = TokenStream::of(code: $code, file: $file);
        $writers = [];

        $found = $mightWrite
            ? [...$this->newOrders($stream), ...$this->receiverWrites($stream), ...$this->relationWrites($stream), ...$this->builderWrites($stream), ...$this->rawSqlWrites($stream)]
            : [];
        foreach ($found as [$owner, $spelling]) {
            $writers[$owner][] = $spelling;
        }

        foreach ($queries as $key => [$types, $method]) {
            $callers[$key] = $this->callersOf(stream: $stream, types: $types, method: $method);
        }

        return ['writers' => array_map(static fn (array $spellings): array => array_values(array_unique($spellings)), $writers), 'callers' => $callers];
    }

    /** @return array<string, list<string>> */
    public function writersIn(string $code, string $file = 'file.php'): array
    {
        return $this->analyse(code: $code, file: $file)['writers'];
    }

    /**
     * @param list<string> $types the short names of the classes
     * @return list<string> the code that calls the method statically on one of them, on a variable or property the file declares as one of them, on one the container gives, or on itself inside one of them
     */
    public function callersIn(string $code, array $types, string $method, string $file = 'file.php'): array
    {
        return $this->analyse(code: $code, file: $file, callerQueries: ['query' => [$types, $method]])['callers']['query'];
    }

    /**
     * @param list<string> $types
     * @return list<string>
     */
    private function callersOf(TokenStream $stream, array $types, string $method): array
    {
        $receivers = $this->declaredReceivers(stream: $stream, types: $types);
        $owners = [];

        foreach ($stream->tokens as $index => $token) {
            $call = $this->callAt(stream: $stream, index: $index, types: $types, receivers: $receivers, includingTheClassItself: true);
            if (($call['chain'][0] ?? null) === $method) {
                $owners[] = $token['owner'];
            }
        }

        $owners = array_values(array_unique($owners));
        sort($owners);

        return $owners;
    }

    /**
     * The creating methods called on the Order: on its name, on a variable or property declared as one, on the container's.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function receiverWrites(TokenStream $stream): array
    {
        $receivers = $this->declaredReceivers(stream: $stream, types: ['Order']);
        $found = [];

        foreach ($stream->tokens as $index => $token) {
            $call = $this->callAt(stream: $stream, index: $index, types: ['Order'], receivers: $receivers, includingTheClassItself: false);
            if ($call === null) {
                continue;
            }

            $method = match ($call['receiver']) {
                'name', 'container' => $this->creatingMethodOnTheBuilder(chain: $call['chain'], creating: self::CREATING),
                'declared' => $this->creatingMethodOnAReceiver(chain: $call['chain'], creating: self::CREATING),
                default => null,
            };
            if ($method !== null) {
                $found[] = [$token['owner'], match ($call['receiver']) {
                    'name' => "Order::{$method}()",
                    'container' => "container Order ->{$method}()",
                    default => "a declared Order ->{$method}()",
                }];
            }
        }

        return $found;
    }

    /** @return list<array{0: string, 1: string}> */
    private function newOrders(TokenStream $stream): array
    {
        $found = [];

        foreach ($stream->tokens as $index => $token) {
            if ($token['id'] === T_NEW && $stream->isNameOf(index: $index + 1, types: ['Order'])) {
                $found[] = [$token['owner'], 'new Order'];
            }
        }

        return $found;
    }

    /** @return list<array{0: string, 1: string}> */
    private function relationWrites(TokenStream $stream): array
    {
        $found = [];

        foreach ($stream->tokens as $index => $token) {
            if ($stream->isArrow($index) && in_array($stream->text($index + 1), ['orders', 'order'], true) && $stream->text($index + 2) === '(' && $stream->text($index + 3) === ')') {
                $method = $this->creatingMethodOnAReceiver(chain: $stream->chainAfter($index + 3), creating: self::RELATION_WRITES);
                if ($method !== null) {
                    $found[] = [$token['owner'], "->{$stream->text($index + 1)}()->{$method}()"];
                }
            }
        }

        return $found;
    }

    /** @return list<array{0: string, 1: string}> */
    private function builderWrites(TokenStream $stream): array
    {
        $found = [];
        $kept = [];

        foreach ($stream->tokens as $index => $token) {
            $table = $token['id'] === T_STRING && $token['text'] === 'table' && $stream->text($index + 1) === '('
                && in_array($stream->id($index - 1), [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && $this->namesTheOrdersTable($stream->tokens[$index + 2] ?? null);

            if ($table) {
                $method = $this->firstOf(wanted: self::BUILDER_INSERTS, chain: $stream->chainAfter($stream->closingParenthesis($index + 1)));
                $variable = $this->variableAssignedBefore(stream: $stream, index: $index);
                if ($method !== null) {
                    $found[] = [$token['owner'], "table('orders')->{$method}()"];
                } elseif ($variable !== null) {
                    $kept[$token['owner']][] = $variable;
                }
            }

            if ($token['id'] === T_VARIABLE && in_array($token['text'], $kept[$token['owner']] ?? [], true) && $stream->isArrow($index + 1) && $stream->id($index + 2) === T_STRING && $stream->text($index + 3) === '(') {
                $method = $this->firstOf(wanted: self::BUILDER_INSERTS, chain: [$stream->text($index + 2), ...$stream->chainAfter($stream->closingParenthesis($index + 3))]);
                if ($method !== null) {
                    $found[] = [$token['owner'], "a builder of orders ->{$method}()"];
                }
            }
        }

        return $found;
    }

    /** @return list<array{0: string, 1: string}> */
    private function rawSqlWrites(TokenStream $stream): array
    {
        $found = [];

        foreach ($stream->tokens as $token) {
            if (in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match('/\b(?:insert\s+(?:ignore\s+)?into|replace\s+into)\s+[`"\']?(?:\w+\.)?orders\b/i', $token['text']) === 1) {
                $found[] = [$token['owner'], 'raw SQL into orders'];
            }
        }

        return $found;
    }

    /**
     * What a call on a builder of the model is, when the methods called one after another from it are `$chain`: the creating method it reaches, unless a method that leaves the builder comes first.
     *
     * @param list<string> $chain
     * @param list<string> $creating
     */
    private function creatingMethodOnTheBuilder(array $chain, array $creating): ?string
    {
        foreach ($chain as $method) {
            if (in_array($method, $creating, true)) {
                return $method;
            }
            if (in_array($method, self::LEAVES_THE_BUILDER, true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * The same for a variable, a property or a relation, which may hold or be a fetched order: a creating method called on it directly, or reached through the calls that give a builder.
     *
     * @param list<string> $chain
     * @param list<string> $creating
     */
    private function creatingMethodOnAReceiver(array $chain, array $creating): ?string
    {
        $first = $chain[0] ?? null;

        return match (true) {
            in_array($first, $creating, true) => $first,
            in_array($first, self::BUILDER_STEPS, true) => $this->creatingMethodOnTheBuilder(chain: array_slice($chain, 1), creating: $creating),
            default => null,
        };
    }

    /**
     * @param list<string> $wanted
     * @param list<string> $chain
     */
    private function firstOf(array $wanted, array $chain): ?string
    {
        foreach ($chain as $method) {
            if (in_array($method, $wanted, true)) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The call the token at the index starts on one of the types, and the methods called one after another from it: `Type::method()`, `app(Type::class)->method()`, `(new Type())->method()`, a
     * call on a variable or property declared as one of them, and, inside one of them, `self::method()`, `static::method()` and `$this->method()`.
     *
     * @param list<string> $types
     * @param list<string> $receivers
     * @return array{receiver: string, chain: list<string>}|null the receiver is name, container, new, declared or itself
     */
    private function callAt(TokenStream $stream, int $index, array $types, array $receivers, bool $includingTheClassItself): ?array
    {
        if ($stream->isStaticReferenceTo(index: $index, types: $types) && $stream->id($index + 2) === T_STRING && $stream->text($index + 3) === '(') {
            return ['receiver' => 'name', 'chain' => [$stream->text($index + 2), ...$stream->chainAfter($stream->closingParenthesis($index + 3))]];
        }

        if ($stream->isContainerCall(index: $index, types: $types)) {
            return ['receiver' => 'container', 'chain' => $stream->chainAfter($stream->closingParenthesis($index + 1))];
        }

        if ($stream->id($index) === T_NEW && $stream->isNameOf(index: $index + 1, types: $types)) {
            $close = $stream->text($index + 2) === '(' ? $stream->closingParenthesis($index + 2) : $index + 1;

            return ['receiver' => 'new', 'chain' => $stream->chainAfter($stream->text($close + 1) === ')' ? $close + 1 : $close)];
        }

        $call = $this->callOnReceiver(stream: $stream, index: $index, receivers: $receivers);
        if ($call !== null) {
            return ['receiver' => 'declared', 'chain' => [$stream->text($call), ...$stream->chainAfter($stream->closingParenthesis($call + 1))]];
        }

        if ($includingTheClassItself && $stream->text($index) === '$this' && $stream->isArrow($index + 1) && $stream->id($index + 2) === T_STRING && $stream->text($index + 3) === '(' && $stream->standsInOneOf(index: $index, types: $types)) {
            return ['receiver' => 'itself', 'chain' => [$stream->text($index + 2), ...$stream->chainAfter($stream->closingParenthesis($index + 3))]];
        }

        return null;
    }

    /**
     * The index of the method called directly on a variable or property from the list (`$name->method()`, `$this->name->method()`), when the token at the index starts such a call.
     *
     * @param list<string> $receivers
     */
    private function callOnReceiver(TokenStream $stream, int $index, array $receivers): ?int
    {
        if ($stream->id($index) !== T_VARIABLE) {
            return null;
        }

        $name = $stream->text($index);
        $viaThis = $name === '$this' && $stream->isArrow($index + 1) && $stream->id($index + 2) === T_STRING && in_array($stream->text($index + 2), $receivers, true) && $stream->isArrow($index + 3);
        $viaVariable = $name !== '$this' && in_array(ltrim($name, '$'), $receivers, true) && $stream->isArrow($index + 1) && !$stream->isArrow($index - 1);
        $call = $viaThis ? $index + 4 : ($viaVariable ? $index + 2 : null);

        return $call !== null && $stream->id($call) === T_STRING && $stream->text($call + 1) === '(' ? $call : null;
    }

    /**
     * @param list<string> $types
     * @return list<string> the names of the variables and properties the file declares with one of the types (a parameter, a promoted property, a typed property) or assigns from the container or from `new`
     */
    private function declaredReceivers(TokenStream $stream, array $types): array
    {
        $names = [];

        foreach ($stream->tokens as $index => $token) {
            if ($stream->isNameOf(index: $index, types: $types)) {
                $next = $index + 1;
                while (in_array($stream->text($next), ['|', '&'], true) || $stream->isName($next)) {
                    $next++;
                }
                if ($stream->id($next) === T_VARIABLE) {
                    $names[] = ltrim($stream->text($next), '$');
                }
            }

            $assigned = $this->assignedName(stream: $stream, index: $index);
            if ($assigned !== null && ($stream->isContainerCall(index: $assigned['value'], types: $types) || ($stream->id($assigned['value']) === T_NEW && $stream->isNameOf(index: $assigned['value'] + 1, types: $types)))) {
                $names[] = $assigned['name'];
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array{name: string, value: int}|null the variable or property that `$name = ` or `$this->name = ` at the index assigns, and the index of the first token of the value */
    private function assignedName(TokenStream $stream, int $index): ?array
    {
        if ($stream->id($index) !== T_VARIABLE) {
            return null;
        }

        if ($stream->text($index) !== '$this') {
            return $stream->text($index + 1) === '=' ? ['name' => ltrim($stream->text($index), '$'), 'value' => $index + 2] : null;
        }

        return $stream->isArrow($index + 1) && $stream->id($index + 2) === T_STRING && $stream->text($index + 3) === '=' ? ['name' => $stream->text($index + 2), 'value' => $index + 4] : null;
    }

    /** The variable that the statement the token is in assigns (`$query = DB::connection('shop')->table('orders')->where(...)`), when it starts by assigning one. */
    private function variableAssignedBefore(TokenStream $stream, int $index): ?string
    {
        for ($back = $index - 1; $back > 0 && !in_array($stream->id($back), [';', '{', '}'], true); $back--) {
            if ($stream->id($back) === '=' && $stream->id($back - 1) === T_VARIABLE) {
                return $stream->text($back - 1);
            }
        }

        return null;
    }

    private function namesTheOrdersTable(?array $token): bool
    {
        return $token !== null && $token['id'] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"](?:\w+\.)?orders(?:\s+as\s+\w+)?[\'"]$/i', $token['text']) === 1;
    }
}
