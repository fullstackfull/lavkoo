<?php

namespace App\Services\DeveloperPortal;

/**
 * The SHAPE of a JSON response — its keys and their types, never their values.
 *
 * The API of this project returns `response()->json(...)` directly nearly a thousand times and has
 * almost no API Resource classes, so there is nothing to reflect: the only honest way to say what
 * an endpoint answers with is to look at what it answered with. This class turns one real body
 * into a structure that can be stored, merged with the next one, and published — and it does that
 * by keeping the vocabulary and discarding the content.
 *
 * That is also what makes it safe to store. A key called `token` is exactly what documentation
 * should say; the token itself never enters this class's output, because no leaf value ever does.
 */
class ResponseShape
{
    /** Deep enough for a paginated list of objects with nested relations; short of a stack trace. */
    private const MAX_DEPTH = 6;

    /** A response with more keys than this at one level is a data dump, not a documented object. */
    private const MAX_KEYS = 60;

    /** Array items sampled and merged. Enough to notice a nullable field, cheap enough to be free. */
    private const MAX_ITEMS = 5;

    /**
     * @return array<string, mixed>|null  null when the body is not something worth describing
     */
    public function of(mixed $decoded): ?array
    {
        if ($decoded === null) {
            return null;
        }

        return $this->describe($decoded, 0);
    }

    /**
     * Combine what two responses said, so one nullable field or one empty list does not become the
     * documented truth.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>|null  $incoming
     * @return array<string, mixed>|null
     */
    public function merge(?array $existing, ?array $incoming): ?array
    {
        if ($existing === null) {
            return $incoming;
        }

        if ($incoming === null) {
            return $existing;
        }

        if (($existing['type'] ?? null) !== ($incoming['type'] ?? null)) {
            // Two different types for the same place: say so rather than picking a winner.
            return ['type' => 'mixed', 'was' => array_values(array_unique(array_filter([
                $existing['type'] ?? null,
                $incoming['type'] ?? null,
            ])))];
        }

        $merged = $existing;
        $merged['nullable'] = ($existing['nullable'] ?? false) || ($incoming['nullable'] ?? false);

        if (($existing['type'] ?? null) === 'object') {
            $properties = $existing['properties'] ?? [];

            foreach ($incoming['properties'] ?? [] as $key => $shape) {
                $properties[$key] = isset($properties[$key])
                    ? $this->merge($properties[$key], $shape)
                    : $shape + ['optional' => true];
            }

            // A key one response had and the other did not is optional, not absent.
            foreach ($properties as $key => $shape) {
                if (!isset($incoming['properties'][$key]) && isset($existing['properties'][$key])) {
                    $properties[$key]['optional'] = true;
                }
            }

            $merged['properties'] = $properties;
        }

        if (($existing['type'] ?? null) === 'array') {
            $merged['items'] = $this->merge($existing['items'] ?? null, $incoming['items'] ?? null);
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(mixed $value, int $depth): array
    {
        if ($value === null) {
            return ['type' => 'null', 'nullable' => true];
        }

        if ($depth >= self::MAX_DEPTH) {
            return ['type' => 'object', 'truncated' => true];
        }

        if (is_bool($value)) {
            return ['type' => 'boolean'];
        }

        if (is_int($value)) {
            return ['type' => 'integer'];
        }

        if (is_float($value)) {
            return ['type' => 'number'];
        }

        if (is_string($value)) {
            return ['type' => 'string'] + $this->stringFormat($value);
        }

        if (is_array($value) && array_is_list($value)) {
            $items = null;

            foreach (array_slice($value, 0, self::MAX_ITEMS) as $item) {
                $items = $this->merge($items, $this->describe($item, $depth + 1));
            }

            // An empty list says nothing about what it holds, and pretending otherwise would put a
            // made-up item schema in the documentation.
            return array_filter([
                'type' => 'array',
                'items' => $items,
                'observed_empty' => $value === [] ? true : null,
            ], static fn ($one) => $one !== null);
        }

        if (is_array($value)) {
            $properties = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if (++$count > self::MAX_KEYS) {
                    return ['type' => 'object', 'properties' => $properties, 'truncated' => true];
                }

                $properties[(string) $key] = $this->describe($item, $depth + 1);
            }

            return ['type' => 'object', 'properties' => $properties];
        }

        return ['type' => 'string'];
    }

    /**
     * A format a reader can act on, recognised from the string's SHAPE rather than its content.
     *
     * Nothing here records the string itself: the answer is a label like "date-time", which is what
     * a client integrating against this endpoint needs and carries none of the value.
     *
     * @return array<string, string>
     */
    private function stringFormat(string $value): array
    {
        return match (true) {
            preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value) === 1 => ['format' => 'date-time'],
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => ['format' => 'date'],
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 => ['format' => 'uuid'],
            preg_match('#^https?://#i', $value) === 1 => ['format' => 'uri'],
            filter_var($value, FILTER_VALIDATE_EMAIL) !== false => ['format' => 'email'],
            default => [],
        };
    }
}
