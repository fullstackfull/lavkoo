<?php

namespace App\Services\Fulfillment\Verification;

use App\Services\Fulfillment\Support\SecretRedactor;

/**
 * Turns a decoded JSON response into the record kept as evidence, and reads the answers a person needs out of it: where the list of
 * records is, how it is paginated, which fields look like stock, which look like identifiers, which words a status takes.
 *
 * Secrets are removed first (SecretRedactor), people second (inside FieldProfile), so a token or a customer cannot reach the
 * record whatever key the provider happens to use.
 */
final class ResponseShape
{
    private const PAGINATION_KEY = '/^(total|totalcount|totalitems|totalelements|totalpages|pages|pagesize|size|limit|page|currentpage|perpage|lastpage|hasnext|hasmore|hasnextpage|next|nextpage|count)$/';

    private const STOCK_KEY = '/(stock|qty|quantit|available|onhand|reserved|inventory|balance)/i';

    private const IDENTIFIER_KEY = '/^(id|sku|code|barcode|externalid|.+id)$/i';

    private const STATUS_KEY = '/(status|state)/i';

    /** @return array<string, mixed> the profile of the whole response */
    public static function profile(mixed $json, bool $strict = false): array
    {
        $profile = new FieldProfile();
        $profile->add(SecretRedactor::redact($json), '', $strict);

        return $profile->toArray();
    }

