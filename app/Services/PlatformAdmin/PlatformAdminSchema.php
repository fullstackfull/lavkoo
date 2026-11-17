<?php

namespace App\Services\PlatformAdmin;

use Illuminate\Support\Facades\Schema;

/**
 * Whether the tables of the platform admin API exist. The code can be deployed, and the flag switched on, before the migrations have run;
 * until they have, every route answers that the service is unavailable instead of failing with a server error.
 */
final class PlatformAdminSchema
{
    private const TABLES = ['platform_admin_grants', 'platform_admin_tokens', 'platform_admin_audit_logs'];

    /** @var list<string>|null */
    private ?array $missing = null;

    public function ready(): bool
    {
        return $this->missingTables() === [];
    }

    /** @return list<string> */
    public function missingTables(): array
    {
        return $this->missing ??= array_values(array_filter(self::TABLES, static fn (string $table): bool => !Schema::hasTable($table)));
    }
}
