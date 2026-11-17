<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may use the platform admin API (GCC-20, level 1). One row per admin, created and revoked only by `gcc:platform-admin:grant` and
 * `gcc:platform-admin:revoke` on the server: no route can create or widen a grant. A null `countries` is the platform owner, who sees every
 * market; a list of country codes is a viewer restricted to those markets. Additive: the table is new and nothing reads it until the API is
 * switched on.
 *
 * The foreign key assumes `admins.id` is BIGINT UNSIGNED, as the installation dump and `Schema::id()` make it, and it is what removes a grant
 * with its admin, so that an id that is ever reused cannot inherit one. Check `SHOW CREATE TABLE admins` before migrating a real database.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_admin_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->unique();
            $table->unsignedTinyInteger('level')->default(1);
            $table->json('countries')->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('admin_id', 'platform_admin_grants_admin_fk')->references('id')->on('admins')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_grants');
    }
};
