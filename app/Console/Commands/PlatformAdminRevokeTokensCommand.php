<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Services\PlatformAdmin\PlatformAdminGrantService;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;

class PlatformAdminRevokeTokensCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:revoke-tokens {admin_id : The id of the admin}';

    protected $description = 'End every session an admin has open on the platform admin API, and keep the grant, so a stolen token stops working and the admin can sign in again';

    public function handle(PlatformAdminGrantService $grants, PlatformAdminSchema $schema): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $adminId = $this->adminIdArgument();
        if ($adminId === null) {
            return self::FAILURE;
        }

        $this->info(sprintf('%d session(s) of admin %d ended.', $grants->revokeTokens(adminId: $adminId), $adminId));

        return self::SUCCESS;
    }
}
