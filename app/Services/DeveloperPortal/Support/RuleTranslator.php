<?php

namespace App\Services\DeveloperPortal\Support;

use Illuminate\Support\Str;

/**
 * Turns Laravel validation rules into something a developer who has never used Laravel can act on.
 *
 * `required|integer|exists:products,id` is precise and useless to somebody writing Kotlin. It says
 * nothing about the JSON type they must send, and "exists:products,id" leaks a table name while
 * failing to say the only thing that matters — that the id has to be a real product.
 *
 * So each rule is translated twice: into a plain-English constraint for the reference table, and
 * into a JSON Schema fragment for the generated OpenAPI spec and the code examples. Both come from
 * the same rules the application actually validates against, so neither can describe an endpoint
 * that no longer behaves that way.
 */
class RuleTranslator
{
    /** Rules that decide the JSON type of a field. */
    private const TYPES = [
        'integer' => 'integer',
        'int' => 'integer',
        'numeric' => 'number',
        'decimal' => 'number',
        'boolean' => 'boolean',
        'bool' => 'boolean',
        'array' => 'array',
        'file' => 'file',
        'image' => 'file',
        'string' => 'string',
        'date' => 'string',
        'email' => 'string',
        'url' => 'string',
        'uuid' => 'string',
        'json' => 'string',
    ];

    /**
     * One field's rules, normalised.
     *
     * @param  array<int, string>|string  $rules
     * @return array<string, mixed>
     */
    public function field(string $name, array|string $rules): array
    {
        $parts = $this->normalise($rules);
        $type = 'string';
        $required = false;
        $nullable = false;
        $constraints = [];
        $enum = null;
        $min = null;
        $max = null;
        $format = null;

        foreach ($parts as $rule) {
            [$head, $argument] = array_pad(explode(':', $rule, 2), 2, null);
            $head = strtolower(trim($head));

            if (isset(self::TYPES[$head])) {
                $type = self::TYPES[$head];
            }

            match ($head) {
                'required' => $required = true,
                'nullable' => $nullable = true,
                'sometimes' => null,
                default => null,
            };

            $format = match ($head) {
                'email' => 'email',
                'url' => 'uri',
                'uuid' => 'uuid',
                'date', 'date_format' => 'date-time',
                'ip' => 'ip',
                default => $format,
            };

            if (in_array($head, ['in', 'not_in'], true) && $argument !== null) {
                // An empty list is what Laravel's own Enum rule casts to when the backing enum has
                // no cases; publishing it produced a documented field whose only allowed value was
                // the empty string.
                $values = array_values(array_filter(array_map('trim', explode(',', $argument)), static fn ($v) => $v !== ''));

                if ($values === []) {
                    continue;
                }

                if ($head === 'in') {
                    $enum = $values;
                }
                $constraints[] = ($head === 'in' ? 'one of: ' : 'not one of: ') . implode(', ', $values);

                continue;
            }

            if ($head === 'min' && $argument !== null) {
                $min = (float) $argument;
                $constraints[] = $this->boundary('at least', $type, $argument);

                continue;
            }

            if ($head === 'max' && $argument !== null) {
                $max = (float) $argument;
                $constraints[] = $this->boundary('at most', $type, $argument);

                continue;
            }

            if ($head === 'between' && $argument !== null) {
                [$low, $high] = array_pad(explode(',', $argument), 2, null);
                $min = is_numeric($low) ? (float) $low : null;
                $max = is_numeric($high) ? (float) $high : null;
                $constraints[] = "between {$low} and {$high}";

                continue;
            }

            // exists / unique name a table, which is an implementation detail. What a caller needs
            // is the promise: this must already exist, or this must not already exist.
            if ($head === 'exists') {
                $constraints[] = 'must reference an existing ' . $this->subject($argument, $name);

                continue;
            }

            if ($head === 'unique') {
                $constraints[] = 'must not already be taken';

                continue;
            }

            if (in_array($head, ['mimes', 'mimetypes'], true) && $argument !== null) {
                $type = 'file';
                $constraints[] = 'file type: ' . str_replace(',', ', ', $argument);

                continue;
            }

            if ($head === 'dimensions' && $argument !== null) {
                $constraints[] = 'image dimensions: ' . str_replace(',', ', ', $argument);

                continue;
            }

            if (in_array($head, ['same', 'different', 'confirmed', 'after', 'before', 'gt', 'gte', 'lt', 'lte', 'required_if', 'required_with', 'required_without', 'regex'], true)) {
                $constraints[] = $this->relational($head, $argument);

                continue;
            }

            if (!in_array($head, ['required', 'nullable', 'sometimes', 'string', 'integer', 'int', 'numeric', 'boolean', 'bool', 'array', 'file', 'image', 'date', 'email', 'url', 'uuid', 'json', 'present', 'filled'], true) && $head !== '') {
                $constraints[] = $rule;
            }
        }

        return array_filter([
            'name' => $name,
            'type' => $type,
            'format' => $format,
            'required' => $required,
            'nullable' => $nullable,
            'enum' => $enum,
            'min' => $min,
            'max' => $max,
            'constraints' => array_values(array_unique($constraints)),
            'rules' => implode(' | ', $parts),
        ], static fn ($value) => $value !== null && $value !== [] && $value !== false);
    }

