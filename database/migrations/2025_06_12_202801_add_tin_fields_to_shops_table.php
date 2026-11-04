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
        Schema::table('shops', function (Blueprint $table) {
            $table->string('tax_identification_number')->after('setup_guide')->nullable();
            $table->date('tin_expire_date')->after('tax_identification_number')->nullable();
            $table->string('tin_certificate')->after('tin_expire_date')->nullable();
            $table->string('tin_certificate_storage_type')->after('tin_certificate')->default('public')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['tax_identification_number', 'tin_expire_date', 'tin_certificate', 'tin_certificate_storage_type']);
        });
    }
};
