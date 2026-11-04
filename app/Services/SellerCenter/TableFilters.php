<?php

namespace App\Services\SellerCenter;

use Illuminate\Http\Request;

/**
 * The one filter system (handoff 05 part B).
 *
 * A screen declares its filter fields; this turns the request into applied chips, into the URLs
 * that add and remove them, and into the values a repository can query on. Every list in the Seller
 * Center goes through it, which is what keeps the toolbar identical on Orders, Issues and Products
 * and what makes a filter key mean the same thing everywhere.
 *
 * The URL always carries the full filter state, so any view is linkable and reloadable, and a
 * drill-down from the Control Tower can hand the destination exactly the set the issue counted —
 * the number in the alert and the number in the toolbar have to match (handoff 05 B6, 09 §1).
 */
class TableFilters
{
    /**
     * The shared filter vocabulary. A key means the same thing on every screen (handoff 05 B6).
     */
    public const SHARED_KEYS = [
        'status', 'severity', 'category', 'brand', 'warehouse', 'carrier', 'payment',
        'assignee', 'sku', 'date_from', 'date_to', 'due', 'view', 'q', 'sort', 'dir',
        'page', 'size', 'density', 'issues',
    ];

    /** Keys that are not filters — they must survive a filter change without becoming a chip. */
    private const NON_FILTER_KEYS = ['page', 'sort', 'dir', 'size', 'density'];

    /**
     * @param  array<string, array<string, mixed>>  $fields  key => [label, type, options?, group?]
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $fields,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * The filters actually applied, as chips ready to render.
     *
     * @return array<int, array{key: string, label: string, value: string, removeUrl: string, tone: ?string}>
     */
    public function chips(): array
    {
        $chips = [];

        foreach ($this->fields as $key => $field) {
            $raw = $this->request->query($key);
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }

            $chips[] = [
                'key' => $key,
                'label' => translate($field['label'] ?? $key),
                'value' => $this->readable($key, $field, $raw),
                'removeUrl' => $this->urlWithout($key),
                'tone' => $field['tone'] ?? ($key === 'severity' ? $this->severityTone($raw) : null),
            ];
        }

        return $chips;
    }

    /** Is anything filtering the list right now? Distinguishes "empty" from "no results". */
    public function isFiltered(): bool
    {
        foreach (array_keys($this->fields) as $key) {
            $value = $this->request->query($key);
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return trim((string) $this->request->query('q', '')) !== '';
    }

    /**
     * The fields still available to add, grouped for the filter panel.
     *
     * @return array<string, array<int, array{key: string, label: string, type: string}>>
     */
    public function available(): array
    {
        $groups = [];

        foreach ($this->fields as $key => $field) {
            $raw = $this->request->query($key);
            if ($raw !== null && $raw !== '' && $raw !== []) {
                continue;   // already applied — the chip is the editor
            }

            $group = translate($field['group'] ?? 'filters');
            $groups[$group][] = [
                'key' => $key,
                'label' => translate($field['label'] ?? $key),
                'type' => $field['type'] ?? 'text',
                // Each choice carries the URL that applies it. Without this the panel renders the
                // options and every one of them is a link to nowhere — the product bans dead
                // controls, and an option that cannot be chosen is the plainest kind.
                'options' => $this->linked($key, $field['options'] ?? []),
            ];
        }

        return $groups;
    }

    /**
     * A field's choices, each with the URL that applies it.
     *
     * @param  array<int, array<string, mixed>>  $options
     * @return array<int, array<string, mixed>>
     */
    private function linked(string $key, array $options): array
    {
        return array_map(function (array $option) use ($key) {
            $option['href'] ??= $this->urlWith($key, $option['value'] ?? '');

            return $option;
        }, $options);
    }

    /** The URL with one filter set, resetting the page — a new filter always starts at page 1. */
    public function urlWith(string $key, mixed $value): string
    {
        $query = $this->request->query();
        $query[$key] = $value;
        unset($query['page']);

        return $this->build($query);
    }

    public function urlWithout(string $key): string
    {
        $query = $this->request->query();
        unset($query[$key], $query['page']);

        return $this->build($query);
    }

    /**
     * `Clear all` removes the chips but keeps the saved view's own baseline (handoff 05 B3), so it
     * clears filters without silently throwing the seller out of the view they were working in.
     */
    public function urlClearAll(): string
    {
        $query = $this->request->query();
        foreach (array_keys($this->fields) as $key) {
            unset($query[$key]);
        }
        unset($query['q'], $query['page']);

        return $this->build($query);
    }

    /** The URL for a sort click: asc → desc → default (handoff 05 A4). */
    public function urlSort(string $key): string
    {
        $query = $this->request->query();
        $current = $this->request->query('sort');
        $direction = $this->request->query('dir', 'asc');

        if ($current !== $key) {
            $query['sort'] = $key;
            $query['dir'] = 'asc';
        } elseif ($direction === 'asc') {
            $query['dir'] = 'desc';
        } else {
            unset($query['sort'], $query['dir']);
        }

        unset($query['page']);

        return $this->build($query);
    }

    public function urlWithParams(array $params): string
    {
        $query = array_merge($this->request->query(), $params);
        foreach ($params as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
            }
        }
        unset($query['page']);

        return $this->build($query);
    }

    /** The applied values, for the repository. Non-filter keys never leak into a where clause. */
    public function values(): array
    {
        $values = [];

        foreach (array_keys($this->fields) as $key) {
            if (in_array($key, self::NON_FILTER_KEYS, true)) {
                continue;
            }
            $value = $this->request->query($key);
            if ($value !== null && $value !== '' && $value !== []) {
                $values[$key] = $value;
            }
        }

        $search = trim((string) $this->request->query('q', ''));
        if ($search !== '') {
            $values['q'] = $search;
        }

        return $values;
    }

    private function build(array $query): string
    {
        $query = array_filter($query, static fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $query === [] ? $this->baseUrl : $this->baseUrl . '?' . http_build_query($query);
    }

    /** What the chip reads: the option's own label where there is one, never a raw enum. */
    private function readable(string $key, array $field, mixed $raw): string
    {
        $values = is_array($raw) ? $raw : [$raw];
        $options = $field['options'] ?? [];

        if ($options !== [] && count($values) > 2) {
            return count($values) . ' ' . translate('selected');
        }

        $labels = array_map(static function ($value) use ($options) {
            foreach ($options as $option) {
                if ((string) ($option['value'] ?? '') === (string) $value) {
                    return $option['label'] ?? $value;
                }
            }

            return translate((string) $value);
        }, $values);

        return implode(', ', $labels);
    }

    private function severityTone(mixed $raw): ?string
    {
        $values = is_array($raw) ? $raw : [$raw];
        $highest = Status::highest($values);

        return $highest === null ? null : Status::severity($highest)['tone'];
    }
}
