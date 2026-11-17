<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What makes the audit trail of the platform admin API tamper evident (GCC-20, phase 2): every line gains the hash of the line before it (`prev_hash`) and the
 * hash of its own content (`hash`), both SHA-256 in hex. The columns are nullable because the lines that exist have neither: a line written before the chain
 * reads as older than it. Two columns and nothing else, each skipped when it is already there and removed only when it is, so that neither direction can fail
 * a deploy. Nothing writes them until the API is switched on.
 */
return new class extends Migration {
    private const COLUMNS = ['prev_hash', 'hash'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if (!Schema::hasColumn('platform_admin_audit_logs', $column)) {
                Schema::table('platform_admin_audit_logs', fn (Blueprint $table) => $table->char($column, 64)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('platform_admin_audit_logs', $column)) {
                Schema::table('platform_admin_audit_logs', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