    /**
     * @param array<string, mixed> $root a profile from profile()
     * @return array<string, mixed>
     */
    public static function findings(array $root): array
    {
        $itemsPath = self::itemsPath($root);
        $list = $itemsPath === null ? null : self::at($root, $itemsPath);
        $item = $list['items'] ?? null;

        return array_filter([
            'items_path' => $itemsPath,
            'item_count' => $list['count']['max'] ?? null,
            'envelope' => self::envelope($root, $itemsPath),
            'pagination' => self::pagination($root),
            'identifier_fields' => $item === null ? null : self::identifiers($item),
            'stock_fields' => $item === null ? null : self::stock($item),
            'status_fields' => self::statuses($item ?? $root),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Where the list of records is: '' for a response that is a list itself, 'data' or 'data.content' for an envelope, null when no
     * list of objects was found. With several lists, the longest one.
     *
     * @param array<string, mixed> $profile
     */
    public static function itemsPath(array $profile): ?string
    {
        $candidates = [];
        self::collectLists($profile, '', 0, $candidates);

        $best = null;
        foreach ($candidates as $path => $count) {
            if ($best === null || $count > $candidates[$best]) {
                $best = $path;
            }
        }

        return $best === null ? null : (string) $best;
    }

    /** @param array<string, mixed> $profile @param array<string, int> $candidates path => longest length seen */
    private static function collectLists(array $profile, string $prefix, int $depth, array &$candidates): void
    {
        if (self::is($profile, 'array') && (isset($profile['items']['keys']) || ($profile['count']['max'] ?? 0) === 0)) {
            $candidates[$prefix] = (int) ($profile['count']['max'] ?? 0);

            return;
        }
        if ($depth >= 3 || !self::is($profile, 'object')) {
            return;
        }

        foreach ($profile['keys'] ?? [] as $name => $child) {
            self::collectLists($child, $prefix === '' ? (string) $name : $prefix . '.' . $name, $depth + 1, $candidates);
        }
    }

    /** @param array<string, mixed> $profile */
    private static function is(array $profile, string $type): bool
    {
        $types = $profile['types'] ?? null;

        return is_array($types) ? isset($types[$type]) : $types === $type;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>|null
     */
    public static function at(array $profile, string $path): ?array
    {
        foreach ($path === '' ? [] : explode('.', $path) as $segment) {
            $profile = $profile['keys'][$segment] ?? null;
            if ($profile === null) {
                return null;
            }
        }

        return $profile;
    }

    /** @param array<string, mixed> $root @return array<string, string>|null */
    private static function envelope(array $root, ?string $itemsPath): ?array
    {
        if (($root['types'] ?? null) !== 'object') {
            return null;
        }

        $envelope = [];
        foreach ($root['keys'] ?? [] as $name => $child) {
            if ($itemsPath !== null && ($name === explode('.', $itemsPath)[0])) {
                $envelope[$name] = 'the list of records';

                continue;
            }
            $envelope[$name] = is_array($child['types'] ?? null) ? implode('|', array_keys($child['types'])) : (string) ($child['types'] ?? '?');
        }

        return $envelope;
    }

    /** @param array<string, mixed> $root @return array<string, string|int|float>|null */
    private static function pagination(array $root): ?array
    {
        $found = [];
        self::collectPagination($root, '', 0, $found);

        return $found === [] ? null : $found;
    }

    /** @param array<string, mixed> $profile @param array<string, string|int|float> $found */
    private static function collectPagination(array $profile, string $prefix, int $depth, array &$found): void
    {
        foreach ($profile['keys'] ?? [] as $name => $child) {
            $path = $prefix === '' ? (string) $name : $prefix . '.' . $name;
            if (preg_match(self::PAGINATION_KEY, strtolower((string) preg_replace('/[^A-Za-z]/', '', (string) $name))) === 1 && ($child['types'] ?? null) !== 'array') {
                $found[$path] = $child['numeric']['min'] ?? array_key_first($child['values'] ?? []) ?? ($child['examples'][0] ?? '?');
            } elseif ($depth < 1 && ($child['types'] ?? null) === 'object') {
                self::collectPagination($child, $path, $depth + 1, $found);
            }
        }
    }

    /** @param array<string, mixed> $item @return list<array{field: string, unique: bool}>|null */
    private static function identifiers(array $item): ?array
    {
        $fields = [];
        foreach ($item['keys'] ?? [] as $name => $child) {
            $types = is_array($child['types'] ?? null) ? $child['types'] : [(string) ($child['types'] ?? '') => $child['present'] ?? 0];
            if (preg_match(self::IDENTIFIER_KEY, (string) $name) === 1 && array_intersect(array_keys($types), ['object', 'array']) === []) {
                $withValue = ($child['present'] ?? 0) - ($types['null'] ?? 0);
                $fields[] = ['field' => (string) $name, 'unique' => ($child['distinct'] ?? 0) === $withValue && !isset($child['distinct_capped'])];
            }
        }

        return $fields;
    }

    /**
     * The numeric fields whose names say "stock": the candidates for "how many can be sold". Which one it is stays a human decision,
     * confirmed against a physical count; this only lists them with their ranges.
     *
     * @param array<string, mixed> $item
     * @return list<array<string, mixed>>|null
     */
    private static function stock(array $item): ?array
    {
        $fields = [];
        foreach ($item['keys'] ?? [] as $name => $child) {
            if (preg_match(self::STOCK_KEY, (string) $name) === 1 && isset($child['numeric'])) {
                $fields[] = ['field' => (string) $name, 'present_in' => $child['present'] . ' of ' . $item['present'], 'min' => $child['numeric']['min'], 'max' => $child['numeric']['max'], 'sum' => $child['numeric']['sum'], 'non_zero' => $child['numeric']['non_zero']];
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $profile @return list<array{field: string, values: array<string, int>}>|null */
    private static function statuses(array $profile, string $prefix = '', int $depth = 0): ?array
    {
        $fields = [];
        foreach ($profile['keys'] ?? [] as $name => $child) {
            $path = $prefix === '' ? (string) $name : $prefix . '.' . $name;
            if (preg_match(self::STATUS_KEY, (string) $name) === 1 && isset($child['values'])) {
                $fields[] = ['field' => $path, 'values' => $child['values']];
            } elseif ($depth < 2 && self::is($child, 'object')) {
                array_push($fields, ...(self::statuses($child, $path, $depth + 1) ?? []));
            }
        }

        return $fields;
    }
}
