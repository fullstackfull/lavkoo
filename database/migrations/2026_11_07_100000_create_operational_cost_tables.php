<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational costs: contracts and their price rules, the cost ledger (estimated / actual / reconciled / missing /
 * disputed entries), the allocation of shared charges to items, invoices with their lines, the reconciliation of
 * invoices against estimates, and the cost layers that give a sold unit its purchase cost.
 *
 * Additive only. Every statement creates a NEW table, guarded so a re-run is a no-op; nothing existing is altered or
 * dropped. Like the fulfillment tables, no foreign key points at the platform's own tables (`orders`, `products`:
 * they come from the installation dump, so their engine and key types cannot be assumed); integrity to them is by
 * indexed id. Money is DECIMAL(20,6), exchange rates DECIMAL(20,10); a cost that does not exist is NULL, never 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('operational_cost_contracts')) {
            Schema::create('operational_cost_contracts', function (Blueprint $table) {
                $table->id();
                // 0 = the platform's own books; a seller id isolates the row to that seller (never NULL, so unique keys that include it hold).
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                $table->string('party_type', 24)->index();
                // torod_point | m5azn | amazon | trendyol | a payment gateway code | free text for a new company.
                $table->string('provider_code', 64)->index();
                $table->string('party_name', 160);
                $table->string('reference', 120)->nullable();
                // Every version of one contract shares the id of its first version.
                $table->unsignedBigInteger('root_id')->nullable()->index();
                $table->unsignedInteger('version')->default(1);
                $table->string('currency', 8);
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->string('status', 16)->default('draft')->index();
                $table->text('notes')->nullable();
                // Contract-level policy: the services every shipment is expected to be charged for, the allocation basis per
                // service and the reconciliation tolerance. Figures, never defaults.
                $table->json('settings')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->unsignedBigInteger('activated_by')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['root_id', 'version'], 'occ_root_version_unique');
                $table->index(['provider_code', 'status', 'effective_from'], 'occ_provider_status_from_idx');
            });
        }

        if (!Schema::hasTable('operational_cost_contract_revisions')) {
            Schema::create('operational_cost_contract_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contract_id')->index();
                $table->string('action', 32);
                $table->string('actor_type', 16)->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('operational_cost_rules')) {
            Schema::create('operational_cost_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contract_id')->index();
                $table->string('service_type', 40)->index();
                $table->string('label', 160)->nullable();
                $table->string('calc_method', 24);
                // Amounts, percentages, tiers, zones and weight steps. Nothing is defaulted: a rule holds the figures a person typed.
                $table->json('params');
                // Narrowing conditions: cities, countries, payment methods, service levels, sales channels.
                $table->json('conditions')->nullable();
                $table->string('currency', 8)->nullable();
                $table->unsignedSmallInteger('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->timestamps();

                $table->index(['contract_id', 'service_type', 'is_active'], 'ocr_contract_service_active_idx');
            });
        }

        if (!Schema::hasTable('operational_cost_entries')) {
            Schema::create('operational_cost_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // One row per logical cost: the same computation repeated, retried or re-imported maps to the same key and is refused.
                $table->string('dedupe_key', 64)->unique();
                // One EFFECTIVE row per slot (subject + component + service): an actual cost supersedes the estimate of its slot.
                $table->string('slot_key', 64)->index();
                $table->string('subject_type', 32);
                $table->unsignedBigInteger('subject_id');
                $table->string('sales_channel', 24)->nullable()->index();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('external_order_mapping_id')->nullable()->index();
                $table->unsignedBigInteger('provider_fulfillment_id')->nullable()->index();
                $table->string('provider', 32)->nullable()->index();
                $table->unsignedBigInteger('inventory_location_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('variant_key', 191)->default('');
                $table->string('sku', 191)->nullable();
                $table->date('period_date')->index();
                $table->string('cost_component', 40)->index();
                $table->string('cost_class', 24)->index();
                $table->string('state', 16)->index();
                $table->boolean('is_effective')->default(true)->index();
                $table->unsignedBigInteger('superseded_by_id')->nullable();
                // NULL when the state is `missing`: a cost nobody could determine is not a free one.
                $table->decimal('amount', 20, 6)->nullable();
                $table->string('currency', 8);
                $table->decimal('fx_rate', 20, 10)->nullable();
                $table->decimal('base_amount', 20, 6)->nullable();
                $table->string('base_currency', 8)->nullable();
                $table->decimal('quantity', 20, 6)->nullable();
                $table->decimal('unit_amount', 20, 6)->nullable();
                $table->string('allocation_status', 16)->default('not_applicable');
                $table->string('source', 24);
                $table->unsignedBigInteger('contract_id')->nullable()->index();
                $table->unsignedInteger('contract_version')->nullable();
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->unsignedBigInteger('invoice_item_id')->nullable()->index();
                $table->string('external_reference', 191)->nullable()->index();
                $table->json('calc_inputs')->nullable();
                $table->string('missing_reason', 120)->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id'], 'oce_subject_idx');
                $table->index(['slot_key', 'is_effective'], 'oce_slot_effective_idx');
                $table->index(['period_date', 'cost_component'], 'oce_period_component_idx');
                $table->index(['sales_channel', 'period_date'], 'oce_channel_period_idx');
                $table->index(['provider', 'period_date'], 'oce_provider_period_idx');
            });
        }

        if (!Schema::hasTable('operational_cost_allocations')) {
            Schema::create('operational_cost_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entry_id')->index();
                // order_line | channel_order_line | shipment_item | product
                $table->string('target_type', 24);
                $table->unsignedBigInteger('target_id');
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('variant_key', 191)->default('');
                $table->string('basis', 16);
                $table->decimal('basis_value', 20, 6);
                $table->decimal('share', 20, 10);
                $table->decimal('amount', 20, 6);
                $table->string('currency', 8);
                $table->timestamps();

                $table->unique(['entry_id', 'target_type', 'target_id'], 'oca_entry_target_unique');
                $table->index(['target_type', 'target_id'], 'oca_target_idx');
            });
        }

        if (!Schema::hasTable('operational_cost_invoices')) {
            Schema::create('operational_cost_invoices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // service | purchase | marketplace_statement
                $table->string('kind', 24)->index();
                $table->string('party_type', 24);
                $table->string('provider_code', 64)->index();
                $table->string('party_name', 160);
                $table->string('invoice_number', 120);
                $table->date('invoice_date');
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->string('currency', 8);
                $table->decimal('subtotal', 20, 6)->nullable();
                $table->decimal('tax_total', 20, 6)->nullable();
                $table->decimal('total', 20, 6);
                $table->unsignedBigInteger('inventory_location_id')->nullable();
                $table->string('status', 16)->default('draft')->index();
                $table->string('source', 16);
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->string('file_path', 500)->nullable();
                $table->string('file_hash', 64)->nullable()->index();
                $table->unsignedBigInteger('duplicate_of_id')->nullable();
                $table->string('import_batch', 64)->nullable()->index();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                // The same company never has two invoices with one number, whatever the file it arrived in.
                $table->unique(['provider_code', 'invoice_number', 'seller_id'], 'oci_provider_number_seller_unique');
            });
        }

        if (!Schema::hasTable('operational_cost_invoice_items')) {
            Schema::create('operational_cost_invoice_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->unsignedInteger('line_no');
                $table->string('dedupe_key', 64)->unique();
                $table->string('description', 500)->nullable();
                $table->string('cost_component', 40);
                $table->decimal('quantity', 20, 6)->nullable();
                $table->decimal('unit_price', 20, 6)->nullable();
                $table->decimal('amount', 20, 6);
                $table->decimal('tax_amount', 20, 6)->nullable();
                $table->string('currency', 8);
                // The link a purchase line needs to become a cost layer.
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('variant_key', 191)->default('');
                $table->string('sku', 191)->nullable();
                // What the line is about: an order, a shipment, a channel order, a period or nothing in particular.
                $table->string('reference_type', 24)->nullable();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('provider_fulfillment_id')->nullable()->index();
                $table->unsignedBigInteger('external_order_mapping_id')->nullable()->index();
                $table->string('external_reference', 191)->nullable()->index();
                $table->date('service_date')->nullable();
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->string('match_status', 16)->default('unmatched')->index();
                $table->unsignedBigInteger('entry_id')->nullable();
                $table->json('raw')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('operational_cost_reconciliations')) {
            Schema::create('operational_cost_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('invoice_item_id')->nullable()->unique();
                $table->unsignedBigInteger('estimated_entry_id')->nullable()->index();
                $table->unsignedBigInteger('actual_entry_id')->nullable()->index();
                $table->string('status', 24)->index();
                $table->decimal('estimated_amount', 20, 6)->nullable();
                $table->decimal('actual_amount', 20, 6)->nullable();
                $table->decimal('variance_amount', 20, 6)->nullable();
                $table->decimal('variance_percent', 12, 4)->nullable();
                $table->decimal('tolerance_percent', 12, 4)->nullable();
                $table->string('currency', 8);
                $table->string('resolution', 24)->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('inventory_cost_layers')) {
            Schema::create('inventory_cost_layers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                $table->unsignedBigInteger('inventory_location_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('variant_key', 191)->default('');
                $table->string('sku', 191)->nullable();
                // purchase_invoice | opening_balance | transfer_in | return | adjustment
                $table->string('source_type', 24);
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('source_ref', 120)->nullable();
                $table->string('dedupe_key', 64)->unique();
                $table->timestamp('received_at')->index();
                $table->unsignedInteger('quantity_received');
                $table->unsignedInteger('quantity_remaining');
                $table->decimal('unit_cost', 20, 6);
                // Costs added to the stock (inbound freight, import duty) when policy says they are part of its cost.
                $table->decimal('landed_unit_cost', 20, 6)->default(0);
                $table->string('currency', 8);
                $table->string('supplier_name', 160)->nullable();
                $table->string('status', 12)->default('open');
                $table->timestamps();

                // FIFO order lookup: oldest open layer of a product at a location.
                $table->index(['inventory_location_id', 'product_id', 'variant_key', 'status', 'received_at'], 'icl_fifo_idx');
            });
        }

        if (!Schema::hasTable('inventory_cost_consumptions')) {
            Schema::create('inventory_cost_consumptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('layer_id')->index();
                // order_line | channel_order_line | transfer | write_off
                $table->string('consumer_type', 24);
                $table->unsignedBigInteger('consumer_id');
                $table->unsignedInteger('quantity');
                $table->decimal('unit_cost', 20, 6);
                $table->decimal('landed_unit_cost', 20, 6)->default(0);
                $table->decimal('total_cost', 20, 6);
                $table->string('currency', 8);
                $table->timestamp('consumed_at');
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('entry_id')->nullable();
                $table->timestamps();

                // A sold line takes from a given layer once, however many times the recognition job runs.
                $table->unique(['consumer_type', 'consumer_id', 'layer_id'], 'icc_consumer_layer_unique');
                $table->index(['consumer_type', 'consumer_id'], 'icc_consumer_idx');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'inventory_cost_consumptions', 'inventory_cost_layers', 'operational_cost_reconciliations',
            'operational_cost_invoice_items', 'operational_cost_invoices', 'operational_cost_allocations',
            'operational_cost_entries', 'operational_cost_rules', 'operational_cost_contract_revisions',
            'operational_cost_contracts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
