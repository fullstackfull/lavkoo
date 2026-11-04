<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seo_meta_info')) {
            return;
        }

        DB::statement("
            DELETE s1 FROM seo_meta_info s1
            INNER JOIN seo_meta_info s2
                ON s1.seoable_type = s2.seoable_type
                AND s1.seoable_id = s2.seoable_id
                AND s1.id > s2.id
        ");

        Schema::table('seo_meta_info', function (Blueprint $table) {
            $table->unique(['seoable_type', 'seoable_id'], 'seo_meta_info_seoable_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('seo_meta_info')) {
            return;
        }

        Schema::table('seo_meta_info', function (Blueprint $table) {
            $table->dropUnique('seo_meta_info_seoable_unique');
        });
    }
};
