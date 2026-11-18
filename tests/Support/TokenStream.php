<?php

namespace Tests\Support;

/**
 * The tokens of one PHP file that carry meaning (no whitespace, no comment), each with the function or method it stands in, and the questions a scan asks of them. A closure stands in the
 * function that contains it. A token is named `Namespace\Class::method`, a plain function name, the class name outside a method, or `(file) path` outside any class or function.
 */
final class TokenStream
{
    private const SKIPPED = [T_WHITESPACE => true, T_COMMENT => true, T_DOC_COMMENT => true, T_OPEN_TAG => true, T_OPEN_TAG_WITH_ECHO => true, T_CLOSE_TAG => true, T_INLINE_HTML => true];

    /** @param list<array{id: int|string, text: string, owner: string}> $tokens */
    private function __construct(public readonly array $tokens)
    {
    }

    public static function of(string $code, string $file): self
    {
        $tokens = [];
        $scopes = [];
        $pending = null;
        $namespace = '';
        $expecting = null;
        $previous = null;
        $owner = self::ownerOf(scopes: [], file: $file);

        foreach (token_get_all($code) as $raw) {
            $id = is_array($raw) ? $raw[0] : $raw;
            $text = is_array($raw) ? $raw[1] : $raw;

            if (isset(self::SKIPPED[$id])) {
                continue;
            }

            if ($expecting === 'namespace' && in_array($id, [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $text;
            } elseif ($expecting === 'class') {
                $pending = ['class', $id === T_STRING ? ($namespace === '' ? '' : $namespace . '\\') . $text : 'class@anonymous'];
            } elseif ($expecting === 'function' && $text === '&') {
                $previous = ['id' => $id, 'text' => $text];

                continue;
            } elseif ($expecting === 'function') {
                $pending = $id === T_STRING ? ['function', $text] : ['block', ''];
            }
            $expecting = null;

            if ($id === T_NAMESPACE) {
                $expecting = 'namespace';
            } elseif ($id === T_FUNCTION) {
                $expecting = 'function';
            } elseif (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && ($previous['id'] ?? null) !== T_DOUBLE_COLON) {
                if (($previous['id'] ?? null) === T_NEW) {
                    $pending = ['class', 'class@anonymous'];
                } else {
                    $expecting = 'class';
                }
            }

            if (in_array($id, ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $scopes[] = $pending ?? ['block', ''];
                $pending = null;
                $owner = self::ownerOf(scopes: $scopes, file: $file);
            } elseif ($id === '}') {
                array_pop($scopes);
                $owner = self::ownerOf(scopes: $scopes, file: $file);
            } elseif ($id === ';') {
                $pending = null;
            }

            $tokens[] = ['id' => $id, 'text' => $text, 'owner' => $owner];
            $previous = ['id' => $id, 'text' => $text];
        }

        return new self(tokens: $tokens);
    }

    public function id(int $index): int|string|null
    {
        return $this->tokens[$index]['id'] ?? null;
    }

    public function text(int $index): ?string
    {
        return $this->tokens[$index]['text'] ?? null;
    }

    public function isArrow(int $index): bool
    {
        return in_array($this->id($index), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    public function isName(int $index): bool
    {
        return in_array($this->id($index), [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    /** @param list<string> $types short names: the last segment of the name decides, so `\App\Models\Order` is an Order and `PurchaseOrder` is not */
    public function isNameOf(int $index, array $types): bool
    {
        return $this->isName($index) && in_array(substr(strrchr('\\' . $this->tokens[$index]['text'], '\\'), 1), $types, true);
    }

    /** `app(Type::class)` and `resolve(Type::class)`: the index is the one of the function's name, and its parenthesis follows. */
    public function isContainerCall(int $index, array $types): bool
    {
        return $this->id($index) === T_STRING && in_array($this->text($index), ['app', 'resolve'], true) && $this->text($index + 1) === '('
            && $this->isNameOf(index: $index + 2, types: $types) && $this->id($index + 3) === T_DOUBLE_COLON && $this->id($index + 4) === T_CLASS && $this->text($index + 5) === ')';
    }

    public function closingParenthesis(int $open): int
    {
        $depth = 0;

        for ($index = $open, $count = count($this->tokens); $index < $count; $index++) {
            $depth += match ($this->tokens[$index]['id']) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return $index;
            }
        }

        return $count;
    }

    /** @return list<string> the methods called one after another from the token after a closing parenthesis, as far as the chain goes */
    public function chainAfter(int $close): array
    {
        $methods = [];
        $index = $close + 1;

        while ($this->isArrow($index) && $this->id($index + 1) === T_STRING && $this->text($index + 2) === '(') {
            $methods[] = $this->tokens[$index + 1]['text'];
            $index = $this->closingParenthesis($index + 2) + 1;
        }

        return $methods;
    }

    /** @param list<array{0: string, 1: string}> $scopes the open braces: a class, a function or a block of any other kind, with its name */
    private static function ownerOf(array $scopes, string $file): string
    {
        $class = null;
        $function = null;

        foreach ($scopes as [$kind, $name]) {
            if ($kind === 'class') {
                $class = $name;
                $function = null;
            } elseif ($kind === 'function') {
                $function = $name;
            }
        }

        return match (true) {
            $function !== null && $class !== null => "{$class}::{$function}",
            $function !== null => $function,
            $class !== null => $class,
            default => "(file) {$file}",
        };
    }
}
