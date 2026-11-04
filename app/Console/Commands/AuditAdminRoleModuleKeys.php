<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditAdminRoleModuleKeys extends Command
{
    protected $signature = 'admin-roles:audit-module-keys
                            {--fix : Rewrite stale keys in place using the same map as the migration}';

    protected $description = 'Find admin_roles whose module_access JSON still contains the pre-rename keys (pos_management, product_management, etc).';

    private const FORWARD_MAP = [
        'pos_management' => 'pos',
        'promotion_management' => 'marketing',
        'blog_management' => 'marketing',
        'user_section' => 'people',
        'support_section' => 'people',
        'order_management' => 'orders',
        'product_management' => 'catalog',
        'report' => 'reports',
    ];

    public function handle(): int
    {
        $oldKeys = array_keys(self::FORWARD_MAP);
        $stale = [];

        foreach (DB::table('admin_roles')->get(['id', 'name', 'status', 'module_access']) as $role) {
            if (empty($role->module_access)) {
                continue;
            }
            $modules = json_decode($role->module_access, true);
            if (!is_array($modules)) {
                $this->warn("Role #{$role->id} ({$role->name}): module_access is not valid JSON — skipped.");
                continue;
            }
            $found = array_values(array_intersect($modules, $oldKeys));
            if ($found) {
                $stale[] = [
                    'id' => $role->id,
                    'name' => $role->name,
                    'status' => $role->status,
                    'old' => implode(', ', $found),
                    'current' => implode(', ', $modules),
                ];
            }
        }

        if (!$stale) {
            $this->info('All admin_roles use the new module keys. Nothing to fix.');
            return self::SUCCESS;
        }

        $this->warn(sprintf('%d role(s) still hold pre-rename module keys:', count($stale)));
        $this->table(['ID', 'Name', 'Status', 'Stale keys', 'Full module_access'], $stale);

        if (!$this->option('fix')) {
            $this->line('');
            $this->line('Re-run with --fix to rewrite these rows using the same mapping as');
            $this->line('migration 2026_04_23_120000_rename_admin_role_module_access_keys.');
            return self::FAILURE;
        }

        $rewritten = 0;
        foreach ($stale as $row) {
            $modules = json_decode(
                DB::table('admin_roles')->where('id', $row['id'])->value('module_access'),
                true
            ) ?: [];
            $new = array_values(array_unique(array_map(
                static fn($m) => self::FORWARD_MAP[$m] ?? $m,
                $modules
            )));
            DB::table('admin_roles')
                ->where('id', $row['id'])
                ->update(['module_access' => json_encode($new)]);
            $rewritten++;
        }

        $this->info("Rewrote module_access for {$rewritten} role(s).");
        $this->line('Tip: clear caches so middleware/sidebar see the new values immediately:');
        $this->line('     php artisan optimize:clear');

        return self::SUCCESS;
    }
}
