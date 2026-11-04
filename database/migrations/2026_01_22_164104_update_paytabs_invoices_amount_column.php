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
        Schema::table('paytabs_invoices', function (Blueprint $table) {
            $table->decimal('amount', 21, 12)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paytabs_invoices', function (Blueprint $table) {
            $table->double('amount', 8, 2)->nullable()->change();
        });
    }
};
