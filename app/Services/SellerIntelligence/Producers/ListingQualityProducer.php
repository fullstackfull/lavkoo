<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\Product;
use App\Models\ProductModerationEvent;
use App\Services\Marketplace\ProductModerationService;
use App\Services\SellerIntelligence\InsightDraft;
use App\Models\SellerInsight;
use App\Services\Platform\Policy;
use App\Services\SellerCenter\Copy;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\Schema;

/**
 * Listings that are costing the seller attention they have already earned.
 *
 * Two separate problems, deliberately kept apart:
 *
 * A rejected product is not a quality suggestion — it is a listing the marketplace has refused, with
 * a reason the seller has never been shown anywhere in the app. That is critical and comes first.
 *
 * Everything else is a quality score: what is missing from a listing, weighted by how much each
 * omission actually costs a shopper deciding whether to buy. The score is computed from the record,
 * never estimated, and only listings that fall below the bar are raised — a seller with good
 * listings should hear nothing.
 */
class ListingQualityProducer implements InsightProducer
{
    public const TYPE = 'LISTING_QUALITY';

    private const REQUEST_STATUS_DENIED = 2;

    /**
     * What a missing field costs, out of 100. Ordered by what a shopper actually needs to decide,
     * and limited to fields this schema really has — a score that penalised a column the products
     * table does not carry would mark every listing down for something no seller could fix.
     */
    private const WEIGHTS = [
        'images' => 25,
        'description' => 20,
        'thumbnail' => 15,
        'details' => 13,
        'brand' => 12,
        'unit' => 8,
        'meta' => 7,
    ];

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('products')) {
            return [];
        }

        // The bar a marketplace raises as its catalogue matures, rather than a constant it deploys.
        $qualityBar = app(Policy::class)->int('catalog_quality_bar');

        $products = Product::query()
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ->limit(500)
            ->get(['id', 'name', 'request_status', 'images', 'thumbnail', 'details', 'brand_id',
                   'unit', 'meta_title', 'meta_description']);

        $rejections = $this->latestRejections($products->pluck('id')->all());

        foreach ($products as $product) {
            if ((int) $product->request_status === self::REQUEST_STATUS_DENIED) {
                $rejection = $rejections[$product->id] ?? null;

                yield new InsightDraft(
                    sellerId: $sellerId,
                    type: self::TYPE,
                    severity: 'critical',
                    title: 'insight_product_rejected',
                    // The moderator's own words where there are any: a rejection with no stated
                    // reason is a dead end, which is the whole complaint this producer answers.
                    body: Copy::line('insight_body_product_rejected', [
                        'product' => $product->getRawOriginal('name'),
                        'reason' => $rejection['note'] ?? translate('no_reason_was_recorded'),
                    ]),
                    entityType: 'product',
                    entityId: $product->id,
                    actionKey: 'open_product',
                    actionParams: [
                        'product_id' => $product->id,
                        // The moderator's own words and codes. The app has never shown these, so a
                        // rejected listing has been a dead end: refused, with no way to learn why.
                        'reason_codes' => $rejection['reason_codes'] ?? [],
                        'note' => $rejection['note'] ?? null,
                    ],
                    category: SellerInsight::CATEGORY_CATALOG,
                    signals: new ImpactSignals(
                        affectedCount: 1,
                        // Not a matter of degree. A refused listing earns nothing, for a shop with
                        // one product and for a shop with ten thousand, and the arithmetic would
                        // rank it near the bottom for the second one.
                        severityFloor: SellerInsight::SEVERITY_CRITICAL,
                    ),
                    metadata: ['reason_codes' => $rejection['reason_codes'] ?? [], 'note' => $rejection['note'] ?? null],
                );

                continue;
            }

            [$score, $missing] = $this->score($product);

            if ($score >= $qualityBar) {
                continue;
            }

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: $score < 40 ? 'high' : 'medium',
                title: 'insight_listing_incomplete',
                // Names the fields, so the seller knows what to fix before opening the editor.
                // Reported as distinct fields: `description` and `details` are two thresholds on
                // the same box, and telling a seller both is telling them the same thing twice.
                body: Copy::line('insight_body_listing_incomplete', [
                    'product' => $product->getRawOriginal('name'),
                    'score' => $score,
                    'missing' => implode(', ', $this->missingLabels($missing)),
                ]),
                entityType: 'product',
                entityId: $product->id,
                metric: $score,
                actionKey: 'open_product',
                actionParams: ['product_id' => $product->id, 'missing' => $missing, 'score' => $score],
                category: SellerInsight::CATEGORY_CATALOG,
                signals: new ImpactSignals(
                    affectedCount: 1,
                    // No revenue figure and no deadline: an incomplete listing costs sales that
                    // cannot be counted because they never happened. Reported as one signal rather
                    // than dressed up as several, and `confidence()` says so.
                    severityFloor: $score < 40 ? SellerInsight::SEVERITY_MEDIUM : null,
                ),
                metadata: ['score' => $score, 'missing' => $missing],
            );
        }
    }

    /**
     * The most recent refusal for each of these products, keyed by product id.
     *
     * One query rather than one per product: a seller with a batch of rejected listings would
     * otherwise cost a query each, on a page that runs on a schedule for every seller.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{reason_codes: array<int, string>, note: ?string}>
     */
    private function latestRejections(array $productIds): array
    {
        if ($productIds === [] || !Schema::hasTable('product_moderation_events')) {
            return [];
        }

        $events = ProductModerationEvent::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('action', [ProductModerationService::ACTION_REJECTED, ProductModerationService::ACTION_NEEDS_CHANGES])
            ->orderByDesc('id')
            ->get(['product_id', 'reason_codes', 'note']);

        $latest = [];
        foreach ($events as $event) {
            // Ordered newest first, so the first one seen for a product is the one that stands.
            if (isset($latest[$event->product_id])) {
                continue;
            }

            $codes = is_array($event->reason_codes)
                ? $event->reason_codes
                : (json_decode((string) $event->reason_codes, true) ?: []);

            $latest[$event->product_id] = ['reason_codes' => $codes, 'note' => $event->note];
        }

        return $latest;
    }

    /**
     * @return array{0: int, 1: array<int, string>}
     */
    private function score(Product $product): array
    {
        $missing = [];

        $checks = [
            'images' => $this->isEmptyList($product->images),
            'thumbnail' => empty($product->thumbnail),
            'description' => strlen(trim(strip_tags((string) $product->details))) < 120,
            'details' => strlen(trim(strip_tags((string) $product->details))) < 40,
            'brand' => empty($product->brand_id),
            'unit' => empty($product->unit),
            'meta' => empty($product->meta_title) || empty($product->meta_description),
        ];

        $score = 100;
        foreach ($checks as $key => $isMissing) {
            if ($isMissing) {
                $score -= self::WEIGHTS[$key];
                $missing[] = $key;
            }
        }

        return [max(0, $score), $missing];
    }

    /**
     * The field names a seller reads, deduplicated.
     *
     * @param  array<int, string>  $missing
     * @return array<int, string>
     */
    private function missingLabels(array $missing): array
    {
        $labels = array_map(
            static fn (string $field) => translate($field === 'details' ? 'description' : $field),
            $missing,
        );

        return array_values(array_unique($labels));
    }

    /** Images are stored as a JSON list; an empty list and a null column mean the same thing here. */
    private function isEmptyList(mixed $value): bool
    {
        if (empty($value)) {
            return true;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return !is_array($decoded) || $decoded === [];
    }
}
