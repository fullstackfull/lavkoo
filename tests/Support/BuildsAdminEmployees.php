<?php

namespace Tests\Support;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;

/**
 * Employees of the admin panel with the role a test needs, on the `admin_roles` and `admins` tables of BuildsFulfillmentSchema.
 * The master admin is the first admin (the first role), which that schema seeds.
 */
trait BuildsAdminEmployees
{
    /**
     * @param list<string>|null $modules the permissions the role holds; null leaves the role without `module_access`
     */
    private function employeeHolding(?array $modules, bool $roleIsActive = true): Admin
    {
        $roleId = DB::table('admin_roles')->insertGetId([
            'name' => 'Role of ' . json_encode($modules),
            'module_access' => $modules === null ? null : json_encode($modules),
            'status' => $roleIsActive ? 1 : 0,
        ]);

        return $this->employeeOfRole(roleId: $roleId);
    }

    private function employeeOfRole(int $roleId): Admin
    {
        $adminId = DB::table('admins')->insertGetId(['name' => 'Employee', 'email' => 'employee' . $roleId . '@example.test', 'admin_role_id' => $roleId, 'status' => 1]);

        return Admin::query()->findOrFail($adminId);
    }
}
