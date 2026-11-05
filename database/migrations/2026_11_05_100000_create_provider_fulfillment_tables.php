<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-provider fulfillment (M5AZN, Torod Point).
 *
 * Additive only: every statement creates a NEW table, guarded so a re-run (or an environment that
 * already has one) is a no-op. Nothing here alters or drops an existing table, and no foreign key points
 * at the core store tables — those come from the installation dump, not from migrations, so their
 * engine and key types cannot be assumed. Referential integrity to `orders` / `products` is by indexed
 * id, the same convention the existing Phase 3 tables use.
 *
 * `provider_fulfillments` is the provider-side child of a customer's parent order. It is NOT the same
 * thing as `order_fulfillments`, the in-house pick/pack overlay that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The Chatwoot integration already reads and writes this table through the IntegrationSetting
        // model, but no migration ever created it (it came with the installation dump). Created here only
        // if absent, with exactly the columns the model declares, so a database that has it is untouched.
        if (!Schema::hasTable('integration_settings')) {
            Schema::create('integration_settings', function (Blueprint $table) {
                $table->id();
                $table->string('provider')->index();
                $table->string('name')->nullable();
                $table->boolean('enabled')->default(false);
                $table->string('base_url')->nullable();
                $table->text('credentials')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('provider_fulfillments')) {
            Schema::create('provider_fulfillments', function (Blueprint $table) {
                $table->id();
                // Our own reference, also sent to the provider as its order/invoice number. Unique per
                // (order, provider, generation) by construction: "{order_id}-{MZ|TP}{generation}".
                $table->string('reference', 40)->unique();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('provider', 32)->index();
                $table->unsignedSmallInteger('generation')->default(1);
                // sha256 of order + provider + generation + the exact set of items. Same logical
                // request ⇒ same key ⇒ the database refuses a second row.
                $table->string('idempotency_key', 64)->unique();

                $table->string('status', 32)->default('pending')->index();
                $table->string('hold_reason', 64)->nullable();

                $table->string('payment_mode', 16)->default('prepaid');
                $table->decimal('cod_amount', 14, 2)->default(0);
                $table->string('currency', 8)->default('SAR');
                $table->decimal('items_total', 14, 2)->default(0);
                $table->decimal('shipping_allocation', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);

                $table->string('provider_reference', 120)->nullable();
                $table->string('provider_internal_id', 120)->nullable();
                $table->string('provider_secondary_reference', 120)->nullable();
                $table->string('tracking_number', 120)->nullable();
                $table->string('tracking_url', 500)->nullable();
                $table->string('provider_status', 100)->nullable();
                $table->string('warehouse_status', 32)->nullable();
                $table->decimal('provider_cost', 14, 2)->nullable();

                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('reconcile_attempts')->default(0);
                $table->string('claim_token', 64)->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('next_attempt_at')->nullable()->index();
                $table->string('last_error_code', 64)->nullable();
                $table->text('last_error_message')->nullable();

                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('returned_at')->nullable();
                $table->timestamp('failed_at')->nullable();

                // Provider-side time of the newest event applied, for stale/out-of-order protection.
                $table->timestamp('last_event_at', 3)->nullable();
                $table->timestamp('last_received_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();

                $table->json('meta')->nullable();
                $table->string('created_by_type', 16)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['order_id', 'provider', 'generation'], 'pf_order_provider_generation_unique');
                $table->unique(['provider', 'provider_reference'], 'pf_provider_reference_unique');
                $table->index(['provider', 'status'], 'pf_provider_status_idx');
            });
        }

        if (!Schema::hasTable('provider_fulfillment_items')) {
            Schema::create('provider_fulfillment_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('provider_fulfillment_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->index();
                // One snapshot per order line, enforced by the database: a line cannot be owned twice.
                $table->unsignedBigInteger('order_detail_id')->unique();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('variant_key', 191)->default('');

                // Immutable ownership snapshot, taken inside the checkout transaction. Routing reads
                // THIS, never the product's current provider, so reassigning a product later cannot
                // move an order that is already placed.
                $table->string('provider', 32)->index();
                $table->unsignedBigInteger('mapping_id')->nullable();
                $table->string('external_product_id', 120)->nullable();
                $table->string('external_sku', 191)->nullable();
                $table->string('external_barcode', 191)->nullable();

                $table->string('sku', 191)->nullable();
                $table->string('name', 255);
                $table->unsignedInteger('quantity');
                $table->decimal('unit_price', 20, 6)->default(0);
                $table->decimal('line_discount', 20, 6)->default(0);
                $table->decimal('line_tax', 20, 6)->default(0);
                // The customer-facing line value in the provider currency, after discount, tax-inclusive
                // as charged. Sums of these drive COD allocation.
                $table->decimal('line_total', 14, 2)->default(0);
                $table->decimal('unit_cost', 14, 4)->nullable();
                $table->decimal('weight_kg', 10, 3)->nullable();

                $table->string('status', 16)->default('unrouted')->index();
                $table->json('snapshot')->nullable();
                $table->timestamps();

                $table->index(['order_id', 'provider', 'status'], 'pfi_order_provider_status_idx');
            });
        }

        if (!Schema::hasTable('provider_fulfillment_events')) {
            Schema::create('provider_fulfillment_events', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32)->index();
                $table->string('source', 16);
                $table->string('event_type', 40);
                // sha256 over the identifying parts of an inbound event. A webhook the provider sends
                // twice (they retry) maps to the same key and is stored exactly once.
                $table->string('dedupe_key', 64)->nullable()->unique();
                $table->unsignedBigInteger('provider_fulfillment_id')->nullable()->index();
                $table->string('external_reference', 120)->nullable()->index();
                $table->string('provider_status', 100)->nullable();
                $table->string('internal_status', 32)->nullable();
                $table->timestamp('occurred_at', 3)->nullable();
                $table->timestamp('received_at')->nullable();
                $table->string('outcome', 32)->default('received')->index();
                $table->string('outcome_note', 255)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('provider_api_logs')) {
            Schema::create('provider_api_logs', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32)->index();
                $table->string('operation', 60)->index();
                $table->unsignedBigInteger('provider_fulfillment_id')->nullable()->index();
                $table->string('correlation_id', 40)->index();
                $table->string('method', 8);
                $table->string('url', 500);
                $table->json('request_summary')->nullable();
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->text('response_summary')->nullable();
                $table->boolean('success')->default(false)->index();
                $table->string('error_class', 120)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (!Schema::hasTable('provider_sync_states')) {
            Schema::create('provider_sync_states', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('capability', 40);
                $table->timestamp('last_received_at')->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->timestamp('last_reconciled_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('cursor')->nullable();
                $table->json('stats')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'capability'], 'provider_sync_states_unique');
            });
        }

        if (!Schema::hasTable('provider_product_mappings')) {
            Schema::create('provider_product_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->unsignedBigInteger('product_id')->index();
                // The product's own variation key (order_details.variant); '' means the whole product.
                $table->string('variant_key', 191)->default('');
                $table->string('external_id', 120);
                $table->string('external_variant_id', 120)->default('');
                $table->string('external_sku', 191)->nullable();
                $table->string('external_barcode', 191)->nullable()->index();
                $table->string('external_name', 255)->nullable();

                $table->string('status', 16)->default('pending')->index();
                $table->string('stock_source', 16)->default('none');
                $table->integer('provider_stock')->nullable();
                $table->timestamp('provider_stock_synced_at')->nullable();
                $table->decimal('supplier_cost', 14, 4)->nullable();
                $table->string('supplier_cost_currency', 8)->nullable();
                $table->decimal('supplier_retail_price', 14, 4)->nullable();

                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('notes', 500)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                // An external item maps to exactly one store item, and a store item has one mapping
                // per provider. A barcode is NOT unique: the same barcode legitimately appears under
                // two providers, and matching on it alone must never decide ownership.
                $table->unique(['provider', 'external_id', 'external_variant_id'], 'ppm_provider_external_unique');
                $table->unique(['provider', 'product_id', 'variant_key'], 'ppm_provider_product_variant_unique');
            });
        }

        if (!Schema::hasTable('provider_catalog_items')) {
            Schema::create('provider_catalog_items', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('external_id', 120);
                $table->string('source', 16)->default('api');
                $table->json('payload');
                $table->string('payload_hash', 64);
                $table->string('name', 255)->nullable();
                $table->string('sku', 191)->nullable();
                $table->string('barcode', 191)->nullable();
                $table->integer('stock')->nullable();
                $table->decimal('cost', 14, 4)->nullable();
                $table->decimal('retail_price', 14, 4)->nullable();
                $table->string('status', 16)->default('new')->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->text('note')->nullable();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'external_id'], 'pci_provider_external_unique');
            });
        }

        if (!Schema::hasTable('provider_price_changes')) {
            Schema::create('provider_price_changes', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32)->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('mapping_id')->nullable();
                $table->string('field', 24);
                $table->decimal('old_value', 20, 6)->nullable();
                $table->decimal('new_value', 20, 6)->nullable();
                $table->string('source', 24);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('provider_cities')) {
            Schema::create('provider_cities', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('external_id', 32);
                $table->string('name_ar', 191)->nullable();
                $table->string('name_en', 191)->nullable();
                $table->string('region_external_id', 32)->nullable();
                $table->string('normalized_ar', 191)->nullable()->index();
                $table->string('normalized_en', 191)->nullable()->index();
                $table->json('raw')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'external_id'], 'provider_cities_unique');
            });
        }

        if (!Schema::hasTable('provider_city_aliases')) {
            Schema::create('provider_city_aliases', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('alias', 191);
                $table->string('external_city_id', 32);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'alias'], 'provider_city_aliases_unique');
            });
        }

        if (!Schema::hasTable('product_provider_changes')) {
            Schema::create('product_provider_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('from_provider', 32)->nullable();
                $table->string('to_provider', 32)->nullable();
                $table->string('actor_type', 16);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('reason', 255)->nullable();
                $table->json('context')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'product_provider_changes', 'provider_city_aliases', 'provider_cities', 'provider_price_changes',
            'provider_catalog_items', 'provider_product_mappings', 'provider_sync_states', 'provider_api_logs',
            'provider_fulfillment_events', 'provider_fulfillment_items', 'provider_fulfillments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
