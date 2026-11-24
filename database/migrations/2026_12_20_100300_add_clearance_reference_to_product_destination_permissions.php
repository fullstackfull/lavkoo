<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authority a permission rests on (H1-04).
 *
 * H1-03 records that a product MAY reach a country. For a regulated product -- a medicine, a supplement, a medical device -- that is not
 * enough: somebody has to be able to say on whose authority, and point at the licence, the decision or the file. So the reference lives on
 * the permission row rather than in a second table, because one place saying what a product may do and on what authority is one place to
 * read and one place to withdraw.
 *
 * Nullable, and empty for every row that exists: an ordinary product needs no licence to cross a border, and the gate asks for a reference
 * only where the owner's lists say the product is regulated. A reference without a person who recorded it is not a clearance either, which
 * is why `recorded_by` was there from the start.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('product_destination_permissions') || Schema::hasColumn('product_destination_permissions', 'clearance_reference')) {
            return;
        }

        Schema::table('product_destination_permissions', function (Blueprint $table): void {
            // The licence number, the decision reference, the file: whatever a person can be asked to produce later.
            $table->string('clearance_reference', 191)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_destination_permissions', 'clearance_reference')) {
            Schema::table('product_destination_permissions', fn (Blueprint $table) => $table->dropColumn('clearance_reference'));
        }
    }
};
