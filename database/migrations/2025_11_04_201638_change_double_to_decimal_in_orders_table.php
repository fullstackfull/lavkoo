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
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('order_amount', 18, 12)->default(0)->change();
            $table->decimal('discount_amount', 18, 12)->default(0)->change();
            $table->decimal('shipping_cost', 18, 12)->default(0)->change();
            $table->decimal('deliveryman_charge', 18, 12)->default(0)->change();
            $table->decimal('extra_discount', 18, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->double('order_amount', 8, 2)->default(0)->change();
            $table->double('discount_amount', 8, 2)->default(0)->change();
            $table->double('shipping_cost', 8, 2)->default(0)->change();
            $table->double('deliveryman_charge', 8, 2)->default(0)->change();
            $table->double('extra_discount', 8, 2)->default(0)->change();
        });
    }
};
