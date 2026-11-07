<?php

namespace App\Services\SalesChannels\Routing;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ExecutionPolicy;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Enums\SalesChannels\RoutingAction;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingDecision;
use App\Models\FulfillmentRoutingRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Decides what is to be done about the fulfilment of a channel order, records the decision with its reason, and applies it to the order's record.
 *
 * THE ENGINE CANNOT BE TALKED OUT OF THESE (checked before any rule is read):
 *  - an order the channel ships itself (Amazon FBA, channel-managed) is never given to a provider of ours;
 *  - an order whose fulfilment mode the channel did not state, that is not yet released (payment unconfirmed) or is already closed, is never routed;
 *  - an order that has been dispatched or whose shipment is linked is never decided again: the decision that was applied stands;
 *  - a package whose carrier the channel arranges and pays for is only followed, because a shipment of ours would be a second shipment of the same parcel;
 *  - cash on delivery and lines not linked to a product wait for a person.
 * Only after those does an administrator's rule decide. With NO rule the account's default decides, and that default is either manual review (the standard: nothing
 * is routed unless someone said so) or "managed by someone else", which only follows the order. An account can never default to creating shipments: that needs a rule
 * that names where the goods are shipped from.
 *
 * App-driven creation of a provider shipment for a channel order is not available in this version (it needs the buyer's address, which is deliberately never stored,
 * and an internal order). A rule asking for it is recorded as wanted and the order waits for a person, with the reason `managed_dispatch_not_available`.
 */
class DynamicFulfillmentRoutingEngine
{
    /** Review reasons this engine itself sets: the only ones it may clear. */
    private const OWN_REASONS = [
        'unknown_fulfillment_mode', 'order_not_releasable', 'no_active_lines', 'cod_requires_review', 'unmapped_lines', 'no_rule_matched', 'managed_dispatch_not_available',
        'line_has_no_stock_location', 'stock_not_at_target_provider', 'rule_requires_manual_review', 'rule_holds_order',
    ];

    public function __construct(
        private readonly RoutingContextFactory $contexts,
        private readonly RoutingRuleMatcher $matcher,
    ) {
    }

    /** The decision for a context. Pure: it reads rules and the facts it is given, and writes nothing. */
    public function decide(RoutingContext $context, int $sellerId = 0): RoutingDecision
    {
        $inputs = $context->toInputs();
        $lines = $context->activeLines();

        if ($context->mode->isFulfilledByChannel()) {
            return new RoutingDecision(RoutingAction::ChannelFulfills, null, 'channel_fulfills_order', "The {$context->channel} channel ships this order itself ({$context->mode->value}); no provider of ours is involved.", inputs: $inputs, lineDecisions: $this->uniform($lines, RoutingAction::ChannelFulfills, null));
        }
        if ($context->mode === FulfillmentMode::Unknown) {
            return $this->wait(RoutingAction::ManualReview, 'unknown_fulfillment_mode', 'The channel did not say who fulfils this order, so it is never treated as ours to ship.', $context, $inputs);
        }
        if ($context->status === ChannelOrderStatus::Pending || $context->status->isTerminal()) {
            return $this->wait(RoutingAction::Hold, 'order_not_releasable', "The order is '{$context->status->value}' at the channel: it is not released for fulfilment.", $context, $inputs);
        }
        if ($lines === []) {
            return $this->wait(RoutingAction::Hold, 'no_active_lines', 'Every line of the order is cancelled.', $context, $inputs);
        }
        if ($context->channelPaysCarrier === 'channel') {
            return new RoutingDecision(RoutingAction::MonitorOnly, null, 'carrier_arranged_by_channel', 'The channel pays and arranges the carrier for this package; a shipment of ours would be a second shipment of the same parcel, so it is only followed.', inputs: $inputs, lineDecisions: $this->uniform($lines, RoutingAction::MonitorOnly, null));
        }
        if ($context->isCod && !$context->allowCod) {
            return $this->wait(RoutingAction::ManualReview, 'cod_requires_review', 'Cash on delivery: the amount to collect is checked by a person before anything is shipped.', $context, $inputs);
        }
        if (array_filter($lines, static fn (RoutingLine $line): bool => $line->productId === null) !== []) {
            return $this->wait(RoutingAction::ManualReview, 'unmapped_lines', 'A line is not linked to a product of ours.', $context, $inputs);
        }

        $rule = $this->matcher->firstMatch($context, $sellerId);
        if ($rule === null) {
            return $this->fromAccountDefault($context, $inputs, $lines);
        }

        return $this->fromRule($rule, $context, $inputs);
    }

