<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_methods', function (Blueprint $table) {
            $table->tinyInteger('vendor_status')->default(0)->after('is_active');
            $table->tinyInteger('customer_status')->default(0)->after('vendor_status');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_methods', function (Blueprint $table) {
            $table->dropColumn(['vendor_status', 'customer_status']);
        });
    }
};
