<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Exceptions\PlatformAdminMfaRefusedException;
use App\Services\PlatformAdmin\PlatformAdminMfaEnrolment;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use Illuminate\Console\Command;

class PlatformAdminMfaStatusCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:mfa:status {admin_id? : Only this admin. Leave it out for every admin who holds a grant}';

    protected $description = 'Show the state of the second factor of every platform admin (or one): pending or active, how many recovery codes are left, whether the secret can still be read, and how many open sessions passed it. Never a secret';

    public function handle(PlatformAdminMfaEnrolment $enrolment, PlatformAdminSchema $schema, PlatformAdminSettings $settings): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $adminId = $this->argument('admin_id') === null ? null : $this->adminIdArgument();
        if ($this->argument('admin_id') !== null && $adminId === null) {
            return self::FAILURE;
        }

        try {
            $rows = $enrolment->status(adminId: $adminId);
        } catch (PlatformAdminMfaRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line($settings->mfaRequired() ? 'The second factor is required.' : 'The second factor is NOT required (GCC_PLATFORM_ADMIN_MFA_REQUIRED is off): staging only.');
        $this->table(
            ['Admin', 'Factor', 'Active since', 'Codes left', 'Secret', 'Sessions with it', 'Sessions without'],
            array_map(static fn (array $row): array => [
                $row['admin_id'], $row['factor'], $row['since'] ?? '-', $row['codes_left'] ?? '-', $row['secret'] ?? '-', $row['sessions_with'], $row['sessions_without'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
