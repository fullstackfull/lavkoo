<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\OperationalCostEntry;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderLayerService;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Sales\SaleLine;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsPlatformProcurementSchema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\CreatesOperationalCostData;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * Two guards about whose goods and whose supplier a cost is: a supplier outlives its deletion in the layers and the ledger entries made from them, and the goods of a vendor on a channel
 * order that was made into an internal order are never costed from the platform's stock. The platform's tables are INFERRED.
 */
class SupplierAndVendorGoodsTest extends TestCase
{
    use BuildsPlatformProcurementSchema;
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;
    use CreatesOperationalCostData;

    private InventoryLocation $own;

    private InventoryLocation $torod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildPlatformProcurementSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR', 'operational_costs.enabled' => true, 'operational_costs.sources.purchase_orders' => 'on']);
        [$this->torod, , $this->own] = app(InventoryLocationService::class)->ensureDefaults();
    }

    private function layer(int $product, int $units = 5, string $identity = 'inv-1'): InventoryCostLayer
    {
        return app(InventoryCostLayerService::class)->receive(new LayerReceipt(
            locationId: $this->own->id, productId: $product, quantity: $units, unitCost: Money::of('10'), currency: 'SAR', receivedAt: CarbonImmutable::parse('2026-11-01'),
            sourceType: 'purchase_invoice', identity: $identity . ':' . $product,
        ));
    }

    /** @return array{0: int, 1: int} a product and its supplier, with a layer made from a received purchase order line */
    private function layerFromAnOrder(): array
    {
        $product = $this->platformProduct();
        $supplier = $this->supplier('Acme Pharma Supply');
        $order = $this->purchaseOrder($supplier, [[$product, 5, 12.5]]);
        $this->receiveOnPlatform($order->items->first(), 5);
        app(PurchaseOrderLayerService::class)->apply(since: CarbonImmutable::parse('2000-01-01'));

        return [$product, $supplier->id];
    }

    private function sold(int $product): OperationalCostEntry
    {
        $orderId = $this->storefrontSale(orderId: 930 + $product, productId: $product);
        app(SaleCostService::class)->refresh(app(SaleDocumentReader::class)->forOrder($orderId));

        return OperationalCostEntry::where('subject_type', 'order_line')->where('cost_component', CostComponent::Cogs->value)->where('product_id', $product)->where('is_effective', true)->sole();
    }

    public function test_the_supplier_of_a_layer_made_from_an_order_reaches_the_cost_entry_of_the_sale_and_survives_the_supplier_being_deleted(): void
    {
        [$product, $supplier] = $this->layerFromAnOrder();
        $entry = $this->sold($product);

        $piece = $entry->calc_inputs['pieces'][0];
        $this->assertSame([$supplier, 'Acme Pharma Supply'], [$piece['supplier_id'], $piece['supplier_name']], 'The entry says which supplier the goods it costs came from.');

        DB::table('suppliers')->delete();
        DB::table('purchase_order_items')->delete();
        DB::table('purchase_orders')->delete();

        $layer = InventoryCostLayer::sole();
        $this->assertSame([$supplier, 'Acme Pharma Supply', 0], [$layer->supplier_id, $layer->supplier_name, DB::table('suppliers')->count()], 'The platform may hard-delete a supplier: the layer keeps the id and the name it had.');
        $this->assertSame($piece, $entry->fresh()->calc_inputs['pieces'][0]);
    }

    public function test_a_layer_with_no_supplier_link_hands_on_pieces_of_exactly_the_shape_they_always_had_so_no_entrys_identity_changes(): void
    {
        $product = $this->platformProduct();
        $layer = $this->layer($product);

        $entry = $this->sold($product);

        $this->assertSame([['layer_id' => $layer->id, 'quantity' => 1, 'unit_cost' => '10.000000', 'landed_unit_cost' => '0.000000']], $entry->calc_inputs['pieces'], 'The entry\'s identity is a hash of these pieces: for goods with no supplier link they are what they were before suppliers were handed on.');
    }

    public function test_stock_moved_to_another_location_keeps_its_supplier(): void
    {
        [$product, $supplier] = $this->layerFromAnOrder();

        app(InventoryCostLayerService::class)->transfer('T-1', $this->own->id, $this->torod->id, $product, '', 5, CarbonImmutable::parse('2026-11-12'));

        $moved = InventoryCostLayer::where('source_type', 'transfer_in')->sole();
        $this->assertSame([$supplier, 'Acme Pharma Supply', $this->torod->id], [$moved->supplier_id, $moved->supplier_name, $moved->inventory_location_id]);
    }

    /**
     * A channel order of two lines made into an internal order: line 1 for a product of the platform, line 2 for the product named.
     *
     * @return array{0: ExternalOrderMapping, 1: ExternalOrderLine, 2: ExternalOrderLine}
     */
    private function mirroredOrder(int $platformProduct, int $otherProduct, bool $mirrored = true, string $otherSellerIs = 'admin'): array
    {
        $mapping = $this->channelSale('100', 'trendyol', '2026-11-05 09:00:00', 0, $platformProduct);
        $first = $mapping->lines()->firstOrFail();
        $second = ExternalOrderLine::create([
            'mapping_id' => $mapping->id, 'external_line_id' => 'L-2', 'sku' => 'SKU-2', 'product_id' => $otherProduct, 'quantity' => 1, 'quantity_cancelled' => 0, 'line_total' => '100',
            'discount_total' => '0', 'tax_total' => '0', 'currency' => 'SAR', 'status' => 'open',
        ]);
        if ($mirrored) {
            DB::table('orders')->insert(['id' => 940, 'order_status' => 'confirmed', 'payment_status' => 'paid', 'seller_is' => 'admin', 'created_at' => '2026-11-05 09:00:00', 'updated_at' => '2026-11-05 09:00:00']);
            $says = Schema::hasColumn('order_details', 'seller_is');
            DB::table('order_details')->insert([
                ['order_id' => 940, 'product_id' => $platformProduct, 'qty' => 1, 'price' => 1] + ($says ? ['seller_is' => 'admin'] : []),
                ['order_id' => 940, 'product_id' => $otherProduct, 'qty' => 1, 'price' => 1] + ($says ? ['seller_is' => $otherSellerIs] : []),
            ]);
            $mapping->forceFill(['order_id' => 940])->save();
        }

        return [$mapping->fresh(), $first, $second];
    }

    /** @return array<int, string> how each line of a channel order is costed, by product (the order the database lists the lines in is not the order they were made in) */
    private function basisByProduct(ExternalOrderMapping $mapping): array
    {
        $bases = [];
        foreach (app(SaleDocumentReader::class)->forChannelOrder($mapping->id)->lines as $line) {
            $bases[$line->productId] = $line->costBasis;
        }
        ksort($bases);

        return $bases;
    }

    /** @return list<OperationalCostEntry> the cost of goods now counting for each line, by line */
    private function goodsCosts(ExternalOrderMapping $mapping): array
    {
        app(SaleCostService::class)->refresh(app(SaleDocumentReader::class)->forChannelOrder($mapping->id));

        return OperationalCostEntry::where('subject_type', 'channel_order_line')->whereIn('cost_component', [CostComponent::Cogs->value])->where('is_effective', true)->orderBy('subject_id')->get()->all();
    }

    public function test_on_a_channel_order_made_into_an_internal_order_only_the_platforms_own_lines_are_costed_from_its_stock(): void
    {
        Schema::table('order_details', static fn ($table) => $table->string('seller_is')->nullable());
        $ours = $this->platformProduct();
        $vendors = $this->platformProduct();
        $this->layer($ours);
        $this->layer($vendors);
        [$mapping, $first, $second] = $this->mirroredOrder($ours, $vendors, otherSellerIs: 'seller');

        $costs = $this->goodsCosts($mapping);

        $this->assertSame([$ours => SaleLine::BASIS_OWNED_STOCK, $vendors => SaleLine::BASIS_VENDOR_STOCK], $this->basisByProduct($mapping));
        $this->assertSame([$first->id, $second->id], array_map(static fn (OperationalCostEntry $entry): int => $entry->subject_id, $costs));
        $this->assertSame(['actual', 'missing', 'vendor_owned_goods'], [$costs[0]->state->value, $costs[1]->state->value, $costs[1]->missing_reason]);
        $this->assertSame([5 - 1, 5], [InventoryCostLayer::where('product_id', $ours)->sole()->quantity_remaining, InventoryCostLayer::where('product_id', $vendors)->sole()->quantity_remaining], 'Not one unit of the vendor\'s goods was taken from the platform\'s layers.');
        $this->assertSame(1, InventoryCostConsumption::count());
        $this->assertNull($costs[1]->amount, 'No figure at all: not an estimate and not zero.');
    }

    public function test_a_product_that_belongs_to_a_vendor_is_a_vendors_line_even_when_the_internal_line_does_not_say_so(): void
    {
        $ours = $this->platformProduct();
        $vendors = $this->platformProduct(['added_by' => 'seller']);
        $this->layer($ours);
        $this->layer($vendors);
        [$mapping] = $this->mirroredOrder($ours, $vendors);

        $this->assertSame([$ours => SaleLine::BASIS_OWNED_STOCK, $vendors => SaleLine::BASIS_VENDOR_STOCK], $this->basisByProduct($mapping));
    }

    public function test_a_channel_order_that_was_not_made_into_an_internal_order_is_costed_as_it_always_was(): void
    {
        $ours = $this->platformProduct();
        $vendors = $this->platformProduct(['added_by' => 'seller']);
        $this->layer($ours);
        $this->layer($vendors);
        [$mapping] = $this->mirroredOrder($ours, $vendors, mirrored: false);

        $this->goodsCosts($mapping);

        $this->assertSame([$ours => SaleLine::BASIS_OWNED_STOCK, $vendors => SaleLine::BASIS_OWNED_STOCK], $this->basisByProduct($mapping), 'The guard is about the internal order that exists for a vendor\'s line, and nothing else.');
        $this->assertSame(2, InventoryCostConsumption::count());
    }

    public function test_a_line_dropshipped_through_m5azn_stays_a_supplier_cost_whoever_owns_the_product(): void
    {
        $ours = $this->platformProduct();
        $vendors = $this->platformProduct(['added_by' => 'seller']);
        [$mapping, , $second] = $this->mirroredOrder($ours, $vendors);
        $second->forceFill(['provider' => 'm5azn'])->save();

        $this->assertSame([$ours => SaleLine::BASIS_OWNED_STOCK, $vendors => SaleLine::BASIS_SUPPLIER], $this->basisByProduct($mapping));
    }
}
