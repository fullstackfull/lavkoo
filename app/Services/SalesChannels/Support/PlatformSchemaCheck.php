<?php

namespace App\Services\SalesChannels\Support;

use App\Services\SalesChannels\Dispatch\ChannelOrderMaterializer;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the platform's own tables have what this module reads and writes. They come from a database dump the repository does not hold, so every test of the module builds them from the
 * code; this is the check to run against a COPY of the real database before anything is switched on (`php artisan channels:check-platform-schema`, see docs/sales-channels/rehearsal-on-a-copy.md).
 *
 *  - `columns` are needed: a table without one of them fails the check;
 *  - `optional` columns belong to the tables the dispatcher inserts into and are written only when the table has them (`not_written` lists the ones it has not: informational);
 *  - on those tables a column the table insists on (not null, no default) that the dispatcher does not fill fails the check, because the dispatch would refuse.
 *
 * Keep the lists in step with ChannelOrderMaterializer and the readers; a test fails when a declared column the table has is not written.
 */
final class PlatformSchemaCheck
{
    /** @var array<string, array{written: bool, columns: list<string>, optional?: list<string>}> */
    public const TABLES = [
        'orders' => [
            'written' => true,
            'columns' => ['id', 'customer_id', 'seller_id', 'seller_is', 'payment_status', 'payment_method', 'order_status', 'order_amount', 'paid_amount', 'shipping_address_data', 'order_type', 'created_at', 'updated_at'],
            'optional' => ['verification_code', 'is_guest', 'customer_type', 'transaction_ref', 'order_group_id', 'discount_amount', 'init_order_amount', 'total_tax_amount', 'tax_model', 'shipping_cost', 'shipping_address', 'billing_address', 'order_note'],
        ],
        'order_details' => [
            'written' => true,
            'columns' => ['id', 'order_id', 'product_id', 'qty', 'price', 'is_stock_decreased', 'delivery_status', 'payment_status'],
            'optional' => ['seller_id', 'product_details', 'tax', 'tax_model', 'discount', 'discount_type', 'variant', 'variation', 'created_at', 'updated_at'],
        ],
        'products' => ['written' => false, 'columns' => ['id', 'code', 'barcode', 'added_by', 'user_id', 'product_type', 'fulfillment_provider', 'current_stock', 'variation', 'purchase_price']],
        'audit_logs' => ['written' => false, 'columns' => ['id', 'action', 'subject_type', 'subject_id', 'context']],
        'provider_fulfillments' => ['written' => false, 'columns' => ['id', 'order_id', 'provider', 'status', 'provider_reference', 'tracking_number', 'tracking_url', 'generation', 'meta', 'last_error_message']],
        'provider_fulfillment_items' => ['written' => false, 'columns' => ['id', 'order_id', 'order_detail_id', 'provider_fulfillment_id', 'product_id', 'status']],
        'provider_product_mappings' => ['written' => false, 'columns' => ['id', 'provider', 'product_id', 'variant_key', 'status', 'provider_stock']],
        'provider_api_logs' => ['written' => false, 'columns' => ['id', 'provider_fulfillment_id', 'error_message', 'response_summary']],
        'provider_sync_states' => ['written' => false, 'columns' => ['id', 'provider', 'last_error']],
    ];

    public function __construct(private readonly ChannelOrderMaterializer $materializer)
    {
    }

    /** @return array<string, array{exists: bool, missing: list<string>, not_written: list<string>, required_unfilled: list<string>}> */
    public function run(): array
    {
        $report = [];
        foreach (self::TABLES as $table => $spec) {
            $exists = Schema::hasTable($table);
            $present = $exists ? Schema::getColumnListing($table) : [];
            $declared = [...$spec['columns'], ...($spec['optional'] ?? [])];
            $report[$table] = [
                'exists' => $exists,
                'missing' => $exists ? array_values(array_diff($spec['columns'], $present)) : $spec['columns'],
                'not_written' => $exists ? array_values(array_diff($spec['optional'] ?? [], $present)) : [],
                'required_unfilled' => $exists && $spec['written'] ? $this->materializer->unfilledRequiredColumns($table, array_flip($declared)) : [],
            ];
        }

        return $report;
    }

    /** @param array<string, array{exists: bool, missing: list<string>, not_written: list<string>, required_unfilled: list<string>}> $report */
    public static function passes(array $report): bool
    {
        return collect($report)->every(static fn (array $row): bool => $row['exists'] && $row['missing'] === [] && $row['required_unfilled'] === []);
    }
}
