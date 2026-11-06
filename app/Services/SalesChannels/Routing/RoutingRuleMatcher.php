<?php

namespace App\Services\SalesChannels\Routing;

use App\Models\FulfillmentRoutingRule;
use App\Services\Fulfillment\Support\ArabicText;
use App\Services\OperationalCosts\Support\Money;

/**
 * Chooses the rule that decides an order: the active rules inside their validity window, lowest priority number first, the more specific rule first among equals, the older
 * rule first among those. A rule matches only when EVERY condition it sets holds; a condition it leaves empty matches anything.
 */
class RoutingRuleMatcher
{
    public function firstMatch(RoutingContext $context, int $sellerId = 0): ?FulfillmentRoutingRule
    {
        $rules = FulfillmentRoutingRule::where('is_active', true)->where('seller_id', $sellerId)
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
            ->get()
            ->sort(static fn (FulfillmentRoutingRule $a, FulfillmentRoutingRule $b): int => [$a->priority, $b->specificity(), $a->id] <=> [$b->priority, $a->specificity(), $b->id]);

        return $rules->first(fn (FulfillmentRoutingRule $rule): bool => $this->matches($rule, $context));
    }

    public function matches(FulfillmentRoutingRule $rule, RoutingContext $context): bool
    {
        $lines = $context->activeLines();
        $checks = [
            'sales_channel' => $rule->sales_channel === null || $rule->sales_channel === $context->channel,
            'account_id' => $rule->account_id === null || $rule->account_id === $context->accountId,
            'fulfillment_mode' => $rule->fulfillment_mode === null || $rule->fulfillment_mode === $context->mode->value,
            'execution_policy' => $rule->execution_policy === null || $rule->execution_policy === $context->currentExecutionPolicy,
            'product_id' => $rule->product_id === null || $this->anyLine($lines, static fn (RoutingLine $line): bool => $line->productId === $rule->product_id),
            'sku' => blank($rule->sku) || $this->anyLine($lines, static fn (RoutingLine $line): bool => $line->sku !== null && strcasecmp($line->sku, (string) $rule->sku) === 0),
            'inventory_location_id' => $rule->inventory_location_id === null || $this->anyLine($lines, static fn (RoutingLine $line): bool => $line->locationId === $rule->inventory_location_id),
            'product_provider' => blank($rule->product_provider) || $this->anyLine($lines, static fn (RoutingLine $line): bool => $line->provider === $rule->product_provider),
            'country' => blank($rule->country) || ($context->country !== null && strtoupper((string) $rule->country) === $context->country),
            'city' => blank($rule->city) || ($context->city !== null && ArabicText::normalize((string) $rule->city) === ArabicText::normalize($context->city)),
            'confirmed_stock' => !$rule->requires_confirmed_stock || ($lines !== [] && $this->everyLine($lines, static fn (RoutingLine $line): bool => $line->stockConfirmed)),
            'conditions' => $this->conditionsHold((array) ($rule->conditions ?? []), $context),
        ];

        return !in_array(false, $checks, true);
    }

    /** @param array<string, mixed> $conditions min_order_total, max_order_total, cod */
    private function conditionsHold(array $conditions, RoutingContext $context): bool
    {
        if (isset($conditions['cod']) && (bool) $conditions['cod'] !== $context->isCod) {
            return false;
        }
        if (isset($conditions['min_order_total']) || isset($conditions['max_order_total'])) {
            // A bound on the total cannot be checked against an order whose total is unknown: the rule does not match it.
            if ($context->orderTotal === null) {
                return false;
            }
            $total = Money::of($context->orderTotal);
            if (isset($conditions['min_order_total']) && $total->compareTo((string) $conditions['min_order_total']) < 0) {
                return false;
            }
            if (isset($conditions['max_order_total']) && $total->compareTo((string) $conditions['max_order_total']) > 0) {
                return false;
            }
        }

        return true;
    }

    /** @param list<RoutingLine> $lines */
    private function anyLine(array $lines, callable $test): bool
    {
        return array_filter($lines, $test) !== [];
    }

    /** @param list<RoutingLine> $lines */
    private function everyLine(array $lines, callable $test): bool
    {
        return count(array_filter($lines, $test)) === count($lines);
    }
}
