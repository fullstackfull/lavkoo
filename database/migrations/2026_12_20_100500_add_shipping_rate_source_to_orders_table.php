<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which rule produced an order's shipping charge (H1-08).
 *
 * The order kept the figure and nothing about where it came from, so a charge could not be explained afterwards: a zone's rate and a
 * shipping method's own cost are different decisions that land in the same column, and once the zones change nobody can tell which one a
 * past order was charged under. `zone:{id}` or `method:{id}`, written when the order is placed and never recomputed.
 *
 * The cart carries it too, because that is where the charge is decided -- when the customer chooses a method, before an order exists.
 *
 * Both nullable and empty for every row that exists: an order placed before this says nothing about its rate rather than claiming one.
 * The order's currency is already its own (`orders.currency_code`, D1-06), so this is the other half of the same question.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'shipping_rate_source')) {
            Schema::table('orders', fn (Blueprint $table) => $table->string('shipping_rate_source', 64)->nullable());
        }

        if (Schema::hasTable('cart_shippings') && !Schema::hasColumn('cart_shippings', 'rate_source')) {
            Schema::table('cart_shippings', fn (Blueprint $table) => $table->string('rate_source', 64)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'shipping_rate_source')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('shipping_rate_source'));
        }

        if (Schema::hasColumn('cart_shippings', 'rate_source')) {
            Schema::table('cart_shippings', fn (Blueprint $table) => $table->dropColumn('rate_source'));
        }
    }
};
