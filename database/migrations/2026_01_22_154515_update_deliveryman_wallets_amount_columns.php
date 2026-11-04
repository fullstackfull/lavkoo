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
        Schema::table('deliveryman_wallets', function (Blueprint $table) {
            $table->decimal('current_balance', 21, 12)->default(0)->change();
            $table->decimal('cash_in_hand', 21, 12)->default(0)->change();
            $table->decimal('pending_withdraw', 21, 12)->default(0)->change();
            $table->decimal('total_withdraw', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deliveryman_wallets', function (Blueprint $table) {
            $table->decimal('current_balance', 50, 2)->default(0)->change();
            $table->decimal('cash_in_hand', 50, 2)->default(0)->change();
            $table->decimal('pending_withdraw', 50, 2)->default(0)->change();
            $table->decimal('total_withdraw', 50, 2)->default(0)->change();
        });
    }
};
