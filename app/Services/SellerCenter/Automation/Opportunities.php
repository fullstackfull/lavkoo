<?php

namespace App\Services\SellerCenter\Automation;

use App\Models\Product;
use App\Services\Marketplace\StockPolicy;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\SellerCenter\Copy;
use App\Services\SellerCenter\Shell;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Improvements a seller could make, as distinct from problems they have to fix (handoff 08 A5).
 *
 * Issues and opportunities are deliberately different screens with different treatments: an issue
 * is measured in severity and demands attention, an opportunity is measured in evidence and invites
 * it. Nothing here is ever painted with a severity colour.
 *
 * Two rules decide what appears.
 *
 * **Every card states the evidence it was derived from**, in the same sentence as the count — "7
 * products · from 14 days of views against orders". A card that says only "you could do better" is
 * an advertisement, not an opportunity.
 *
 * **A type with no data does not appear at all.** The conversion opportunity needs product views;
 * where the storefront has never recorded one, the card is absent rather than showing zero or a
 * guess. Nothing on this screen is estimated, extrapolated or filled in (PART 14).
 */
class Opportunities
{
    public function __construct(private readonly StockPolicy $stock)
    {
    }

    /** The window every opportunity is measured over — the same one the inventory screen uses. */
    public function windowDays(): int
    {
        return $this->stock->velocityDays();
    }

    /** How many products a card may name before it stops listing them. */
    private const SAMPLE = 20;

    /**
     * @return array<int, array{key: string, title: string, count: int, evidence: string, action: ?array{label: string, href: ?string}}>
     */
    public function for(int $sellerId): array
    {
        $from = Carbon::now()->subDays($this->windowDays());

        return array_values(array_filter([
            $this->stockRiskOnFastSellers($sellerId, $from),
            $this->highTrafficLowConversion($sellerId, $from),
            $this->pricedBelowCategoryMedian($sellerId),
        ]));
    }

    public function count(int $sellerId): int
    {
        return count($this->for($sellerId));
    }

