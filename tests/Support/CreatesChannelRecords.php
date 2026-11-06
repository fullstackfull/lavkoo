<?php

namespace Tests\Support;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingDecision;
use App\Models\FulfillmentRoutingRule;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Listings, orders, routing rules and decisions as the admin screens find them in the database. The figures are arbitrary. */
trait CreatesChannelRecords
{
    private int $recordSequence = 0;

    protected function product(string $name = 'Paracetamol 500mg', string $code = 'PARA-500', ?string $barcode = null, string $addedBy = 'admin', string $type = 'physical'): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => $name, 'slug' => Str::slug($name) . '-' . ++$this->recordSequence, 'code' => $code, 'barcode' => $barcode,
            'added_by' => $addedBy, 'product_type' => $type, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function listing(SalesChannelAccount $account, array $attributes = []): SalesChannelProductMapping
    {
        $number = ++$this->recordSequence;

        return SalesChannelProductMapping::create($attributes + [
            'account_id' => $account->id,
            'listing_key' => '62800000' . $number,
            'external_sku' => 'SKU-' . $number,
            'external_barcode' => '62800000' . $number,
            'external_id' => 'EXT-' . $number,
            'external_title' => 'Listing title ' . $number,
            'status' => SalesChannelProductMapping::UNMAPPED,
            'price_source' => 'internal',
            'remote_price' => '49.500000',
            'remote_currency' => 'SAR',
            'remote_quantity' => 12,
            'remote_status' => 'ACTIVE',
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param list<array<string, mixed>>|null $lines
     */
    protected function channelOrder(SalesChannelAccount $account, array $attributes = [], ?array $lines = null): ExternalOrderMapping
    {
        $number = ++$this->recordSequence;
        $order = ExternalOrderMapping::create($attributes + [
            'account_id' => $account->id,
            'sales_channel' => $account->channel,
            'seller_id' => $account->seller_id,
            'external_order_id' => 'ORD-' . $number,
            'external_package_id' => '',
            'external_status' => 'Created',
            'status' => 'new',
            'fulfillment_mode' => 'fbm',
            'currency' => 'SAR',
            'items_total' => '200.000000',
            'discount_total' => '0.000000',
            'shipping_total' => '10.000000',
            'tax_total' => '26.086957',
            'order_total' => '210.000000',
            'prices_include_tax' => true,
            'purchased_at' => now()->subDay(),
            'snapshot' => ['is_cod' => false, 'destination' => ['city' => 'Riyadh', 'country' => 'SA']],
        ]);

        foreach ($lines ?? [[]] as $index => $line) {
            ExternalOrderLine::create($line + [
                'mapping_id' => $order->id,
                'external_line_id' => 'L' . ($index + 1),
                'sku' => 'SKU-' . $number,
                'barcode' => '62800000' . $number,
                'title' => 'Ordered item ' . ($index + 1),
                'quantity' => 2,
                'unit_price' => '100.000000',
                'line_total' => '200.000000',
                'currency' => 'SAR',
            ]);
        }

        return $order->fresh();
    }

    protected function routingRule(array $attributes = []): FulfillmentRoutingRule
    {
        return FulfillmentRoutingRule::create($attributes + ['name' => 'Rule ' . ++$this->recordSequence, 'priority' => 100, 'is_active' => true, 'seller_id' => 0, 'action' => 'monitor_only']);
    }

    protected function routingDecision(ExternalOrderMapping $order, array $attributes = []): FulfillmentRoutingDecision
    {
        return FulfillmentRoutingDecision::create($attributes + [
            'external_order_mapping_id' => $order->id,
            'sales_channel' => $order->sales_channel,
            'account_id' => $order->account_id,
            'fulfillment_mode' => $order->fulfillment_mode,
            'action' => 'manual_review',
            'execution_mode' => 'manual_review',
            'reason_code' => 'no_rule_matched',
            'reason' => 'No routing rule matches this order, so nothing is done until a person decides.',
            'inputs' => ['channel' => $order->sales_channel, 'fulfillment_mode' => 'fbm', 'order_status' => 'new', 'cod' => false, 'city' => 'Riyadh', 'country' => 'SA', 'order_total' => '210.000000', 'lines' => []],
            'line_decisions' => [],
            'version' => 1,
            'is_current' => true,
            'applied' => true,
            'decided_by' => 'system',
        ]);
    }
}
