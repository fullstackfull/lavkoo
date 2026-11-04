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
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('init_order_amount', 18, 12)->default(0)->after('order_amount');
            $table->decimal('edit_due_amount', 18, 12)->default(0)->after('init_order_amount');
            $table->decimal('edit_return_amount', 18, 12)->default(0)->after('edit_due_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'init_order_amount',
                'edit_due_amount',
                'edit_return_amount',
            ]);
        });
    }
};
