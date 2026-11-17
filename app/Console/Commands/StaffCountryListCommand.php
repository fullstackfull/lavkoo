<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksStaffCountryCommandInput;
use App\Services\Staff\StaffCountryAssignments;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Console\Command;

class StaffCountryListCommand extends Command
{
    use ChecksStaffCountryCommandInput;

    protected $signature = 'gcc:staff-country:list
        {admin_id? : The id of one employee. Leave it out to list everyone who has a country}';

    protected $description = 'Show the countries employees work with, what they come to, and whether they are enforced. Read only';

    public function handle(StaffCountryAssignments $assignments, StaffCountryScope $scope): int
    {
        if ($this->tableMissing(assignments: $assignments)) {
            return self::FAILURE;
        }

        $named = $this->argument('admin_id') !== null;
        $adminId = $named ? $this->adminIdArgument() : null;
        if ($named && $adminId === null) {
            return self::FAILURE;
        }

        $scopes = $assignments->scopes(adminId: $adminId);
        if ($adminId !== null) {
            $this->describeOne(assignments: $assignments, scope: $scope, adminId: $adminId, scopes: $scopes);
        } elseif ($scopes === []) {
            $this->line('No employee has a country scope: every employee is unrestricted.');
        } else {
            $this->describeAll(scope: $scope, scopes: $scopes);
        }

        $this->sayWhetherScopesAreEnforced(scope: $scope);

        return self::SUCCESS;
    }

    /** @param list<array{admin_id: int, name: string|null, role_id: int|null, stored: list<string>}> $scopes */
    private function describeAll(StaffCountryScope $scope, array $scopes): void
    {
        $this->table(['Admin', 'Name', 'Stored', 'Works with'], array_map(fn (array $row): array => [
            $row['admin_id'],
            $row['name'] ?? '(no such admin)',
            implode(', ', $row['stored']),
            $this->worksWith(scope: $scope, row: $row),
        ], $scopes));

        foreach ($scopes as $row) {
            $this->flagValuesThatGrantNothing(scope: $scope, row: $row);
        }
    }

    /** @param list<array{admin_id: int, name: string|null, role_id: int|null, stored: list<string>}> $scopes */
    private function describeOne(StaffCountryAssignments $assignments, StaffCountryScope $scope, int $adminId, array $scopes): void
    {
        $admin = $assignments->adminOf(adminId: $adminId);
        $label = sprintf('Admin %d (%s)', $adminId, $admin->name ?? 'no such admin');
        $row = $scopes[0] ?? ['admin_id' => $adminId, 'name' => $admin?->name, 'role_id' => $admin?->admin_role_id === null ? null : (int) $admin->admin_role_id, 'stored' => []];

        if ($admin !== null && (int) $admin->admin_role_id === 1) {
            $this->line("{$label}: the master admin is never restricted." . ($row['stored'] === [] ? '' : ' Stored, and ignored: ' . implode(', ', $row['stored']) . '.'));

            return;
        }

        if ($row['stored'] === []) {
            $this->line("{$label} has no country scope and is not restricted.");

            return;
        }

        $this->line("{$label} works with " . $this->worksWith(scope: $scope, row: $row) . '.');
        $this->flagValuesThatGrantNothing(scope: $scope, row: $row);
    }

    /** @param array{admin_id: int, name: string|null, role_id: int|null, stored: list<string>} $row */
    private function worksWith(StaffCountryScope $scope, array $row): string
    {
        $granted = $scope->resolve(adminId: $row['admin_id'], roleId: $row['role_id']);

        return match (true) {
            $granted === null => 'every country (never restricted)',
            $granted === [] => 'no country: every value stored for it grants nothing',
            default => implode(', ', $granted) . ' only',
        };
    }

    /** @param array{admin_id: int, name: string|null, role_id: int|null, stored: list<string>} $row */
    private function flagValuesThatGrantNothing(StaffCountryScope $scope, array $row): void
    {
        foreach (array_diff($row['stored'], $scope->configuredCountries()) as $stored) {
            $this->warn("Stored value {$stored} grants nothing: it is not a configured country. It keeps the employee restricted; run gcc:staff-country:revoke --all to remove it.");
        }
    }
}
