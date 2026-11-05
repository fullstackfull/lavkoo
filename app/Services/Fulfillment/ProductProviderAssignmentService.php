<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProductProviderChange;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Services\AuditLogger;
use App\Services\Fulfillment\Data\AssignmentResult;
use Illuminate\Support\Facades\DB;

/**
 * The only way a product's fulfillment provider changes.
 *
 * `products.fulfillment_provider` is not mass-assignable, so an admin form field, an API payload or a bulk
 * import can never set it by accident. This service enforces the rules, then writes the column and the
 * audit row together:
 *
 *  - only an admin-owned physical product may belong to a provider;
 *  - a provider other than "default" needs an APPROVED mapping (every variant, when the product has variants);
 *  - it cannot change while orders for the product are still unrouted or in flight with the old provider;
 *  - every change is recorded: who, from what, to what, why.
 *
 * Existing products are never touched: NULL (in-house / default) stays NULL until someone assigns it here.
 */
class ProductProviderAssignmentService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function assign(int $productId, ?string $provider, string $actorType, ?int $actorId, ?string $reason = null): AssignmentResult
    {
        $target = $this->normalize($provider);
        if ($provider !== null && $provider !== '' && $provider !== 'default' && $target === null) {
            return AssignmentResult::refused('fulfillment_provider_unknown');
        }

        return DB::transaction(function () use ($productId, $target, $actorType, $actorId, $reason): AssignmentResult {
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            if ($product === null) {
                return AssignmentResult::refused('fulfillment_product_not_found');
            }

            $current = $this->normalize($product->fulfillment_provider ?? null);
            if ($current === $target) {
                return AssignmentResult::ok();
            }

            if ($target !== null) {
                if (($product->added_by ?? 'admin') !== 'admin') {
                    return AssignmentResult::refused('fulfillment_vendor_product_not_allowed');
                }
                if (($product->product_type ?? 'physical') !== 'physical') {
                    return AssignmentResult::refused('fulfillment_digital_product_not_allowed');
                }
                if (($missing = $this->variantsWithoutApprovedMapping($product, $target)) !== []) {
                    return AssignmentResult::refused('fulfillment_mapping_required', implode(', ', $missing));
                }
            }

            if ($current !== null && $this->activeOperations($productId, $current) > 0) {
                return AssignmentResult::refused('fulfillment_active_operations_exist');
            }

            DB::table('products')->where('id', $productId)->update([
                'fulfillment_provider' => $target?->value,
                'updated_at' => now(),
            ]);

            ProductProviderChange::create([
                'product_id' => $productId,
                'from_provider' => $current?->value,
                'to_provider' => $target?->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
            ]);

            $this->audit->record(
                action: 'product.fulfillment_provider_changed',
                subject: ['type' => 'product', 'id' => $productId],
                before: ['fulfillment_provider' => $current?->value],
                after: ['fulfillment_provider' => $target?->value],
                context: ['reason' => $reason],
            );

            return AssignmentResult::ok();
        });
    }

    /** Orders for this product that still depend on the provider it has now. */
    public function activeOperations(int $productId, ProviderCode $provider): int
    {
        $unrouted = DB::table('provider_fulfillment_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->where('items.product_id', $productId)
            ->where('items.provider', $provider->value)
            ->where('items.status', ProviderFulfillmentItem::STATUS_UNROUTED)
            ->whereNotIn('orders.order_status', OrderEligibility::CLOSED_STATUSES)
            ->count();

        $inFlight = DB::table('provider_fulfillment_items as items')
            ->join('provider_fulfillments as pf', 'pf.id', '=', 'items.provider_fulfillment_id')
            ->where('items.product_id', $productId)
            ->where('items.provider', $provider->value)
            ->whereIn('pf.status', FulfillmentStatus::activeValues())
            ->count();

        return $unrouted + $inFlight;
    }

    /**
     * Variant keys (or '' for the whole product) that have no approved mapping with this provider.
     *
     * @return list<string>
     */
    private function variantsWithoutApprovedMapping(object $product, ProviderCode $provider): array
    {
        $approved = ProviderProductMapping::where('provider', $provider->value)
            ->where('product_id', $product->id)
            ->where('status', MappingStatus::Approved->value)
            ->pluck('variant_key')
            ->all();

        if (in_array('', $approved, true)) {
            return [];
        }

        $variations = json_decode((string) ($product->variation ?? '[]'), true) ?: [];
        $keys = array_values(array_filter(array_map(static fn ($variation): ?string => $variation['type'] ?? null, $variations)));
        if ($keys === []) {
            return $approved === [] ? ['*'] : [];
        }

        return array_values(array_diff($keys, $approved));
    }

    private function normalize(?string $provider): ?ProviderCode
    {
        return $provider === null || $provider === '' || $provider === 'default' ? null : ProviderCode::tryFrom($provider);
    }
}
