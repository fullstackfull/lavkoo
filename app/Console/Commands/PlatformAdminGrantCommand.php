<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Exceptions\PlatformAdminGrantRefusedException;
use App\Services\PlatformAdmin\PlatformAdminGrantService;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;

class PlatformAdminGrantCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:grant
        {admin_id : The id of the admin}
        {--countries= : Comma-separated country codes. Leave it out for every market (the platform owner)}
        {--level=1 : 1 is monitor, the only level there is}';

    protected $description = 'Let an admin use the platform admin API, for every market or only the countries named. Only an operator on the server can do this; no request can';

    public function handle(PlatformAdminGrantService $grants, PlatformAdminSchema $schema): int
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

        return self::SUCCESS;
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
