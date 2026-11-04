<?php

namespace App\Services\Marketplace;

use App\Models\SellerVerificationDocument;
use App\Services\Platform\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller performance scorecard (Phase 3, Stage A).
 *
 * The platform reports on seller *earnings* and *tax*, but nowhere on seller *quality*: fulfilment,
 * cancellations, returns, refunds, rating, moderation strikes. This computes those from the data the
 * rest of the phase already produces — orders, refund requests, reviews, the product-moderation
 * trail, the vendor ledger, and the KYC standing — and derives a health tier from them.
 *
 * Every number here maps to a real column (measured, not invented): order_status is the platform's
 * own vocabulary (delivered / canceled / returned / failed / …); a product's seller is
 * products.user_id; a review is active at status 1; a refunded line is order_details.refund_request.
 * Missing tables degrade to zero rather than fataling, so this is safe on a partial install.
 *
 * Connected, not a vanity page: the tier is a value other stages (SLA, disputes, featured placement)
 * can consume, and `deriveTier()` is a pure function so those callers get the same answer this screen
 * shows.
 */
class SellerScorecardService
{
    public const TIER_NEW = 'new';        // not enough activity to judge — never labelled good or bad
    public const TIER_GOOD = 'good';
    public const TIER_WATCH = 'watch';
    public const TIER_AT_RISK = 'at_risk';

    /** Order statuses that count as fulfilled / cancelled / returned — the platform's real vocabulary. */
    private const STATUS_DELIVERED = 'delivered';
    private const STATUS_CANCELED = 'canceled';
    private const STATUS_RETURNED = 'returned';
    private const STATUS_FAILED = 'failed';

    public function __construct(private readonly ?SellerVerificationService $verification = null)
    {
    }

    /**
     * The full scorecard for one seller.
     *
     * @return array<string, mixed>
     */
    public function scorecard(int|string $sellerId): array
    {
        $orders = $this->orderCounts($sellerId);
        $reviews = $this->reviewStats($sellerId);
        $refunds = $this->refundStats($sellerId);
        $strikes = $this->moderationStrikes($sellerId);

        $total = (int) $orders['total'];
        $rate = fn (int $n) => $total > 0 ? round($n / $total, 4) : 0.0;

        $metrics = [
            'seller_id' => (int) $sellerId,
            'orders_total' => $total,
            'delivered' => (int) $orders['delivered'],
            'canceled' => (int) $orders['canceled'],
            'returned' => (int) $orders['returned'],
            'failed' => (int) $orders['failed'],
            'fulfillment_rate' => $rate((int) $orders['delivered']),
            'cancellation_rate' => $rate((int) $orders['canceled']),
            'return_rate' => $rate((int) $orders['returned']),
            'items_total' => (int) $refunds['items_total'],
            'items_refunded' => (int) $refunds['items_refunded'],
            'refund_rate' => $refunds['items_total'] > 0 ? round($refunds['items_refunded'] / $refunds['items_total'], 4) : 0.0,
            'avg_rating' => $reviews['avg'],
            'review_count' => (int) $reviews['count'],
            'moderation_strikes' => (int) $strikes,
            'kyc_status' => $this->kycStatus($sellerId),
        ];

        $metrics['tier'] = $this->deriveTier($metrics);

        return $metrics;
    }

