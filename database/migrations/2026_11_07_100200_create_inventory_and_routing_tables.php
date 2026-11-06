<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where stock lives (inventory locations and their independent balances), who decides what is published from where
 * (source policies), what is promised to open orders (reservations), and how an order is routed (rules and the
 * decisions taken, with the reason).
 *
 * Additive only, guarded, no foreign key to the platform's own tables. Provider stock (Torod Point, M5AZN) is NOT
 * copied here: it stays in `provider_product_mappings`, which is where its verification status lives, and is read from
 * there. `inventory_location_balances` holds the stock whose source of truth is somewhere else (Amazon FBA) or a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_locations')) {
            Schema::create('inventory_locations', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name', 160);
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // torod_warehouse | m5azn_supplier | amazon_fba | own_warehouse | other
                $table->string('type', 24)->index();
                $table->string('provider', 32)->nullable();
                $table->unsignedBigInteger('sales_channel_account_id')->nullable()->index();
                $table->string('marketplace_id', 32)->nullable();
                // provider | channel | manual: who is the reference for the quantity held here.
                $table->string('source_of_truth', 16);
                // Whether the storefront may sell from it. Amazon FBA stock is Amazon's: never.
                $table->boolean('storefront_sellable')->default(false);
                // Which sales channels may be shown a quantity taken from here (storefront, trendyol, amazon_fbm...).
                $table->json('publishable_channels')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('inventory_location_balances')) {
            Schema::create('inventory_location_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id')->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('variant_key', 191)->default('');
                $table->string('sku', 191);
                $table->integer('available')->nullable();
                $table->integer('reserved')->nullable();
                $table->integer('unfulfillable')->nullable();
                $table->integer('inbound')->nullable();
                $table->integer('total')->nullable();
                // confirmed | manual | stale | unknown. Only `confirmed` and `manual` may be published; an estimate is never stored as stock.
                $table->string('quality', 16)->default('unknown');
                $table->timestamp('source_synced_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['location_id', 'sku', 'variant_key'], 'ilb_location_sku_variant_unique');
            });
        }

        if (!Schema::hasTable('inventory_source_policies')) {
            Schema::create('inventory_source_policies', function (Blueprint $table) {
                $table->id();
                // account: the default of one account; product: one product in one account.
                $table->string('scope', 16);
                $table->unsignedBigInteger('account_id')->index();
                $table->unsignedBigInteger('product_id')->default(0);
                $table->string('variant_key', 191)->default('');
                $table->unsignedBigInteger('stock_location_id')->nullable();
                $table->string('price_source', 16)->nullable();
                // off | push. Reading a channel's quantity never writes ours, so there is no "pull" setting to misconfigure into a loop.
                $table->string('inventory_publish_mode', 8)->default('off');
                $table->string('price_publish_mode', 8)->default('off');
                $table->unsignedInteger('safety_buffer')->nullable();
                $table->unsignedInteger('max_publish_quantity')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['account_id', 'product_id', 'variant_key'], 'isp_account_product_variant_unique');
            });
        }

        if (!Schema::hasTable('inventory_reservations')) {
            Schema::create('inventory_reservations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('variant_key', 191)->default('');
                $table->string('sku', 191)->nullable();
                $table->unsignedInteger('quantity');
                // One reservation per order line, however often the order is seen again.
                $table->string('reservation_key', 191)->unique();
                // reserved | committed | released
                $table->string('state', 16)->index();
                $table->unsignedBigInteger('external_order_mapping_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->timestamp('reserved_at');
                $table->timestamp('committed_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->string('release_reason', 120)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('fulfillment_routing_rules')) {
            Schema::create('fulfillment_routing_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->unsignedBigInteger('seller_id')->default(0)->index();
                // Lower runs first. Among equal priorities the more specific rule wins, then the older.
                $table->unsignedInteger('priority')->default(100)->index();
                $table->boolean('is_active')->default(true)->index();
                // ---- what the rule matches (NULL = anything) ----
                $table->string('sales_channel', 24)->nullable();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->string('fulfillment_mode', 24)->nullable();
                $table->string('execution_policy', 24)->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('sku', 191)->nullable();
                $table->unsignedBigInteger('inventory_location_id')->nullable();
                $table->string('product_provider', 32)->nullable();
                $table->string('country', 8)->nullable();
                $table->string('city', 120)->nullable();
                $table->boolean('requires_confirmed_stock')->default(false);
                $table->json('conditions')->nullable();
                // ---- what it decides ----
                // route_to_provider | monitor_only | channel_fulfills | manual_review | hold
                $table->string('action', 24);
                $table->string('target_provider', 32)->nullable();
                $table->unsignedBigInteger('target_location_id')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_to')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'priority'], 'frr_active_priority_idx');
            });
        }

        if (!Schema::hasTable('fulfillment_routing_decisions')) {
            Schema::create('fulfillment_routing_decisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('external_order_mapping_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('sales_channel', 24)->index();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->string('fulfillment_mode', 24)->nullable();
                // route_to_provider | monitor_only | channel_fulfills | manual_review | hold
                $table->string('action', 24)->index();
                // managed | externally_managed | manual_review
                $table->string('execution_mode', 24);
                $table->string('provider', 32)->nullable();
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->json('rule_snapshot')->nullable();
                $table->string('reason_code', 64);
                $table->string('reason', 500);
                $table->json('inputs')->nullable();
                $table->json('line_decisions')->nullable();
                $table->unsignedInteger('version')->default(1);
                // Exactly one current decision per order; an earlier one stays as history.
                $table->boolean('is_current')->default(true);
                $table->boolean('applied')->default(false);
                $table->timestamp('applied_at')->nullable();
                $table->string('apply_result', 64)->nullable();
                $table->string('decided_by', 16)->default('system');
                $table->unsignedBigInteger('decided_by_id')->nullable();
                $table->timestamps();

                $table->unique(['external_order_mapping_id', 'version'], 'frd_mapping_version_unique');
                $table->index(['order_id', 'version'], 'frd_order_version_idx');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'fulfillment_routing_decisions', 'fulfillment_routing_rules', 'inventory_reservations',
            'inventory_source_policies', 'inventory_location_balances', 'inventory_locations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
