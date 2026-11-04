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
        Schema::table('referral_customers', function (Blueprint $table) {
            $table->decimal('ref_by_earning_amount', 21, 12)->default(0)->change();
            $table->decimal('customer_discount_amount', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referral_customers', function (Blueprint $table) {
            $table->double('ref_by_earning_amount')->default(0)->change();
            $table->double('customer_discount_amount')->default(0)->change();
        });
    }
};
