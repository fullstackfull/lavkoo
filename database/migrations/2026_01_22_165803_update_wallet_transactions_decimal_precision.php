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
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('credit', 21, 12)->change();
            $table->decimal('debit', 21, 12)->change();
            $table->decimal('admin_bonus', 21, 12)->change();
            $table->decimal('balance', 21, 12)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('credit', 24, 3)->change();
            $table->decimal('debit', 24, 3)->change();
            $table->decimal('admin_bonus', 24, 3)->change();
            $table->decimal('balance', 24, 3)->change();
        });
    }
};
