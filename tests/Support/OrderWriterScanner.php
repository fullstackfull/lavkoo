<?php

namespace Tests\Support;

/**
 * Reads PHP source for the code that makes a row of `orders`, and for the code that calls a method of a class that does. It reads text, not behaviour, and only the spellings below:
 * the creating methods called on the Order model, on a builder of it, on a variable or property the file declares as an Order, on the container's Order, or on a relation named order or
 * orders; `new Order`; the query builder's inserts on `DB::table('orders')`, kept in a variable or not; and raw SQL that inserts into the table. What it cannot resolve (a table named by a
 * constant or a variable, a class named by a string, a receiver typed only in a docblock or in another file) it does not report.
 *
 * Every hit is named by what contains it: see TokenStream.
 */
final class OrderWriterScanner
{
    private const CREATING = ['create', 'forceCreate', 'createQuietly', 'forceCreateQuietly', 'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'firstOrCreate', 'updateOrCreate', 'firstOrNew', 'updateOrInsert', 'make', 'replicate'];

    private const BUILDER_INSERTS = ['insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'upsert', 'updateOrInsert'];

    private const RELATION_WRITES = ['create', 'forceCreate', 'createMany', 'firstOrCreate', 'updateOrCreate', 'save', 'saveMany', 'insert'];

    /** What a file must contain for any of the writer spellings to be in it: reading the tokens of every file that has none of these would only cost time. */
    private const MIGHT_WRITE = '/\bOrder\b|[\'"]orders|->\s*orders?\s*\(|(?i:\binto\s+[`"\']?(?:\w+\.)?orders\b)/';

    /** After one of these the call is made on a model or on a result, so a create that follows belongs to a relation of the order and not to the order. */
    private const LEAVES_THE_BUILDER = ['find', 'findOrFail', 'findMany', 'findOr', 'first', 'firstOrFail', 'firstWhere', 'sole', 'get', 'all', 'cursor', 'lazy', 'paginate', 'simplePaginate', 'cursorPaginate', 'pluck', 'value', 'count', 'sum', 'avg', 'min', 'max', 'exists', 'doesntExist', 'toArray', 'toSql', 'chunk', 'each', 'delete', 'update', 'increment', 'decrement', 'truncate', 'restore', 'forceDelete'];

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
            ? [...$this->staticWrites($stream), ...$this->newOrders($stream), ...$this->receiverWrites($stream), ...$this->relationWrites($stream), ...$this->builderWrites($stream), ...$this->rawSqlWrites($stream)]
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
     * @return list<string> the code that calls the method statically on one of them, on a variable or property the file declares as one of them, or on one the container gives
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
            $static = $stream->isNameOf(index: $index, types: $types) && $stream->id($index + 1) === T_DOUBLE_COLON && $stream->text($index + 2) === $method && $stream->text($index + 3) === '(';
            $container = $stream->isContainerCall(index: $index, types: $types) && $this->callsAfter(stream: $stream, close: $index + 5, methods: [$method]) !== null;

            if ($static || $container || $this->callsOnReceiver(stream: $stream, index: $index, receivers: $receivers, methods: [$method]) !== null) {
                $owners[] = $token['owner'];
            }
        }

        $owners = array_values(array_unique($owners));
        sort($owners);

        return $owners;
    }

