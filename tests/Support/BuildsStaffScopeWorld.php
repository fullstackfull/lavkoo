<?php

namespace Tests\Support;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The tables the staff country scope reads and writes, built from the real migration of its own table, and the employees the tests sign in as.
 *
 * Like the other schema builders, `admins` and `admin_roles` are INFERRED from the models: the installation dump is not in the repository. The tables are
 * dropped before they are built, so on a real engine the database must be unmistakably a throwaway one.
 *
 * Employees: 1 is the master admin (role 1), 2 and 3 are catalog employees (role 2), 4 is an employee whose role was never set.
 */
trait BuildsStaffScopeWorld
{
    private const STAFF_SCOPE_MIGRATION = '2026_11_28_100000_create_admin_country_scopes_table.php';

    protected function buildStaffAdminTables(): void
    {
        $this->refuseToDropStaffTablesOutsideATestDatabase();

        foreach (['admin_country_scopes', 'admin_roles', 'admins'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('module_access')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('admin_roles')->insert([
            ['id' => 1, 'name' => 'Master Admin', 'module_access' => null, 'status' => 1],
            ['id' => 2, 'name' => 'Catalog', 'module_access' => json_encode(['catalog']), 'status' => 1],
        ]);

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('admin_role_id')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        DB::table('admins')->insert([
            ['id' => 1, 'name' => 'Master', 'email' => 'master@example.test', 'admin_role_id' => 1],
            ['id' => 2, 'name' => 'Catalog one', 'email' => 'one@example.test', 'admin_role_id' => 2],
            ['id' => 3, 'name' => 'Catalog two', 'email' => 'two@example.test', 'admin_role_id' => 2],
            ['id' => 4, 'name' => 'No role', 'email' => 'none@example.test', 'admin_role_id' => null],
        ]);
    }

    protected function runStaffScopeMigration(): void
    {
        (require database_path('migrations/' . self::STAFF_SCOPE_MIGRATION))->up();
    }

    protected function rollBackStaffScopeMigration(): void
    {
        (require database_path('migrations/' . self::STAFF_SCOPE_MIGRATION))->down();
    }

    /** @param list<string> $countries stored as they are given: a test may hand over a value no command would accept */
    protected function giveCountries(int $adminId, array $countries): void
    {
        foreach ($countries as $country) {
            DB::table('admin_country_scopes')->insert(['admin_id' => $adminId, 'country' => $country, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function switchStaffScopesOn(): void
    {
        config(['gcc.staff.country_scopes' => true]);
    }

    protected function signInAsStaff(int $adminId): static
    {
        return $this->actingAs(Admin::query()->findOrFail($adminId), 'admin');
    }

    private function refuseToDropStaffTablesOutsideATestDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $database = (string) $connection->getDatabaseName();
        if (!app()->environment('testing') || !preg_match('/(^|_)test(s|ing)?($|_)/i', $database)) {
            throw new RuntimeException("Refusing to drop the admin tables of '{$database}': the test database name must contain 'test' and APP_ENV must be 'testing'.");
        }
    }
}
