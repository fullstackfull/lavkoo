<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillmentItem;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\OrderItemOwnershipSnapshotter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class OwnershipSnapshotTest extends FulfillmentTestCase
{
    private function capture(int $orderId, int $productId, ?string $provider, int $detailId = 1, string $variant = '', float $net = 50.0): ?ProviderFulfillmentItem
    {
        return app(OrderItemOwnershipSnapshotter::class)->capture(
            orderId: $orderId,
            orderDetailId: $detailId,
            product: ['id' => $productId, 'name' => 'Item', 'code' => 'SKU' . $productId, 'fulfillment_provider' => $provider, 'variation' => '[]'],
            variant: $variant,
            quantity: 2,
            unitPrice: $net / 2,
            netAmount: $net,
            lineTax: 0.0,
            taxModel: 'include',
        );
    }

    private function approvedMapping(ProviderCode $provider, int $productId, string $externalId, string $variantKey = '', ?string $barcode = null): ProviderProductMapping
    {
        return ProviderProductMapping::create([
            'provider' => $provider->value, 'product_id' => $productId, 'variant_key' => $variantKey,
            'external_id' => $externalId, 'external_variant_id' => '', 'external_sku' => 'X-' . $externalId,
            'external_barcode' => $barcode, 'status' => MappingStatus::Approved,
        ]);
    }

    public function test_an_in_house_product_writes_no_snapshot_and_costs_nothing(): void
    {
        $this->makeOrder(1);

        $this->assertNull($this->capture(1, 10, null));
        $this->assertSame(0, ProviderFulfillmentItem::count());
    }

    public function test_an_unknown_provider_value_falls_back_to_in_house_rather_than_guessing(): void
    {
        $this->makeOrder(1);

        $this->assertNull($this->capture(1, 10, 'some_other_supplier'));
        $this->assertSame(0, ProviderFulfillmentItem::count());
    }

    public function test_a_provider_product_is_frozen_with_its_mapping_prices_and_provider(): void
    {
        $this->makeProduct(10, ProviderCode::M5azn);
        $this->makeOrder(1);
        $mapping = $this->approvedMapping(ProviderCode::M5azn, 10, 'M5-777');
        $mapping->update(['supplier_cost' => 6.5]);

        $item = $this->capture(1, 10, 'm5azn');

        $this->assertSame('m5azn', $item->provider);
        $this->assertSame($mapping->id, $item->mapping_id);
        $this->assertSame('M5-777', $item->external_product_id);
        $this->assertSame('X-M5-777', $item->external_sku);
        $this->assertSame(2, $item->quantity);
        $this->assertEqualsWithDelta(50.0, (float) $item->line_total, 0.001);
        $this->assertEqualsWithDelta(6.5, (float) $item->unit_cost, 0.0001);
        $this->assertSame(ProviderFulfillmentItem::STATUS_UNROUTED, $item->status);
        $this->assertTrue($item->snapshot['currency_converted']);
    }

    public function test_the_snapshot_cannot_be_changed_afterwards(): void
    {
        $this->makeOrder(1);
        $item = $this->capture(1, 10, 'torod_point');

        foreach (['provider' => 'm5azn', 'quantity' => 99, 'external_product_id' => 'HIJACK', 'unit_price' => 1] as $column => $value) {
            try {
                $item->update([$column => $value]);
                $this->fail("{$column} should be immutable");
            } catch (LogicException) {
                $item->refresh();
            }
        }

        $this->assertSame('torod_point', $item->fresh()->provider);
    }

    public function test_routing_bookkeeping_remains_updatable(): void
    {
        $this->makeOrder(1);
        $item = $this->capture(1, 10, 'torod_point');

        $item->update(['status' => ProviderFulfillmentItem::STATUS_ROUTED, 'provider_fulfillment_id' => 5]);

        $this->assertSame(ProviderFulfillmentItem::STATUS_ROUTED, $item->fresh()->status);
    }

    public function test_a_snapshot_is_never_deleted(): void
    {
        $this->makeOrder(1);
        $item = $this->capture(1, 10, 'torod_point');

        $this->expectException(LogicException::class);
        $item->delete();
    }

    public function test_an_order_line_cannot_be_owned_twice(): void
    {
        $this->makeOrder(1);
        $this->capture(1, 10, 'torod_point', detailId: 7);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->capture(1, 10, 'm5azn', detailId: 7);
    }

    public function test_reassigning_the_product_later_does_not_move_an_order_already_placed(): void
    {
        $this->makeProduct(10, ProviderCode::TorodPoint);
        $this->makeOrder(1);
        $this->approvedMapping(ProviderCode::TorodPoint, 10, 'T-1');
        $item = $this->capture(1, 10, 'torod_point');

        DB::table('products')->where('id', 10)->update(['fulfillment_provider' => 'm5azn']);

        $this->assertSame('torod_point', $item->fresh()->provider);
    }

    public function test_the_same_barcode_under_two_providers_never_crosses_over(): void
    {
        $this->makeProduct(10, ProviderCode::M5azn);
        $this->makeProduct(11, ProviderCode::TorodPoint);
        $this->makeOrder(1);
        $this->approvedMapping(ProviderCode::M5azn, 10, 'MZ-1', barcode: '6281000000012');
        $this->approvedMapping(ProviderCode::TorodPoint, 11, 'TP-1', barcode: '6281000000012');

        $a = $this->capture(1, 10, 'm5azn', detailId: 1);
        $b = $this->capture(1, 11, 'torod_point', detailId: 2);

        $this->assertSame('MZ-1', $a->external_product_id);
        $this->assertSame('TP-1', $b->external_product_id);
        $this->assertSame('6281000000012', $a->external_barcode);
        $this->assertSame('6281000000012', $b->external_barcode);
        $this->assertSame(['m5azn', 'torod_point'], ProviderFulfillmentItem::orderBy('id')->pluck('provider')->all());
    }

    public function test_a_variant_uses_its_own_mapping_and_falls_back_to_the_product_mapping(): void
    {
        $this->makeProduct(10, ProviderCode::M5azn);
        $this->makeOrder(1);
        $this->approvedMapping(ProviderCode::M5azn, 10, 'PRODUCT-LEVEL');
        $this->approvedMapping(ProviderCode::M5azn, 10, 'VARIANT-RED', variantKey: 'Red');

        $red = $this->capture(1, 10, 'm5azn', detailId: 1, variant: 'Red');
        $blue = $this->capture(1, 10, 'm5azn', detailId: 2, variant: 'Blue');

        $this->assertSame('VARIANT-RED', $red->external_product_id);
        $this->assertSame('PRODUCT-LEVEL', $blue->external_product_id);
    }

    public function test_a_missing_mapping_still_records_ownership_so_the_line_cannot_fall_through_to_in_house(): void
    {
        $this->makeOrder(1);

        $item = $this->capture(1, 10, 'torod_point');

        $this->assertSame('torod_point', $item->provider);
        $this->assertNull($item->mapping_id);
        $this->assertNull($item->external_product_id);
    }

    public function test_the_line_total_is_converted_from_stored_units_to_riyals(): void
    {
        $this->multiCurrencyUsdBase();
        $this->makeOrder(1);

        $item = $this->capture(1, 10, 'torod_point', net: 20.0);

        $this->assertEqualsWithDelta(75.0, (float) $item->line_total, 0.001);
    }

    public function test_an_unconvertible_currency_is_recorded_not_guessed(): void
    {
        DB::table('currencies')->truncate();
        DB::table('currencies')->insert(['id' => 1, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
        $this->makeOrder(1);

        $item = $this->capture(1, 10, 'torod_point');

        $this->assertFalse($item->snapshot['currency_converted']);
        $this->assertEqualsWithDelta(0.0, (float) $item->line_total, 0.001);
    }
}
