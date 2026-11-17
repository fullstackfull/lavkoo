<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Exceptions\PlatformAdminGrantRefusedException;
use App\Models\Admin;
use App\Services\PlatformAdmin\PlatformAdminGrantService;
use App\Services\PlatformAdmin\PlatformAdminMfaFactors;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use Illuminate\Console\Command;

class PlatformAdminGrantCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:grant
        {admin_id : The id of the admin}
        {--countries= : Comma-separated country codes. Leave it out for every market (the platform owner)}
        {--level=1 : 1 is monitor, the only level there is}';

    protected $description = 'Let an admin use the platform admin API, for every market or only the countries named. Only an operator on the server can do this; no request can';

    public function handle(PlatformAdminGrantService $grants, PlatformAdminSchema $schema, PlatformAdminSettings $settings, PlatformAdminMfaFactors $factors): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $adminId = $this->adminIdArgument();
        $level = $this->levelOption();
        if ($adminId === null || $level === null) {
            return self::FAILURE;
        }

        try {
            $grant = $grants->grant(adminId: $adminId, countries: $this->countriesOption(), level: $level);
        } catch (PlatformAdminGrantRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $countries = $grant->countryList();
        $this->info(sprintf('Admin %d may use the platform admin API at level %d (monitor) for %s.', $adminId, $level, $countries === null ? 'every market' : implode(', ', $countries) . ' only'));

        if ($grant->wasChanged('admin_fingerprint')) {
            $this->warn('The grant that existed had been made for another admin row that carried this id. It now belongs to the row that carries the id, and the sessions of the old row have ended.');
        }

        $this->adviseOnTheSecondFactor(adminId: $adminId, settings: $settings, factors: $factors);

        return self::SUCCESS;
    }

    /** What the grant does not give the admin: while the second factor is required they cannot sign in without a confirmed one; while it is not, nobody is asked for one. */
    private function adviseOnTheSecondFactor(int $adminId, PlatformAdminSettings $settings, PlatformAdminMfaFactors $factors): void
    {
        if (!$settings->mfaRequired()) {
            $this->warn('The second factor is NOT required (GCC_PLATFORM_ADMIN_MFA_REQUIRED is off): every sign-in is an e-mail and a password alone. That is for staging only.');

            return;
        }

        $admin = Admin::query()->select(['id', 'created_at'])->find($adminId);
        $factor = $factors->forAdmin(adminId: $adminId);

        if ($admin === null || $factor === null || !$factor->isActiveFor(admin: $admin)) {
            $this->warn("Admin {$adminId} has no confirmed second factor, so they cannot sign in yet. Run: php artisan gcc:platform-admin:mfa:enroll {$adminId}, then gcc:platform-admin:mfa:confirm {$adminId} <code>.");
        }
    }

    /** @return list<string>|null null when the option is absent or empty, which is every market */
    private function countriesOption(): ?array
    {
        $given = $this->option('countries');

        return $given === null || trim((string) $given) === '' ? null : explode(',', (string) $given);
    }

    private function levelOption(): ?int
    {
        $given = (string) $this->option('level');

        if (preg_match('/^[0-9]+$/', $given) !== 1) {
            $this->error('The level must be a whole number, and only level 1 (monitor) exists.');

            return null;
        }

        return (int) $given;
    }
}
