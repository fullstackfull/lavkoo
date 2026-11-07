<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the cost module needs to take the cost of goods from the platform's purchase orders without keeping a second, typed copy. Columns on this module's own tables only, all
 * nullable, no foreign key into any platform table (a supplier can be deleted, a purchase order line is the platform's), and no data is written or changed.
 *
 *  - `inventory_cost_layers.source_from` / `source_to`: the units of a purchase-order line a layer holds (the line has received `source_to` units, the layers before this one cover
 *    the first `source_from`). The layer table is UNIQUE on `(source_type, source_id, source_from)`, so two runs that overlap cannot both make the layer of the same units, whatever
 *    each of them saw; layers of every other source leave both NULL and are not constrained (NULL is never equal to NULL in a unique key).
 *  - `inventory_cost_layers.supplier_id` / `received_at_basis`: the platform's supplier at the time (the name is already kept in `supplier_name`, a snapshot that outlives a deleted
 *    supplier) and where the layer's date comes from (the stock ledger, or only the line's last change).
 *  - `operational_cost_invoices.supplier_id`: the same supplier link, beside the `party_name` the invoice already keeps.
 *  - `operational_cost_invoice_items.purchase_order_item_id`: the purchase-order line this invoice line bills (it records a cost variance and makes no second layer);
 *    `resembles_purchase_order_item_id`: the received line it may be the goods of without being linked to it, which holds the invoice for a person (0 once a person has looked).
 *
 * Additive and guarded: a re-run is a no-op, and `down` removes exactly what `up` added.
 */
return new class extends Migration
{
    private const TRANCHE_UNIQUE = 'icl_source_tranche_unique';

    private const INVOICE_ITEM_INDEX = 'ocii_purchase_order_item_idx';

    public function up(): void
    {
        $this->addColumn('inventory_cost_layers', 'source_from', static fn (Blueprint $table) => $table->unsignedInteger('source_from')->nullable());
        $this->addColumn('inventory_cost_layers', 'source_to', static fn (Blueprint $table) => $table->unsignedInteger('source_to')->nullable());
        $this->addColumn('inventory_cost_layers', 'supplier_id', static fn (Blueprint $table) => $table->unsignedBigInteger('supplier_id')->nullable());
        $this->addColumn('inventory_cost_layers', 'received_at_basis', static fn (Blueprint $table) => $table->string('received_at_basis', 16)->nullable());
        $this->addColumn('operational_cost_invoices', 'supplier_id', static fn (Blueprint $table) => $table->unsignedBigInteger('supplier_id')->nullable());
        $this->addColumn('operational_cost_invoice_items', 'purchase_order_item_id', static fn (Blueprint $table) => $table->unsignedBigInteger('purchase_order_item_id')->nullable());
        $this->addColumn('operational_cost_invoice_items', 'resembles_purchase_order_item_id', static fn (Blueprint $table) => $table->unsignedBigInteger('resembles_purchase_order_item_id')->nullable());

        if (Schema::hasColumn('inventory_cost_layers', 'source_from') && !Schema::hasIndex('inventory_cost_layers', self::TRANCHE_UNIQUE)) {
            Schema::table('inventory_cost_layers', static function (Blueprint $table): void {
                $table->unique(['source_type', 'source_id', 'source_from'], self::TRANCHE_UNIQUE);
            });
        }
        if (Schema::hasColumn('operational_cost_invoice_items', 'purchase_order_item_id') && !Schema::hasIndex('operational_cost_invoice_items', self::INVOICE_ITEM_INDEX)) {
            Schema::table('operational_cost_invoice_items', static function (Blueprint $table): void {
                $table->index('purchase_order_item_id', self::INVOICE_ITEM_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_cost_layers') && Schema::hasIndex('inventory_cost_layers', self::TRANCHE_UNIQUE)) {
            Schema::table('inventory_cost_layers', static function (Blueprint $table): void {
                $table->dropUnique(self::TRANCHE_UNIQUE);
            });
        }
        if (Schema::hasTable('operational_cost_invoice_items') && Schema::hasIndex('operational_cost_invoice_items', self::INVOICE_ITEM_INDEX)) {
            Schema::table('operational_cost_invoice_items', static function (Blueprint $table): void {
                $table->dropIndex(self::INVOICE_ITEM_INDEX);
            });
        }

        $this->dropColumn('operational_cost_invoice_items', 'resembles_purchase_order_item_id');
        $this->dropColumn('operational_cost_invoice_items', 'purchase_order_item_id');
        $this->dropColumn('operational_cost_invoices', 'supplier_id');
        $this->dropColumn('inventory_cost_layers', 'received_at_basis');
        $this->dropColumn('inventory_cost_layers', 'supplier_id');
        $this->dropColumn('inventory_cost_layers', 'source_to');
        $this->dropColumn('inventory_cost_layers', 'source_from');
    }

    /** @param callable(Blueprint): mixed $definition */
    private function addColumn(string $table, string $column, callable $definition): void
    {
        if (Schema::hasTable($table) && !Schema::hasColumn($table, $column)) {
            Schema::table($table, static function (Blueprint $blueprint) use ($definition): void {
                $definition($blueprint);
            });
        }
    }

    private function dropColumn(string $table, string $column): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            Schema::table($table, static function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropColumn($column);
            });
        }
    }
};
