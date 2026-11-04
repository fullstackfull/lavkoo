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
        Schema::table('customer_wallets', function (Blueprint $table) {
            $table->decimal('balance', 21, 12)->default(0)->change();
            $table->decimal('royality_points', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_wallets', function (Blueprint $table) {
            $table->decimal('balance', 8, 2)->default(0)->change();
            $table->decimal('royality_points', 8, 2)->default(0)->change();
        });
    }
};
