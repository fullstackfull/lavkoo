<?php

namespace App\Services\DeveloperPortal\Support;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Recovers the rules an endpoint actually validates against.
 *
 * This project validates two ways, and a portal that only understood one of them would document a
 * fraction of the API:
 *
 *  1. A FormRequest type-hinted on the controller method. Its rules() is the authority — but it
 *     cannot simply be called, because in this codebase rules() routinely reads $this->user() and
 *     $this->route(), and calling it outside a request would either throw or, worse, return a
 *     different rule set than a real caller sees. So it is called against a request the container
 *     has been given, and on any failure the method's source is read instead.
 *
 *  2. Validator::make($request->all(), [...]) written inline in the controller. Most of this
 *     application's 439 endpoints validate this way. There is no object to reflect on, so the rules
 *     are read out of the method's own source.
 *
 * Reading source is a compromise and is treated as one: what it recovers is marked with how it was
 * obtained, so the documentation-quality screen can distinguish "extracted from the FormRequest"
 * from "read out of the controller" from "nothing found", instead of presenting all three with the
 * same confidence.
 */
class ValidationExtractor
{
    public function __construct(private readonly RuleTranslator $translator)
    {
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>, source: string, class: string|null}
     */
    public function forMethod(?string $controller, ?string $method): array
    {
        if ($controller === null || $method === null || !class_exists($controller)) {
            return $this->nothing();
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (\Throwable) {
            return $this->nothing();
        }

        $fromRequest = $this->fromFormRequest($reflection);

        if ($fromRequest !== null) {
            return $fromRequest;
        }

        return $this->fromMethodBody($reflection);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @return array{fields: array<int, array<string, mixed>>, source: string, class: string|null}|null
     */
    private function fromFormRequest(ReflectionMethod $method): ?array
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (!class_exists($class) || !is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            $rules = $this->callRules($class) ?? $this->parseRulesSource($class);

            if ($rules === []) {
                return ['fields' => [], 'source' => 'form_request_unreadable', 'class' => $class];
            }

            return [
                'fields' => $this->translate($rules),
                'source' => 'form_request',
                'class' => $class,
            ];
        }

        return null;
    }

    /**
     * Call rules() with a real request bound, and give up quietly rather than loudly.
     *
     * @return array<string, mixed>|null
     */
    private function callRules(string $class): ?array
    {
        // Warnings are promoted to exceptions for the duration of the call. rules() in this
        // codebase routinely reads $this->user()->id, which without an authenticated user is a
        // warning rather than a throw: PHP carries on with null and returns a rule set that is
        // subtly not the one a real caller is validated against. Treating that as a failure sends
        // it to the source parser, which reads what is actually written.
        $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            /** @var FormRequest $request */
            $request = $class::createFrom(request());
            $request->setContainer(app());

            $rules = $request->rules();

            return is_array($rules) && $rules !== [] ? $rules : null;
        } catch (\Throwable) {
            // rules() that needs an authenticated user, a bound route model or a database row is
            // normal here; the source parse still recovers the shape.
            return null;
        } finally {
            restore_error_handler();

            if ($previous !== null) {
                set_error_handler($previous);
                restore_error_handler();
            }
        }
    }

    /**
     * Read the literal rules out of a FormRequest's rules() method.
     *
     * @return array<string, mixed>
     */
    private function parseRulesSource(string $class): array
    {
        try {
            $reflection = new ReflectionClass($class);

            if (!$reflection->hasMethod('rules')) {
                return [];
            }

            return $this->parseRuleArray($this->sourceOf($reflection->getMethod('rules')));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>, source: string, class: string|null}
     */
    private function fromMethodBody(ReflectionMethod $method): array
    {
        try {
            $source = $this->sourceOf($method);
        } catch (\Throwable) {
            return $this->nothing();
        }

        // Validator::make($request->all(), [ ... ]) — the second argument is what is wanted, and
        // the array literal is what is parsed. An array built by a helper is not recoverable this
        // way, and is reported as not found rather than as empty.
        if (preg_match('/Validator::make\s*\(/', $source) !== 1 && preg_match('/\$request->validate\s*\(/', $source) !== 1) {
            return $this->nothing();
        }

        $rules = $this->parseRuleArray($source);

        return $rules === []
            ? ['fields' => [], 'source' => 'inline_unreadable', 'class' => null]
            : ['fields' => $this->translate($rules), 'source' => 'inline', 'class' => null];
    }

    /**
     * Pull `'field' => 'rule|rule'` and `'field' => ['rule', 'rule']` pairs out of PHP source.
     *
     * Deliberately literal-only. Anything dynamic — a rule built from a variable, a spread, a
     * conditional — is skipped rather than guessed at, because a guessed rule in documentation is
     * worse than an acknowledged gap.
     *
     * @return array<string, mixed>
     */
    private function parseRuleArray(string $source): array
    {
        $rules = [];

        // Commented-out rules are not rules. Without this a field somebody disabled months ago is
        // published as a live request parameter, which is worse than not documenting it: a client
        // sends it and the API ignores it.
        $source = $this->withoutComments($source);

        // 'field' => 'required|integer|min:1'
        if (preg_match_all("/'([a-zA-Z0-9_.*\\[\\]]+)'\s*=>\s*'([^']*?)'/", $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($this->looksLikeRules($match[2])) {
                    $rules[$match[1]] = $match[2];
                }
            }
        }

        // 'field' => ['required', 'integer', Rule::in([...])]
        //
        // Scanned rather than matched with [^\]]*: a rule list holding a character class —
        // 'regex:/^[a-zA-Z0-9]+$/' is ordinary — ended at the first ] inside it, so the field was
        // published with whatever preceded the truncation and still labelled as read from the
        // FormRequest.
        foreach ($this->arrayAssignments($source) as $field => $body) {
            $matches = [[null, $field, $body]];

            foreach ($matches as $match) {
                $items = [];

                if (preg_match_all("/'([^']+)'/", $match[2], $literals)) {
                    foreach ($literals[1] as $literal) {
                        if ($this->looksLikeRules($literal)) {
                            $items[] = $literal;
                        }
                    }
                }

                if ($items !== []) {
                    $rules[$match[1]] = array_values(array_unique(array_merge(
                        is_array($rules[$match[1]] ?? null) ? $rules[$match[1]] : [],
                        $items,
                    )));
                }
            }
        }

        return $rules;
    }

    /**
     * The same source with every comment removed, using PHP's own tokeniser rather than a regex.
     */
    private function withoutComments(string $source): string
    {
        $tokens = @token_get_all('<?php ' . $source);

        if ($tokens === false || $tokens === []) {
            return $source;
        }

        $clean = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                if ($token[0] === T_OPEN_TAG) {
                    continue;
                }
                $clean .= $token[1];

                continue;
            }

            $clean .= $token;
        }

