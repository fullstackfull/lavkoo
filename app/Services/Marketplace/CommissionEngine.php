<?php

namespace App\Services\Marketplace;

use App\Models\CommissionRule;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves which commission applies to a line, and what it comes to (Phase 3, Stage B).
 *
 * The engine is deliberately pure: it takes a description of a line and returns numbers plus the
 * rule that produced them. It writes nothing and reads no request state, so the same inputs always
 * give the same output — which is what "financial calculations must be deterministic" requires, and
 * what makes it testable without an order.
 *
 * ## Priority
 *
 *     product (400) > vendor (300) > category (200) > global (100)
 *
 * Ties are broken by the most recently created rule, so two rules can never both "win" and the
 * choice is never arbitrary. A rule may carry an explicit priority to float a campaign above a
 * product override, which is why the number is stored rather than derived at read time.
 *
 * ## Compatibility
 *
 * With **no rules configured at all**, this falls back to exactly what the platform does today —
 * `sellers.sales_commission_percentage`, else the global `sales_commission` setting — so installing
 * the migration and doing nothing else changes no figure. That is deliberate: the engine has to be
 * adoptable one rule at a time, not all at once.
 *
 * Admin-owned (in-house) lines carry no commission, matching `Helpers::seller_sales_commission()`,
 * which returns 0 unless `seller_is === 'seller'`. The marketplace does not pay itself.
 */
class CommissionEngine
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_VENDOR = 'vendor';
    public const SCOPE_PRODUCT = 'product';

    public const RATE_PERCENTAGE = 'percentage';
    public const RATE_FIXED = 'fixed';
    public const RATE_PERCENTAGE_PLUS_FIXED = 'percentage_plus_fixed';

    /** Higher wins. Stored on the rule so an operator can deliberately deviate. */
    public const DEFAULT_PRIORITY = [
        self::SCOPE_PRODUCT => 400,
        self::SCOPE_VENDOR => 300,
        self::SCOPE_CATEGORY => 200,
        self::SCOPE_GLOBAL => 100,
    ];

    /**
     * @param array{product_id?: int|null, category_id?: int|null, seller_id?: int|null, seller_is?: string} $line
     * @param float $commissionableAmount line total after product discount, before commission
     *
     * @return array{
     *     rule_id: int|null, rule_scope_type: string|null, rule_label: string|null,
     *     rate_type: string, percentage: float, fixed_amount: float,
     *     commissionable_amount: float, commission_amount: float, seller_net_amount: float
     * }
     */
    public function calculate(array $line, float $commissionableAmount): array
    {
        $commissionableAmount = round(max(0, $commissionableAmount), 4);

        // The marketplace does not charge itself for its own stock.
        if (($line['seller_is'] ?? 'admin') !== 'seller') {
            return $this->result(null, self::RATE_PERCENTAGE, 0, 0, $commissionableAmount, 0);
        }

        $rule = $this->resolveRule($line);

        if (!$rule) {
            return $this->legacyFallback($line, $commissionableAmount);
        }

        $commission = $this->applyRate(
            rateType: $rule->rate_type,
            percentage: (float) $rule->percentage,
            fixedAmount: (float) $rule->fixed_amount,
            base: $commissionableAmount,
        );

        // Bounds are guard rails against a misconfigured rule, not a pricing feature. 0 = unbounded.
        if ((float) $rule->min_amount > 0) {
            $commission = max($commission, (float) $rule->min_amount);
        }
        if ((float) $rule->max_amount > 0) {
            $commission = min($commission, (float) $rule->max_amount);
        }

        // Commission can never exceed the line, or the seller owes the marketplace money for having
        // made a sale — which is never the intent of a misconfigured rate.
        $commission = min($commission, $commissionableAmount);

        return $this->result($rule, $rule->rate_type, (float) $rule->percentage, (float) $rule->fixed_amount, $commissionableAmount, $commission);
    }

    /**
     * The single winning rule for a line, or null when nothing is configured.
     */
    public function resolveRule(array $line): ?CommissionRule
    {
        if (!Schema::hasTable('commission_rules')) {
            return null;
        }

        $candidates = [
            [self::SCOPE_PRODUCT, $line['product_id'] ?? null],
            [self::SCOPE_VENDOR, $line['seller_id'] ?? null],
            [self::SCOPE_CATEGORY, $line['category_id'] ?? null],
            [self::SCOPE_GLOBAL, null],
        ];

        $matches = collect();
        foreach ($candidates as [$scopeType, $scopeId]) {
            if ($scopeType !== self::SCOPE_GLOBAL && !$scopeId) {
                continue;
            }

            $rule = CommissionRule::query()
                ->active()
                ->where('scope_type', $scopeType)
                ->when($scopeType === self::SCOPE_GLOBAL, fn ($q) => $q->whereNull('scope_id'))
                ->when($scopeType !== self::SCOPE_GLOBAL, fn ($q) => $q->where('scope_id', $scopeId))
                ->orderByDesc('priority')
                ->orderByDesc('id')
                ->first();

            if ($rule) {
                $matches->push($rule);
            }
        }

        // Sorting the matches rather than returning the first candidate is what lets an explicit
        // priority override the scope order — a campaign rule can outrank a product one.
        return $matches
            ->sortByDesc(fn (CommissionRule $rule) => [(int) $rule->priority, (int) $rule->id])
            ->first();
    }

    private function applyRate(string $rateType, float $percentage, float $fixedAmount, float $base): float
    {
        $commission = match ($rateType) {
            self::RATE_FIXED => $fixedAmount,
            self::RATE_PERCENTAGE_PLUS_FIXED => ($base * $percentage / 100) + $fixedAmount,
            default => $base * $percentage / 100,
        };

        return round(max(0, $commission), 4);
    }

    /**
     * Exactly what Helpers::seller_sales_commission() does, so an install with no rules configured
     * sees no change in any figure.
     */
    private function legacyFallback(array $line, float $commissionableAmount): array
    {
        $percentage = null;

        if (!empty($line['seller_id']) && Schema::hasTable('sellers')) {
            $percentage = \App\Models\Seller::where('id', $line['seller_id'])->value('sales_commission_percentage');
        }

        if ($percentage === null) {
            try {
                $percentage = getWebConfig(name: 'sales_commission');
            } catch (\Throwable) {
                $percentage = 0;
            }
        }

        $percentage = (float) ($percentage ?? 0);
        $commission = min(round($commissionableAmount * $percentage / 100, 4), $commissionableAmount);

        return $this->result(null, self::RATE_PERCENTAGE, $percentage, 0, $commissionableAmount, $commission, label: 'legacy_seller_percentage');
    }

    private function result(
        ?CommissionRule $rule,
        string $rateType,
        float $percentage,
        float $fixedAmount,
        float $commissionableAmount,
        float $commission,
        ?string $label = null,
    ): array {
        return [
            'rule_id' => $rule?->id,
            'rule_scope_type' => $rule?->scope_type,
            'rule_label' => $rule?->label ?? $label,
            'rate_type' => $rateType,
            'percentage' => round($percentage, 4),
            'fixed_amount' => round($fixedAmount, 4),
            'commissionable_amount' => round($commissionableAmount, 4),
            'commission_amount' => round($commission, 4),
            'seller_net_amount' => round($commissionableAmount - $commission, 4),
        ];
    }
}
