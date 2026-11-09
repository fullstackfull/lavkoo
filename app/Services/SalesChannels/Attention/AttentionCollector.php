<?php

namespace App\Services\SalesChannels\Attention;

use Illuminate\Database\Eloquent\Model;

/**
 * Everything waiting for a person, across every integration, in one list.
 *
 * ## What it does not do
 *
 * It reads. It never sends, never retries, never settles anything. Every state it collects is one that some pipeline reached
 * deliberately because the outcome was not knowable, and the whole value of those states is that no machine touches them
 * again — a collector that "helpfully" retried would undo the one guarantee they exist to give.
 *
 * ## Ordering
 *
 * Oldest first, within domain order. A refund nobody has looked at for three days matters more than a stock event from this
 * morning, and the screen should not need a person to sort it to see that.
 */
class AttentionCollector
{
    public function __construct(private readonly AttentionRegistry $registry)
    {
    }

    /**
     * How many rows are waiting, per source, plus a total — cheap enough for a dashboard badge.
     *
     * @return array{total: int, late: int, by_source: array<string, int>, by_domain: array<string, int>}
     */
    public function summary(): array
    {
        $bySource = [];
        $byDomain = [];
        $total = 0;
        foreach ($this->registry->all() as $source) {
            $count = $this->query($source)->count();
            $bySource[$source->key] = $count;
            $byDomain[$source->domain] = ($byDomain[$source->domain] ?? 0) + $count;
            $total += $count;
        }

        return [
            'total' => $total,
            'late' => count(array_filter($this->items(), static fn (AttentionItem $item): bool => $item->isLate())),
            'by_source' => $bySource,
            'by_domain' => array_filter($byDomain),
        ];
    }

    /**
     * The rows themselves.
     *
     * @param string|null $onlySource one source's rows, or every source's
     * @return list<AttentionItem>
     */
    public function items(?string $onlySource = null, int $limit = 200): array
    {
        $items = [];
        foreach ($this->registry->all() as $source) {
            if ($onlySource !== null && $source->key !== $onlySource) {
                continue;
            }
            foreach ($this->query($source)->limit(max(1, $limit))->get() as $row) {
                $items[] = $this->itemOf($source, $row);
            }
        }

        // Oldest first. A row with no recorded time goes last rather than first: an unknown age is not an old age.
        usort($items, static function (AttentionItem $left, AttentionItem $right): int {
            return [$left->since === null, $left->since?->getTimestamp() ?? 0] <=> [$right->since === null, $right->since?->getTimestamp() ?? 0];
        });

        return $items;
    }

    public function item(string $sourceKey, int $id): ?AttentionItem
    {
        $source = $this->registry->find($sourceKey);
        if ($source === null) {
            return null;
        }
        $row = $this->query($source)->whereKey($id)->first();

        return $row === null ? null : $this->itemOf($source, $row);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Model> */
    private function query(AttentionSource $source): \Illuminate\Database\Eloquent\Builder
    {
        /** @var Model $model */
        $model = new $source->model();

        return $model->newQuery()->whereIn($source->stateColumn, $source->states)->orderBy('id');
    }

    private function itemOf(AttentionSource $source, Model $row): AttentionItem
    {
        $read = static function (?string $column) use ($row): mixed {
            return $column === null ? null : ($row->{$column} ?? null);
        };
        $since = $read($source->column('since'));

        return new AttentionItem(
            source: $source->key,
            domain: $source->domain,
            id: (int) $row->getKey(),
            state: (string) $row->{$source->stateColumn},
            reference: ($value = $read($source->column('reference'))) === null ? null : (string) $value,
            accountId: ($account = $read($source->column('account'))) === null ? null : (int) $account,
            reason: ($reason = $read($source->column('reason'))) === null ? null : (string) $reason,
            detail: ($detail = $read($source->column('detail'))) === null ? null : (string) $detail,
            since: $since instanceof \Carbon\CarbonInterface ? $since : null,
            actions: $source->actions,
            retryable: $source->retryable,
            whyNotRetryable: $source->whyNotRetryable(),
            orderReference: ($order = $read($source->column('order'))) === null ? null : (string) $order,
        );
    }
}
