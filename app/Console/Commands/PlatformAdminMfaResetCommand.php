<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Exceptions\PlatformAdminMfaRefusedException;
use App\Services\PlatformAdmin\PlatformAdminMfaEnrolment;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;

class PlatformAdminMfaResetCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:mfa:reset {admin_id : The id of the admin}';

    protected $description = 'Remove the second factor of a platform admin (a lost phone, lost codes) and end every session and pending sign-in they have. The grant stays; they enrol again';

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
            $result = $enrolment->reset(adminId: $adminId);
        } catch (PlatformAdminMfaRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'The second factor of admin %d was removed (it was %s); %d session(s) and %d pending sign-in(s) ended. Run gcc:platform-admin:mfa:enroll %d to make a new one.',
            $adminId,
            $result['was'],
            $result['tokens_revoked'],
            $result['challenges_ended'],
            $adminId,
        ));

        return self::SUCCESS;
    }
}
