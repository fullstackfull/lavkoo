<?php

namespace App\Enums\OperationalCosts;

/**
 * What is known about one cost. The five states are never blended: an estimate and the actual charge of the same service are one
 * cost (the actual one supersedes the estimate), and a cost that could not be determined is `Missing`, not zero.
 */
enum CostState: string
{
    /** Computed from a contract rule or a recorded unit cost, before the real charge is known. */
    case Estimated = 'estimated';
    /** Taken from the party that charged it (an API, an invoice line), not yet matched to anything. */
    case Actual = 'actual';
    /** An actual cost that an approved invoice line or statement confirmed. */
    case Reconciled = 'reconciled';
    /** Nobody could determine the amount (no contract, no rule, no data). The amount is NULL. */
    case Missing = 'missing';
    /** The party charged something we contest. Kept apart from every total until resolved. */
    case Disputed = 'disputed';

    public function hasAmount(): bool
    {
        return $this !== self::Missing;
    }

    /** Counts toward ACTUAL profit. */
    public function isSettled(): bool
    {
        return in_array($this, [self::Actual, self::Reconciled], true);
    }

    /** A settled amount outranks an estimate of the same slot. */
    public function rank(): int
    {
        return match ($this) {
            self::Missing => 0,
            self::Estimated => 1,
            self::Disputed => 2,
            self::Actual => 3,
            self::Reconciled => 4,
        };
    }

    public function labelKey(): string
    {
        return 'cost_state_' . $this->value;
    }
}
