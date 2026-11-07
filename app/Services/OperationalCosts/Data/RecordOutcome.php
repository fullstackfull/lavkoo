<?php

namespace App\Services\OperationalCosts\Data;

use App\Models\OperationalCostEntry;

/** What CostLedger::record() did with a draft. */
final readonly class RecordOutcome
{
    public const CREATED = 'created';
    /** The same computation or document was already recorded: nothing was written. */
    public const DUPLICATE = 'duplicate';
    /** The slot already holds something better (an actual cost where an estimate arrived): nothing was written. */
    public const IGNORED = 'ignored';
    /** Written, but kept out of the totals because it conflicts with an approved entry and needs a person. */
    public const CONFLICT = 'conflict';

    /** @param list<int> $supersededIds */
    public function __construct(
        public string $result,
        public OperationalCostEntry $entry,
        public array $supersededIds = [],
    ) {
    }

    public function wroteSomething(): bool
    {
        return in_array($this->result, [self::CREATED, self::CONFLICT], true);
    }
}
