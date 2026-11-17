<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the platform admin API reads and the ones it owns.
 *
 * The core store tables come from the installation dump, which is not in the repository, so the minimal inferred schema of the
 * fulfillment tests stands in for them (see BuildsFulfillmentSchema). The platform admin tables are built by the REAL migrations.
 */
trait BuildsPlatformAdminWorld
{
    use BuildsFulfillmentSchema;

    private const PLATFORM_ADMIN_MIGRATIONS = [
        '2026_11_27_100000_create_platform_admin_grants_table.php',
        '2026_11_27_100100_create_platform_admin_tokens_table.php',
        '2026_11_27_100200_create_platform_admin_audit_logs_table.php',
    ];

    /** The store tables and the admin sign-in columns, without the tables of the platform admin API. */
    protected function buildStoreTables(): void
    {
        $this->buildCoreSchema();

        DB::table('admins')->delete();
        Schema::table('admins', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->string('phone')->nullable();
        });

        $this->runStorefrontCountryColumn();
    }

    protected function buildPlatformAdminWorld(): void
    {
        $this->buildStoreTables();
        $this->buildSellerTables();
        $this->runPlatformAdminMigrations();
    }

    /** What the seller credentials need: a seller, its staff, their roles and the keys it issued. Inferred, like the rest of the store tables. */
    protected function buildSellerTables(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
            $table->decimal('stock_limit', 24, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('seller_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name');
            $table->json('permissions')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->string('name');
            $table->string('prefix', 12);
            $table->string('token_hash');
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    protected function runPlatformAdminMigrations(): void
    {
        foreach (self::PLATFORM_ADMIN_MIGRATIONS as $file) {
            (require database_path("migrations/{$file}"))->up();
        }
    }

    protected function rollBackPlatformAdminMigrations(): void
    {
        foreach (array_reverse(self::PLATFORM_ADMIN_MIGRATIONS) as $file) {
            (require database_path("migrations/{$file}"))->down();
        }
    }

    /**
     * A JSON column is read back with its keys in the order the engine keeps them (MySQL sorts them by length, then by name), so
     * contexts are compared with every level sorted by key.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    protected function sortedByKey(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortedByKey($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<array-key, mixed> the context of an audit line, sorted by key */
    protected function contextOf(object $auditLine): array
    {
        return $this->sortedByKey((array) json_decode((string) $auditLine->context, true));
    }

    private function runStorefrontCountryColumn(): void
    {
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
    }
}
