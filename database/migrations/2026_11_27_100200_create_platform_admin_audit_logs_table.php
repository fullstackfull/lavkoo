<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform admin API did and who asked (GCC-20, level 1): every request of a signed-in admin, every sign-in and sign-out, and every
 * change of a grant made on the server. Rows are written once and never updated, and they carry no secret: not a password, not a token. The
 * admin id has no foreign key, so the trail outlives the admin. Additive: the table is new and nothing reads it until the API is switched on.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('platform_admin_audit_logs')) {
            return;
        }

        Schema::create('platform_admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('action', 64);
            $table->char('market', 2)->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_audit_logs');
    }
};
