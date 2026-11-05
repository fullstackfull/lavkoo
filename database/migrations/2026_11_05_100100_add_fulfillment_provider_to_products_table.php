<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product-level fulfillment owner.
 *
 * NULL means "in-house / default": the product keeps the platform's existing behaviour, which is what
 * every existing row gets. Only 'm5azn' and 'torod_point' are meaningful, and they are only ever set
 * through ProductProviderAssignmentService (which validates the mapping and writes the audit trail) —
 * the column is deliberately not mass-assignable.
 *
 * Nullable, no default, no backfill: nothing about existing rows changes. Rolling back drops one
 * column and loses no store data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'fulfillment_provider')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('fulfillment_provider', 32)->nullable()->index('products_fulfillment_provider_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'fulfillment_provider')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_fulfillment_provider_idx');
                $table->dropColumn('fulfillment_provider');
            });
        }
    }
};
