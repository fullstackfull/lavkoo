<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Exceptions\PlatformAdminMfaRefusedException;
use App\Services\PlatformAdmin\PlatformAdminMfaEnrolment;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;

class PlatformAdminMfaConfirmCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:mfa:confirm
        {admin_id : The id of the admin}
        {code : The six digits the authenticator app shows for the secret that gcc:platform-admin:mfa:enroll printed}';

    protected $description = 'Activate the pending second factor of a platform admin with the code their authenticator app shows. Only a confirmed factor counts at sign-in';

    public function handle(PlatformAdminMfaEnrolment $enrolment, PlatformAdminSchema $schema): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $adminId = $this->adminIdArgument();
        if ($adminId === null) {
            return self::FAILURE;
        }

        try {
            $enrolment->confirm(adminId: $adminId, code: (string) $this->argument('code'));
        } catch (PlatformAdminMfaRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info("The second factor of admin {$adminId} is active. The admin can now sign in with it.");

        return self::SUCCESS;
    }
}
