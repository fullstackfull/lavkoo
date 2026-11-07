<?php

namespace App\Http\Controllers\Admin\SalesChannels\Support;

use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelProductMapping;
use App\Services\AuditLogger;
use App\Services\SalesChannels\Routing\DynamicFulfillmentRoutingEngine;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;

/**
 * The decisions a person makes about a channel listing: which of our products it stands for, or that it is none of ours.
 *
 * Linking is explicit and changes no product. Once a listing is linked, the order lines that were waiting for it are linked too (the ingestor's own rule), and the orders
 * whose lines changed are decided again, because their decision was based on a line that had no product. Nothing here publishes anything or calls a channel.
 */
final class ListingLinker
{
    private const BEFORE = ['status', 'product_id', 'variant_key', 'match_method', 'suggested_product_id', 'inventory_publish', 'price_publish'];

    public function __construct(
        private readonly ChannelOrderIngestor $orders,
        private readonly DynamicFulfillmentRoutingEngine $routing,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return array{lines: int, orders: int, alsoLinkedTo: list<string>} order lines linked, orders decided again, and the other listings of the account the product was already linked to
     */
    public function link(SalesChannelProductMapping $listing, int $productId, string $variantKey, string $matchMethod, ?int $adminId): array
    {
        $before = $listing->only(self::BEFORE);
        $alsoLinkedTo = SalesChannelProductMapping::where('account_id', $listing->account_id)->where('product_id', $productId)->where('variant_key', $variantKey)
            ->where('status', SalesChannelProductMapping::MAPPED)->where('id', '!=', $listing->id)->pluck('listing_key')->all();

        $listing->forceFill([
            'product_id' => $productId,
            'variant_key' => $variantKey,
            'status' => SalesChannelProductMapping::MAPPED,
            'match_method' => $matchMethod,
            'suggested_product_id' => null,
            'mapped_by' => $adminId,
            'mapped_at' => now(),
        ])->save();
        $this->audit->record(action: 'sales_channel.listing_linked', subject: $listing, before: $before, after: $listing->only(self::BEFORE), context: ['by' => $adminId]);

        return [...$this->relinkOrders($listing), 'alsoLinkedTo' => $alsoLinkedTo];
    }

    /** Not ours to manage: the link, if there was one, and both publish switches go with it. */
    public function ignore(SalesChannelProductMapping $listing, ?int $adminId): void
    {
        $before = $listing->only(self::BEFORE);
        $listing->forceFill([
            'status' => SalesChannelProductMapping::IGNORED,
            'product_id' => null,
            'suggested_product_id' => null,
            'inventory_publish' => false,
            'price_publish' => false,
        ])->save();
        $this->audit->record(action: 'sales_channel.listing_ignored', subject: $listing, before: $before, after: $listing->only(self::BEFORE), context: ['by' => $adminId]);
    }

    public function unignore(SalesChannelProductMapping $listing, ?int $adminId): void
    {
        $before = $listing->only(self::BEFORE);
        $listing->forceFill(['status' => SalesChannelProductMapping::UNMAPPED])->save();
        $this->audit->record(action: 'sales_channel.listing_unignored', subject: $listing, before: $before, after: $listing->only(self::BEFORE), context: ['by' => $adminId]);
    }

    /** @return array{lines: int, orders: int} */
    private function relinkOrders(SalesChannelProductMapping $listing): array
    {
        $key = $listing->listing_key;
        $waiting = ExternalOrderMapping::where('account_id', $listing->account_id)
            ->whereHas('lines', static fn ($lines) => $lines->whereNull('product_id')->where(static fn ($match) => $match->where('sku', $key)->orWhere('barcode', $key)))
            ->get();

        $linked = 0;
        $decided = 0;
        foreach ($waiting as $order) {
            $count = $this->orders->relinkLines($order);
            if ($count > 0) {
                $this->routing->route($order->refresh());
                $decided++;
            }
            $linked += $count;
        }

        return ['lines' => $linked, 'orders' => $decided];
    }
}
