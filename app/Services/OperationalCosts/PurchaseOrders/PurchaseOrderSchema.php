<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use Illuminate\Support\Facades\Schema;

/**
 * Whether everything the purchase-order source needs is in place: the platform's own tables (which come from the installation dump, so may not be there) and the columns of this
 * module's own tables that migration 2026_11_11_100000 adds. When something is missing the source does nothing and says what.
 */
class PurchaseOrderSchema
{
    public const MIGRATION = '2026_11_11_100000_add_purchase_order_sources_to_cost_tables';

    /** @var array<string, list<string>> */
    private const OWN_COLUMNS = [
        'inventory_cost_layers' => ['source_from', 'source_to', 'supplier_id', 'received_at_basis'],
        'operational_cost_invoices' => ['supplier_id'],
        'operational_cost_invoice_items' => ['purchase_order_item_id', 'resembles_purchase_order_item_id'],
    ];

    private ?string $reason = null;

    private bool $checked = false;

    public function __construct(private readonly PlatformProcurement $procurement)
    {
    }

    /** True when everything is in place; checked once and remembered for the life of the instance. */
    public function ready(): bool
    {
        if (!$this->checked) {
            $this->reason = $this->unavailable();
            $this->checked = true;
        }

        return $this->reason === null;
    }

    /** @return string|null why nothing can be read or made, in a sentence, or null when everything is there */
    public function unavailable(): ?string
    {
        $missing = $this->procurement->missing();
        if ($missing !== []) {
            return 'The platform\'s own tables are not complete here (' . implode(', ', $missing) . '): they come from the installation dump, which has to be restored first. Nothing was read or written.';
        }
        $absent = $this->missingOwnColumns();
        if ($absent !== []) {
            return 'The purchase-order columns of this module are not there yet (' . implode(', ', $absent) . '): run migration ' . self::MIGRATION . '. Nothing was read or written.';
        }

        return null;
    }

    /** @return list<string> the columns ("inventory_cost_layers.source_to") of this module's tables the source needs and that are not there */
    public function missingOwnColumns(): array
    {
        $missing = [];
        foreach (self::OWN_COLUMNS as $table => $columns) {
            $present = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
            array_push($missing, ...array_map(static fn (string $column): string => "{$table}.{$column}", array_values(array_diff($columns, $present))));
        }

        return $missing;
    }
}
