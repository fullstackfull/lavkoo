<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\SellerInsight;
use App\Services\SellerCenter\Copy;
use App\Services\Marketplace\CategoryGovernanceService;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue rows that will cause a problem later.
 *
 * Both findings here are the kind nobody looks for because looking means comparing rows against each
 * other, which no product screen does.
 *
 * **Duplicate barcodes.** Two of a seller's own products claiming the same barcode is a scanning
 * failure waiting to happen — at the packing station, in a stock count, in any integration that
 * matches on it. Scoped to one seller: two different shops selling the same manufactured item
 * legitimately share a barcode, and flagging that would be flagging the barcode system working.
 *
 * **Missing required attributes.** The marketplace already declares, per category, which attributes
 * a product must carry — `CategoryGovernanceService` knows and `ProductModerationService` rejects on
 * it. A seller finds out at rejection. This tells them first, which is the same information moved
 * to where it is still cheap to act on.
 */
class CatalogIntegrityProducer implements InsightProducer
{
    public const TYPE = 'CATALOG_INTEGRITY';

    private const LIMIT = 200;

    public function __construct(private readonly CategoryGovernanceService $governance)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('products')) {
            return [];
        }

        yield from $this->duplicateBarcodes($sellerId);
        yield from $this->missingAttributes($sellerId);
    }

    /** @return iterable<InsightDraft> */
    private function duplicateBarcodes(int|string $sellerId): iterable
    {
        if (!Schema::hasColumn('products', 'barcode')) {
            return;
        }

        $duplicates = DB::table('products')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->selectRaw('barcode, COUNT(*) as uses')
            ->groupBy('barcode')
            ->havingRaw('COUNT(*) > 1')
            ->limit(self::LIMIT)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        yield new InsightDraft(
            sellerId: $sellerId,
            type: self::TYPE,
            severity: SellerInsight::SEVERITY_MEDIUM,
            title: 'insight_duplicate_barcodes',
            body: Copy::choice('insight_body_duplicate_barcodes_one', 'insight_body_duplicate_barcodes', $duplicates->count()),
            entityType: 'catalog_check',
            entityId: 'duplicate_barcode',
            metric: $duplicates->count(),
            actionKey: 'open_products',
            actionParams: ['barcodes' => $duplicates->pluck('barcode')->take(50)->all()],
            category: SellerInsight::CATEGORY_CATALOG,
            affectedCount: (int) $duplicates->sum('uses'),
            signals: new ImpactSignals(affectedCount: (int) $duplicates->sum('uses')),
            metadata: ['barcode_count' => $duplicates->count(), 'product_count' => (int) $duplicates->sum('uses')],
        );
    }

    /**
     * Products missing what their own category requires.
     *
     * Reads the governance service rather than a copy of its rules, so a marketplace that changes
     * what a category demands changes what this reports on the next sweep — with no second list of
     * requirements to fall out of step.
     *
     * @return iterable<InsightDraft>
     */
    private function missingAttributes(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('category_governance')) {
            return;
        }

        $products = DB::table('products')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ->whereNotNull('category_id')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'category_id', 'attributes', 'choice_options']);

        $incomplete = [];

        foreach ($products as $product) {
            $missing = $this->governance->missingRequiredAttributes(
                categoryId: $product->category_id,
                productAttributes: $this->attributesOf($product),
            );

            if ($missing !== []) {
                $incomplete[$product->id] = $missing;
            }
        }

        if ($incomplete === []) {
            return;
        }

        yield new InsightDraft(
            sellerId: $sellerId,
            type: self::TYPE,
            severity: SellerInsight::SEVERITY_MEDIUM,
            title: 'insight_missing_required_attributes',
            // Names the attribute where there is one, so the seller knows what to fix without
            // opening the product first — "Missing required attribute: SPF value", never "Error".
            body: Copy::choice('insight_body_missing_attributes_one', 'insight_body_missing_attributes', count($incomplete), [
                'attribute' => translate((string) (reset($incomplete)[0] ?? '')),
            ]),
            entityType: 'catalog_check',
            entityId: 'missing_attributes',
            metric: count($incomplete),
            actionKey: 'open_products',
            actionParams: ['product_ids' => array_slice(array_keys($incomplete), 0, 50)],
            category: SellerInsight::CATEGORY_CATALOG,
            affectedCount: count($incomplete),
            signals: new ImpactSignals(
                affectedCount: count($incomplete),
                // These get rejected on submission, so the finding is not advisory.
                severityFloor: SellerInsight::SEVERITY_MEDIUM,
            ),
            metadata: ['count' => count($incomplete), 'sample' => array_slice($incomplete, 0, 10, true)],
        );
    }

    /**
     * The attribute keys a product actually carries, from whichever column holds them.
     *
     * @return array<string, mixed>
     */
    private function attributesOf(object $product): array
    {
        foreach (['attributes', 'choice_options'] as $column) {
            $decoded = json_decode($product->{$column} ?? '', true);

            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        return [];
    }
}
