<?php

namespace App\Services\OperationalCosts\Data;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\OperationalCostContract;

/**
 * The outcome of pricing one service: an amount from a rule, or the reason there is none. Never a silent zero.
 */
final readonly class ComputedCost
{
    public const PRICED = 'priced';
    public const MISSING = 'missing';
    /** The contract does not price this service and does not claim to: there is nothing to record. */
    public const NOT_PRICED = 'not_priced';

    private function __construct(
        public string $kind,
        public CostComponent $component,
        public ?RuleResult $result,
        public ?string $missingReason,
        public ?OperationalCostContract $contract,
    ) {
    }

    public static function priced(CostComponent $component, RuleResult $result, OperationalCostContract $contract): self
    {
        return new self(self::PRICED, $component, $result, null, $contract);
    }

    public static function missing(CostComponent $component, string $reason, ?OperationalCostContract $contract = null): self
    {
        return new self(self::MISSING, $component, null, $reason, $contract);
    }

    public static function notPriced(CostComponent $component, ?OperationalCostContract $contract): self
    {
        return new self(self::NOT_PRICED, $component, null, null, $contract);
    }

    public function isPriced(): bool
    {
        return $this->kind === self::PRICED;
    }

    public function isMissing(): bool
    {
        return $this->kind === self::MISSING;
    }
}
