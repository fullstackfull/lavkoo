<?php

namespace App\Services\Commerce;

use App\Models\ProductCollection;
use App\Services\Platform\Policy;

/**
 * The shape of a collection's merchandising, and the referee of what it may say (Phase 3.2).
 *
 * Merchandising is the hand on the scale: pins fix positions, exclusions remove, boosts re-rank,
 * a minimum decides when the list is too thin to show, and the fallback names what happens then.
 * All of it is admin input headed for the resolver, so all of it goes through here first — and a
 * config that contradicts itself (a pinned product that is also excluded, a fallback pointing at
 * a collection whose fallback points back) is refused at save, where the admin can fix it, not
 * discovered at render, where a shopper pays for it.
 */
class MerchandisingRules
{
    public const BOOST_KINDS = ['product', 'brand', 'category', 'featured'];
    public const FALLBACK_KINDS = ['hide', 'source', 'collection'];

    /** The catalogue orderings a fallback may name — 'collection' deliberately not among them. */
    public const FALLBACK_SOURCES = ['featured', 'best_selling', 'new_arrival', 'top_rated'];

    public function __construct(private readonly Policy $policy)
    {
    }

    /**
     * Validate untrusted merchandising into exactly what the resolver reads — or name what is
     * wrong. `null` in, `null` out: no merchandising is a valid state, not an error.
     *
     * @return array{config: ?array<string, mixed>, errors: array<int, string>}
     */
    public function validate(mixed $raw, ?int $collectionId = null): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return ['config' => null, 'errors' => []];
        }

        if (!is_array($raw)) {
            return ['config' => null, 'errors' => ['merchandising:not_an_object']];
        }

        $errors = [];

        $pins = $this->pins($raw['pins'] ?? []);
        $excluded = $this->ids($raw['excluded'] ?? [], $this->limit('commerce_max_exclusions'));

        // A product cannot be fixed into the list and banned from it at once. Refusing beats
        // picking a winner silently: whichever the admin meant, the other one is a mistake.
        $contradiction = array_intersect(array_column($pins, 'id'), $excluded);
        if ($contradiction !== []) {
            $errors[] = 'pinned_and_excluded_at_once:' . implode(',', $contradiction);
        }

        $boosts = [];
        foreach (array_slice(array_values(is_array($raw['boosts'] ?? null) ? $raw['boosts'] : []), 0, $this->limit('commerce_max_boosts')) as $row) {
            if (!is_array($row) || !in_array($row['kind'] ?? null, self::BOOST_KINDS, true)) {
                $errors[] = 'boost:unknown_kind';
                continue;
            }
            $weight = $row['weight'] ?? null;
            if (!is_numeric($weight) || (float) $weight <= 0 || (float) $weight > $this->limit('commerce_max_boost_weight')) {
                $errors[] = 'boost:weight_out_of_range';
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($row['kind'] !== 'featured' && $id <= 0) {
                $errors[] = 'boost:' . $row['kind'] . '_needs_an_id';
                continue;
            }
            $boosts[] = [
                'kind'   => $row['kind'],
                'id'     => $row['kind'] === 'featured' ? null : $id,
                'weight' => (float) $weight,
            ];
        }

        $minItems = $raw['min_items'] ?? 0;
        $minItems = is_numeric($minItems) && (int) $minItems >= 0 ? min((int) $minItems, 24) : 0;

        $fallback = $this->fallback($raw['fallback'] ?? null, $collectionId, $errors);

        $config = [
            'pins'      => $pins,
            'excluded'  => $excluded,
            'boosts'    => $boosts,
            'min_items' => $minItems,
            'replace'   => (bool) ($raw['replace'] ?? false),
            'fallback'  => $fallback,
        ];

        // All defaults means no merchandising: store the honest NULL, not an empty costume.
        $empty = $pins === [] && $excluded === [] && $boosts === [] && $minItems === 0
            && !$config['replace'] && $fallback['kind'] === 'hide';

        return ['config' => $empty ? null : $config, 'errors' => $errors];
    }

    /**
     * The validated config a stored collection carries, with every key present.
     *
     * @return array{pins: array<int, int>, excluded: array<int, int>, boosts: array<int, array<string, mixed>>,
     *               min_items: int, replace: bool, fallback: array{kind: string, id: ?int, source: ?string}}
     */
    public function configFor(ProductCollection $collection): array
    {
        $stored = is_array($collection->merchandising) ? $collection->merchandising : [];

        // The save path refuses a product that is both pinned and excluded; a row written past
        // the save path (an import, a hand edit) can still carry the contradiction, and here the
        // exclusion wins — it is the stronger statement, and rendering a banned product because
        // it is also pinned is the wrong reading of both.
        $excluded = $this->ids($stored['excluded'] ?? [], $this->limit('commerce_max_exclusions'));

        return [
            'pins'      => array_values(array_filter(
                $this->pins($stored['pins'] ?? []),
                fn (array $pin) => !in_array($pin['id'], $excluded, true),
            )),
            'excluded'  => $excluded,
            'boosts'    => array_values(array_filter(
                is_array($stored['boosts'] ?? null) ? $stored['boosts'] : [],
                fn ($boost) => is_array($boost) && in_array($boost['kind'] ?? null, self::BOOST_KINDS, true),
            )),
            'min_items' => max(0, (int) ($stored['min_items'] ?? 0)),
            'replace'   => (bool) ($stored['replace'] ?? false),
            'fallback'  => [
                'kind'   => in_array($stored['fallback']['kind'] ?? null, self::FALLBACK_KINDS, true)
                    ? $stored['fallback']['kind'] : 'hide',
                'id'     => isset($stored['fallback']['id']) ? (int) $stored['fallback']['id'] : null,
                'source' => in_array($stored['fallback']['source'] ?? null, self::FALLBACK_SOURCES, true)
                    ? $stored['fallback']['source'] : null,
            ],
        ];
    }

    // ---------------------------------------------------------------------------------------

    /**
     * Pins, each with the 1-based position it holds (§26: "#1 pinned, #2 automatic, #3 pinned").
     * Accepts [{id, position}] rows or a plain id list, where order means position. A pin keeps
     * its identity whatever the dynamic ranking does around it.
     *
     * @return array<int, array{id: int, position: int}>
     */
    private function pins(mixed $raw): array
    {
        $rows = is_array($raw) ? array_values($raw) : [];
        $pins = [];
        $taken = [];

        foreach ($rows as $index => $row) {
            $id = (int) (is_array($row) ? ($row['id'] ?? 0) : $row);
            $position = is_array($row) && is_numeric($row['position'] ?? null)
                ? (int) $row['position']
                : $index + 1;

            if ($id <= 0 || $position < 1 || $position > 24 || in_array($id, $taken, true)) {
                continue;
            }

            $taken[] = $id;
            $pins[] = ['id' => $id, 'position' => $position];

            if (count($pins) >= $this->limit('commerce_max_pins')) {
                break;
            }
        }

        usort($pins, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $pins;
    }

    /** @return array<int, int> */
    private function ids(mixed $raw, int $cap): array
    {
        $list = is_array($raw) ? $raw : explode(',', is_string($raw) ? $raw : '');

        return array_slice(array_values(array_unique(array_filter(
            array_map('intval', $list),
            fn ($id) => $id > 0,
        ))), 0, $cap);
    }

    /**
     * @param  array<int, string>  $errors
     * @return array{kind: string, id: ?int, source: ?string}
     */
    private function fallback(mixed $raw, ?int $collectionId, array &$errors): array
    {
        $none = ['kind' => 'hide', 'id' => null, 'source' => null];

        if (!is_array($raw) || !in_array($raw['kind'] ?? null, self::FALLBACK_KINDS, true)) {
            return $none;
        }

        if ($raw['kind'] === 'source') {
            $source = $raw['source'] ?? null;
            if (!in_array($source, self::FALLBACK_SOURCES, true)) {
                $errors[] = 'fallback:unknown_source';

                return $none;
            }

            return ['kind' => 'source', 'id' => null, 'source' => $source];
        }

        if ($raw['kind'] === 'collection') {
            $id = (int) ($raw['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = 'fallback:collection_needs_an_id';

                return $none;
            }
            if (!$this->chainIsAcyclic($collectionId, $id)) {
                $errors[] = 'fallback:cycle_detected';

                return $none;
            }

            return ['kind' => 'collection', 'id' => $id, 'source' => null];
        }

        return $none;
    }

    /**
     * Walk the fallback chain a proposed reference would create and refuse a loop (§30) — a
     * collection falling back to itself, or through any ring of others back to where it began.
     */
    private function chainIsAcyclic(?int $fromId, int $toId): bool
    {
        $seen = $fromId !== null ? [$fromId] : [];
        $current = $toId;
        $hops = 0;

        while ($current !== null && $hops < $this->limit('commerce_max_chain')) {
            if (in_array($current, $seen, true)) {
                return false;
            }
            $seen[] = $current;
            $hops++;

            try {
                $next = ProductCollection::query()->find($current)?->merchandising['fallback'] ?? null;
            } catch (\Throwable) {
                return true; // unreadable is not provably cyclic; the resolver's depth guard holds
            }

            $current = is_array($next) && ($next['kind'] ?? null) === 'collection'
                ? (int) ($next['id'] ?? 0) ?: null
                : null;
        }

        // A chain longer than the cap is treated as a loop: nobody composes five honest hops.
        return $current === null;
    }
    /**
     * How large a merchandiser may curate.
     *
     * These were constants, so a seasonal collection that needed a thirteenth pin was refused with
     * no route open to the person refused except a deploy. They are the marketplace's limits now.
     */
    private function limit(string $key): int
    {
        return $this->policy->int($key);
    }

}
