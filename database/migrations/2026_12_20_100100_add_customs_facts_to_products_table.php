<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customs officer asks about a product (H1-03, read by H1-09): its tariff code, where it was made, and what it weighs.
 *
 * None of the three existed on a product. `weight_kg` exists only on provider and channel items, which are a supplier's numbers about their
 * own copy of a product, not the platform's record of the thing itself -- and a duty is charged on the thing itself.
 *
 * All three are nullable and all three are empty after this runs, so nothing reads differently: a shipment that needs them is a shipment
 * across a border, and a product with no permission row cannot make one (H1-03). The columns are appended without a position, so MySQL 8
 * and MariaDB 11.4 add them without rebuilding the table.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'hs_code')) {
            Schema::table('products', function (Blueprint $table): void {
                // The Harmonised System tariff code, as a string: it has leading zeros and is never arithmetic.
                $table->string('hs_code', 16)->nullable();
            });
        }

        if (!Schema::hasColumn('products', 'origin_country')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('origin_country', 2)->nullable();
            });
        }

        if (!Schema::hasColumn('products', 'weight_grams')) {
            Schema::table('products', function (Blueprint $table): void {
                // Grams, as a whole number: a carrier's rate table is banded in grams, and a float weight rounds a parcel into another band.
                $table->unsignedInteger('weight_grams')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['hs_code', 'origin_country', 'weight_grams'] as $column) {
            if (Schema::hasColumn('products', $column)) {
                Schema::table('products', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
