<?php

namespace App\Services\SalesChannels\Data;

use Carbon\CarbonInterface;

/**
 * One page of anything a connector reads, with the cursor to ask for the next. A connector never loads "everything": it hands back pages so that a run can be
 * resumed, rate-limited and stopped.
 *
 * @template T
 */
final readonly class Pages
{
    /**
     * @param list<T> $items
     * @param array<string, mixed>|null $nextCursor null when there is nothing more
     */
    public function __construct(
        public array $items,
        public ?array $nextCursor = null,
        public ?CarbonInterface $highWatermark = null,
        public int $skipped = 0,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
