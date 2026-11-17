<?php

namespace App\Console\Commands\Concerns;

use App\Services\Staff\StaffCountryAssignments;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Schema;

/** What the commands that manage the countries of employees check before they change or show anything: that the table exists, which admin they were asked about, and whether anything they save is enforced yet. */
trait ChecksStaffCountryCommandInput
{
    /** Tells the operator, and answers true, when the migration of the staff countries has not run. */
    private function tableMissing(StaffCountryAssignments $assignments): bool
    {
        if ($assignments->tableExists()) {
            return false;
        }

        $this->error('The table ' . StaffCountryScope::TABLE . ' does not exist yet. Run the migrations first.');

        return true;
    }

    /** The admin id argument, or null, after telling the operator, when it is not a positive whole number. */
    private function adminIdArgument(): ?int
    {
        $given = (string) $this->argument('admin_id');

        if (preg_match('/^[1-9][0-9]*$/', $given) !== 1) {
            $this->error('The admin id must be a positive whole number.');

            return null;
        }

        return (int) $given;
    }

    /** Says what keeps what the operator saved from being enforced, when something does. */
    private function sayWhetherScopesAreEnforced(StaffCountryScope $scope): void
    {
        if (!$scope->isOn()) {
            $this->warn('GCC_STAFF_COUNTRY_SCOPES is off: the countries are saved and nothing is enforced yet.');

            return;
        }

        if (!Schema::hasColumn('products', StorefrontCatalog::COLUMN)) {
            $this->warn('products.' . StorefrontCatalog::COLUMN . ' does not exist yet: nothing is enforced until the products migration has run.');

            return;
        }

        $this->info('Scopes are enforced.');
    }
}