    /**
     * Products selling fast enough to run out inside a fortnight.
     *
     * Cover, not stock: forty units is comfortable for one product and a week's supply for another,
     * and the number that matters is how long it lasts at the rate it is actually selling.
     */
    private function stockRiskOnFastSellers(int $sellerId, Carbon $from): ?array
    {
        if (!Schema::hasTable('order_details') || !Schema::hasTable('products')) {
            return null;
        }

        $sold = DB::table('order_details')
            ->where('seller_id', $sellerId)
            ->where('created_at', '>=', $from)
            ->whereIn('delivery_status', ['delivered', 'processing', 'out_for_delivery', 'pending'])
            ->groupBy('product_id')
            ->select('product_id', DB::raw('SUM(qty) sold'))
            ->having('sold', '>', 0)
            ->pluck('sold', 'product_id');

        if ($sold->isEmpty()) {
            return null;
        }

        $products = Product::withoutGlobalScope('translate')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'status' => 1])
            ->whereIn('id', $sold->keys())
            ->get(['id', 'name', 'current_stock']);

        $atRisk = $products->filter(function (Product $product) use ($sold) {
            $perDay = (float) $sold[$product->id] / $this->windowDays();

            return $perDay > 0 && ((float) $product->current_stock / $perDay) < $this->stock->coverBands()['opportunity'];
        });

        if ($atRisk->isEmpty()) {
            return null;
        }

        return [
            'key' => 'fast_sellers_at_stock_risk',
            'title' => translate('opportunity_fast_sellers_at_stock_risk'),
            'count' => $atRisk->count(),
            'evidence' => Copy::line('opportunity_stock_risk_evidence', [
                'days' => $this->windowDays(),
                'cover' => $this->stock->coverBands()['opportunity'],
            ]),
            'action' => [
                'label' => translate('review_stock'),
                'href' => Shell::route('seller.inventory.index', [
                    'ids' => implode(',', $atRisk->take(self::SAMPLE)->pluck('id')->all()),
                ]),
            ],
        ];
    }

    /**
     * Products people look at and do not buy.
     *
     * Absent entirely where the storefront has recorded no views for this shop — a conversion rate
     * with no denominator is not a low conversion rate, it is no measurement.
     */
    private function highTrafficLowConversion(int $sellerId, Carbon $from): ?array
    {
        if (!Schema::hasTable('analytics_events')) {
            return null;
        }

        $views = DB::table('analytics_events')
            ->where('vendor_id', $sellerId)
            ->where('name', AnalyticsEvent::PRODUCT_VIEWED)
            ->where('entity_type', 'product')
            ->where('occurred_at', '>=', $from)
            ->where('is_bot', false)
            ->where('is_internal', false)
            ->groupBy('entity_id')
            ->select('entity_id', DB::raw('COUNT(*) views'))
            ->pluck('views', 'entity_id');

        if ($views->isEmpty()) {
            return null;
        }

        // The median of what this shop actually gets, rather than a fixed "high traffic" number
        // that would mean something different for every shop.
        $median = $this->median($views->values()->map(fn ($value) => (float) $value)->all());

        $ordered = Schema::hasTable('order_details')
            ? DB::table('order_details')
                ->where('seller_id', $sellerId)
                ->where('created_at', '>=', $from)
                ->whereIn('product_id', $views->keys())
                ->groupBy('product_id')
                ->select('product_id', DB::raw('COUNT(DISTINCT order_id) orders'))
                ->pluck('orders', 'product_id')
            : collect();

        $productIds = $views->filter(function ($count, $productId) use ($median, $ordered) {
            return (float) $count >= $median && (int) ($ordered[$productId] ?? 0) === 0;
        })->keys()->all();

        if ($productIds === []) {
            return null;
        }

        return [
            'key' => 'high_traffic_low_conversion',
            'title' => translate('opportunity_high_traffic_low_conversion'),
            'count' => count($productIds),
            'evidence' => Copy::line('opportunity_conversion_evidence', ['days' => $this->windowDays()]),
            'action' => [
                'label' => translate('review_products'),
                'href' => Shell::route('seller.products.index', [
                    'ids' => implode(',', array_slice($productIds, 0, self::SAMPLE)),
                ]),
            ],
        ];
    }

    /**
     * Listings priced under the middle of their own category.
     *
     * Stated as a fact, not as advice: the seller knows why their price is what it is, and this
     * only tells them where it sits.
     */
    private function pricedBelowCategoryMedian(int $sellerId): ?array
    {
        if (!Schema::hasTable('products')) {
            return null;
        }

        $mine = Product::withoutGlobalScope('translate')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'status' => 1])
            ->where('unit_price', '>', 0)
            ->whereNotNull('category_id')
            ->get(['id', 'category_id', 'unit_price']);

        if ($mine->isEmpty()) {
            return null;
        }

        $below = [];

        foreach ($mine->groupBy('category_id') as $categoryId => $products) {
            $prices = Product::withoutGlobalScope('translate')
                ->where(['category_id' => $categoryId, 'status' => 1])
                ->where('unit_price', '>', 0)
                ->limit(500)
                ->pluck('unit_price')
                ->map(fn ($price) => (float) $price)
                ->all();

            // A category holding only this seller's own products has no market to compare against.
            if (count($prices) < 5) {
                continue;
            }

            $median = $this->median($prices);

            foreach ($products as $product) {
                if ((float) $product->unit_price < $median) {
                    $below[] = $product->id;
                }
            }
        }

        if ($below === []) {
            return null;
        }

        return [
            'key' => 'priced_below_category_median',
            'title' => translate('opportunity_priced_below_category_median'),
            'count' => count($below),
            'evidence' => translate('opportunity_price_evidence'),
            'action' => [
                'label' => translate('review_prices'),
                'href' => Shell::route('seller.products.index', ['ids' => implode(',', array_slice($below, 0, self::SAMPLE))]),
            ],
        ];
    }

    /** @param array<int, float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
