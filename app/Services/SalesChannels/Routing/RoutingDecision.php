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
        return self::fingerprintOf(action: $this->action->value, provider: $this->provider, reasonCode: $this->reasonCode, ruleId: $this->ruleId, lineDecisions: $this->lineDecisions);
    }

    /**
     * The fingerprint of a decision from its parts, which is also how a stored decision is compared with a new one. A JSON column does not keep the order its keys were
     * written in (MySQL sorts them), so the keys of every line are put in one order first: the same decision read back must still be the same decision.
     *
     * @param list<array<string, mixed>> $lineDecisions
     */
    public static function fingerprintOf(string $action, ?string $provider, string $reasonCode, ?int $ruleId, array $lineDecisions): string
    {
        $lines = array_map(static function (array $line): array {
            ksort($line);

            return $line;
        }, $lineDecisions);

        return hash('sha256', json_encode([$action, $provider, $reasonCode, $ruleId, $lines], JSON_THROW_ON_ERROR));
    }
}
