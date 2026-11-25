<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tax that was already inside a tax-included order's price (D1-07).
 *
 * A store running the tax module in `is_included` mode records no tax on its orders at all: `total_tax_amount` is 0, every line's `tax` is
 * 0 and `order_taxes` has no row, while `tax_model` says `include`. These two columns are where that figure goes.
 *
 * Columns of their own rather than the existing ones, because the included tax is NOT a charge on top: `total_tax_amount` is added to the
 * order total by every reader that recomputes it, so putting the included tax there would inflate orders that were never charged it.
 *
 * Nullable with no default, and empty for every row that exists: an order placed before this says nothing about the tax inside it rather
 * than claiming zero, which is exactly the claim this column exists to stop being made. Nothing writes either column while
 * `gcc.tax.record_included_amount` is off, which is how the platform ships.
 *
 * Twelve decimals, like `orders.total_tax_amount`, so that the third decimal of a dinar and the share a line takes of it both survive.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'included_tax_amount')) {
            Schema::table('orders', fn (Blueprint $table) => $table->decimal('included_tax_amount', 18, 12)->nullable());
        }

        if (Schema::hasTable('order_details') && !Schema::hasColumn('order_details', 'included_tax')) {
            Schema::table('order_details', fn (Blueprint $table) => $table->decimal('included_tax', 18, 12)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'included_tax_amount')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('included_tax_amount'));
        }

        if (Schema::hasColumn('order_details', 'included_tax')) {
            Schema::table('order_details', fn (Blueprint $table) => $table->dropColumn('included_tax'));
        }
    }
};
