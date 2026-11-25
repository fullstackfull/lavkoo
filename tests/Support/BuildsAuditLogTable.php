<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The platform's audit trail table, which no migration of this repository creates: without it AuditLogger records nothing, so tests of an audited action build it first. */
trait BuildsAuditLogTable
{
    protected function buildAuditLogTable(): void
    {
        // Guarded, because tables persist across test classes within a process: a class that builds this and walks away would make the next
        // class's own call a duplicate-table error rather than a no-op.
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }
}
