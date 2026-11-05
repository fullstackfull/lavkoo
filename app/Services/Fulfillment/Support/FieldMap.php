<?php

namespace App\Services\Fulfillment\Support;

use Illuminate\Support\Arr;

/**
 * A mapping from OUR logical field names to dot-paths inside a provider's JSON.
 *
 * Several provider responses are not documented (their official docs give a URL and a description but
 * no response schema). Rather than guess field names in code, each such response is read through a map
 * that an administrator verifies against a real sample before anything depends on it.
 */
final class FieldMap
{
    /** Where a list of records is usually found when the provider wraps it. */
    private const LIST_WRAPPERS = ['data', 'content', 'items', 'list', 'results', 'records', 'rows', 'products', 'orders', 'data.items', 'data.content', 'data.data', 'data.products', 'data.orders'];

    /** @param array<string, string|null> $paths logical name => dot-path */
    public function __construct(private readonly array $paths)
    {
    }

    public function path(string $logical): ?string
    {
        $path = $this->paths[$logical] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function has(string $logical): bool
    {
        return $this->path($logical) !== null;
    }

    /** @param array<array-key, mixed> $record */
    public function value(array $record, string $logical, mixed $default = null): mixed
    {
        $path = $this->path($logical);

        return $path === null ? $default : Arr::get($record, $path, $default);
    }

    /** @param array<array-key, mixed> $record */
    public function string(array $record, string $logical): ?string
    {
        $value = $this->value($record, $logical);

        return is_scalar($value) && (string) $value !== '' ? trim((string) $value) : null;
    }

    /** @param array<array-key, mixed> $record */
    public function number(array $record, string $logical): ?float
    {
        $value = $this->value($record, $logical);

        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return $this->paths;
    }

    /**
     * Pull the list of records out of a decoded response: the configured path if there is one, else a
     * root list, else the first conventional wrapper that holds a list of objects.
     *
     * @param array<array-key, mixed> $response
     * @return list<array<array-key, mixed>>|null
     */
    public static function extractItems(array $response, ?string $itemsPath = null): ?array
    {
        if ($itemsPath !== null && $itemsPath !== '') {
            $found = Arr::get($response, $itemsPath);

            return self::asRecordList($found);
        }

        $root = self::asRecordList($response);
        if ($root !== null) {
            return $root;
        }

        foreach (self::LIST_WRAPPERS as $wrapper) {
            $found = self::asRecordList(Arr::get($response, $wrapper));
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return list<array<array-key, mixed>>|null */
    private static function asRecordList(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return is_array($value) && $value === [] ? [] : null;
        }

        if (!array_is_list($value)) {
            return null;
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                return null;
            }
        }

        return $value;
    }
}
