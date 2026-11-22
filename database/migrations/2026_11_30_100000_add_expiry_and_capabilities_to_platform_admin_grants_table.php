<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a grant of the platform admin API may also say (GCC-20, phase 2): the moment it ends (`expires_at`, null for no end) and the parts of the API it
 * includes (`capabilities`, a JSON list; SQL NULL is every capability that exists, so a grant made before this means what it meant). Two nullable columns
 * and nothing else, each skipped when it is already there and removed only when it is, so that neither direction can fail a deploy. Nothing reads them
 * until the API is switched on.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('platform_admin_grants', 'capabilities')) {
            Schema::table('platform_admin_grants', fn (Blueprint $table) => $table->json('capabilities')->nullable()->after('countries'));
        }

        if (!Schema::hasColumn('platform_admin_grants', 'expires_at')) {
            Schema::table('platform_admin_grants', fn (Blueprint $table) => $table->timestamp('expires_at')->nullable()->after('revoked_at'));
        }
    }

    public function down(): void
    {
        foreach (['capabilities', 'expires_at'] as $column) {
            if (Schema::hasColumn('platform_admin_grants', $column)) {
                Schema::table('platform_admin_grants', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
