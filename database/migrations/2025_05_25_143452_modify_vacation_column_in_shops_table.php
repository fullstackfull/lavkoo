<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dateTime('vacation_start_date')->after('vacation_status')->nullable()->change();
            $table->dateTime('vacation_end_date')->after('vacation_start_date')->nullable()->change();
            $table->string('vacation_duration_type')->after('vacation_end_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('vacation_duration_type');
            $table->date('vacation_start_date')->nullable()->change();
            $table->date('vacation_end_date')->nullable()->change();
        });
    }
};
