<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'taxables',
        'order_taxes',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'tax_for')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('tax_for', 30)
                        ->default('tax_for_orders')
                        ->nullable()
                        ->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tax_for')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('tax_for');
                });
            }
        }
    }
};
