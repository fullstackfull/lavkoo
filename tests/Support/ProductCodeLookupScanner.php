<?php

namespace Tests\Support;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the places in PHP source where a record is looked up, filtered or validated by a column called `code` or `barcode`: a where-style call
 * whose column is one of them, a unique rule on the products table, or a repository filter that names them. It reads tokens, so a comment or a
 * string that only mentions a lookup is not one.
 *
 * What the lookup is about is read from the statement that holds it, climbing out of closures to the chain they belong to. It is a PRODUCT lookup
 * when that statement starts from the Product model, the products table or the product repository, OTHER when it starts from another model, table
 * or repository (a colour, a currency, a coupon), and UNKNOWN when the statement does not say. UNKNOWN has to be listed like a product lookup: a
 * guard that skips what it cannot read lets the next blind lookup through.
 */
final class ProductCodeLookupScanner
{
    public const PRODUCT = 'product';

    public const OTHER = 'other';

    public const UNKNOWN = 'unknown';

    /** Calls that look a record up, narrow a query or filter a list by the column they are given. */
    private const LOOKUP_CALLS = [
        'where', 'orWhere', 'whereIn', 'orWhereIn', 'whereNot', 'orWhereNot', 'whereNotIn', 'firstWhere', 'getFirstWhere', 'getFirstWhereActive',
        'getFirstWhereWithoutGlobalScope', 'getFirstWhereWithCount', 'getWebFirstWhereActive', 'getListWhere', 'getListWhereIn', 'getListWithScope',
        'getWebListWithScope', 'getStockLimitListWhere', 'updateWhere', 'deleteWhere', 'updateOrCreate', 'firstOrCreate', 'firstOrNew', 'sole',
    ];

    private const COLUMNS = ['code', 'barcode', 'products.code', 'products.barcode'];

    /** What the chain a lookup belongs to starts from when it is about products: the Product model, the products table, the product repository. */
    private const PRODUCT_CHAIN = '/^(?:\\\\?(?:[A-Za-z0-9_]+\\\\)*Product::|\$this->productRepo\b|\$productRepo\b|\$this->product(?:->|$))|(?:::|->)table\(\'products\'\)/';

    /**
     * What the chain starts from when it is about something that is not a product: another model class, another table, or a repository or
     * a model property that is not the product's.
     */
    private const OTHER_CHAIN = '/^(?:\\\\?(?!(?:[A-Za-z0-9_]+\\\\)*(?:Product|DB)::)(?:[A-Za-z0-9_]+\\\\)*[A-Z][A-Za-z0-9_]*::|\$this->(?:color|colors|colorRepo|currencyRepo|couponRepo|languageRepo|cart|coupon|wishlist)\b)|(?:::|->)table\(\'(?!products\')\w+\'\)/';

