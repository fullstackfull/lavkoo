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
        Schema::table('order_details', function (Blueprint $table) {
            $table->decimal('price', 21, 12)->default(0)->change();
            $table->decimal('tax', 21, 12)->default(0)->change();
            $table->decimal('discount', 21, 12)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            $table->double('price')->default(0)->change();
            $table->double('tax')->default(0)->change();
            $table->double('discount')->default(0)->change();
        });
    }
};