    /**
     * @param array<string, mixed> $inputs
     * @param list<RoutingLine> $lines
     */
    private function fromAccountDefault(RoutingContext $context, array $inputs, array $lines): RoutingDecision
    {
        if ($context->accountPolicy === ExecutionPolicy::ExternallyManaged) {
            return new RoutingDecision(RoutingAction::MonitorOnly, null, 'account_default_externally_managed', 'No routing rule matches, and this account\'s default is that someone else ships its orders: the order is only followed.', inputs: $inputs, lineDecisions: $this->uniform($lines, RoutingAction::MonitorOnly, null));
        }

        return $this->wait(RoutingAction::ManualReview, 'no_rule_matched', 'No routing rule matches this order, so nothing is done until a person decides.', $context, $inputs);
    }

    /**
     * Decides, records (only if the decision is new), and applies it to the order. An order already dispatched or linked keeps the decision it has.
     */
    public function route(ExternalOrderMapping $mapping, string $decidedBy = 'system', ?int $adminId = null): ?FulfillmentRoutingDecision
    {
        return DB::transaction(function () use ($mapping, $decidedBy, $adminId): ?FulfillmentRoutingDecision {
            $locked = ExternalOrderMapping::whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            $current = FulfillmentRoutingDecision::where('external_order_mapping_id', $locked->id)->where('is_current', true)->first();
            $context = $this->contexts->for($locked);

            if ($context->alreadyDispatched) {
                return $current;
            }

            $decision = $this->decide($context, (int) $locked->seller_id);
            if ($current !== null && $current->applied && $decidedBy === 'system' && $this->settled($locked)) {
                return $current;
            }
            if ($current !== null && $this->same($current, $decision)) {
                return $current;
            }

            $stored = $this->store($locked, $decision, $current, $decidedBy, $adminId);
            $this->apply($locked, $decision, $stored);

            return $stored;
        });
    }

