<?php

namespace App\Services\Marketplace;

use App\Models\Product;
use App\Models\SellerPricingPolicy;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Schema;

/**
 * The floor under a seller's own prices, and the one place that decides whether a price clears it.
 *
 * `PricingRiskProducer` already reports selling below cost. That is worth having and is not enough:
 * by the time a finding appears the price has been live, and orders may have been taken at it. This
 * is the same knowledge applied one step earlier — at the moment the price is written — for the
 * paths the seller controls.
 *
 * It is deliberately not enforced everywhere. An admin correcting a price, an importer, a promotion
 * the marketplace itself runs: those are not the seller mis-typing a discount, and a floor that
 * refused them would turn a seller's preference into a platform-wide constraint. Those paths stay
 * covered by the detector, which is the honest division: refuse where the seller is acting, report
 * everywhere else.
 *
 * The effective price is what a customer would pay, not the list price. A floor that only looked at
 * `unit_price` would be cleared by every product with a large enough discount, which is precisely
 * the case it exists to catch.
 */
class PricingPolicyService
{
    /** Read once per request: several products in one bulk job share the same shop. */
    private array $cache = [];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function forSeller(int|string $sellerId): ?SellerPricingPolicy
    {
        if (!Schema::hasTable('seller_pricing_policies')) {
            return null;
        }

        $key = (int) $sellerId;

        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = SellerPricingPolicy::where('seller_id', $key)->first();
        }

        return $this->cache[$key];
    }

    /**
     * What a customer would actually pay.
     *
     * @param  string|null  $discountType  'percent' or anything else, which is treated as a flat amount
     */
    public function effectivePrice(float $unitPrice, float $discount, ?string $discountType): float
    {
        $off = $discountType === 'percent' ? $unitPrice * $discount / 100 : $discount;

        return round($unitPrice - $off, 2);
    }

    /**
     * The floor for one product under one policy, or null when there is none to compute.
     *
     * A margin floor needs a recorded cost. A product with no purchase price has no margin to
     * compute and the policy says nothing about it, rather than treating a missing cost as zero and
     * inventing a floor of zero — which would clear everything and look like enforcement.
     */
    public function floorFor(Product $product, ?SellerPricingPolicy $policy): ?float
    {
        if (!$policy || !$policy->isBinding()) {
            return null;
        }

        $floors = [];

        if ($policy->min_price !== null) {
            $floors[] = (float) $policy->min_price;
        }

        $cost = (float) $product->purchase_price;

        if ($policy->min_margin_percent !== null && $cost > 0) {
            $floors[] = round($cost * (1 + $policy->min_margin_percent / 100), 2);
        }

        return $floors === [] ? null : max($floors);
    }

    /**
     * May this price be written?
     *
     * Returns a reason rather than throwing, so a bulk job can record the row it refused and carry
     * on with the other four hundred.
     *
     * @return array{ok: bool, reason?: string, floor?: float, price?: float}
     */
    public function check(Product $product, float $unitPrice, float $discount, ?string $discountType): array
    {
        $policy = $this->forSeller($product->added_by === 'seller' ? $product->user_id : 0);
        $floor = $this->floorFor($product, $policy);

        if ($floor === null) {
            return ['ok' => true];
        }

        $price = $this->effectivePrice($unitPrice, $discount, $discountType);

        if ($price >= $floor) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'reason' => 'pricing_reason_below_your_floor',
            'floor' => $floor,
            'price' => $price,
        ];
    }

    /**
     * Set or change a shop's policy.
     *
     * Recorded, because a floor that quietly moved is a floor that explains nothing when a price
     * that was refused yesterday goes through today.
     */
    public function save(SellerPrincipal $principal, array $input): SellerPricingPolicy
    {
        $policy = SellerPricingPolicy::firstOrNew(['seller_id' => $principal->sellerId()]);
        $before = $policy->exists
            ? $policy->only(['min_margin_percent', 'min_price', 'enforce'])
            : null;

        $policy->fill([
            'seller_id' => $principal->sellerId(),
            'updated_by_staff_id' => $principal->staffId(),
            'min_margin_percent' => $input['min_margin_percent'] ?? null,
            'min_price' => $input['min_price'] ?? null,
            'enforce' => (bool) ($input['enforce'] ?? false),
        ])->save();

        unset($this->cache[$principal->sellerId()]);

        $this->audit->record(
            action: 'seller.pricing_policy_changed',
            subject: ['type' => 'seller_pricing_policy', 'id' => $policy->id],
            before: $before,
            after: $policy->only(['min_margin_percent', 'min_price', 'enforce']),
            context: ['seller_id' => $principal->sellerId(), 'actor' => $principal->actorLabel()],
        );

        return $policy;
    }
}
