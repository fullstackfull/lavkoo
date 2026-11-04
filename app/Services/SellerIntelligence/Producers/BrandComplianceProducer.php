<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\SellerInsight;
use App\Services\SellerCenter\Copy;
use App\Services\Marketplace\BrandRegistryService;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use Illuminate\Support\Facades\Schema;

/**
 * Listings under a brand this seller is not entitled to sell.
 *
 * This is the half of the brand registry that does something before enforcement is switched on. A
 * gate that silently starts refusing listings is a bad way for a legitimate reseller to find out
 * they needed paperwork; a finding that says which of their listings will be affected, while they
 * can still do something about it, is a good one.
 *
 * It reports only what is real. A brand nobody has claimed produces nothing — the registry treats it
 * as open, and inventing a compliance problem for it would be exactly the fabricated finding the
 * brief rules out. What it reports is the concrete case: somebody else has proved ownership of this
 * name to a person, and these listings of yours carry it.
 *
 * The severity floor is deliberate. Selling under somebody else's brand is not a matter of degree
 * measured against the seller's turnover — a shop where it happens twice has the same problem as a
 * shop where it happens two hundred times, and the engine ranking it low because the shop is large
 * would be the wrong answer.
 */
class BrandComplianceProducer implements InsightProducer
{
    public const TYPE = 'BRAND_COMPLIANCE';

    public function __construct(private readonly BrandRegistryService $registry)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('brand_claims') || !Schema::hasTable('products')) {
            return [];
        }

        $enforcing = $this->registry->isEnforcing();

        foreach ($this->registry->brandExposure($sellerId) as $exposure) {
            if ($exposure['may_list']) {
                continue;
            }

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: $enforcing ? SellerInsight::SEVERITY_CRITICAL : SellerInsight::SEVERITY_HIGH,
                // Two different problems, and the seller can act on only one of them the same way.
                // While the marketplace is only reporting, this is a deadline; once it is refusing,
                // the listings are already unsellable.
                title: $enforcing ? 'insight_brand_listings_blocked' : 'insight_brand_not_claimed',
                body: Copy::line('insight_body_brand', [
                    'brand' => $exposure['brand_name'],
                    'count' => $exposure['products'],
                ]),
                entityType: 'brand',
                entityId: $exposure['brand_id'],
                metric: $exposure['products'],
                affectedCount: $exposure['products'],
                actionKey: 'open_brand_claims',
                actionParams: ['brand_id' => $exposure['brand_id']],
                category: SellerInsight::CATEGORY_CATALOG,
                signals: new ImpactSignals(
                    affectedCount: $exposure['products'],
                    // Not measured against the shop's size: this is the same problem in a shop with
                    // two listings and a shop with two hundred.
                    severityFloor: $enforcing ? SellerInsight::SEVERITY_CRITICAL : SellerInsight::SEVERITY_HIGH,
                ),
                metadata: [
                    'brand_id' => $exposure['brand_id'],
                    'products' => $exposure['products'],
                    'claim_status' => $exposure['claim_status'],
                    'enforcing' => $enforcing,
                ],
            );
        }
    }
}
