<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

/**
 * Everything one reading of the provider's city list has seen, and what it proves about whether it saw all of it.
 *
 * The first response (`returnAll=true`) and every page that follows are merged by the provider's id, so a page that repeats cities
 * adds nothing and the cities of the first response are never lost. What it keeps apart is which ids the PAGED reading saw:
 * a reading is only "paging worked and ended" when the pages themselves, not just the first response, reached the end.
 */
final class CityListRead
{
    public int $requests = 0;

    /** Records seen across all responses, repeats included. */
    public int $fetched = 0;

    /** The provider's own count of cities, when a response states it. */
    public ?int $expectedTotal = null;

    /** The provider said whether the response it just gave was the last page; null when it never says. */
    public ?bool $lastPage = null;

    public ?int $pageCount = null;

    /** A page past the end came back empty. */
    public bool $emptyPage = false;

    /** A page added nothing the paged reading had not already seen: the server does not page. */
    public bool $repeated = false;

    public bool $ceiling = false;

    public bool $deadline = false;

    /** Why reading stopped early, in the provider's words (redacted). */
    public ?string $failure = null;

    /** @var list<array{page: int|null, status: int|null, items: int, new: int}> */
    public array $trace = [];

    /** @var array<string, array<array-key, mixed>> */
    private array $items = [];

    /** @var array<string, true> */
    private array $firstIds = [];

    /** @var array<string, true> */
    private array $pagedIds = [];

    /**
     * @param list<array<array-key, mixed>> $items
     * @param array{total: ?int, pages: ?int, last: ?bool} $envelope
     */
    public function addFirstResponse(array $items, array $envelope, ?int $status): void
    {
        $this->record(page: null, status: $status, items: count($items), new: $this->absorb(items: $items, envelope: $envelope, seen: $this->firstIds));
    }

    /**
     * @param list<array<array-key, mixed>> $items
     * @param array{total: ?int, pages: ?int, last: ?bool} $envelope
     * @return int the ids this page added to the paged reading
     */
    public function addPage(int $page, array $items, array $envelope, ?int $status): int
    {
        $new = $this->absorb(items: $items, envelope: $envelope, seen: $this->pagedIds);
        $this->record(page: $page, status: $status, items: count($items), new: $new);

        return $new;
    }

    /** A request that brought no records: it failed, or it was a page past the end. */
    public function noteEmptyRequest(?int $page, ?int $status): void
    {
        $this->record(page: $page, status: $status, items: 0, new: 0);
    }

    public function distinct(): int
    {
        return count($this->items);
    }

    /** @return array<string, array<array-key, mixed>> the records by the provider's id */
    public function items(): array
    {
        return $this->items;
    }

    public function regionsSeen(): int
    {
        $regions = [];
        foreach ($this->items as $item) {
            if (isset($item['regionId']) && is_scalar($item['regionId'])) {
                $regions[(string) $item['regionId']] = true;
            }
        }

        return count($regions);
    }

    /** Does the paged list contain every city the first response had? If not, the two are not readings of one list. */
    public function pagesCoverFirstResponse(): bool
    {
        return array_diff_key($this->firstIds, $this->pagedIds) === [];
    }

    /** The provider itself says there is more than was read. */
    public function moreIsExpected(): bool
    {
        return ($this->expectedTotal !== null && $this->distinct() < $this->expectedTotal)
            || $this->lastPage === false
            || ($this->pageCount !== null && $this->pageCount > 1);
    }

    /** The provider itself says what was read is all of it: its total is reached, or the response is its last page. */
    public function endIsStated(): bool
    {
        if ($this->moreIsExpected()) {
            return false;
        }

        return ($this->expectedTotal !== null && $this->distinct() >= $this->expectedTotal) || $this->lastPage === true || $this->pageCount === 1;
    }

    /**
     * @param list<array<array-key, mixed>> $items
     * @param array{total: ?int, pages: ?int, last: ?bool} $envelope
     * @param array<string, true> $seen
     */
    private function absorb(array $items, array $envelope, array &$seen): int
    {
        $this->fetched += count($items);
        $this->expectedTotal = $envelope['total'] !== null ? max($this->expectedTotal ?? 0, $envelope['total']) : $this->expectedTotal;
        $this->pageCount = $envelope['pages'] ?? $this->pageCount;
        $this->lastPage = $envelope['last'] ?? $this->lastPage;

        $new = 0;
        foreach ($items as $item) {
            if (!isset($item['id']) || !is_scalar($item['id'])) {
                continue;
            }

            $id = (string) $item['id'];
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $new++;
            }
            $this->items[$id] = $item;
        }

        return $new;
    }

    private function record(?int $page, ?int $status, int $items, int $new): void
    {
        $this->requests++;
        $this->trace[] = ['page' => $page, 'status' => $status, 'items' => $items, 'new' => $new];
    }
}
