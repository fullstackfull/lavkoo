<?php

namespace Tests\Support;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The records a stock-allocation test needs: a store product, the provider's figure for it, store orders at different stages of their journey to the provider, channel
 * listings of several accounts on the same pool, and channel orders ingested the way the poller does. Needs BuildsSalesChannelSchema and BuildsRemoteOrders.
 */
trait BuildsStockPools
{
    protected function torodLocation(): InventoryLocation
    {
        return app(InventoryLocationService::class)->ensureDefaults()[0];
    }

    protected function ownLocation(): InventoryLocation
    {
        return app(InventoryLocationService::class)->ensureDefaults()[2];
    }

    /** A product the store sells (`current_stock` is the storefront's own number, never touched by the module). */
    protected function storeProduct(int $id, ?string $provider = 'torod_point', int $stock = 50, string $variation = '[]'): int
    {
        DB::table('products')->insert([
            'id' => $id, 'name' => 'Product ' . $id, 'code' => 'SKU' . $id, 'barcode' => 'BC' . $id, 'added_by' => 'admin', 'product_type' => 'physical', 'unit_price' => 10,
            'current_stock' => $stock, 'variation' => $variation, 'status' => 1, 'fulfillment_provider' => $provider, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** What the provider says it holds of a product (or one variant of it), as the reader judges it: `ProviderSync` is confirmed, `Manual` is a figure a person typed. */
    protected function providerFigure(int $productId, int $quantity, ?CarbonInterface $syncedAt = null, StockSource $source = StockSource::ProviderSync, string $variantKey = '', string $provider = 'torod_point'): ProviderProductMapping
    {
        return ProviderProductMapping::updateOrCreate(
            ['provider' => $provider, 'product_id' => $productId, 'variant_key' => $variantKey],
            ['external_id' => 'ext-' . $productId, 'external_variant_id' => $variantKey === '' ? '' : 'var-' . $variantKey, 'external_sku' => 'XS' . $productId, 'status' => MappingStatus::Approved,
                'stock_source' => $source, 'provider_stock' => $quantity, 'provider_stock_synced_at' => $syncedAt ?? now()],
        );
    }

    /**
     * A store order of `$quantity` units of a provider product. With no `$acceptedAs` the provider has not got it yet (an unrouted line); with a status and a time the provider
     * accepted it then. Returns the order id.
     */
    protected function storeOrder(int $orderId, int $productId, int $quantity, ?FulfillmentStatus $acceptedAs = null, ?CarbonInterface $acceptedAt = null, string $variantKey = '', string $orderStatus = 'confirmed'): int
    {
        DB::table('orders')->insert(['id' => $orderId, 'customer_id' => 1, 'order_status' => $orderStatus, 'payment_status' => 'paid', 'order_amount' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $detailId = DB::table('order_details')->insertGetId([
            'order_id' => $orderId, 'product_id' => $productId, 'qty' => $quantity, 'price' => 10, 'variant' => $variantKey === '' ? null : $variantKey, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fulfillmentId = $acceptedAs === null ? null : DB::table('provider_fulfillments')->insertGetId([
            'reference' => $orderId . '-TP1', 'order_id' => $orderId, 'provider' => 'torod_point', 'generation' => 1, 'idempotency_key' => sha1('order-' . $orderId),
            'status' => $acceptedAs->value, 'submitted_at' => $acceptedAt ?? now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('provider_fulfillment_items')->insert([
            'provider_fulfillment_id' => $fulfillmentId, 'order_id' => $orderId, 'order_detail_id' => $detailId, 'product_id' => $productId, 'variant_key' => $variantKey, 'provider' => 'torod_point',
            'name' => 'Product ' . $productId, 'quantity' => $quantity, 'status' => $fulfillmentId === null ? ProviderFulfillmentItem::STATUS_UNROUTED : ProviderFulfillmentItem::STATUS_ROUTED,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    /** A listing of an account, linked to a product, taking its stock from a location and opted in to stock publishing. */
    protected function stockListing(SalesChannelAccount $account, int $productId, InventoryLocation $location, string $key, array $overrides = []): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::create($overrides + [
            'account_id' => $account->id, 'product_id' => $productId, 'variant_key' => '', 'listing_key' => $key, 'external_sku' => $key, 'external_barcode' => $key, 'status' => 'mapped',
            'stock_location_id' => $location->id, 'inventory_publish' => true, 'fulfillment_mode' => $account->channel === 'amazon' ? 'fbm' : null,
        ]);
    }

    /** A channel order read from the channel and recorded as the poller records it (its units are held when it is stored). */
    protected function ingestOrder(SalesChannelAccount $account, string $orderId, string $listingKey, int $quantity, string $updatedAt = '2026-11-10 10:00:00', array $overrides = []): ExternalOrderMapping
    {
        app(ChannelOrderIngestor::class)->ingest($account, $this->remoteOrder(orderId: $orderId, lines: [$this->remoteLine('L1', $listingKey, $listingKey, $quantity, (string) ($quantity * 100))], updatedAt: $updatedAt, overrides: $overrides));

        return ExternalOrderMapping::where('account_id', $account->id)->where('external_order_id', $orderId)->firstOrFail();
    }
}
