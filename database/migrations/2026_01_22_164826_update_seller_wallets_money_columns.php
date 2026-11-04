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
        Schema::table('seller_wallets', function (Blueprint $table) {
            $table->decimal('total_earning', 21, 12)->default(0)->change();
            $table->decimal('withdrawn', 21, 12)->default(0)->change();
            $table->decimal('commission_given', 21, 12)->default(0)->change();
            $table->decimal('pending_withdraw', 21, 12)->default(0)->change();
            $table->decimal('delivery_charge_earned', 21, 12)->default(0)->change();
            $table->decimal('collected_cash', 21, 12)->default(0)->change();
            $table->decimal('total_tax_collected', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seller_wallets', function (Blueprint $table) {
            $table->double('total_earning', 8, 2)->default(0)->change();
            $table->double('withdrawn', 8, 2)->default(0)->change();
            $table->double('commission_given', 8, 2)->default(0)->change();
            $table->double('pending_withdraw', 8, 2)->default(0)->change();
            $table->double('delivery_charge_earned', 8, 2)->default(0)->change();
            $table->double('collected_cash', 8, 2)->default(0)->change();
            $table->double('total_tax_collected', 8, 2)->default(0)->change();
        });
    }
};
