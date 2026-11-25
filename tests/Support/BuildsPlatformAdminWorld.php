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

    /** Level 1: the grants, the sessions and the audit trail. */
    private const PLATFORM_ADMIN_MIGRATIONS = [
        '2026_11_27_100000_create_platform_admin_grants_table.php',
        '2026_11_27_100100_create_platform_admin_tokens_table.php',
        '2026_11_27_100200_create_platform_admin_audit_logs_table.php',
    ];

    /** Phase 2, after level 1 and every one of them additive: the second factor, and what the sessions and the audit trail gained. */
    private const PLATFORM_ADMIN_PHASE_TWO_MIGRATIONS = [
        '2026_11_29_100000_create_platform_admin_mfa_factors_table.php',
        '2026_11_29_100100_create_platform_admin_mfa_challenges_table.php',
        '2026_11_29_100200_add_mfa_method_to_platform_admin_tokens_table.php',
        '2026_11_30_100000_add_expiry_and_capabilities_to_platform_admin_grants_table.php',
        '2026_11_30_100100_add_hash_chain_to_platform_admin_audit_logs_table.php',
        '2026_11_30_100200_create_platform_admin_audit_chain_tables.php',
    ];

    /** Phase 3, additive like the rest: what a session gained so that an action can ask for the second factor again (L-15), and the devices it may be alerted on (L-16). */
    private const PLATFORM_ADMIN_PHASE_THREE_MIGRATIONS = [
        '2026_12_31_100000_add_step_up_to_platform_admin_tokens_table.php',
        '2026_12_31_100100_create_platform_admin_devices_table.php',
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

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id')->default(0);
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('status');
            $table->decimal('amount', 24, 2)->default(0);
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('order_id')->default(0);
            $table->text('refund_reason')->nullable();
            $table->timestamps();
        });

        $this->runStorefrontCountryColumn();
    }

    protected function buildPlatformAdminWorld(): void
    {
        $this->buildStoreTables();
        $this->buildSellerTables();
        $this->runPlatformAdminMigrations();
        $this->runPlatformAdminPhaseTwoMigrations();
        $this->runPlatformAdminPhaseThreeMigrations();
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

    /**
     * What a grant remembers of the admin row it was made for: the id and the creation time of the row, hashed. The formula is spelled out here
     * and not taken from the code under test, so that a change to it, which would end every grant that exists, fails a test.
     */
    protected function adminFingerprint(int $adminId): string
    {
        return hash('sha256', $adminId . '|' . DB::table('admins')->where('id', $adminId)->value('created_at'));
    }

    protected function runPlatformAdminMigrations(): void
    {
        foreach (self::PLATFORM_ADMIN_MIGRATIONS as $file) {
            (require database_path("migrations/{$file}"))->up();
        }
    }

    /**
     * @param string|null $only the file of one migration of phase 2, to run it alone
     * @param list<string> $except files of phase 2 not to run
     */
    protected function runPlatformAdminPhaseTwoMigrations(?string $only = null, array $except = []): void
    {
        foreach (self::PLATFORM_ADMIN_PHASE_TWO_MIGRATIONS as $file) {
            if (($only === null || $only === $file) && !in_array($file, $except, true)) {
                (require database_path("migrations/{$file}"))->up();
            }
        }
    }

    /** @param list<string> $except */
    protected function runPlatformAdminPhaseThreeMigrations(?string $only = null, array $except = []): void
    {
        foreach (self::PLATFORM_ADMIN_PHASE_THREE_MIGRATIONS as $file) {
            if (($only === null || $only === $file) && !in_array($file, $except, true)) {
                (require database_path("migrations/{$file}"))->up();
            }
        }
    }

    /** Everything of the API, newest first, because what phase 2 added refers to what level 1 made. */
    protected function rollBackPlatformAdminMigrations(): void
    {
        $this->rollBackPlatformAdminPhaseTwoMigrations();

        foreach (array_reverse(self::PLATFORM_ADMIN_MIGRATIONS) as $file) {
            (require database_path("migrations/{$file}"))->down();
        }
    }

    protected function rollBackPlatformAdminPhaseTwoMigrations(): void
    {
        foreach (array_reverse(self::PLATFORM_ADMIN_PHASE_TWO_MIGRATIONS) as $file) {
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
