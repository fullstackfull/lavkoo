<?php

namespace App\Services\SalesChannels\Routing;

use App\Enums\SalesChannels\RoutingAction;

/**
 * What the engine decided about one order, and why. `lineDecisions` says where each line goes; `ruleSnapshot` keeps the rule as it was when it decided, so
 * the record stays true after the rule is edited.
 */
final readonly class RoutingDecision
{
    /**
     * @param list<array<string, mixed>> $lineDecisions
     * @param array<string, mixed>|null $ruleSnapshot
     * @param array<string, mixed> $inputs
     */
    public function __construct(
        public RoutingAction $action,
        public ?string $provider,
        public string $reasonCode,
        public string $reason,
        public ?int $ruleId = null,
        public ?array $ruleSnapshot = null,
        public array $lineDecisions = [],
        public array $inputs = [],
    ) {
    }

    /** Two decisions that agree on all of this are the same decision: recording it again would only add noise. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->action->value, $this->provider, $this->reasonCode, $this->ruleId, $this->lineDecisions], JSON_THROW_ON_ERROR));
    }
}
