<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a session passed the second factor (GCC-20): `totp`, `recovery_code`, or null for a session opened while the second factor was not required.
 * One nullable column and nothing else, so it adds without rewriting the table; it is skipped when it is already there, and removed only when it is,
 * so that neither direction can fail a deploy.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('platform_admin_tokens', 'mfa_method')) {
            return;
        }

        Schema::table('platform_admin_tokens', fn (Blueprint $table) => $table->string('mfa_method', 20)->nullable()->after('user_agent'));
    }

    public function down(): void
    {
        if (!Schema::hasColumn('platform_admin_tokens', 'mfa_method')) {
            return;
        }

        Schema::table('platform_admin_tokens', fn (Blueprint $table) => $table->dropColumn('mfa_method'));
    }
};