    private function fromRule(FulfillmentRoutingRule $rule, RoutingContext $context, array $inputs): RoutingDecision
    {
        $action = $rule->routingAction() ?? RoutingAction::ManualReview;
        $snapshot = $rule->only(['id', 'name', 'priority', 'action', 'target_provider', 'target_location_id', 'sales_channel', 'account_id', 'sku', 'product_id']);
        $lines = $context->activeLines();

        return match ($action) {
            RoutingAction::RouteToProvider => $this->routeToProvider($rule, $context, $inputs, $snapshot, $lines),
            RoutingAction::ManualReview => new RoutingDecision($action, null, 'rule_requires_manual_review', "Rule '{$rule->name}' sends this order to manual review.", $rule->id, $snapshot, $this->uniform($lines, $action, null), $inputs),
            RoutingAction::Hold => new RoutingDecision($action, null, 'rule_holds_order', "Rule '{$rule->name}' holds this order.", $rule->id, $snapshot, $this->uniform($lines, $action, null), $inputs),
            default => new RoutingDecision($action, $rule->target_provider, 'rule_' . $action->value, "Rule '{$rule->name}': " . $action->value . '.', $rule->id, $snapshot, $this->uniform($lines, $action, $rule->target_provider), $inputs),
        };
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $snapshot
     * @param list<RoutingLine> $lines
     */
    private function routeToProvider(FulfillmentRoutingRule $rule, RoutingContext $context, array $inputs, array $snapshot, array $lines): RoutingDecision
    {
        $perLine = [];
        foreach ($lines as $line) {
            $provider = $rule->target_provider ?? $line->provider;
            if ($provider === null) {
                return new RoutingDecision(RoutingAction::ManualReview, null, 'line_has_no_stock_location', "Rule '{$rule->name}' routes to a provider, but line {$line->externalLineId} has no stock location to say which.", $rule->id, $snapshot, [], $inputs + ['wanted_action' => $rule->action]);
            }
            if ($line->provider !== null && $line->provider !== $provider) {
                return new RoutingDecision(RoutingAction::ManualReview, null, 'stock_not_at_target_provider', "Rule '{$rule->name}' targets {$provider}, but line {$line->externalLineId} is stocked at {$line->provider}.", $rule->id, $snapshot, [], $inputs + ['wanted_action' => $rule->action, 'wanted_provider' => $provider]);
            }
            $perLine[] = $this->lineDecision($line, RoutingAction::RouteToProvider, $provider);
        }
        $providers = array_values(array_unique(array_column($perLine, 'provider')));

        if (!config('sales_channels.managed_dispatch_enabled', false)) {
            return new RoutingDecision(RoutingAction::ManualReview, null, 'managed_dispatch_not_available', "Rule '{$rule->name}' wants this order created at " . implode(', ', $providers) . ', but creating provider shipments for channel orders is not available in this version; a person ships it, or links the shipment.', $rule->id, $snapshot, $perLine, $inputs + ['wanted_action' => $rule->action, 'wanted_provider' => count($providers) === 1 ? $providers[0] : null]);
        }

        return new RoutingDecision(RoutingAction::RouteToProvider, count($providers) === 1 ? $providers[0] : null, 'rule_route_to_provider', "Rule '{$rule->name}' routes this order to " . implode(' and ', $providers) . '.', $rule->id, $snapshot, $perLine, $inputs);
    }

    /** @param array<string, mixed> $inputs */
    private function wait(RoutingAction $action, string $code, string $reason, RoutingContext $context, array $inputs): RoutingDecision
    {
        return new RoutingDecision($action, null, $code, $reason, null, null, $this->uniform($context->activeLines(), $action, null), $inputs);
    }

    /**
     * @param list<RoutingLine> $lines
     * @return list<array<string, mixed>>
     */
    private function uniform(array $lines, RoutingAction $action, ?string $provider): array
    {
        return array_map(fn (RoutingLine $line): array => $this->lineDecision($line, $action, $provider), $lines);
    }

    /** @return array<string, mixed> */
    private function lineDecision(RoutingLine $line, RoutingAction $action, ?string $provider): array
    {
        return ['line' => $line->externalLineId, 'line_id' => $line->lineId, 'product_id' => $line->productId, 'quantity' => $line->quantity, 'action' => $action->value, 'provider' => $provider, 'location_id' => $line->locationId, 'stock_confirmed' => $line->stockConfirmed];
    }

    private function same(FulfillmentRoutingDecision $stored, RoutingDecision $decision): bool
    {
        return RoutingDecision::fingerprintOf(action: $stored->action, provider: $stored->provider, reasonCode: $stored->reason_code, ruleId: $stored->rule_id, lineDecisions: (array) $stored->line_decisions) === $decision->fingerprint();
    }

    /** Once an order's follow-up has begun (a shipment found and linked, or it was shipped), a changed rule or stock figure no longer re-decides it. */
    private function settled(ExternalOrderMapping $mapping): bool
    {
        return in_array($mapping->orderStatus(), [ChannelOrderStatus::Shipped, ChannelOrderStatus::Delivered], true);
    }

    private function store(ExternalOrderMapping $mapping, RoutingDecision $decision, ?FulfillmentRoutingDecision $current, string $decidedBy, ?int $adminId): FulfillmentRoutingDecision
    {
        $version = ($current?->version ?? 0) + 1;
        $current?->forceFill(['is_current' => false])->save();

        try {
            return FulfillmentRoutingDecision::create([
                'external_order_mapping_id' => $mapping->id,
                'order_id' => $mapping->order_id,
                'sales_channel' => $mapping->sales_channel,
                'account_id' => $mapping->account_id,
                'fulfillment_mode' => $mapping->fulfillment_mode,
                'action' => $decision->action->value,
                'execution_mode' => $decision->action->executionPolicy()->value,
                'provider' => $decision->provider,
                'rule_id' => $decision->ruleId,
                'rule_snapshot' => $decision->ruleSnapshot,
                'reason_code' => $decision->reasonCode,
                'reason' => mb_substr($decision->reason, 0, 500),
                'inputs' => $decision->inputs,
                'line_decisions' => $decision->lineDecisions,
                'version' => $version,
                'is_current' => true,
                'applied' => false,
                'decided_by' => $decidedBy,
                'decided_by_id' => $adminId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker decided the same order at the same moment: its decision stands.
            return FulfillmentRoutingDecision::where('external_order_mapping_id', $mapping->id)->where('is_current', true)->firstOrFail();
        }
    }

    /** Puts the decision on the order's record: its policy, provider, the reason a person is needed, and where each line's goods are to come from. */
    private function apply(ExternalOrderMapping $mapping, RoutingDecision $decision, FulfillmentRoutingDecision $stored): void
    {
        $waiting = in_array($decision->action, [RoutingAction::ManualReview, RoutingAction::Hold], true);
        $heldReason = $mapping->review_reason;
        $ourOwn = $heldReason === null || in_array($heldReason, self::OWN_REASONS, true);
        // A reason someone else raised (the order changed after routing, a package was split) outlives any decision of ours; a reason of ours is replaced or cleared.
        $review = $waiting ? ($ourOwn ? $decision->reasonCode : $heldReason) : ($ourOwn ? null : $heldReason);

        $mapping->forceFill([
            'execution_policy' => $decision->action->executionPolicy()->value,
            'provider' => $decision->provider ?? $mapping->provider,
            'review_reason' => $review,
            'shipment_link_state' => match ($decision->action) {
                RoutingAction::MonitorOnly => $mapping->shipment_link_state ?? ExternalOrderMapping::LINK_UNLINKED,
                RoutingAction::ChannelFulfills => ExternalOrderMapping::LINK_NOT_APPLICABLE,
                default => $mapping->shipment_link_state,
            },
        ])->save();

        foreach ($decision->lineDecisions as $lineDecision) {
            if (($lineDecision['provider'] ?? null) !== null) {
                ExternalOrderLine::whereKey($lineDecision['line_id'])->update(['provider' => $lineDecision['provider']]);
            }
        }
        $stored->forceFill(['applied' => true, 'applied_at' => now(), 'apply_result' => $decision->action->executionPolicy()->value])->save();
    }
}
