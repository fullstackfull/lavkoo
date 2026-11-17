<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sign-ins of the platform admin API that have a good password and are waiting for the second factor (GCC-20). A challenge is shown once, at the
 * first step; only its SHA-256 is kept. It lives a few minutes, allows a few tries (`attempts`), and is consumed by the first success or by the last try.
 * `password_fingerprint` is the SHA-256 of the admin's password hash at the first step, so that a password changed in between ends it.
 *
 * Tied to the grants table with ON DELETE CASCADE and to nothing else: see the migration of the factors. Additive: nothing reads it until the API is
 * switched on.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_admin_mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('admin_id')->index();
            $table->unsignedBigInteger('grant_id');
            $table->char('password_fingerprint', 64);
            $table->string('device_name', 100);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('grant_id', 'platform_admin_mfa_challenges_grant_fk')->references('id')->on('platform_admin_grants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_mfa_challenges');
    }
};