    /** @return list<array{0: string, 1: string}> */
    private function staticWrites(TokenStream $stream): array
    {
        $found = [];

        foreach ($stream->tokens as $index => $token) {
            if (!$stream->isNameOf(index: $index, types: ['Order']) || $stream->id($index + 1) !== T_DOUBLE_COLON || $stream->id($index + 2) !== T_STRING || $stream->text($index + 3) !== '(') {
                continue;
            }

            $chain = [$stream->text($index + 2), ...$stream->chainAfter($stream->closingParenthesis($index + 3))];
            $method = $this->creatingMethodOnTheBuilder($chain);
            if ($method !== null) {
                $found[] = [$token['owner'], "Order::{$method}()"];
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

    /**
     * A variable or property the file declares as an Order, and the container's Order: the creating method called on it directly (`$this->order->create()`), not on a relation of it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function receiverWrites(TokenStream $stream): array
    {
        $receivers = $this->declaredReceivers(stream: $stream, types: ['Order']);
        $found = [];

        foreach ($stream->tokens as $index => $token) {
            $method = $this->callsOnReceiver(stream: $stream, index: $index, receivers: $receivers, methods: self::CREATING);
            if ($method !== null) {
                $found[] = [$token['owner'], "a declared Order ->{$method}()"];
            }

            if ($stream->isContainerCall(index: $index, types: ['Order'])) {
                $method = $this->callsAfter(stream: $stream, close: $index + 5, methods: self::CREATING);
                if ($method !== null) {
                    $found[] = [$token['owner'], "container Order ->{$method}()"];
                }
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
                $method = $this->callsAfter(stream: $stream, close: $index + 3, methods: self::RELATION_WRITES);
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
                if ($method !== null) {
                    $found[] = [$token['owner'], "table('orders')->{$method}()"];
                } elseif ($stream->id($index - 1) === T_DOUBLE_COLON && $stream->text($index - 3) === '=' && $stream->id($index - 4) === T_VARIABLE) {
                    $kept[$token['owner']][] = $stream->text($index - 4);
                }
            }

            if ($token['id'] === T_VARIABLE && in_array($token['text'], $kept[$token['owner']] ?? [], true) && $stream->isArrow($index + 1) && in_array($stream->text($index + 2), self::BUILDER_INSERTS, true) && $stream->text($index + 3) === '(') {
                $found[] = [$token['owner'], "a builder of orders ->{$stream->text($index + 2)}()"];
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

    /** @param list<string> $chain the methods called one after another on a builder of the model */
    private function creatingMethodOnTheBuilder(array $chain): ?string
    {
        foreach ($chain as $method) {
            if (in_array($method, self::CREATING, true)) {
                return $method;
            }
            if (in_array($method, self::LEAVES_THE_BUILDER, true)) {
                return null;
            }
        }

        return null;
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
     * The first method called after a closing parenthesis, when it is one of the methods.
     *
     * @param list<string> $methods
     */
    private function callsAfter(TokenStream $stream, int $close, array $methods): ?string
    {
        $method = $stream->chainAfter($close)[0] ?? null;

        return in_array($method, $methods, true) ? $method : null;
    }

    /**
     * The method called directly on a variable or property from the list (`$name->method()`, `$this->name->method()`), when the token at the index starts such a call.
     *
     * @param list<string> $receivers
     * @param list<string> $methods
     */
    private function callsOnReceiver(TokenStream $stream, int $index, array $receivers, array $methods): ?string
    {
        if ($stream->id($index) !== T_VARIABLE) {
            return null;
        }

        $name = $stream->text($index);
        $viaThis = $name === '$this' && $stream->isArrow($index + 1) && $stream->id($index + 2) === T_STRING && in_array($stream->text($index + 2), $receivers, true) && $stream->isArrow($index + 3);
        $viaVariable = $name !== '$this' && in_array(ltrim($name, '$'), $receivers, true) && $stream->isArrow($index + 1) && !$stream->isArrow($index - 1);
        $call = $viaThis ? $index + 4 : ($viaVariable ? $index + 2 : null);

        if ($call !== null && $stream->id($call) === T_STRING && $stream->text($call + 1) === '(' && in_array($stream->text($call), $methods, true)) {
            return $stream->text($call);
        }

        return null;
    }

    /**
     * @param list<string> $types
     * @return list<string> the names of the variables and properties the file declares with one of the types: a parameter, a promoted property, a typed property
     */
    private function declaredReceivers(TokenStream $stream, array $types): array
    {
        $names = [];

        foreach ($stream->tokens as $index => $token) {
            if (!$stream->isNameOf(index: $index, types: $types)) {
                continue;
            }

            $next = $index + 1;
            while (in_array($stream->text($next), ['|', '&'], true) || $stream->isName($next)) {
                $next++;
            }
            if ($stream->id($next) === T_VARIABLE) {
                $names[] = ltrim($stream->text($next), '$');
            }
        }

        return array_values(array_unique($names));
    }

    private function namesTheOrdersTable(?array $token): bool
    {
        return $token !== null && $token['id'] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]orders(?:\s+as\s+\w+)?[\'"]$/i', $token['text']) === 1;
    }
}
