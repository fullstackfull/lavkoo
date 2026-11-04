<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\Product;
use App\Services\Marketplace\InventoryService;
use App\Services\SellerIntelligence\InsightDraft;
use App\Models\SellerInsight;
use App\Services\Marketplace\StockPolicy;
use App\Services\SellerCenter\Copy;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock about to cost the seller sales.
 *
 * Ranked by what it is worth, not by how low it is: a best-seller at three units is a bigger problem
 * than a product nobody buys at zero, and a seller with fifty low-stock lines needs to know which
 * one to restock first. "Worth" here is real — units actually sold in the last thirty days, from
 * delivered order details.
 */
class InventoryRiskProducer implements InsightProducer
{
    public const TYPE = 'INVENTORY_RISK';

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('products') || !Schema::hasTable('order_details')) {
            return [];
        }

        // The window and the threshold are the marketplace's, and they are the same ones the
        // inventory screen this finding links to measures with — a briefing that disagreed with the
        // screen it sent the seller to taught them to distrust both.
        $stockPolicy = app(StockPolicy::class);
        $lookbackDays = $stockPolicy->velocityDays();
        $raiseUnderDays = $stockPolicy->coverBands()['raise'];

        $stockLimit = app(InventoryService::class)->stockLimitFor($sellerId);
        $sales = $this->recentSales($sellerId, $lookbackDays);

        $products = Product::query()
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'product_type' => 'physical'])
            ->where('current_stock', '<=', max($stockLimit, 0))
            ->get(['id', 'name', 'current_stock', 'unit_price']);

        foreach ($products as $product) {
            $sold = (float) ($sales[$product->id] ?? 0);
            $stock = (int) $product->current_stock;

            // A product that has not sold inside the window is not urgent, however little is left.
            if ($sold <= 0 && $stock > 0) {
                continue;
            }

            $perDay = $sold / $lookbackDays;
            $daysLeft = $perDay > 0 ? $stock / $perDay : null;

            if ($daysLeft !== null && $daysLeft > $raiseUnderDays) {
                continue;
            }

            // One window's sales at the current rate, which is what a stockout actually costs.
            $revenueAtRisk = round($sold * (float) $product->unit_price, 2);

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                // The declared severity is now a floor rather than the answer: the engine ranks this
                // against the seller's own turnover, which is what makes a best-seller's stockout a
                // different event from a rare item's.
                severity: $this->severityFor($stock, $sold),
                title: $stock <= 0 ? 'insight_out_of_stock' : 'insight_running_out',
                body: $stock <= 0
                    ? Copy::line('insight_body_out_of_stock', [
                        'product' => $product->getRawOriginal('name'),
                        'sold' => $sold,
                        'days' => $lookbackDays,
                    ])
                    : Copy::line('insight_body_running_out', [
                        'product' => $product->getRawOriginal('name'),
                        'stock' => $stock,
                        'cover' => $daysLeft === null ? '—' : round($daysLeft, 1),
                    ]),
                entityType: 'product',
                entityId: $product->id,
                metric: $stock,
                impact: $revenueAtRisk,
                actionKey: 'open_product',
                actionParams: ['product_id' => $product->id, 'days_of_supply' => $daysLeft === null ? null : round($daysLeft, 1)],
                category: SellerInsight::CATEGORY_INVENTORY,
                // Days of supply is a deadline: a product with two days left has two days.
                dueAt: $daysLeft === null ? null : now()->addHours(max(0, (int) round($daysLeft * 24))),
                signals: new ImpactSignals(
                    revenueAtRisk: $revenueAtRisk,
                    affectedCount: 1,
                    hoursUntilDue: $daysLeft === null ? null : round($daysLeft * 24, 2),
                    severityFloor: $stock <= 0 ? SellerInsight::SEVERITY_HIGH : null,
                ),
                metadata: [
                    'units_sold_30d' => $sold,
                    'stock' => $stock,
                    'days_of_supply' => $daysLeft === null ? null : round($daysLeft, 1),
                ],
            );
        }
    }

    /** Units of each product actually delivered in the lookback window. */
    private function recentSales(int|string $sellerId, int $lookbackDays): array
    {
        return DB::table('order_details')
            ->join('products', 'products.id', '=', 'order_details.product_id')
            ->where(['products.added_by' => 'seller', 'products.user_id' => $sellerId])
            ->where('order_details.delivery_status', 'delivered')
            ->where('order_details.created_at', '>=', now()->subDays($lookbackDays))
            ->groupBy('order_details.product_id')
            // Aliased, then plucked by that alias: pluck reads a property off the result row, so a
            // raw expression as the column name finds nothing.
            ->selectRaw('order_details.product_id as product_id, SUM(order_details.qty) as units')
            ->pluck('units', 'product_id')
            ->all();
    }

    private function severityFor(int $stock, float $sold): string
    {
        if ($stock <= 0 && $sold > 0) {
            // Out of stock and selling: every day is a lost sale.
            return 'critical';
        }

        return $stock <= 0 ? 'medium' : 'high';
    }
}
