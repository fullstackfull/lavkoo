<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_transactions', function (Blueprint $table) {
            $table->decimal('order_amount', 21, 12)->default(0)->change();
            $table->decimal('seller_amount', 21, 12)->default(0)->change();
            $table->decimal('admin_commission', 21, 12)->default(0)->change();
            $table->decimal('delivery_charge', 21, 12)->default(0)->change();
            $table->decimal('tax', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_transactions', function (Blueprint $table) {
            $table->decimal('order_amount', 50, 2)->default(0)->change();
            $table->decimal('seller_amount', 50, 2)->default(0)->change();
            $table->decimal('admin_commission', 50, 2)->default(0)->change();
            $table->decimal('delivery_charge', 50, 2)->default(0)->change();
            $table->decimal('tax', 50, 2)->default(0)->change();
        });
    }
};
