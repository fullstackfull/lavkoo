<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Models\ProviderFulfillmentItem;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * Which stock location a sale line is read from. The decision is one resolver shared with the purchase-order layers (they must be made where the sale will take them from), so
 * what the reader decided before and after it was handed to that resolver is pinned here, case by case.
 */
class SaleDocumentReaderLocationTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private InventoryLocation $own;

    private InventoryLocation $torod;

    private InventoryLocation $m5azn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        [$this->torod, $this->m5azn, $this->own] = app(InventoryLocationService::class)->ensureDefaults();
    }

    /** One store order with a line per entry: the provider the line was snapshotted for (null: in-house). */
    private function storeOrder(array $providers, int $orderId = 700): int
    {
        DB::table('orders')->insert(['id' => $orderId, 'order_status' => 'confirmed', 'payment_status' => 'paid', 'order_amount' => 0, 'seller_is' => 'admin', 'created_at' => '2026-11-05 10:00:00', 'updated_at' => '2026-11-05 10:00:00']);
        foreach ($providers as $index => $provider) {
            $detail = $orderId * 10 + $index;
            DB::table('order_details')->insert(['id' => $detail, 'order_id' => $orderId, 'product_id' => 50 + $index, 'qty' => 1, 'price' => 10, 'discount' => 0, 'tax' => 0]);
            if ($provider !== null) {
                ProviderFulfillmentItem::create(['order_id' => $orderId, 'order_detail_id' => $detail, 'product_id' => 50 + $index, 'provider' => $provider, 'name' => 'Item', 'quantity' => 1, 'line_total' => 10, 'status' => 'routed']);
            }
        }

        return $orderId;
    }

    /** @return list<int|null> the location of every line of a store order */
    private function storeLocations(int $orderId): array
    {
        return array_map(static fn ($line): ?int => $line->inventoryLocationId, app(SaleDocumentReader::class)->forOrder($orderId)->lines);
    }

    public function test_a_store_line_is_read_from_the_location_of_the_provider_it_was_snapshotted_for_and_an_in_house_line_from_the_own_warehouse(): void
    {
        $order = $this->storeOrder([null, 'torod_point', 'm5azn']);

        $this->assertSame([$this->own->id, $this->torod->id, $this->m5azn->id], $this->storeLocations($order));
    }

    public function test_a_provider_with_no_active_location_leaves_the_line_without_one(): void
    {
        $order = $this->storeOrder([null, 'torod_point', 'a_new_provider']);
        $this->torod->forceFill(['is_active' => false])->save();

        $this->assertSame([$this->own->id, null, null], $this->storeLocations($order));
    }

    public function test_an_inactive_own_warehouse_is_not_used(): void
    {
        $order = $this->storeOrder([null, 'torod_point']);
        $this->own->forceFill(['is_active' => false])->save();

        $this->assertSame([null, $this->torod->id], $this->storeLocations($order));
    }

    public function test_when_two_active_locations_share_a_provider_the_first_one_made_is_used(): void
    {
        $order = $this->storeOrder(['torod_point']);
        InventoryLocation::create(['code' => 'torod-second', 'name' => 'Second', 'seller_id' => 0, 'type' => InventoryLocation::TYPE_TOROD, 'provider' => 'torod_point', 'source_of_truth' => InventoryLocation::TRUTH_PROVIDER, 'is_active' => true]);

        $this->assertSame([$this->torod->id], $this->storeLocations($order));

        $this->torod->forceFill(['is_active' => false])->save();
        $this->assertNotSame([$this->torod->id], $this->storeLocations($order), 'The next active one takes over when the first is switched off.');
    }

    /** @param array<string, mixed> $line @param array<string, mixed> $mapping */
    private function channelOrder(array $line = [], array $mapping = []): ExternalOrderMapping
    {
        $account = SalesChannelAccount::first() ?? SalesChannelAccount::create(['channel' => 'amazon', 'name' => 'Amazon KSA']);
        $order = ExternalOrderMapping::create($mapping + [
            'account_id' => $account->id, 'sales_channel' => 'amazon', 'seller_id' => 0, 'external_order_id' => 'ORD-' . (ExternalOrderMapping::count() + 1), 'external_package_id' => '',
            'status' => 'shipped', 'currency' => 'SAR', 'fulfillment_mode' => 'merchant_managed', 'prices_include_tax' => false, 'items_total' => '100', 'purchased_at' => '2026-11-05 09:00:00',
        ]);
        ExternalOrderLine::create($line + ['mapping_id' => $order->id, 'external_line_id' => 'L1', 'sku' => 'SKU-1', 'product_id' => 50, 'quantity' => 1, 'quantity_cancelled' => 0, 'line_total' => '100', 'discount_total' => '0', 'tax_total' => '0', 'currency' => 'SAR', 'status' => 'open']);

        return $order;
    }

    private function channelLocation(ExternalOrderMapping $order): ?int
    {
        return app(SaleDocumentReader::class)->forChannelOrder($order->id)->lines[0]->inventoryLocationId;
    }

    public function test_a_channel_line_is_read_from_the_provider_that_ships_it_or_else_the_own_warehouse(): void
    {
        $this->assertSame($this->own->id, $this->channelLocation($this->channelOrder()));
        $this->assertSame($this->torod->id, $this->channelLocation($this->channelOrder(['provider' => 'torod_point'])));
        $this->assertSame($this->m5azn->id, $this->channelLocation($this->channelOrder(['provider' => 'm5azn'])));
        $this->assertNull($this->channelLocation($this->channelOrder(['provider' => 'a_new_provider'])));
    }

    public function test_a_location_named_on_the_channel_line_wins_over_every_other_rule(): void
    {
        $chosen = InventoryLocation::create(['code' => 'chosen', 'name' => 'Chosen', 'seller_id' => 0, 'type' => InventoryLocation::TYPE_OTHER, 'source_of_truth' => InventoryLocation::TRUTH_MANUAL, 'is_active' => false]);

        $this->assertSame($chosen->id, $this->channelLocation($this->channelOrder(['inventory_location_id' => $chosen->id, 'provider' => 'torod_point'])), 'Even a location nobody uses any more: the line says where it was taken from.');
    }

    public function test_an_fba_order_is_read_from_the_fulfilment_centre_of_its_own_account_only(): void
    {
        $order = $this->channelOrder(mapping: ['fulfillment_mode' => 'fba']);
        $this->assertNull($this->channelLocation($order), 'An FBA order takes no unit from Torod Point or the own warehouse; with no FBA location for the account there is none to name.');

        $fba = app(InventoryLocationService::class)->ensureFbaLocation(SalesChannelAccount::findOrFail($order->account_id));
        $other = app(InventoryLocationService::class)->ensureFbaLocation(SalesChannelAccount::create(['channel' => 'amazon', 'name' => 'Another account']));

        $this->assertSame($fba->id, app(SaleDocumentReader::class)->forChannelOrder($order->id)->lines[0]->inventoryLocationId);
        $this->assertNotSame($other->id, $fba->id);
        $this->assertSame($this->own->id, $this->channelLocation($this->channelOrder(mapping: ['fulfillment_mode' => 'fbm'])), 'Only FBA looks in the account\'s fulfilment centre.');
    }
}
