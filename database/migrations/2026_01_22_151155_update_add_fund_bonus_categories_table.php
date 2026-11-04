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
        Schema::table('add_fund_bonus_categories', function (Blueprint $table) {
            $table->decimal('bonus_amount', 21, 12)->change();
            $table->decimal('min_add_money_amount', 21, 12)->change();
            $table->decimal('max_bonus_amount', 21, 12)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('add_fund_bonus_categories', function (Blueprint $table) {
            $table->double('bonus_amount', 14, 2)->default(0)->change();
            $table->double('min_add_money_amount', 14, 2)->default(0)->change();
            $table->double('max_bonus_amount', 14, 2)->default(0)->change();
        });
    }
};
