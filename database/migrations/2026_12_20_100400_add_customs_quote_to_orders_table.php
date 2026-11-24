<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was quoted for customs duty on an order (H1-09).
 *
 * One JSON column rather than four of its own, because a quote is read and written as a whole: the kind (not quoted, none, payable), the
 * amount, what it was charged on and who owes it only mean anything together. "Not quoted" is a value here, and the most important one --
 * a route nobody has written a rule for must say so rather than record a zero, which would read as "these goods cross free".
 *
 * Nullable and empty for every order that exists, and nothing writes it while duty is off, which is how the platform ships.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders') || Schema::hasColumn('orders', 'customs_quote')) {
            return;
        }

        Schema::table('orders', fn (Blueprint $table) => $table->json('customs_quote')->nullable());
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'customs_quote')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('customs_quote'));
        }
    }
};
