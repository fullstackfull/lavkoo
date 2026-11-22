<?php

namespace Tests\Support;

/**
 * Reads one PHP source for every cache key it reads, writes or forgets, and says whether the key is the storefront's own: run through `scopedCacheKey` (or another of the wrappers
 * StorefrontContext and StorefrontCatalog offer), or listed by `keysForEveryStorefront` and `keysForStorefronts`, the way a flush forgets the copy of each storefront. It reads text,
 * not behaviour: a key it cannot resolve to words is reported as "(a key held in a variable)", which a list that declares keys has to name.
 *
 * A key is the first argument of `Cache::remember` and of the other calls of the facade (the `key:` argument when the call names its arguments), of `cache()->remember`, and of a call
 * made after a store is picked (`Cache::store('redis')->put`). A constant is read where the source defines it, or in the constants of the other classes it was given. A variable is
 * read as the nearest statement before the call that gave it a value in the same function: an assignment, or the `foreach` that hands it a key. Every part of a key that is not a
 * word (a variable, a call) is written `*`, so `'commerce_experiments_' . $page` is `commerce_experiments_*`, whichever way it is built.
 */
final class CacheKeyScanner
{
    public const UNKNOWN_KEY = '(a key held in a variable)';

    /** The calls of the cache that name a key. `flush` names none. */
    private const CALLS_WITH_A_KEY = ['remember', 'rememberforever', 'sear', 'flexible', 'put', 'add', 'forever', 'forget', 'has', 'missing', 'get', 'pull', 'increment', 'decrement', 'touch'];

    /** What the facade is asked before the call that names the key: `Cache::store('redis')->put(...)`. */
    private const CHOICES_OF_STORE = ['store', 'driver', 'tags', 'memo'];

    /** The calls that make a key the storefront's own, or list the key of every storefront. */
    private const STOREFRONT_KEYS = '/(?:->|::|\b)(?:scopedCacheKey|storefrontCacheKey|keysForEveryStorefront|keysForStorefronts|cacheKeysForEveryStorefront)\(|\bStorefrontCatalog::cacheKey\(/';

    /** The calls that hand a key on, with the storefront it names or the one they list. */
    private const CALLS_THAT_HAND_A_KEY_ON = ['scopedCacheKey', 'storefrontCacheKey', 'keysForEveryStorefront', 'keysForStorefronts', 'cacheKeysForEveryStorefront', 'cacheKey'];

    /** How far a variable is followed back through the statements that gave it a value. */
    private const DEPTH = 4;

    /** @var list<array{0: int|string, 1: string}> */
    private readonly array $tokens;

    private readonly string $class;

    /** @var array<string, array<string, string>> */
    private readonly array $constants;

    /** @param array<string, array<string, string>> $constants the string constants of the other classes, by class name, for a key built from another class's constant */
    public function __construct(string $code, array $constants = [])
    {
        $this->tokens = self::tokensOf(code: $code);
        $this->class = self::classIn(tokens: $this->tokens);
        $this->constants = [...$constants, ...self::constantsIn(code: $code)];
    }

    /**
     * The string constants a source defines, by the class that defines them.
     *
     * @return array<string, array<string, string>>
     */
    public static function constantsIn(string $code): array
    {
        $tokens = self::tokensOf(code: $code);
        $class = self::classIn(tokens: $tokens);
        $constants = [];

        foreach ($tokens as $position => $token) {
            if ($token[0] !== T_CONST) {
                continue;
            }

            $equals = self::equalsOfTheConstant(tokens: $tokens, from: $position);
            $isAWord = ($tokens[$equals][0] ?? null) === '=' && ($tokens[$equals - 1][0] ?? null) === T_STRING && ($tokens[$equals + 1][0] ?? null) === T_CONSTANT_ENCAPSED_STRING;
            if ($isAWord) {
                $constants[$class][$tokens[$equals - 1][1]] = self::unquoted(literal: $tokens[$equals + 1][1]);
            }
        }

        return $constants;
    }

