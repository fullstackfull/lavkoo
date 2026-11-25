<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second factor of a platform admin (GCC-20): one row per admin, made and removed only by the console commands `gcc:platform-admin:mfa:*`
 * on the server. It holds the TOTP secret encrypted with the application key, and the ten recovery codes as HMACs, each with the time it was used.
 * `last_used_step` is the 30-second step of the last code accepted, so that a code is used once.
 *
 * A factor is pending until `confirmed_at` is set. `admin_fingerprint` is the fingerprint of the admin row it was enrolled for, the same one the grant
 * carries, so that a different row that comes to carry the id does not inherit the secret.
 *
 * The table hangs off the grants table (a foreign key with ON DELETE CASCADE: the types match by construction, as the tokens table's do) and not off
 * `admins`: the installation dump that makes `admins` is not in this repository, and a key to a column whose type cannot be seen is the one thing a
 * migration of this feature must not depend on. Deleting an admin takes the factor with it exactly when it takes the grant. Additive: nothing reads it
 * until the API is switched on.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('platform_admin_mfa_factors')) {
            return;
        }

        Schema::create('platform_admin_mfa_factors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->unique();
            $table->unsignedBigInteger('grant_id');
            $table->char('admin_fingerprint', 64);
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->json('recovery_codes')->nullable();
            $table->timestamps();

            $table->foreign('grant_id', 'platform_admin_mfa_factors_grant_fk')->references('id')->on('platform_admin_grants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_mfa_factors');
    }
};
