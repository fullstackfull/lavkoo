<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Exceptions\PlatformAdminGrantRefusedException;
use App\Services\PlatformAdmin\PlatformAdminGrantService;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;

class PlatformAdminRevokeCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:revoke {admin_id : The id of the admin}';

    protected $description = 'End an admin\'s right to use the platform admin API, and every session they have open';

    public function handle(PlatformAdminGrantService $grants, PlatformAdminSchema $schema): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $adminId = $this->adminIdArgument();
        if ($adminId === null) {
            return self::FAILURE;
        }

        try {
            $result = $grants->revoke(adminId: $adminId);
        } catch (PlatformAdminGrantRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('The grant of admin %d is %s and %d session(s) ended.', $adminId, $result['already_revoked'] ? 'already revoked' : 'revoked', $result['tokens_revoked']));

        return self::SUCCESS;
    }
}
