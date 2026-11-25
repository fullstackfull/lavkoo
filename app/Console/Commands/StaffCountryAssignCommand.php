<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksStaffCountryCommandInput;
use App\Exceptions\StaffCountryScopeRefusedException;
use App\Services\Staff\StaffCountryAssignments;
use App\Services\Staff\StaffCountryScope;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class StaffCountryAssignCommand extends Command
{
    use ChecksStaffCountryCommandInput;

    protected $signature = 'gcc:staff-country:assign
        {admin_id : The id of the employee}
        {countries : Comma-separated country codes the employee may work with, such as SA,KW}
        {--granted-by= : The id of the admin who decided this, recorded on every row the grant creates}
        {--expires= : When the grant ends, as a date or date and time (2027-01-31, "2027-01-31 18:00"). Left out, it lasts until it is revoked}';

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
            $result = $assignments->assign(
                adminId: $adminId,
                countries: $assignments->parseCountries(given: (string) $this->argument('countries')),
                grantedBy: $this->grantedBy(),
                expiresAt: $this->expiresAt(),
            );
        } catch (StaffCountryScopeRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Admin %d (%s) works with %s only.', $adminId, $result['admin']->name, implode(', ', $result['held'])));
        $this->line($result['added'] === [] ? "Nothing to add: admin {$adminId} already had them." : 'Added ' . implode(', ', $result['added']) . '.');
        $this->sayWhetherScopesAreEnforced(scope: $scope);

        return self::SUCCESS;
    }

    /** @throws StaffCountryScopeRefusedException when the option is not a number */
    private function grantedBy(): ?int
    {
        $given = trim((string) $this->option('granted-by'));

        if ($given === '') {
            return null;
        }

        if (!ctype_digit($given) || (int) $given < 1) {
            throw new StaffCountryScopeRefusedException('--granted-by must be the id of an admin.');
        }

        return (int) $given;
    }

    /** @throws StaffCountryScopeRefusedException when the option is not a time this platform can read */
    private function expiresAt(): ?CarbonInterface
    {
        $given = trim((string) $this->option('expires'));

        if ($given === '') {
            return null;
        }

        try {
            return Carbon::parse($given);
        } catch (\Throwable) {
            throw new StaffCountryScopeRefusedException("--expires is not a time this platform can read: {$given}. Use 2027-01-31 or \"2027-01-31 18:00\".");
        }
    }
}
