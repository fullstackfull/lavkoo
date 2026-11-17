<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Support\Facades\Schema;

/**
 * Whether the tables of the platform admin API exist. The code can be deployed, and the flag switched on, before the migrations have run;
 * until they have, every route answers that the service is unavailable instead of failing with a server error.
 */
final class PlatformAdminSchema
{
    private const TABLES = ['platform_admin_grants', 'platform_admin_tokens', 'platform_admin_audit_logs', 'platform_admin_mfa_factors', 'platform_admin_mfa_challenges'];

    /** What the migrations that change a table of the API add to it, which the code reads and writes as soon as it is switched on. */
    private const COLUMNS = ['platform_admin_tokens' => ['mfa_method']];

    /** @var list<string>|null */
    private ?array $missing = null;

    public function ready(): bool
    {
        return $this->missingTables() === [];
    }

    /** @return list<string> the tables that do not exist, and the table.column of what a migration was to add to a table that does */
    public function missingTables(): array
    {
        return $this->missing ??= array_values(array_merge(
            array_filter(self::TABLES, static fn (string $table): bool => !Schema::hasTable($table)),
            $this->missingColumns(),
        ));
    }

    /** @return list<string> */
    private function missingColumns(): array
    {
        $missing = [];

        foreach (self::COLUMNS as $table => $columns) {
            if (Schema::hasTable($table)) {
                $missing = array_merge($missing, array_map(static fn (string $column): string => "{$table}.{$column}", array_filter($columns, static fn (string $column): bool => !Schema::hasColumn($table, $column))));
            }
        }

        return $missing;
    }
}
