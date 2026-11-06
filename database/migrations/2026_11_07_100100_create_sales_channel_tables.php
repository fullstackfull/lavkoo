<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales channels (Amazon, Trendyol, and whatever comes next): connected accounts, the listings they hold, the orders
 * they send, and the bookkeeping of every synchronisation.
 *
 * Additive only, guarded so a re-run is a no-op, and no foreign key points at the platform's own tables (see the
 * fulfillment migration for why). An external order never needs a row in `orders` to be recorded: `external_order_mappings`
 * is its own record, and `order_id` is filled only if the application itself is made responsible for fulfilling it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales_channel_accounts')) {
            Schema::create('sales_channel_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('channel', 24)->index();
                $table->string('name', 120);
                // 0 = the platform's own account; a seller id isolates the account (and everything it produces) to that seller.
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                $table->string('environment', 16)->default('production');
                $table->string('market', 32)->nullable();
                // The seller / supplier id the channel knows this account by. Unique per channel and environment once known.
                $table->string('external_account_id', 64)->nullable();
                $table->json('marketplace_ids')->nullable();
                $table->string('currency', 8)->nullable();
                // Everything ships off. `enabled` is the master switch of the account; `write_enabled` the separate one for outbound writes.
                $table->boolean('enabled')->default(false)->index();
                $table->boolean('write_enabled')->default(false);
                $table->boolean('dry_run')->default(true);
                $table->json('capabilities')->nullable();
                $table->json('capability_approvals')->nullable();
                $table->string('execution_policy', 24)->default('manual_review');
                $table->json('settings')->nullable();
                // Crypt::encryptString(json). Never logged, never rendered, never in a response.
                $table->text('credentials')->nullable();
                $table->string('connection_status', 24)->default('untested')->index();
                $table->timestamp('connection_checked_at')->nullable();
                $table->string('connection_message', 500)->nullable();
                $table->json('granted_permissions')->nullable();
                $table->json('missing_permissions')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->string('last_error', 500)->nullable();
                // The accounting system this account's figures are destined for (the internal books today; Odoo later).
                $table->string('accounting_destination', 24)->default('internal');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['channel', 'environment', 'external_account_id'], 'sca_channel_env_external_unique');
            });
        }

        if (!Schema::hasTable('sales_channel_product_mappings')) {
            Schema::create('sales_channel_product_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                // NULL until a person (or an explicit rule) links the listing to one of our products.
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('variant_key', 191)->default('');
                // The channel's own key for the listing: Amazon seller SKU, Trendyol barcode. Unique per account.
                $table->string('listing_key', 191);
                $table->string('external_sku', 191)->nullable()->index();
                $table->string('external_id', 120)->nullable()->index();
                $table->string('external_barcode', 191)->nullable()->index();
                $table->string('external_parent_id', 120)->nullable();
                $table->string('external_title', 500)->nullable();
                // fba | fbm for Amazon listings; NULL where the channel has no such notion.
                $table->string('fulfillment_mode', 16)->nullable();
                $table->string('status', 16)->default('unmapped')->index();
                $table->string('match_method', 24)->nullable();
                $table->unsignedBigInteger('suggested_product_id')->nullable();
                $table->unsignedBigInteger('mapped_by')->nullable();
                $table->timestamp('mapped_at')->nullable();
                // Where this listing's stock and price come from, and whether we may publish them. Both publish switches default to off.
                $table->unsignedBigInteger('stock_location_id')->nullable()->index();
                $table->string('price_source', 16)->default('internal');
                $table->boolean('inventory_publish')->default(false);
                $table->boolean('price_publish')->default(false);
                $table->unsignedInteger('safety_buffer')->nullable();
                $table->unsignedInteger('max_publish_quantity')->nullable();
                $table->decimal('remote_price', 20, 6)->nullable();
                $table->string('remote_currency', 8)->nullable();
                $table->integer('remote_quantity')->nullable();
                $table->string('remote_status', 48)->nullable();
                $table->timestamp('remote_updated_at')->nullable();
                // What we last pushed: a push that would repeat it is skipped, and a value read back is never pushed again (no loops).
                $table->decimal('last_published_price', 20, 6)->nullable();
                $table->integer('last_published_quantity')->nullable();
                $table->timestamp('last_published_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->string('payload_hash', 64)->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamps();

                $table->unique(['account_id', 'listing_key'], 'scpm_account_listing_unique');
                $table->index(['account_id', 'status'], 'scpm_account_status_idx');
                $table->index(['product_id', 'variant_key'], 'scpm_product_variant_idx');
            });
        }

        if (!Schema::hasTable('external_order_mappings')) {
            Schema::create('external_order_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->string('sales_channel', 24)->index();
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // Amazon order id / Trendyol order number, and Trendyol's shipment package id ('' where the channel has none).
                $table->string('external_order_id', 64);
                $table->string('external_package_id', 64)->default('');
                $table->string('external_status', 48)->nullable();
                $table->string('status', 24)->default('new')->index();
                $table->string('fulfillment_mode', 24)->nullable()->index();
                $table->string('fulfillment_channel_raw', 24)->nullable();
                // managed | externally_managed | manual_review, and the reason a person is needed when it is the last.
                $table->string('execution_policy', 24)->nullable();
                $table->string('review_reason', 120)->nullable();
                // Filled ONLY when the application becomes responsible for fulfilling the order (an internal order is created then).
                $table->unsignedBigInteger('order_id')->nullable()->unique();
                $table->string('provider', 32)->nullable();
                // An externally managed order's shipment: found at the provider, followed, never created by us.
                $table->string('shipment_link_state', 24)->nullable();
                $table->string('shipment_reference', 120)->nullable();
                $table->string('shipment_status', 100)->nullable();
                $table->string('tracking_number', 120)->nullable();
                $table->string('tracking_url', 500)->nullable();
                $table->string('carrier', 120)->nullable();
                $table->timestamp('shipment_checked_at')->nullable();
                $table->string('currency', 8)->nullable();
                $table->decimal('items_total', 20, 6)->nullable();
                $table->decimal('discount_total', 20, 6)->nullable();
                $table->decimal('shipping_total', 20, 6)->nullable();
                $table->decimal('tax_total', 20, 6)->nullable();
                $table->decimal('order_total', 20, 6)->nullable();
                $table->timestamp('purchased_at')->nullable()->index();
                $table->timestamp('external_updated_at', 3)->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('payload_hash', 64)->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();

                $table->unique(['account_id', 'external_order_id', 'external_package_id'], 'eom_account_order_package_unique');
                $table->index(['sales_channel', 'status'], 'eom_channel_status_idx');
                $table->index(['provider', 'shipment_reference'], 'eom_provider_reference_idx');
            });
        }

        if (!Schema::hasTable('external_order_lines')) {
            Schema::create('external_order_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('mapping_id')->index();
                $table->string('external_line_id', 64);
                $table->string('sku', 191)->nullable()->index();
                $table->string('barcode', 191)->nullable();
                $table->string('external_product_id', 120)->nullable();
                $table->string('title', 500)->nullable();
                $table->unsignedInteger('quantity');
                $table->unsignedInteger('quantity_cancelled')->default(0);
                $table->unsignedInteger('quantity_returned')->default(0);
                $table->string('currency', 8)->nullable();
                $table->decimal('unit_price', 20, 6)->nullable();
                $table->decimal('line_total', 20, 6)->nullable();
                $table->decimal('discount_total', 20, 6)->nullable();
                $table->decimal('tax_total', 20, 6)->nullable();
                $table->decimal('shipping_total', 20, 6)->nullable();
                $table->decimal('weight_kg', 10, 3)->nullable();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('variant_key', 191)->default('');
                $table->unsignedBigInteger('product_mapping_id')->nullable();
                $table->unsignedBigInteger('inventory_location_id')->nullable();
                $table->string('provider', 32)->nullable();
                $table->string('status', 24)->default('open');
                $table->json('snapshot')->nullable();
                $table->timestamps();

                $table->unique(['mapping_id', 'external_line_id'], 'eol_mapping_line_unique');
            });
        }

        if (!Schema::hasTable('sales_channel_sync_states')) {
            Schema::create('sales_channel_sync_states', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                // connection | listings | orders | inventory | prices | finances | notifications | shipments
                $table->string('resource', 40);
                $table->json('cursor')->nullable();
                // Incremental sync reads "changed after" this instant, less an overlap, and only then moves it forward.
                $table->timestamp('watermark', 3)->nullable();
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->text('last_error')->nullable();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('backoff_until')->nullable();
                // One run per account and resource at a time; a crashed run loses the lease by itself.
                $table->string('lease_token', 64)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->json('backfill')->nullable();
                $table->json('stats')->nullable();
                $table->timestamps();

                $table->unique(['account_id', 'resource'], 'scss_account_resource_unique');
            });
        }

        if (!Schema::hasTable('sales_channel_api_logs')) {
            Schema::create('sales_channel_api_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->string('channel', 24)->index();
                $table->string('operation', 60)->index();
                $table->string('correlation_id', 40)->index();
                $table->string('method', 8);
                $table->string('url', 500);
                $table->json('request_summary')->nullable();
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->text('response_summary')->nullable();
                $table->string('request_id', 120)->nullable();
                $table->boolean('success')->default(false)->index();
                $table->boolean('dry_run')->default(false);
                $table->string('error_class', 120)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (!Schema::hasTable('sales_channel_events')) {
            Schema::create('sales_channel_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id')->nullable()->index();
                $table->string('channel', 24)->index();
                // webhook | notification | poll | system | manual
                $table->string('source', 16);
                $table->string('event_type', 60);
                // A webhook the channel delivers twice, or a notification redelivered by a queue, maps to the same key and is stored once.
                $table->string('dedupe_key', 64)->unique();
                $table->string('external_reference', 191)->nullable()->index();
                $table->json('payload')->nullable();
                $table->string('outcome', 24)->default('received')->index();
                $table->string('outcome_note', 255)->nullable();
                $table->timestamp('occurred_at', 3)->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'sales_channel_events', 'sales_channel_api_logs', 'sales_channel_sync_states', 'external_order_lines',
            'external_order_mappings', 'sales_channel_product_mappings', 'sales_channel_accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
