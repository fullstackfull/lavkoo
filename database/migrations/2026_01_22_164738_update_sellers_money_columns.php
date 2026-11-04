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
        Schema::table('sellers', function (Blueprint $table) {
            $table->decimal('sales_commission_percentage', 21, 12)->nullable()->change();
            $table->decimal('minimum_order_amount', 21, 12)->default(0)->change();
            $table->decimal('free_delivery_over_amount', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sellers', function (Blueprint $table) {
            $table->double('sales_commission_percentage', 8, 2)->nullable()->change();
            $table->double('minimum_order_amount', 8, 2)->default(0)->change();
            $table->double('free_delivery_over_amount', 8, 2)->default(0)->change();
        });
    }
};
