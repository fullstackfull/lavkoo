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
        Schema::table('deal_of_the_days', function (Blueprint $table) {
            $table->decimal('discount', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deal_of_the_days', function (Blueprint $table) {
            $table->decimal('discount', 8, 2)->default(0)->change();
        });
    }
};
