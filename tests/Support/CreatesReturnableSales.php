<?php

namespace Tests\Support;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;

/** A channel sale whose goods really left the shelf, so a return has something to be put back against. The figures are invented: 3 units sold for 300.00 from stock bought at 10.00 each. */
trait CreatesReturnableSales
{
    /** A shipped Trendyol order of one line of three units (revenue 300.00) whose goods were taken from the cost layers (cost of goods 30.00). */
    protected function returnableSale(SalesChannelAccount $account, InventoryLocation $location, int $stock = 10, string $status = 'shipped'): ExternalOrderLine
    {
        app(InventoryCostLayerService::class)->receive(new LayerReceipt(
            locationId: $location->id, productId: 50, quantity: $stock, unitCost: Money::of('10'), currency: 'SAR', receivedAt: CarbonImmutable::parse('2026-01-01'),
            sourceType: 'purchase_invoice', identity: 'opening',
        ));
        $mapping = ExternalOrderMapping::create([
            'account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => $location->seller_id, 'external_order_id' => '10234567890', 'external_package_id' => '3012345678',
            'status' => $status, 'currency' => 'SAR', 'prices_include_tax' => false, 'purchased_at' => '2026-11-05 09:00:00', 'order_total' => '300', 'items_total' => '300',
            'fulfillment_mode' => 'merchant_managed',
        ]);
        $line = ExternalOrderLine::create([
            'mapping_id' => $mapping->id, 'external_line_id' => '4400000001', 'sku' => 'SKU-1', 'title' => 'Test product', 'product_id' => 50, 'inventory_location_id' => $location->id,
            'quantity' => 3, 'quantity_cancelled' => 0, 'line_total' => '300', 'discount_total' => '0', 'tax_total' => '0', 'currency' => 'SAR', 'status' => 'open',
        ]);
        app(SaleCostService::class)->refresh(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));

        return $line->fresh();
    }

    /**
     * The channel has accepted units back: what ChannelReturnIngestor leaves on the line.
     *
     * @param array<string, int> $claims units per claim id
     */
    protected function acceptedReturn(ExternalOrderLine $line, array $claims): ExternalOrderLine
    {
        $line->forceFill(['quantity_returned' => min(array_sum($claims), $line->quantity), 'snapshot' => ['returned_by_claim' => $claims]])->save();

        return $line->fresh();
    }
}
