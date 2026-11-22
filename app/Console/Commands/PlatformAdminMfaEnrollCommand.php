<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Exceptions\PlatformAdminMfaRefusedException;
use App\Services\PlatformAdmin\PlatformAdminMfaEnrolment;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;

class PlatformAdminMfaEnrollCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:mfa:enroll
        {admin_id : The id of the admin, who must hold an active grant}
        {--replace : Replace the factor the admin already has: it is removed, their sessions and pending sign-ins end}';

    protected $description = 'Make the second factor of a platform admin and print its secret and ten recovery codes, once. It counts only after gcc:platform-admin:mfa:confirm. Only an operator on the server can do this';

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
            $enrolled = $enrolment->enrol(adminId: $adminId, replace: (bool) $this->option('replace'));
        } catch (PlatformAdminMfaRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info("A second factor was made for admin {$adminId}. It is PENDING: it counts at sign-in only after: php artisan gcc:platform-admin:mfa:confirm {$adminId} <code>");
        $this->line('This is the only time the secret and the recovery codes are shown. They are not kept in a form that can show them again, and nothing logs them.');
        $this->newLine();
        $this->line("Secret (base32): {$enrolled->secret}");
        $this->line("otpauth URI: {$enrolled->uri}");
        $this->line('Type the secret into the authenticator app by hand. Do not paste the address into an online QR generator: the secret would leave this server.');
        $this->newLine();
        $this->line('Recovery codes (each works once):');
        foreach ($enrolled->recoveryCodes as $code) {
            $this->line("  {$code}");
        }

        return self::SUCCESS;
    }
}
