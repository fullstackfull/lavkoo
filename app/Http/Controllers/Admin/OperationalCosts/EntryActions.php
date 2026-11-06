<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\Support\Money;

/**
 * What a person may still do with a cost entry. CostLedger is the only writer, and it refuses what it must; this says what is worth offering, so that the
 * screen never shows a button for something that makes no sense (approving an estimate, reversing a cost that a newer entry already replaced).
 *
 * Nothing here edits an entry. An approved or reconciled one is changed by no action at all: it can be disputed, reversed or - once disputed - resolved,
 * and every one of those leaves the original row as it was and adds to the history.
 */
final class EntryActions
{
    public const APPROVE = 'approve';

    public const DISPUTE = 'dispute';

    public const REVERSE = 'reverse';

    public const RESOLVE = 'resolve';

    public const ALL = [self::APPROVE, self::DISPUTE, self::REVERSE, self::RESOLVE];

    public static function allows(string $action, OperationalCostEntry $entry): bool
    {
        if (!$entry->is_effective) {
            return $action === self::RESOLVE && $entry->state === CostState::Disputed && $entry->superseded_by_id === null;
        }

        return match ($action) {
            self::APPROVE => $entry->state === CostState::Actual && $entry->approved_at === null,
            self::DISPUTE => in_array($entry->state, [CostState::Actual, CostState::Reconciled], true),
            self::REVERSE => in_array($entry->state, [CostState::Actual, CostState::Reconciled], true) && $entry->amount !== null && Money::of($entry->amount)->isPositive(),
            self::RESOLVE => $entry->state === CostState::Disputed,
            default => false,
        };
    }

    /** @return list<string> */
    public static function available(OperationalCostEntry $entry): array
    {
        return array_values(array_filter(self::ALL, static fn (string $action): bool => self::allows($action, $entry)));
    }
}
