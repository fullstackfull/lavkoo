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
use App\Services\Fulfillment\Support\ProviderRouteSupport;
use App\Services\Storefront\StorefrontCatalog;
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
 * asking the provider and without making an old figure look new. And where the new owner has given no figure — the product goes back in-house, or the new
 * provider has reported nothing — the stock is set to ZERO and the old number is recorded with the change: the old provider's count is what that provider
 * holds, not a balance of the store's own shelf, so the product stops being sold until a person confirms what is really there.
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

            // The storefront sells from the new owner's figure from now on; and a figure the new owner has not given is not a figure at all (below).
            $stock = $target === null ? ['products' => 0] : $this->inventory->rewriteStoreStockFromStoredFigures($target, $productId);
            $stock['stopped_selling'] = $this->stopSellingUntilTheBalanceIsConfirmed($product, $current, $target, (int) $stock['products']);

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
     * A product that LEAVES a provider must not keep that provider's balance as if it were the new owner's.
     *
     * Torod Point's count is what Torod Point holds; the own warehouse's shelf is a different shelf and nobody has counted it. So when the figures of the new owner wrote
     * nothing (there is no provider, or it has reported none), the storefront's stock is set to **zero** and the number it had is recorded on the change: the product stops
     * being sold until a person enters a balance they have actually confirmed, instead of selling units that may be in another warehouse.
     *
     * `FULFILLMENT_STOP_SELLING_WHEN_A_PRODUCT_LEAVES_A_PROVIDER=false` leaves the old number in place for an owner who would rather correct it themselves.
     *
     * @return array{was: int, now: int}|null what it did, or null when it had nothing to do
     */
    private function stopSellingUntilTheBalanceIsConfirmed(object $product, ?ProviderCode $current, ?ProviderCode $target, int $figuresWritten): ?array
    {
        $leavingAProvider = $current !== null && $current !== $target;
        if (!$leavingAProvider || $figuresWritten > 0 || !config('fulfillment.stop_selling_when_a_product_leaves_a_provider', true)) {
            return null;
        }

        $before = (int) $product->current_stock;
        if ($before === 0) {
            return null;
        }

        DB::table('products')->where('id', $product->id)->update(['current_stock' => 0, 'updated_at' => now()]);
        $this->inventory->recordStockMovement(
            productId: (int) $product->id,
            qtyChange: -$before,
            balanceAfter: 0,
            reason: 'provider_change_balance_unconfirmed',
            note: ($current?->value ?? 'default') . '->' . ($target?->value ?? 'default'),
        );

        return ['was' => $before, 'now' => 0];
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
            if (($outsideItsMarket = $this->marketRefusal($product, $target)) !== null) {
                return $outsideItsMarket;
            }
        }

        // Orders already placed keep the provider they were placed with, so work in flight does not stand in the way; an owner who wants the older, stricter rule switches it back on.
        if (config('fulfillment.lock_provider_while_orders_are_in_flight', false) && $current !== null && $this->activeOperations($product->id, $current) > 0) {
            return AssignmentResult::refused('fulfillment_active_operations_exist');
        }

        return null;
    }

    /**
     * Whether this provider can serve the market the product itself belongs to (C-01).
     *
     * The rule is one execution source per product per country storefront, and the row has carried its country since
     * `2026_11_25_100000_add_storefront_country_to_products`. Nothing compared the two, so a Kuwaiti product could be given a carrier that
     * operates only in Saudi Arabia -- and the refusal would come from the CARRIER, after a customer had bought the thing and the
     * dispatcher had tried to send it. H1-02 already wrote down where each carrier operates, so this asks the question the dispatcher
     * already asks, at the one moment where the answer costs nothing.
     *
     * The DOMESTIC route is what is asked for: a product fulfilled from its own market needs that carrier to serve market-to-market.
     * Where a parcel may then cross a border is a different question, asked per order against the destination, and H1-02 owns it.
     *
     * Two deliberate silences. A product that records NO market is not judged at all -- that is every row of a single-market
     * installation and every row written before the column existed, so this cannot refuse an assignment that works today. And taking a
     * provider away (`$target` null) never reaches here: in-house is not a carrier and has no coverage to have.
     *
     * Every provider's declared routes default to the Saudi one, so Saudi Arabia is unchanged and any other market is refused until
     * somebody writes that carrier's real coverage down. Which carrier serves which country, under which contract and company id, is the
     * owner's question Q-33.
     */
    private function marketRefusal(object $product, ProviderCode $target): ?AssignmentResult
    {
        $market = strtoupper(trim((string) ($product->{StorefrontCatalog::COLUMN} ?? '')));

        if ($market === '' || ProviderRouteSupport::serves($target->value, $market, $market)) {
            return null;
        }

        // The market is named in the detail, because an operator who sees only "not served" cannot tell what to declare.
        return AssignmentResult::refused('fulfillment_provider_does_not_serve_the_products_market', $market);
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
