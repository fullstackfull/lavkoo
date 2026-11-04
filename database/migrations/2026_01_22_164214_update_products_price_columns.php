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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('unit_price', 21, 12)->default(0)->change();
            $table->decimal('purchase_price', 21, 12)->default(0)->change();
            $table->decimal('shipping_cost', 21, 12)->nullable()->change();
            $table->decimal('temp_shipping_cost', 21, 12)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->double('unit_price')->default(0)->change();
            $table->double('purchase_price')->default(0)->change();
            $table->double('shipping_cost', 8, 2)->nullable()->change();
            $table->double('temp_shipping_cost', 8, 2)->nullable()->change();
        });
    }
};
