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
 *  - every change is recorded: who, from what, to what, why, and what stayed behind.
 *
 * THE CHANGE IS FOR WHAT COMES NEXT. Orders already placed do not move: the line of an order carries the provider it was placed with
 * (`OrderItemOwnershipSnapshotter`) and the router reads only that snapshot, never the product's current provider, so a shipment in flight and a
 * line not routed yet both stay with the provider they were placed for. The number of those lines is counted and recorded with the change, and
 * `fulfillment.lock_provider_while_orders_are_in_flight` restores the older, stricter behaviour (refuse until they finish) for an owner who wants it.
 *
 * The storefront's stock follows the new owner at once: the new provider's own stored figures are written to it again (`InventorySyncService`), without
 * asking the provider and without making an old figure look new. Moving a product back in-house writes nothing — the units are the store's own from then
 * on, and the figure it keeps is the last one the old provider gave, which the change records.
 *
 * Existing products are never touched: NULL (in-house / default) stays NULL until someone assigns it here.
 */
class ProductProviderAssignmentService
{
    public function __construct(private readonly AuditLogger $audit, private readonly InventorySyncService $inventory)
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

            if (($refusal = $this->refusal($product, $current, $target)) !== null) {
                return $refusal;
            }

            $stay = $current === null ? 0 : $this->activeOperations($productId, $current);

            DB::table('products')->where('id', $productId)->update([
                'fulfillment_provider' => $target?->value,
                'updated_at' => now(),
            ]);

            // The storefront sells from the new owner's figure from now on; in-house keeps the number it has, because nothing else knows it.
            $stock = $target === null ? ['products' => -1] : $this->inventory->rewriteStoreStockFromStoredFigures($target, $productId);

            ProductProviderChange::create([
                'product_id' => $productId,
                'from_provider' => $current?->value,
                'to_provider' => $target?->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'reason' => $reason,
                'context' => ['kept_with_the_old_provider' => $stay, 'stock' => $stock],
            ]);

            $this->audit->record(
                action: 'product.fulfillment_provider_changed',
                subject: ['type' => 'product', 'id' => $productId],
                before: ['fulfillment_provider' => $current?->value],
                after: ['fulfillment_provider' => $target?->value],
                context: ['reason' => $reason, 'kept_with_the_old_provider' => $stay, 'stock' => $stock],
            );

            return AssignmentResult::ok(keptWithTheOldProvider: $stay, stockRewrittenFrom: (int) $stock['products']);
        });
    }

    /**
     * What the product form offers: "default" and each provider, and for every one that cannot be picked
     * right now, the translation key of the reason. It asks the same question assign() does, so the form
     * and the rule cannot disagree. A product that does not exist yet (the create form) cannot be matched
     * to a provider's product, so no provider is selectable until it has been saved.
     *
     * A choice that is allowed but moves nothing already placed carries a `warning` instead of a reason: the lines that stay with the provider they
     * were placed for, so the person choosing reads it before they choose.
     *
     * @return array{current: string, choices: list<array{value: string, provider: ?ProviderCode, selectable: bool, reason: ?string, warning: ?string, kept_with_the_old_provider: int}>}
     */
    public function choicesFor(?int $productId): array
    {
        $product = $productId === null ? null : DB::table('products')->where('id', $productId)->first();
        $current = $this->normalize($product?->fulfillment_provider);

        $choices = [];
        foreach ([null, ...ProviderCode::cases()] as $candidate) {
            $reason = match (true) {
                $candidate === $current => null,
                $product === null => $candidate === null ? null : 'fulfillment_save_product_first',
                default => $this->refusal($product, $current, $candidate)?->reasonKey,
            };
            $stay = $reason !== null || $candidate === $current || $current === null || $product === null ? 0 : $this->activeOperations((int) $product->id, $current);
            $choices[] = [
                'value' => $candidate?->value ?? 'default',
                'provider' => $candidate,
                'selectable' => $reason === null,
                'reason' => $reason,
                'warning' => $stay > 0 ? 'fulfillment_orders_stay_with_the_old_provider' : null,
                'kept_with_the_old_provider' => $stay,
            ];
        }

        return ['current' => $current?->value ?? 'default', 'choices' => $choices];
    }

    /** Orders for this product that still depend on the provider it has now. */
    public function activeOperations(int $productId, ProviderCode $provider): int
    {
        $unrouted = DB::table('provider_fulfillment_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->where('items.product_id', $productId)
            ->where('items.provider', $provider->value)
            ->where('items.status', ProviderFulfillmentItem::STATUS_UNROUTED)
            ->whereNotIn('orders.order_status', OrderEligibility::FINISHED_STATUSES)
            ->count();

        $inFlight = DB::table('provider_fulfillment_items as items')
            ->join('provider_fulfillments as pf', 'pf.id', '=', 'items.provider_fulfillment_id')
            ->where('items.product_id', $productId)
            ->where('items.provider', $provider->value)
            ->whereIn('pf.status', FulfillmentStatus::activeValues())
            ->count();

        return $unrouted + $inFlight;
    }

    /** Why moving this product from $current to $target is not allowed, or null when it is. */
    private function refusal(object $product, ?ProviderCode $current, ?ProviderCode $target): ?AssignmentResult
    {
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

        // Orders already placed keep the provider they were placed with, so work in flight does not stand in the way; an owner who wants the older, stricter rule switches it back on.
        if (config('fulfillment.lock_provider_while_orders_are_in_flight', false) && $current !== null && $this->activeOperations($product->id, $current) > 0) {
            return AssignmentResult::refused('fulfillment_active_operations_exist');
        }

        return null;
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