    /**
     * Every cache key the source names, in the order it names them.
     *
     * @return list<array{method: string, key: string, storefront: bool}>
     */
    public function keys(): array
    {
        $bodies = $this->functionBodies();
        $found = [];

        foreach (array_keys($this->tokens) as $position) {
            $call = $this->cacheCallAt(position: $position);
            if ($call === null) {
                continue;
            }

            $key = $this->keyOf(arguments: $this->argumentsOf(open: $call['open']));
            $definitions = $this->definitionsIn(body: $this->innermostBody(bodies: $bodies, position: $position));
            $found[] = [
                'method' => $call['method'],
                'key' => $this->keyText(key: $key, at: $position, definitions: $definitions, depth: 0),
                'storefront' => $this->carriesStorefront(key: $key, at: $position, definitions: $definitions, depth: 0),
            ];
        }

        return $found;
    }

    /**
     * The code as tokens, without whitespace or comments. A token is its id (the character itself for the single-character ones) and its text.
     *
     * @return list<array{0: int|string, 1: string}>
     */
    private static function tokensOf(string $code): array
    {
        $tokens = [];
        foreach (token_get_all(str_starts_with($code, '<?php') ? $code : '<?php ' . $code) as $token) {
            $token = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            if (!in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /** @param list<array{0: int|string, 1: string}> $tokens */
    private static function classIn(array $tokens): string
    {
        foreach ($tokens as $position => $token) {
            if ($token[0] === T_CLASS && ($tokens[$position + 1][0] ?? null) === T_STRING) {
                return $tokens[$position + 1][1];
            }
        }

        return '';
    }

    /** @param list<array{0: int|string, 1: string}> $tokens */
    private static function equalsOfTheConstant(array $tokens, int $from): int
    {
        $position = $from;
        while (isset($tokens[$position]) && !in_array($tokens[$position][0], ['=', ';'], true)) {
            $position++;
        }

        return $position;
    }

    private static function unquoted(string $literal): string
    {
        $text = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $text) : $text;
    }

    /**
     * The call that names a key, when one starts at the position: the facade, or `cache()`, with a store picked first or not.
     *
     * @return array{method: string, open: int}|null the method and the position of the parenthesis that opens its arguments
     */
    private function cacheCallAt(int $position): ?array
    {
        $before = $this->tokens[$position - 1][0] ?? null;
        if (in_array($before, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            return null;
        }

        $next = $position + 1;
        if ($this->isTheCacheFacade(token: $this->tokens[$position]) && ($this->tokens[$next][0] ?? null) === T_DOUBLE_COLON) {
            $next++;
        } elseif ($this->isTheCacheHelper(position: $position)) {
            $next += 3;
        } else {
            return null;
        }

        if (in_array(strtolower($this->tokens[$next][1] ?? ''), self::CHOICES_OF_STORE, true) && ($this->tokens[$next + 1][0] ?? null) === '(') {
            $next = $this->closing(open: $next + 1) + 2;
        }

        $method = $this->tokens[$next] ?? null;
        if ($method === null || !in_array(strtolower($method[1]), self::CALLS_WITH_A_KEY, true) || ($this->tokens[$next + 1][0] ?? null) !== '(') {
            return null;
        }

        return ['method' => $method[1], 'open' => $next + 1];
    }

    /** `cache()->`, which is the cache the way the facade is. */
    private function isTheCacheHelper(int $position): bool
    {
        $ids = array_column(array_slice($this->tokens, $position, 4), 0);

        return $ids === [T_STRING, '(', ')', T_OBJECT_OPERATOR] && strtolower($this->tokens[$position][1]) === 'cache';
    }

    /** @param array{0: int|string, 1: string} $token */
    private function isTheCacheFacade(array $token): bool
    {
        return in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/^\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*Cache$/', $token[1]) === 1;
    }

    /**
     * The arguments of the call whose parenthesis opens at the position, each as its tokens.
     *
     * @param list<array{0: int|string, 1: string}>|null $within the tokens to read instead of the source's own
     * @return list<list<array{0: int|string, 1: string}>>
     */
    private function argumentsOf(int $open, ?array $within = null): array
    {
        $tokens = $within ?? $this->tokens;
        $arguments = [[]];
        $depth = 0;

        for ($position = $open + 1, $last = count($tokens); $position < $last; $position++) {
            $id = $tokens[$position][0];
            if ($depth === 0 && $id === ')') {
                break;
            }
            if ($depth === 0 && $id === ',') {
                $arguments[] = [];
                continue;
            }
            $depth += $this->opens(id: $id) - $this->closes(id: $id);
            $arguments[array_key_last($arguments)][] = $tokens[$position];
        }

        return array_values(array_filter($arguments, static fn (array $argument): bool => $argument !== []));
    }

    /**
     * The argument that is the key: the one named `key`, else the first.
     *
     * @param list<list<array{0: int|string, 1: string}>> $arguments
     * @return list<array{0: int|string, 1: string}>
     */
    private function keyOf(array $arguments): array
    {
        foreach ($arguments as $argument) {
            if ($this->isNamed(argument: $argument, name: 'key')) {
                return array_slice($argument, 2);
            }
        }

        $first = $arguments[0] ?? [];

        return $this->isNamed(argument: $first) ? [] : $first;
    }

    /** @param list<array{0: int|string, 1: string}> $argument */
    private function isNamed(array $argument, ?string $name = null): bool
    {
        return ($argument[0][0] ?? null) === T_STRING && ($argument[1][0] ?? null) === ':' && ($name === null || $argument[0][1] === $name);
    }

    /** The position of the bracket that closes the one at the position. */
    private function closing(int $open): int
    {
        $depth = 0;
        for ($position = $open, $last = count($this->tokens); $position < $last; $position++) {
            $depth += $this->opens(id: $this->tokens[$position][0]) - $this->closes(id: $this->tokens[$position][0]);
            if ($depth === 0) {
                return $position;
            }
        }

        return $last - 1;
    }

    private function opens(int|string $id): int
    {
        return in_array($id, ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) ? 1 : 0;
    }

    private function closes(int|string $id): int
    {
        return in_array($id, [')', ']', '}'], true) ? 1 : 0;
    }

    /**
     * The bodies of the named functions and methods, as the positions of their braces.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function functionBodies(): array
    {
        $bodies = [];
        foreach ($this->tokens as $position => $token) {
            $name = $this->tokens[$position + 1][0] ?? null;
            if ($token[0] !== T_FUNCTION || !in_array($name, [T_STRING, '&'], true)) {
                continue;
            }

            $open = $this->endOfTheSignature(from: $position);
            if (($this->tokens[$open][0] ?? null) === '{') {
                $bodies[] = [$open, $this->closing(open: $open)];
            }
        }

        return $bodies;
    }

    /** The position of the brace that opens a function's body, or of the semicolon of one that has none: the parameters are skipped, so a default value cannot be taken for either. */
    private function endOfTheSignature(int $from): int
    {
        $position = $from;
        while (isset($this->tokens[$position]) && !in_array($this->tokens[$position][0], ['{', ';'], true)) {
            $position = $this->tokens[$position][0] === '(' ? $this->closing(open: $position) + 1 : $position + 1;
        }

        return $position;
    }

    /**
     * The smallest function body that holds the position, or the whole source when none does.
     *
     * @param list<array{0: int, 1: int}> $bodies
     * @return array{0: int, 1: int}
     */
    private function innermostBody(array $bodies, int $position): array
    {
        $innermost = [0, count($this->tokens) - 1];
        foreach ($bodies as [$open, $close]) {
            if ($open <= $position && $position <= $close && $close - $open < $innermost[1] - $innermost[0]) {
                $innermost = [$open, $close];
            }
        }

        return $innermost;
    }

    /**
     * What gives each variable a value inside a body: `$x = ...;`, `$x .= ...;`, and the `foreach (... as $x)` that hands it the keys of a list.
     *
     * @param array{0: int, 1: int} $body
     * @return array<string, list<array{at: int, tokens: list<array{0: int|string, 1: string}>}>>
     */
    private function definitionsIn(array $body): array
    {
        $definitions = [];
        for ($position = $body[0]; $position <= $body[1]; $position++) {
            $token = $this->tokens[$position];

            if ($token[0] === T_VARIABLE && in_array($this->tokens[$position + 1][0] ?? null, ['=', T_CONCAT_EQUAL], true)) {
                $definitions[$token[1]][] = ['at' => $position, 'tokens' => $this->untilTheEndOfTheStatement(from: $position + 2)];
            }

            if ($token[0] === T_FOREACH && ($this->tokens[$position + 1][0] ?? null) === '(') {
                foreach ($this->handedByTheLoop(foreach: $position) as $variable => $source) {
                    $definitions[$variable][] = ['at' => $position, 'tokens' => $source];
                }
            }
        }

        return $definitions;
    }

    /**
     * The variables a `foreach` hands a value to, each with the tokens of the list it reads.
     *
     * @return array<string, list<array{0: int|string, 1: string}>>
     */
    private function handedByTheLoop(int $foreach): array
    {
        $close = $this->closing(open: $foreach + 1);
        $depth = 0;
        for ($as = $foreach + 2; $as < $close; $as++) {
            if ($depth === 0 && $this->tokens[$as][0] === T_AS) {
                $source = array_slice($this->tokens, $foreach + 2, $as - $foreach - 2);

                return array_fill_keys(array_column(array_filter(array_slice($this->tokens, $as + 1, $close - $as - 1), static fn (array $token): bool => $token[0] === T_VARIABLE), 1), $source);
            }
            $depth += $this->opens(id: $this->tokens[$as][0]) - $this->closes(id: $this->tokens[$as][0]);
        }

        return [];
    }

    /** @return list<array{0: int|string, 1: string}> */
    private function untilTheEndOfTheStatement(int $from): array
    {
        $statement = [];
        $depth = 0;
        for ($position = $from, $last = count($this->tokens); $position < $last; $position++) {
            $id = $this->tokens[$position][0];
            $depth += $this->opens(id: $id) - $this->closes(id: $id);
            if ($depth < 0 || ($depth === 0 && $id === ';')) {
                break;
            }
            $statement[] = $this->tokens[$position];
        }

        return $statement;
    }

    /**
     * The statement that last gave the variable a value before the position.
     *
     * @param array<string, list<array{at: int, tokens: list<array{0: int|string, 1: string}>}>> $definitions
     * @return array{at: int, tokens: list<array{0: int|string, 1: string}>}|null
     */
    private function definitionBefore(array $definitions, string $variable, int $at): ?array
    {
        $before = array_filter($definitions[$variable] ?? [], static fn (array $definition): bool => $definition['at'] < $at);

        return $before === [] ? null : end($before);
    }

    /**
     * Whether the key is run through one of the wrappers that make it the storefront's own, in the call or in a statement it was built in.
     *
     * @param list<array{0: int|string, 1: string}> $key
     * @param array<string, list<array{at: int, tokens: list<array{0: int|string, 1: string}>}>> $definitions
     */
    private function carriesStorefront(array $key, int $at, array $definitions, int $depth): bool
    {
        if (preg_match(self::STOREFRONT_KEYS, implode('', array_column($key, 1))) === 1) {
            return true;
        }

        foreach ($depth < self::DEPTH ? $key : [] as $token) {
            $definition = $token[0] === T_VARIABLE ? $this->definitionBefore(definitions: $definitions, variable: $token[1], at: $at) : null;
            if ($definition !== null && $this->carriesStorefront(key: $definition['tokens'], at: $definition['at'], definitions: $definitions, depth: $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key as words: what is written is written, and every other part is `*`.
     *
     * @param list<array{0: int|string, 1: string}> $key
     * @param array<string, list<array{at: int, tokens: list<array{0: int|string, 1: string}>}>> $definitions
     */
    private function keyText(array $key, int $at, array $definitions, int $depth): string
    {
        $text = '';
        foreach ($this->concatenatedParts(tokens: $key) as $part) {
            $text .= $this->partText(part: $part, at: $at, definitions: $definitions, depth: $depth);
        }

        $text = (string) preg_replace('/\*+/', '*', $text);

        return in_array($text, ['', '*'], true) ? self::UNKNOWN_KEY : $text;
    }

    /**
     * The tokens split at the dots that concatenate, outside every bracket.
     *
     * @param list<array{0: int|string, 1: string}> $tokens
     * @return list<list<array{0: int|string, 1: string}>>
     */
    private function concatenatedParts(array $tokens): array
    {
        $parts = [[]];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($depth === 0 && $token[0] === '.') {
                $parts[] = [];
                continue;
            }
            $depth += $this->opens(id: $token[0]) - $this->closes(id: $token[0]);
            $parts[array_key_last($parts)][] = $token;
        }

        return $parts;
    }

    /**
     * What one part of a concatenation is: a word, a constant, a variable that was given a key, a call that hands a key on, or `*`.
     *
     * @param list<array{0: int|string, 1: string}> $part
     * @param array<string, list<array{at: int, tokens: list<array{0: int|string, 1: string}>}>> $definitions
     */
    private function partText(array $part, int $at, array $definitions, int $depth): string
    {
        $first = $part[0] ?? ['', ''];

        return match (true) {
            count($part) === 1 && $first[0] === T_CONSTANT_ENCAPSED_STRING => self::unquoted(literal: $first[1]),
            $first[0] === '"' => implode('', array_map(static fn (array $token): string => match ($token[0]) { T_ENCAPSED_AND_WHITESPACE => $token[1], '"' => '', default => '*' }, $part)),
            count($part) === 3 && $part[1][0] === T_DOUBLE_COLON && $part[2][0] === T_STRING => $this->constants[$this->classNamed(name: $first[1])][$part[2][1]] ?? '*',
            $depth < self::DEPTH && count($part) === 1 && $first[0] === T_VARIABLE => $this->textOfDefinition(definition: $this->definitionBefore(definitions: $definitions, variable: $first[1], at: $at), definitions: $definitions, depth: $depth),
            default => $this->textOfWhatIsHandedOn(part: $part, at: $at, definitions: $definitions, depth: $depth),
        };
    }

    /**
     * @param array{at: int, tokens: list<array{0: int|string, 1: string}>}|null $definition
     * @param array<string, list<array{at: int, tokens: list<array{0: int|string, 1: string}>}>> $definitions
     */
    private function textOfDefinition(?array $definition, array $definitions, int $depth): string
    {
        return $definition === null ? '*' : $this->wordsOrAnything(tokens: $definition['tokens'], at: $definition['at'], definitions: $definitions, depth: $depth + 1);
    }

    /**
     * The key of a call that only wraps it (`->scopedCacheKey($key)`, or `$keysOf($key)` for a variable that holds such a wrapper), which is the key it is handed.
     *
     * @param list<array{0: int|string, 1: string}> $part
     * @param array<string, list<array{at: int, tokens: list<array{0: int|string, 1: string}>}>> $definitions
     */
    private function textOfWhatIsHandedOn(array $part, int $at, array $definitions, int $depth): string
    {
        foreach ($part as $position => $token) {
            $callsAWrapper = $token[0] === T_STRING && in_array($token[1], self::CALLS_THAT_HAND_A_KEY_ON, true) && ($part[$position + 1][0] ?? null) === '(';
            $callsAVariable = $position === 0 && $token[0] === T_VARIABLE && ($part[1][0] ?? null) === '(';
            if ($depth < self::DEPTH && ($callsAWrapper || $callsAVariable)) {
                return $this->wordsOrAnything(tokens: $this->keyOf(arguments: $this->argumentsOf(open: $position + 1, within: $part)), at: $at, definitions: $definitions, depth: $depth + 1);
            }
        }

        return '*';
    }

    /**
     * @param list<array{0: int|string, 1: string}> $tokens
     * @param array<string, list<array{at: int, tokens: list<array{0: int|string, 1: string}>}>> $definitions
     */
    private function wordsOrAnything(array $tokens, int $at, array $definitions, int $depth): string
    {
        $text = $this->keyText(key: $tokens, at: $at, definitions: $definitions, depth: $depth);

        return $text === self::UNKNOWN_KEY ? '*' : $text;
    }

    /** The class a name stands for in a constant: the class the source defines for `self` and `static`, else the name without its namespace. */
    private function classNamed(string $name): string
    {
        return in_array(strtolower($name), ['self', 'static'], true) ? $this->class : (string) preg_replace('/^.*\\\\/', '', $name);
    }
}
