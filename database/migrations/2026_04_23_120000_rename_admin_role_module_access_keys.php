<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FORWARD_MAP = [
        'pos_management'       => 'pos',
        'promotion_management' => 'marketing',
        'blog_management'      => 'marketing',
        'user_section'         => 'people',
        'support_section'      => 'people',
        'order_management'     => 'orders',
        'product_management'   => 'catalog',
        'report'               => 'reports',
    ];

    public function up(): void
    {
        $this->rewriteModuleAccess(self::FORWARD_MAP);
    }

    public function down(): void
    {
        // Merge is lossy: marketing → promotion_management, people → user_section.
        // Blog and support get dropped on rollback — same behaviour as any
        // collapsing refactor, documented here so nobody is surprised.
        $this->rewriteModuleAccess([
            'pos'       => 'pos_management',
            'marketing' => 'promotion_management',
            'people'    => 'user_section',
            'orders'    => 'order_management',
            'catalog'   => 'product_management',
            'reports'   => 'report',
        ]);
    }

    private function rewriteModuleAccess(array $map): void
    {
        foreach (DB::table('admin_roles')->get() as $role) {
            if (empty($role->module_access)) {
                continue;
            }
            $modules = json_decode($role->module_access, true);
            if (!is_array($modules)) {
                continue;
            }
            $rewritten = array_values(array_unique(array_map(
                static fn ($m) => $map[$m] ?? $m,
                $modules
            )));
            if ($rewritten !== $modules) {
                DB::table('admin_roles')
                    ->where('id', $role->id)
                    ->update(['module_access' => json_encode($rewritten)]);
            }
        }
    }
};
