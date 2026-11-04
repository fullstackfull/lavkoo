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
        $existing = $this->getExistingIndexes('shops');

        Schema::table('shops', function (Blueprint $table) use ($existing) {

            if (!in_array('idx_shops_seller_id_author_type', $existing)) {
                $table->index(['seller_id', 'author_type'], 'idx_shops_seller_id_author_type');
            }
            if (!in_array('idx_shops_author_type', $existing)) {
                $table->index('author_type', 'idx_shops_author_type');
            }
        });
    }

    public function down(): void
    {
        $existing = $this->getExistingIndexes('shops');

        Schema::table('shops', function (Blueprint $table) use ($existing) {
            if (in_array('idx_shops_seller_id_author_type', $existing)) $table->dropIndex('idx_shops_seller_id_author_type');
            if (in_array('idx_shops_author_type', $existing))           $table->dropIndex('idx_shops_author_type');
        });
    }
};