    /**
     * The JSON Schema fragment for a translated field, for OpenAPI.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    public function schema(array $field): array
    {
        $type = $field['type'] ?? 'string';

        $schema = ['type' => $type === 'file' ? 'string' : $type];

        if ($type === 'file') {
            $schema['format'] = 'binary';
        } elseif (isset($field['format'])) {
            $schema['format'] = $field['format'];
        }

        if (isset($field['enum'])) {
            $schema['enum'] = $field['enum'];
        }

        // A file's min and max are kilobytes, which OpenAPI has no keyword for: emitting them as
        // minimum/maximum put numeric bounds on a {"type":"string","format":"binary"} schema — the
        // same length-and-value conflation the comment below is about, one type further on. The
        // limit is stated in the description instead, where a reader can act on it.
        $isFile = ($field['type'] ?? null) === 'file';

        if (isset($field['min']) && !$isFile) {
            // For a string, min is a length; for a number it is a value. Conflating the two is the
            // usual way a generated spec ends up rejecting valid input.
            $key = $type === 'string' ? 'minLength' : 'minimum';
            $schema[$key] = $type === 'string' ? (int) $field['min'] : $field['min'];
        }

        if (isset($field['max'])) {
            $key = $type === 'string' ? 'maxLength' : 'maximum';
            $schema[$key] = $type === 'string' ? (int) $field['max'] : $field['max'];
        }

        if (!empty($field['nullable'])) {
            $schema['nullable'] = true;
        }

        if (!empty($field['constraints'])) {
            $schema['description'] = implode('; ', $field['constraints']);
        }

        return $schema;
    }

    /**
     * A plausible example value, derived from the schema rather than from real data.
     *
     * Deliberately synthetic: an example lifted from the database would put a real customer's
     * phone number in the documentation.
     *
     * @param  array<string, mixed>  $field
     */
    public function example(array $field): mixed
    {
        if (isset($field['enum'][0])) {
            return $field['enum'][0];
        }

        $name = $field['name'] ?? '';

        return match ($field['type'] ?? 'string') {
            'integer' => (int) ($field['min'] ?? 1),
            'number' => (float) ($field['min'] ?? 9.99),
            'boolean' => true,
            'array' => [],
            'file' => '<binary>',
            default => match (true) {
                ($field['format'] ?? null) === 'email' => 'developer@example.com',
                ($field['format'] ?? null) === 'uri' => 'https://example.com',
                ($field['format'] ?? null) === 'uuid' => '00000000-0000-4000-8000-000000000000',
                ($field['format'] ?? null) === 'date-time' => '2026-01-31 09:00:00',
                str_contains($name, 'phone') => '+96590000000',
                // A placeholder somebody can paste and then replace. Bullets look like a masked
                // secret, which is the right thing in a UI and the wrong thing in a code sample —
                // pasted into a shell they become a literal password made of dots.
                str_contains($name, 'password') => 'your-password',
                str_contains($name, 'token') => 'your-token',
                str_contains($name, 'otp') || str_contains($name, 'code') => '123456',
                str_contains($name, 'name') => 'Example',
                default => 'string',
            },
        };
    }

    /**
     * @param  array<int, mixed>|string  $rules
     * @return array<int, string>
     */
    private function normalise(array|string $rules): array
    {
        $parts = is_string($rules) ? explode('|', $rules) : $rules;
        $flattened = [];

        foreach ($parts as $rule) {
            if (is_string($rule)) {
                $flattened[] = trim($rule);

                continue;
            }

            // Rule objects (Rule::unique(...), Rule::in(...), enum rules) stringify to the rule
            // they represent where they can; where they cannot, their class name is at least an
            // honest label rather than "Object".
            if (is_object($rule)) {
                $flattened[] = method_exists($rule, '__toString')
                    ? trim((string) $rule)
                    : class_basename($rule);
            }
        }

        return array_values(array_filter($flattened, static fn (string $rule) => $rule !== ''));
    }

    private function boundary(string $direction, string $type, string $argument): string
    {
        return match ($type) {
            'string' => "{$direction} {$argument} characters",
            'array' => "{$direction} {$argument} items",
            'file' => "{$direction} {$argument} KB",
            default => "{$direction} {$argument}",
        };
    }

    private function subject(?string $argument, string $field): string
    {
        $table = $argument !== null ? explode(',', $argument)[0] : '';
        // The model class first, while its backslashes are still there — replacing them in the same
        // pass removed the separator the second needle depends on, so that needle never matched.
        $table = trim(str_replace(['App\\Models\\', 'App\\Models'], '', $table));
        $table = trim(str_replace('\\', '', $table));

        if ($table === '' || $table === 'NULL') {
            return str_replace('_id', '', $field);
        }

        // Str::singular, not rtrim($table, 's'): that stripped EVERY trailing s, so "address"
        // became "addre" and "business" became "busine" — misspelled nouns printed in the public
        // API reference. The framework already ships an inflector that knows "addresses" is one
        // address and "status" is already singular.
        return Str::singular(str_replace('_', ' ', $table));
    }

    private function relational(string $head, ?string $argument): string
    {
        return match ($head) {
            'confirmed' => 'must be repeated in a matching _confirmation field',
            'same' => "must equal {$argument}",
            'different' => "must differ from {$argument}",
            'after' => "must be after {$argument}",
            'before' => "must be before {$argument}",
            'gt' => "must be greater than {$argument}",
            'gte' => "must be at least {$argument}",
            'lt' => "must be less than {$argument}",
            'lte' => "must be at most {$argument}",
            'required_if' => "required when {$argument}",
            'required_with' => "required when {$argument} is present",
            'required_without' => "required when {$argument} is absent",
            'regex' => 'must match a specific format',
            default => $head,
        };
    }
}
