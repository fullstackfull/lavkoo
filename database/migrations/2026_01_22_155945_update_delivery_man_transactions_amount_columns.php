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
        Schema::table('delivery_man_transactions', function (Blueprint $table) {
            $table->decimal('debit', 21, 12)->default(0)->change();
            $table->decimal('credit', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_man_transactions', function (Blueprint $table) {
            $table->decimal('debit', 50, 2)->default(0)->change();
            $table->decimal('credit', 50, 2)->default(0)->change();
        });
    }
};
