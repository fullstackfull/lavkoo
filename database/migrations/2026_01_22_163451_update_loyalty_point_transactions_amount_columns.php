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
        Schema::table('loyalty_point_transactions', function (Blueprint $table) {
            $table->decimal('credit', 24, 6)->default(0)->change();
            $table->decimal('debit', 24, 6)->default(0)->change();
            $table->decimal('balance', 24, 6)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loyalty_point_transactions', function (Blueprint $table) {
            $table->decimal('credit', 24, 3)->default(0)->change();
            $table->decimal('debit', 24, 3)->default(0)->change();
            $table->decimal('balance', 24, 3)->default(0)->change();
        });
    }
};
