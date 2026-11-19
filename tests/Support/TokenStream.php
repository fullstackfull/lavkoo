<?php

namespace Tests\Support;

/**
 * The tokens of one PHP file that carry meaning (no whitespace, no comment), each with the function or method it stands in and the class that contains it, and the questions a scan asks of them.
 * A closure stands in the function that contains it. A token is named `Namespace\Class::method`, a plain function name, the class name outside a method, or `(file) path` outside any class or
 * function. A name is read as the class it imports: `use App\Models\Order as OrderModel` makes `OrderModel` an `Order`.
 */
final class TokenStream
{
    private const SKIPPED = [T_WHITESPACE => true, T_COMMENT => true, T_DOC_COMMENT => true, T_OPEN_TAG => true, T_OPEN_TAG_WITH_ECHO => true, T_CLOSE_TAG => true, T_INLINE_HTML => true];

    /** What the container is asked for by: `app(Type::class)`, `resolve(Type::class)`, `$container->make(Type::class)`, `app()->makeWith(Type::class, [])`. */
    private const CONTAINER_CALLS = ['app', 'resolve', 'make', 'makeWith'];

    /**
     * @param list<array{id: int|string, text: string, owner: string, class: string}> $tokens
     * @param array<string, string> $aliases the class a name imported with `as` stands for, by the name: the short name of the class, without its namespace
     * @param array<string, string> $parents the name of the class a class extends, as written, by the name of the class
     */
    private function __construct(public readonly array $tokens, private readonly array $aliases, private readonly array $parents)
    {
    }

    public static function of(string $code, string $file): self
    {
        $tokens = [];
        $scopes = [];
        $pending = null;
        $namespace = '';
        $expecting = null;
        $extending = null;
        $previous = null;
        $parents = [];
        $owner = self::ownerOf(scopes: [], file: $file);
        $class = '';

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
                $pending = preg_match('/^[A-Za-z_]\w*$/', $text) === 1 ? ['function', $text] : ['block', ''];
            }
            $expecting = null;

            if ($extending !== null && in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $parents[$extending] = $text;
                $extending = null;
            }
            if ($id === T_EXTENDS && ($pending[0] ?? null) === 'class') {
                $extending = $pending[1];
            }

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
                $class = self::classOf(scopes: $scopes);
            } elseif ($id === '}') {
                array_pop($scopes);
                $owner = self::ownerOf(scopes: $scopes, file: $file);
                $class = self::classOf(scopes: $scopes);
            } elseif ($id === ';') {
                $pending = null;
            }

            $tokens[] = ['id' => $id, 'text' => $text, 'owner' => $owner, 'class' => $class];
            $previous = ['id' => $id, 'text' => $text];
        }

        return new self(tokens: $tokens, aliases: self::aliasesIn($tokens), parents: $parents);
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

    /** @param list<string> $types short names: the last segment of the name decides, so `\App\Models\Order` is an Order and `PurchaseOrder` is not, and a name imported with `as` is the class it imports */
    public function isNameOf(int $index, array $types): bool
    {
        if (!$this->isName($index)) {
            return false;
        }

        return in_array($this->shortName($this->tokens[$index]['text']), $types, true);
    }

    /** The class a token stands in is one of the types, or extends one: `self::`, `static::` and `$this->` there call a member of it. */
    public function standsInOneOf(int $index, array $types): bool
    {
        $class = $this->tokens[$index]['class'] ?? '';

        return $class !== '' && (in_array($this->shortName($class), $types, true) || $this->extendsOneOf(class: $class, types: $types));
    }

    /** `Type::` for a type, `self::` and `static::` inside one of them or a class that extends one, and `parent::` inside a class that extends one: the token at the index starts a static reference to the type. */
    public function isStaticReferenceTo(int $index, array $types): bool
    {
        if ($this->id($index + 1) !== T_DOUBLE_COLON) {
            return false;
        }

        return match (true) {
            $this->isNameOf(index: $index, types: $types) => true,
            $this->id($index) === T_STATIC || ($this->id($index) === T_STRING && $this->text($index) === 'self') => $this->standsInOneOf(index: $index, types: $types),
            $this->id($index) === T_STRING && $this->text($index) === 'parent' => $this->extendsOneOf(class: $this->tokens[$index]['class'], types: $types),
            default => false,
        };
    }

    /** `app(Type::class)`, `resolve(Type::class)` and `->make(Type::class)`: the index is the one of the function's name, and its parenthesis follows. */
    public function isContainerCall(int $index, array $types): bool
    {
        return $this->id($index) === T_STRING && in_array($this->text($index), self::CONTAINER_CALLS, true) && $this->text($index + 1) === '('
            && $this->isNameOf(index: $index + 2, types: $types) && $this->id($index + 3) === T_DOUBLE_COLON && $this->id($index + 4) === T_CLASS && in_array($this->text($index + 5), [')', ','], true);
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

    /** The short name of a class as written: without its namespace, and, for a bare name, the class it was imported as. */
    private function shortName(string $name): string
    {
        return str_contains($name, '\\') ? substr(strrchr($name, '\\'), 1) : ($this->aliases[$name] ?? $name);
    }

    /** @param list<string> $types */
    private function extendsOneOf(string $class, array $types): bool
    {
        return isset($this->parents[$class]) && in_array($this->shortName($this->parents[$class]), $types, true);
    }

    /** @param list<array{0: string, 1: string}> $scopes the open braces: a class, a function or a block of any other kind, with its name */
    private static function ownerOf(array $scopes, string $file): string
    {
        $class = self::classOf(scopes: $scopes);
        $function = null;

        foreach ($scopes as [$kind, $name]) {
            if ($kind === 'class') {
                $function = null;
            } elseif ($kind === 'function') {
                $function = $name;
            }
        }

        return match (true) {
            $function !== null && $class !== '' => "{$class}::{$function}",
            $function !== null => $function,
            $class !== '' => $class,
            default => "(file) {$file}",
        };
    }

    /** @param list<array{0: string, 1: string}> $scopes */
    private static function classOf(array $scopes): string
    {
        $class = '';

        foreach ($scopes as [$kind, $name]) {
            if ($kind === 'class') {
                $class = $name;
            }
        }

        return $class;
    }

    /**
     * The names a file imports with `as`, outside any class: `use App\Models\Order as OrderModel;`, a list of them, a group (`use App\Models\{Order as OrderModel}`). A function or a constant
     * imported with `as`, the `use` of a closure and the `use` of a trait are not classes imported by name.
     *
     * @param list<array{id: int|string, text: string, owner: string, class: string}> $tokens
     * @return array<string, string>
     */
    private static function aliasesIn(array $tokens): array
    {
        $aliases = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_USE || $token['class'] !== '' || !in_array($tokens[$index + 1]['id'] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $imported = null;
            for ($next = $index + 1; isset($tokens[$next]) && $tokens[$next]['id'] !== ';'; $next++) {
                if (in_array($tokens[$next]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && $tokens[$next - 1]['id'] !== T_AS) {
                    $imported = substr(strrchr('\\' . $tokens[$next]['text'], '\\'), 1);
                } elseif ($tokens[$next]['id'] === T_AS && $imported !== null && ($tokens[$next + 1]['id'] ?? null) === T_STRING) {
                    $aliases[$tokens[$next + 1]['text']] = $imported;
                }
            }
        }

        return $aliases;
    }
}
