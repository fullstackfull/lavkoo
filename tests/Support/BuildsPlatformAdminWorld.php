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