    /**
     * @param list<string> $roots directories to read
     * @return list<array{file: string, line: int, kind: string, subject: string, text: string}>
     */
    public function scan(array $roots, string $base): array
    {
        $found = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && !str_ends_with($file->getFilename(), '.blade.php')) {
                    $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($base))), '/');
                    array_push($found, ...$this->scanSource(source: (string) file_get_contents($file->getPathname()), file: $relative));
                }
            }
        }

        usort($found, static fn (array $first, array $second): int => [$first['file'], $first['line']] <=> [$second['file'], $second['line']]);

        return $found;
    }

    /** @return list<array{file: string, line: int, kind: string, subject: string, text: string}> */
    public function scanSource(string $source, string $file): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => !$token->isIgnorable()));
        $found = [];

        foreach ($tokens as $index => $token) {
            $kind = $this->kindOfLookupAt($tokens, $index);
            if ($kind !== null) {
                $found[] = ['file' => $file, 'line' => $token->line, 'kind' => $kind, 'subject' => $this->subjectOf($tokens, $index, $kind), 'text' => $this->excerpt($tokens, $index)];
            }
        }

        return $found;
    }

    /** @param list<PhpToken> $tokens */
    private function kindOfLookupAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];

        if ($token->id === T_CONSTANT_ENCAPSED_STRING && preg_match('/\bunique:products\b/', $token->text) === 1) {
            return 'unique-rule';
        }
        if ($token->id === T_STRING && $token->text === 'Rule' && $this->textAt($tokens, $index + 1) === '::' && $this->textAt($tokens, $index + 2) === 'unique' && $this->textAt($tokens, $index + 3) === '(') {
            return $this->stringValue($tokens[$index + 4] ?? null) === 'products' ? 'unique-rule' : null;
        }
        if (!in_array($token->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            return null;
        }
        $name = $tokens[$index + 1] ?? null;
        if ($name === null || $name->id !== T_STRING || !in_array($name->text, self::LOOKUP_CALLS, true) || $this->textAt($tokens, $index + 2) !== '(') {
            return null;
        }

        return $this->argumentsNameAColumn($tokens, open: $index + 2) ? 'where-call' : null;
    }

    /**
     * Whether the arguments of the call opened at `$open` name `code` or `barcode` as the column: as the first value, or as a key of an array they carry.
     *
     * @param list<PhpToken> $tokens
     */
    private function argumentsNameAColumn(array $tokens, int $open): bool
    {
        $close = $this->closing($tokens, $open);
        $first = $open + 1;
        if (($tokens[$first]->id ?? null) === T_STRING && $this->textAt($tokens, $first + 1) === ':') {
            $first += 2;
        }
        if (in_array($this->stringValue($tokens[$first] ?? null), self::COLUMNS, true)) {
            return true;
        }

        $inBody = 0;
        for ($cursor = $open + 1; $cursor < $close; $cursor++) {
            if ($tokens[$cursor]->text === '{') {
                $inBody++;
            } elseif ($tokens[$cursor]->text === '}') {
                $inBody--;
            } elseif ($inBody === 0 && in_array($this->stringValue($tokens[$cursor]), self::COLUMNS, true) && ($tokens[$cursor + 1]->id ?? null) === T_DOUBLE_ARROW) {
                return true;
            }
        }

        return false;
    }

    /** @param list<PhpToken> $tokens */
    private function subjectOf(array $tokens, int $index, string $kind): string
    {
        if ($kind === 'unique-rule') {
            return self::PRODUCT;
        }

        $operator = $index;
        while ($operator !== null) {
            $chain = $this->chainBefore($tokens, $operator);
            if (preg_match(self::PRODUCT_CHAIN, $chain) === 1) {
                return self::PRODUCT;
            }
            if (preg_match(self::OTHER_CHAIN, $chain) === 1) {
                return self::OTHER;
            }

            $operator = $this->operatorOfEnclosingCall($tokens, $operator);
        }

        return self::UNKNOWN;
    }

    /**
     * What the call at the operator `$operator` is made on, from the start of its chain: `Product::where().orWhere`, `$this->productRepo`,
     * `DB::table('products')`, `$query`. A call keeps its first argument when that is a plain string, because `table('products')` and
     * `table('colors')` are told apart by it.
     *
     * @param list<PhpToken> $tokens
     */
    private function chainBefore(array $tokens, int $operator): string
    {
        $segments = [];
        $cursor = $operator;
        while (true) {
            $previous = $cursor - 1;
            $token = $tokens[$previous] ?? null;
            if ($token === null) {
                break;
            }
            if ($token->text === ')') {
                $open = $this->opening($tokens, $previous);
                $name = $tokens[$open - 1] ?? null;
                $before = $tokens[$open - 2] ?? null;
                if ($name !== null && $name->id === T_STRING && $before !== null && in_array($before->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $argument = $this->stringValue($tokens[$open + 1] ?? null);
                    array_unshift($segments, $before->text . $name->text . '(' . ($argument === null ? '' : "'" . $argument . "'") . ')');
                    $cursor = $open - 2;

                    continue;
                }
                if ($name !== null && $name->id === T_STRING && ($tokens[$open - 2]->id ?? null) === T_NEW) {
                    array_unshift($segments, 'new ' . $name->text);

                    break;
                }
                array_unshift($segments, '(group)');

                break;
            }
            if ($token->id === T_STRING && in_array($tokens[$previous - 1]->id ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                array_unshift($segments, $tokens[$previous - 1]->text . $token->text);
                $cursor = $previous - 1;

                continue;
            }
            if (in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && $this->textAt($tokens, $cursor) === '::') {
                array_unshift($segments, $token->text);

                break;
            }

            array_unshift($segments, $token->text);

            break;
        }

        return $this->chainText($segments) . $tokens[$operator]->text;
    }

    /** @param list<string> $segments */
    private function chainText(array $segments): string
    {
        $text = '';
        foreach ($segments as $segment) {
            $text .= $text !== '' && !str_starts_with($segment, '->') && !str_starts_with($segment, '?->') && !str_starts_with($segment, '::') ? ' ' . $segment : $segment;
        }

        return $text;
    }

    /**
     * The operator of the call whose arguments hold the token at `$from`, so that a closure's lookup is read together with the chain the closure
     * is an argument of; null at the method that holds it, or when no call does.
     *
     * @param list<PhpToken> $tokens
     */
    private function operatorOfEnclosingCall(array $tokens, int $from): ?int
    {
        $depth = 0;
        for ($cursor = $from - 1; $cursor >= 0; $cursor--) {
            $text = $tokens[$cursor]->text;
            if (in_array($text, [')', ']', '}'], true)) {
                $depth++;
            } elseif (in_array($text, ['(', '[', '{'], true)) {
                if ($depth > 0) {
                    $depth--;

                    continue;
                }
                if ($text === '{' && $this->closureFunctionOf($tokens, $cursor) === null && !$this->opensAControlBlock($tokens, $cursor)) {
                    return null;
                }
                if ($text === '(' && ($tokens[$cursor - 1]->id ?? null) === T_STRING && in_array($tokens[$cursor - 2]->id ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true) && ($tokens[$cursor - 3]->id ?? null) !== T_FUNCTION) {
                    return $cursor - 2;
                }
            }
        }

        return null;
    }

    /**
     * The `function` keyword when the `{` at `$open` starts the body of an anonymous function, and null for a method, a class or a control block.
     *
     * @param list<PhpToken> $tokens
     */
    private function closureFunctionOf(array $tokens, int $open): ?int
    {
        $cursor = $open - 1;
        while ($cursor >= 0 && $tokens[$cursor]->text !== ')' && ($tokens[$cursor]->id === T_STRING || in_array($tokens[$cursor]->id, [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STATIC, T_ARRAY], true) || in_array($tokens[$cursor]->text, [':', '?', '|'], true))) {
            $cursor--;
        }
        if ($cursor < 0 || $tokens[$cursor]->text !== ')') {
            return null;
        }

        $before = $this->opening($tokens, $cursor) - 1;
        if (($tokens[$before]->id ?? null) === T_USE && ($tokens[$before - 1]->text ?? null) === ')') {
            $before = $this->opening($tokens, $before - 1) - 1;
        }

        return ($tokens[$before]->id ?? null) === T_FUNCTION ? $before : null;
    }

    /** @param list<PhpToken> $tokens */
    private function opensAControlBlock(array $tokens, int $open): bool
    {
        $previous = $tokens[$open - 1] ?? null;
        if ($previous !== null && in_array($previous->id, [T_ELSE, T_TRY, T_FINALLY, T_DO], true)) {
            return true;
        }
        if ($previous === null || $previous->text !== ')') {
            return false;
        }
        $keyword = $tokens[$this->opening($tokens, $open - 1) - 1] ?? null;

        return $keyword !== null && in_array($keyword->id, [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_CATCH, T_MATCH], true);
    }

    /**
     * The tokens as text: no space between tokens, except between two names so that `return Product` stays two words.
     *
     * @param list<string> $texts
     */
    private function joined(array $texts): string
    {
        $joined = '';
        foreach ($texts as $text) {
            if ($joined !== '' && preg_match('/[A-Za-z0-9_\\\\$]$/', $joined) === 1 && preg_match('/^[A-Za-z0-9_\\\\$]/', $text) === 1) {
                $joined .= ' ';
            }
            $joined .= $text;
        }

        return $joined;
    }

    /** @param list<PhpToken> $tokens */
    private function excerpt(array $tokens, int $index): string
    {
        return substr($this->joined(array_map(static fn (PhpToken $token): string => $token->text, array_slice($tokens, $index, 14))), 0, 90);
    }

    /** @param list<PhpToken> $tokens */
    private function closing(array $tokens, int $open): int
    {
        $depth = 0;
        for ($cursor = $open; $cursor < count($tokens); $cursor++) {
            if (in_array($tokens[$cursor]->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($tokens[$cursor]->text, [')', ']', '}'], true) && --$depth === 0) {
                return $cursor;
            }
        }

        return count($tokens) - 1;
    }

    /** @param list<PhpToken> $tokens */
    private function opening(array $tokens, int $close): int
    {
        $depth = 0;
        for ($cursor = $close; $cursor >= 0; $cursor--) {
            if (in_array($tokens[$cursor]->text, [')', ']', '}'], true)) {
                $depth++;
            } elseif (in_array($tokens[$cursor]->text, ['(', '[', '{'], true) && --$depth === 0) {
                return $cursor;
            }
        }

        return 0;
    }

    private function stringValue(?PhpToken $token): ?string
    {
        return $token !== null && $token->id === T_CONSTANT_ENCAPSED_STRING ? substr($token->text, 1, -1) : null;
    }

    /** @param list<PhpToken> $tokens */
    private function textAt(array $tokens, int $index): ?string
    {
        return $tokens[$index]->text ?? null;
    }
}
