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
        $this->runPlatformAdminMigrations();
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

    private function runStorefrontCountryColumn(): void
    {
        (require database_path('migrations/2026_11_25_100000_add_storefront_country_to_products.php'))->up();
    }
}
