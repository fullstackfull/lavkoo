<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\ProductPriceChange;
use App\Models\SellerInsight;
use App\Services\Platform\Policy;
use App\Services\SellerCenter\Copy;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prices that are probably a mistake.
 *
 * Both findings became possible only once price changes started being recorded — before that, a
 * price that halved overnight left nothing behind to notice.
 *
 * **An extreme move.** A price that changed by more than half in one write is either a deliberate
 * clearance or a typo, and the platform cannot tell which. It says so in those terms rather than
 * asserting a mistake: this is a prompt to confirm, not an accusation. Deliberate moves are
 * confirmed once and stop being raised, because the detector only looks at recent changes.
 *
 * **Selling below cost.** Only where the seller recorded a purchase price. Where they did not, this
 * says nothing at all rather than guessing at a margin — a made-up cost would produce a made-up
 * loss, which is worse than silence.
 */
class PricingRiskProducer implements InsightProducer
{
    public const TYPE = 'PRICING_RISK';

    private const LIMIT = 100;

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        yield from $this->extremeChanges($sellerId);
        yield from $this->belowCost($sellerId);
    }

    /** @return iterable<InsightDraft> */
    private function extremeChanges(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('product_price_changes')) {
            return;
        }

        // What counts as extreme is the marketplace's call, not one ratio for every category: a
        // half-price move is a clearance on one shelf and a mispricing on the next.
        $policy = app(Policy::class);
        $extremeRatio = $policy->float('catalog_price_swing_ratio');
        $recentHours = $policy->int('catalog_price_swing_hours');

        $changes = ProductPriceChange::where('seller_id', $sellerId)
            ->whereNotNull('previous_price')
            ->where('previous_price', '>', 0)
            ->where('created_at', '>=', now()->subHours($recentHours))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        foreach ($changes as $change) {
            $ratio = abs($change->new_price - $change->previous_price) / $change->previous_price;

            if ($ratio < $extremeRatio) {
                continue;
            }

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: SellerInsight::SEVERITY_HIGH,
                title: $change->new_price < $change->previous_price
                    ? 'insight_price_dropped_sharply'
                    : 'insight_price_rose_sharply',
                // The number and the cause, in one sentence: what it was, what it is now, and who
                // moved it — which is most of the answer to "was this deliberate".
                body: Copy::line('insight_body_price_moved', [
                    'from' => $change->previous_price,
                    'to' => $change->new_price,
                    'percent' => round($ratio * 100, 1),
                    'source' => translate((string) $change->source),
                ]),
                entityType: 'product',
                entityId: $change->product_id,
                metric: round($ratio * 100, 1),
                actionKey: 'open_product',
                actionParams: [
                    'product_id' => $change->product_id,
                    'previous_price' => $change->previous_price,
                    'new_price' => $change->new_price,
                    // Who or what moved it, which is most of the answer to "was this deliberate".
                    'source' => $change->source,
                ],
                category: SellerInsight::CATEGORY_PRICING,
                // Stops being raised once the change is no longer recent: a price left standing has
                // been confirmed by the seller not changing it back.
                expiresAt: $change->created_at->copy()->addHours($recentHours),
                signals: new ImpactSignals(affectedCount: 1),
                metadata: [
                    'previous_price' => $change->previous_price,
                    'new_price' => $change->new_price,
                    'change_percent' => round($ratio * 100, 1),
                    'source' => $change->source,
                    'actor' => $change->actor_name,
                ],
            );
        }
    }

    /**
     * Products priced below what they cost, where a cost is on record.
     *
     * @return iterable<InsightDraft>
     */
    private function belowCost(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'purchase_price')) {
            return;
        }

        $products = DB::table('products')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            // Silence rather than a guess: without a recorded cost there is no margin to be wrong
            // about, and inventing one would invent the loss too.
            ->where('purchase_price', '>', 0)
            ->whereColumn('unit_price', '<', 'purchase_price')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'unit_price', 'purchase_price', 'current_stock']);

        if ($products->isEmpty()) {
            return;
        }

        // What selling the stock on hand at these prices would lose.
        $exposure = round($products->sum(
            fn (object $product) => ((float) $product->purchase_price - (float) $product->unit_price) * max(0, (int) $product->current_stock),
        ), 2);

        yield new InsightDraft(
            sellerId: $sellerId,
            type: self::TYPE,
            severity: SellerInsight::SEVERITY_HIGH,
            title: 'insight_price_below_cost',
            body: Copy::choice('insight_body_below_cost_one', 'insight_body_below_cost', $products->count(), [
                'value' => $exposure,
            ]),
            entityType: 'pricing_check',
            entityId: 'below_cost',
            metric: $products->count(),
            impact: $exposure,
            actionKey: 'open_products',
            actionParams: ['product_ids' => $products->pluck('id')->take(50)->all()],
            category: SellerInsight::CATEGORY_PRICING,
            affectedCount: $products->count(),
            signals: new ImpactSignals(
                revenueAtRisk: $exposure ?: null,
                affectedCount: $products->count(),
                // Every sale makes this worse rather than better, which no other finding here does.
                severityFloor: SellerInsight::SEVERITY_HIGH,
            ),
            metadata: ['count' => $products->count(), 'exposure' => $exposure],
        );
    }
}
