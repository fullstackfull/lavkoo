<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two small tables the chain of the audit trail needs (GCC-20, phase 2). The tail holds one row, the id and the hash of the line written last: the writer
 * holds it while it writes, which is what makes two writers take turns, and verification compares it with the end of the trail, which is how the newest
 * lines being removed is seen. The checkpoints hold what a prune leaves behind of the lines it removed. Both are new; nothing else is touched. When the table of
 * the tail is made over a trail that already has hashed lines, it starts from the newest of them. Skipped when they are already there, so that neither
 * direction can fail a deploy; nothing reads them until the API is switched on.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('platform_admin_audit_tail')) {
            Schema::create('platform_admin_audit_tail', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('last_line_id')->nullable();
                $table->char('last_hash', 64)->nullable();
            });
        }

        if (DB::table('platform_admin_audit_tail')->where('id', 1)->doesntExist()) {
            $newest = Schema::hasColumn('platform_admin_audit_logs', 'hash') ? DB::table('platform_admin_audit_logs')->whereNotNull('hash')->orderByDesc('id')->first(['id', 'hash']) : null;

            DB::table('platform_admin_audit_tail')->insert(['id' => 1, 'last_line_id' => $newest?->id, 'last_hash' => $newest?->hash]);
        }

        if (!Schema::hasTable('platform_admin_audit_checkpoints')) {
            Schema::create('platform_admin_audit_checkpoints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('last_line_id');
                $table->char('last_hash', 64);
                $table->unsignedBigInteger('lines_removed');
                $table->unsignedInteger('days');
                $table->string('operator', 255);
                $table->timestamp('pruned_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_audit_checkpoints');
        Schema::dropIfExists('platform_admin_audit_tail');
    }
};
