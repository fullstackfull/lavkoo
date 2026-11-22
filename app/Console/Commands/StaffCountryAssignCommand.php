<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksStaffCountryCommandInput;
use App\Exceptions\StaffCountryScopeRefusedException;
use App\Services\Staff\StaffCountryAssignments;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Console\Command;

class StaffCountryAssignCommand extends Command
{
    use ChecksStaffCountryCommandInput;

    protected $signature = 'gcc:staff-country:assign
        {admin_id : The id of the employee}
        {countries : Comma-separated country codes the employee may work with, such as SA,KW}';

    protected $description = 'Let an employee work with the countries named, in addition to those they already have. An employee with no country is not restricted. Only an operator on the server can do this; no request can';

    public function handle(StaffCountryAssignments $assignments, StaffCountryScope $scope): int
    {
        if ($this->tableMissing(assignments: $assignments)) {
            return self::FAILURE;
        }

        $adminId = $this->adminIdArgument();
        if ($adminId === null) {
            return self::FAILURE;
        }

        try {
            $result = $assignments->assign(adminId: $adminId, countries: $assignments->parseCountries(given: (string) $this->argument('countries')));
        } catch (StaffCountryScopeRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Admin %d (%s) works with %s only.', $adminId, $result['admin']->name, implode(', ', $result['held'])));
        $this->line($result['added'] === [] ? "Nothing to add: admin {$adminId} already had them." : 'Added ' . implode(', ', $result['added']) . '.');
        $this->sayWhetherScopesAreEnforced(scope: $scope);

        return self::SUCCESS;
    }
}
