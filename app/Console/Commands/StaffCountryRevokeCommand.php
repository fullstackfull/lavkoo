<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksStaffCountryCommandInput;
use App\Exceptions\StaffCountryScopeRefusedException;
use App\Services\Staff\StaffCountryAssignments;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Console\Command;

class StaffCountryRevokeCommand extends Command
{
    use ChecksStaffCountryCommandInput;

    protected $signature = 'gcc:staff-country:revoke
        {admin_id : The id of the employee}
        {countries? : Comma-separated country codes to take back}
        {--all : Take back every country, which makes the employee unrestricted}';

    protected $description = 'Take countries back from an employee. Taking back the last one makes the employee unrestricted, so it needs --all. Only an operator on the server can do this';

    public function handle(StaffCountryAssignments $assignments, StaffCountryScope $scope): int
    {
        if ($this->tableMissing(assignments: $assignments)) {
            return self::FAILURE;
        }

        $adminId = $this->adminIdArgument();
        if ($adminId === null) {
            return self::FAILURE;
        }

        $given = $this->argument('countries');
        if ($this->option('all') === ($given !== null)) {
            $this->error($given === null ? 'Name the countries to take back, or use --all.' : 'Use either a list of countries or --all, not both.');

            return self::FAILURE;
        }

        try {
            return $this->option('all') ? $this->revokeAll(assignments: $assignments, adminId: $adminId, scope: $scope) : $this->revokeNamed(assignments: $assignments, adminId: $adminId, given: (string) $given, scope: $scope);
        } catch (StaffCountryScopeRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }
    }

    private function revokeNamed(StaffCountryAssignments $assignments, int $adminId, string $given, StaffCountryScope $scope): int
    {
        $result = $assignments->revoke(adminId: $adminId, countries: $assignments->parseCountries(given: $given));
        $name = $result['admin']->name ?? 'no such admin';

        if ($result['absent'] !== []) {
            $this->line("Admin {$adminId} ({$name}) did not have " . implode(', ', $result['absent']) . '.');
        }
        $this->info($result['held'] === [] ? "Admin {$adminId} ({$name}) has no country any more and is not restricted." : sprintf('Admin %d (%s) works with %s only.', $adminId, $name, implode(', ', $result['held'])));
        $this->sayWhetherScopesAreEnforced(scope: $scope);

        return self::SUCCESS;
    }

    private function revokeAll(StaffCountryAssignments $assignments, int $adminId, StaffCountryScope $scope): int
    {
        $assignments->revokeAll(adminId: $adminId);

        $this->info(sprintf('Admin %d (%s) has no country any more and is not restricted.', $adminId, $assignments->adminOf(adminId: $adminId)->name ?? 'no such admin'));
        $this->sayWhetherScopesAreEnforced(scope: $scope);

        return self::SUCCESS;
    }
}
