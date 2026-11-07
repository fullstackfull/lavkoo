<?php

namespace App\Services\OperationalCosts\Returns;

use App\Models\OperationalCostReturnDecision;

/** What deciding about a return did: the decision that now stands, and whether it already did (a repeated submit decides nothing twice). */
final readonly class ReturnDecisionResult
{
    public function __construct(public OperationalCostReturnDecision $decision, public bool $alreadyDecided)
    {
    }
}