        return $clean;
    }

    /**
     * Every `'field' => [ ... ]` in the source, with the brackets balanced properly.
     *
     * @return array<string, string>  field => the text between its outermost brackets
     */
    private function arrayAssignments(string $source): array
    {
        $found = [];
        $offset = 0;

        while (preg_match("/'([a-zA-Z0-9_.*\\[\\]]+)'\s*=>\s*\[/", $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $field = $match[1][0];
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            $index = $start;
            $quote = null;
            $length = strlen($source);

            while ($index < $length && $depth > 0) {
                $character = $source[$index];

                if ($quote !== null) {
                    if ($character === '\\') {
                        $index += 2;

                        continue;
                    }
                    if ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === "'" || $character === '"') {
                    $quote = $character;
                } elseif ($character === '[') {
                    $depth++;
                } elseif ($character === ']') {
                    $depth--;
                }

                $index++;
            }

            if ($depth === 0) {
                $found[$field] = substr($source, $start, $index - $start - 1);
            }

            $offset = $index;
        }

        return $found;
    }

    /**
     * Is this string a rule list, or just a string that happens to sit in an array?
     *
     * Without this, every `'message' => 'Something went wrong'` in a controller becomes a
     * documented parameter.
     */
    private function looksLikeRules(string $value): bool
    {
        if ($value === '' || str_contains($value, ' ')) {
            return false;
        }

        $known = [
            'required', 'nullable', 'sometimes', 'string', 'integer', 'numeric', 'boolean', 'array',
            'file', 'image', 'email', 'url', 'uuid', 'date', 'exists', 'unique', 'in', 'not_in',
            'min', 'max', 'between', 'mimes', 'mimetypes', 'digits', 'size', 'confirmed', 'json',
            'regex', 'after', 'before', 'gt', 'gte', 'lt', 'lte', 'present', 'filled', 'distinct',
            'required_if', 'required_with', 'required_without', 'alpha', 'alpha_dash', 'alpha_num',
            'decimal', 'ip', 'accepted', 'nullable', 'bail', 'timezone', 'starts_with', 'ends_with',
        ];

        foreach (explode('|', $value) as $part) {
            $head = strtolower(explode(':', $part, 2)[0]);
            if (in_array($head, $known, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function translate(array $rules): array
    {
        $fields = [];

        foreach ($rules as $name => $rule) {
            $fields[] = $this->translator->field((string) $name, $this->readable($rule));
        }

        return $fields;
    }

    /**
     * A rule in a form the translator can read.
     *
     * `'password' => Password::defaults()` and `'status' => new Enum(Status::class)` are ordinary
     * Laravel, and casting either with (string) throws an Error — not an Exception, so it escaped
     * every catch on the way out and took the whole Developer Portal down with it. A rule object
     * that can describe itself is asked to; one that cannot is named by its class, which is more
     * than the page could say before.
     */
    private function readable(mixed $rule): array|string
    {
        if (is_array($rule)) {
            return array_map(fn ($one) => is_array($one) ? $one : $this->readable($one), $rule);
        }

        if (is_object($rule)) {
            return $rule instanceof \Stringable || method_exists($rule, '__toString')
                ? (string) $rule
                : strtolower(class_basename($rule));
        }

        return is_scalar($rule) ? (string) $rule : '';
    }

    private function sourceOf(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return '';
        }

        $lines = file($file) ?: [];

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * @return array{fields: array<int, array<string, mixed>>, source: string, class: string|null}
     */
    private function nothing(): array
    {
        return ['fields' => [], 'source' => 'none', 'class' => null];
    }
}
