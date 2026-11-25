<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sessions of the platform admin API (GCC-20, level 1). A token is shown once, at sign-in; only its SHA-256 hash is kept. The fingerprint
 * is the SHA-256 of the admin's password hash at the time the token was issued, so any password change invalidates every token of that admin
 * without the admin screens knowing about this table. Additive: the table is new and nothing reads it until the API is switched on.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('platform_admin_tokens')) {
            return;
        }

        Schema::create('platform_admin_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->index();
            $table->unsignedBigInteger('grant_id');
            $table->char('token_hash', 64)->unique();
            $table->char('password_fingerprint', 64);
            $table->string('device_name', 100);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('grant_id', 'platform_admin_tokens_grant_fk')->references('id')->on('platform_admin_grants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_tokens');
    }
};
