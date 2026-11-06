<?php

namespace App\Services\OperationalCosts\Data;

use App\Services\OperationalCosts\Support\Money;

/** What a rule computed, and the inputs it used, so the figure can be audited without re-running anything. */
final readonly class RuleResult
{
    /** @param array<string, mixed> $inputs */
    public function __construct(
        public Money $amount,
        public int $ruleId,
        public string $currency,
        public array $inputs,
        public ?Money $quantity = null,
        public ?Money $unitAmount = null,
    ) {
    }
}
