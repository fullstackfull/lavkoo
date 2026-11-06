<?php

namespace App\Services\SalesChannels\Sync;

use App\Models\ExternalOrderMapping;

/** What ingesting one channel order did. `changes` names the facts that changed, for whoever has to react (routing, costs, a person). */
final readonly class IngestResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    /** The order we hold is newer than the one that arrived: nothing was changed. */
    public const STALE = 'stale';

    /** @param list<string> $changes */
    public function __construct(
        public string $outcome,
        public ExternalOrderMapping $mapping,
        public array $changes = [],
    ) {
    }

    public function changed(): bool
    {
        return in_array($this->outcome, [self::CREATED, self::UPDATED], true);
    }

    public function has(string $change): bool
    {
        return in_array($change, $this->changes, true);
    }
}
