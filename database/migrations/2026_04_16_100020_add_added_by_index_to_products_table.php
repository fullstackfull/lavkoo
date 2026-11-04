<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    private function getExistingIndexes(string $table): array
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->unique()
            ->toArray();
    }

    public function up(): void
    {
        $existing = $this->getExistingIndexes('products');

        Schema::table('products', function (Blueprint $table) use ($existing) {
            if (!in_array('idx_products_added_by', $existing)) {
                $table->index('added_by', 'idx_products_added_by');
            }
        });
    }

    public function down(): void
    {
        $existing = $this->getExistingIndexes('products');

        Schema::table('products', function (Blueprint $table) use ($existing) {
            if (in_array('idx_products_added_by', $existing)) {
                $table->dropIndex('idx_products_added_by');
            }
        });
    }
};
