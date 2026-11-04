<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\SellerInsight;
use App\Services\Marketplace\StockPolicy;
use App\Services\SellerCenter\Copy;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock that is not going anywhere, and listings that contradict their own stock.
 *
 * Three findings that share one query over the catalogue, because running three sweeps over the same
 * table to ask three questions about each row is how a nightly job becomes a nightly outage.
 *
 * **Not moving.** Units sitting on a shelf are money the seller has already spent. This is the one
 * finding here that is an opportunity as much as a problem, and it is reported as an issue only
 * because the money is already committed — an opportunity is something you could do, this is
 * something you already did.
 *
 * **Live with nothing to sell.** A published product at zero stock takes a customer to a dead end
 * and spends the seller's search placement doing it.
 *
 * **Hidden with stock to sell.** The mirror image, and the more expensive of the two: paid-for units
 * nobody can buy because the listing is switched off.
 */
class StaleInventoryProducer implements InsightProducer
{
    public const TYPE = 'INVENTORY_STALE';

    private const LIMIT = 200;

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('products')) {
            return [];
        }

        // The marketplace's own judgement of dead capital, which the seller's equivalent automation
        // rule has always let them set for themselves.
        $stockPolicy = app(StockPolicy::class);
        $staleDays = $stockPolicy->staleDays();
        $minimumUnits = $stockPolicy->staleMinimumUnits();

        $products = DB::table('products')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'product_type' => 'physical'])
            ->limit(self::LIMIT * 5)
            ->get(['id', 'name', 'status', 'current_stock', 'unit_price', 'purchase_price']);

        if ($products->isEmpty()) {
            return [];
        }

        $soldRecently = $this->recentlySoldProductIds($sellerId, $staleDays);

        $stale = [];
        $liveWithoutStock = [];
        $hiddenWithStock = [];

        foreach ($products as $product) {
            $stock = (int) $product->current_stock;
            $isLive = (int) $product->status === 1;

            if ($isLive && $stock <= 0) {
                $liveWithoutStock[] = $product;
                continue;
            }

            if (!$isLive && $stock >= $minimumUnits) {
                $hiddenWithStock[] = $product;
                continue;
            }

            if ($stock >= $minimumUnits && !isset($soldRecently[$product->id])) {
                $stale[] = $product;
            }
        }

        yield from $this->draftFor($sellerId, 'insight_inventory_not_moving', 'not_moving', $stale, SellerInsight::SEVERITY_LOW, $staleDays);
        yield from $this->draftFor($sellerId, 'insight_listing_live_without_stock', 'live_without_stock', $liveWithoutStock, SellerInsight::SEVERITY_MEDIUM, $staleDays);
        yield from $this->draftFor($sellerId, 'insight_listing_hidden_with_stock', 'hidden_with_stock', $hiddenWithStock, SellerInsight::SEVERITY_MEDIUM, $staleDays);
    }

    /**
     * One issue per finding, carrying the count and what it is tying up.
     *
     * A seller with two hundred stale products does not need two hundred rows; they need one number
     * and a filtered list behind it. This is the management-by-exception rule the brief asks for,
     * applied at the point the issue is created rather than hidden in a screen later.
     *
     * @param  array<int, object>  $products
     * @return iterable<InsightDraft>
     */
    private function draftFor(int|string $sellerId, string $title, string $kind, array $products, string $severity, int $staleDays): iterable
    {
        if ($products === []) {
            return;
        }

        // What the units cost, where the seller recorded a cost. Falls back to nothing rather than
        // to the selling price, which would overstate committed money as though it were revenue.
        $tiedUp = round(array_sum(array_map(
            fn (object $product) => (float) ($product->purchase_price ?: 0) * (int) $product->current_stock,
            $products,
        )), 2);

        yield new InsightDraft(
            sellerId: $sellerId,
            type: self::TYPE,
            severity: $severity,
            title: $title,
            // The sentence with the number and the cause. A card whose body restates its own title
            // says nothing twice — and the capital clause is dropped rather than printed as a dash
            // when no cost was recorded, because "holding — in stock" is not a sentence.
            body: $tiedUp
                ? Copy::choice('insight_body_stale_inventory_one', 'insight_body_stale_inventory', count($products), [
                    'days' => $staleDays,
                    'value' => $tiedUp,
                ])
                : Copy::choice('insight_body_stale_inventory_one_no_cost', 'insight_body_stale_inventory_no_cost', count($products), [
                    'days' => $staleDays,
                ]),
            entityType: 'product_group',
            entityId: $kind,
            metric: count($products),
            impact: $tiedUp ?: null,
            actionKey: 'open_products',
            actionParams: [
                'product_ids' => array_slice(array_map(fn (object $product) => $product->id, $products), 0, 50),
                'kind' => $kind,
            ],
            category: SellerInsight::CATEGORY_INVENTORY,
            affectedCount: count($products),
            signals: new ImpactSignals(
                revenueAtRisk: $tiedUp ?: null,
                affectedCount: count($products),
            ),
            metadata: ['kind' => $kind, 'count' => count($products), 'capital_tied_up' => $tiedUp ?: null],
        );
    }

    /**
     * Which of this seller's products have sold at all recently.
     *
     * Delivered lines only. A product whose orders were all cancelled has not sold, and counting
     * them would keep dead stock out of this list forever.
     *
     * @return array<int, bool>
     */
    private function recentlySoldProductIds(int|string $sellerId, int $staleDays): array
    {
        if (!Schema::hasTable('order_details')) {
            return [];
        }

        return DB::table('order_details')
            ->where('seller_id', $sellerId)
            ->where('delivery_status', 'delivered')
            ->where('created_at', '>=', now()->subDays($staleDays))
            ->distinct()
            ->pluck('product_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }
}