    /**
     * Derive the health tier from a metrics array — a pure function so every consumer agrees.
     *
     * A seller with no orders and no reviews is 'new' (insufficient data), never 'good' or 'at_risk':
     * judging a seller who has not traded yet would be noise, not signal.
     */
    public function deriveTier(array $m): string
    {
        $orders = (int) ($m['orders_total'] ?? 0);
        $reviewCount = (int) ($m['review_count'] ?? 0);

        if ($orders === 0 && $reviewCount === 0 && (int) ($m['moderation_strikes'] ?? 0) === 0) {
            return self::TIER_NEW;
        }

        $cancel = (float) ($m['cancellation_rate'] ?? 0);
        $return = (float) ($m['return_rate'] ?? 0);
        $refund = (float) ($m['refund_rate'] ?? 0);
        $rating = $m['avg_rating'] !== null ? (float) $m['avg_rating'] : null;
        $strikes = (int) ($m['moderation_strikes'] ?? 0);
        $ratedEnough = $reviewCount >= 5; // one bad review should not sink a seller

        // The bands the marketplace set. They were fixed here while the SLA thresholds beside them
        // were editable, so an operator could raise the return ceiling to 10% and still watch every
        // seller who passed 5% — two policies about the same number, one of them invisible.
        $policy = app(Policy::class);

        // Written out rather than built by concatenation: a settings key assembled from pieces is
        // one nothing can find, and the whole point of the registry is that every rule is locatable.
        $bands = [
            self::TIER_AT_RISK => [
                'cancellation' => 'health_at_risk_cancellation_rate',
                'return' => 'health_at_risk_return_rate',
                'refund' => 'health_at_risk_refund_rate',
                'rating' => 'health_at_risk_rating',
                'strikes' => 'health_at_risk_strikes',
            ],
            self::TIER_WATCH => [
                'cancellation' => 'health_watch_cancellation_rate',
                'return' => 'health_watch_return_rate',
                'refund' => 'health_watch_refund_rate',
                'rating' => 'health_watch_rating',
                'strikes' => 'health_watch_strikes',
            ],
        ];

        foreach ($bands as $tier => $keys) {
            $crossed = $cancel > $policy->float($keys['cancellation'])
                || $return > $policy->float($keys['return'])
                || $refund > $policy->float($keys['refund'])
                || $strikes >= $policy->int($keys['strikes'])
                || ($ratedEnough && $rating !== null && $rating < $policy->float($keys['rating']));

            if ($crossed) {
                return $tier;
            }
        }

        return self::TIER_GOOD;
    }

    // ---- the individual metrics, each from a measured column ----

    private function orderCounts(int|string $sellerId): array
    {
        $empty = ['total' => 0, 'delivered' => 0, 'canceled' => 0, 'returned' => 0, 'failed' => 0];
        if (!Schema::hasTable('orders')) {
            return $empty;
        }

        // Marketplace orders for this seller. seller_is='seller' excludes in-house/admin orders, which
        // are not a seller's performance.
        $base = DB::table('orders')->where('seller_id', $sellerId)->where('seller_is', 'seller');

        return [
            'total' => (clone $base)->count(),
            'delivered' => (clone $base)->where('order_status', self::STATUS_DELIVERED)->count(),
            'canceled' => (clone $base)->where('order_status', self::STATUS_CANCELED)->count(),
            'returned' => (clone $base)->where('order_status', self::STATUS_RETURNED)->count(),
            'failed' => (clone $base)->where('order_status', self::STATUS_FAILED)->count(),
        ];
    }

    private function refundStats(int|string $sellerId): array
    {
        if (!Schema::hasTable('order_details')) {
            return ['items_total' => 0, 'items_refunded' => 0];
        }

        $base = DB::table('order_details')->where('seller_id', $sellerId);

        return [
            'items_total' => (clone $base)->count(),
            // refund_request is 0 when none; any non-zero means a refund was requested on that line.
            'items_refunded' => (clone $base)->where('refund_request', '!=', 0)->count(),
        ];
    }

    private function reviewStats(int|string $sellerId): array
    {
        if (!Schema::hasTable('reviews') || !Schema::hasTable('products')) {
            return ['avg' => null, 'count' => 0];
        }

        // A review belongs to a seller through its product (products.user_id). Active reviews only.
        $q = DB::table('reviews')
            ->join('products', 'products.id', '=', 'reviews.product_id')
            ->where('products.user_id', $sellerId)
            ->where('reviews.status', 1);

        $count = (clone $q)->count();
        $avg = $count > 0 ? (clone $q)->avg('reviews.rating') : null;

        return ['avg' => $avg !== null ? round((float) $avg, 2) : null, 'count' => $count];
    }

    private function moderationStrikes(int|string $sellerId): int
    {
        if (!Schema::hasTable('product_moderation_events') || !Schema::hasTable('products')) {
            return 0;
        }

        // Rejections and suspensions on this seller's products — the negative moderation outcomes.
        return DB::table('product_moderation_events')
            ->join('products', 'products.id', '=', 'product_moderation_events.product_id')
            ->where('products.user_id', $sellerId)
            ->whereIn('product_moderation_events.action', ['rejected', 'suspended'])
            ->count();
    }

    private function kycStatus(int|string $sellerId): string
    {
        $service = $this->verification ?? app(SellerVerificationService::class);

        return $service->overallStatus($sellerId);
    }
}
