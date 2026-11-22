<?php

namespace App\Services\Staff;

use App\Exceptions\StaffCountryScopeRefusedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giving an employee countries, taking them back and reading them. It is called only by the console commands an operator runs on the server: nothing a request
 * can reach creates or widens a scope. An employee with no country is unrestricted, so the one change that can widen an employee, taking back their last
 * country, has to be asked for by name.
 */
final class StaffCountryAssignments
{
    private const MASTER_ADMIN_ROLE_ID = 1;

    public function __construct(private readonly StaffCountryScope $scope)
    {
    }

    public function tableExists(): bool
    {
        return Schema::hasTable(StaffCountryScope::TABLE);
    }

    /**
     * The codes of a list the operator typed, in any case, as the countries they name.
     *
     * @return list<string>
     *
     * @throws StaffCountryScopeRefusedException
     */
    public function parseCountries(string $given): array
    {
        $codes = array_values(array_unique(array_filter(array_map(static fn (string $code): string => strtoupper(trim($code)), explode(',', $given)), static fn (string $code): bool => $code !== '')));

        if ($codes === []) {
            throw new StaffCountryScopeRefusedException('Name at least one country, as a comma separated list of codes.');
        }

        $configured = $this->scope->configuredCountries();
        foreach ($codes as $code) {
            if (!in_array($code, $configured, true)) {
                throw new StaffCountryScopeRefusedException("{$code} is not a configured country. The configured countries are " . implode(', ', $configured) . '.');
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * @return object{id: int, name: string|null, admin_role_id: int|null}|null
     */
    public function adminOf(int $adminId): ?object
    {
        return DB::table('admins')->where('id', $adminId)->first(['id', 'name', 'admin_role_id']);
    }

    /**
     * Adds the countries the employee does not hold yet.
     *
     * @param list<string> $countries codes the operator typed, already parsed
     * @return array{admin: object, added: list<string>, held: list<string>}
     *
     * @throws StaffCountryScopeRefusedException
     */
    public function assign(int $adminId, array $countries): array
    {
        $admin = $this->adminOf(adminId: $adminId) ?? throw new StaffCountryScopeRefusedException("There is no admin {$adminId}.");
        if ((int) $admin->admin_role_id === self::MASTER_ADMIN_ROLE_ID) {
            throw new StaffCountryScopeRefusedException("Admin {$adminId} is the master admin. The master admin is never restricted, so a country on it would do nothing.");
        }

        $held = $this->scope->storedCountriesOf(adminId: $adminId);
        $added = array_values(array_diff($countries, $held));
        $now = now();
        DB::table(StaffCountryScope::TABLE)->insertOrIgnore(array_map(static fn (string $country): array => ['admin_id' => $adminId, 'country' => $country, 'created_at' => $now, 'updated_at' => $now], $added));

        return ['admin' => $admin, 'added' => $added, 'held' => $this->scope->storedCountriesOf(adminId: $adminId)];
    }

    /**
     * Takes back the countries named, and refuses to leave the employee with none: that would make them unrestricted.
     *
     * @param list<string> $countries codes the operator typed, already parsed
     * @return array{admin: object|null, removed: list<string>, absent: list<string>, held: list<string>}
     *
     * @throws StaffCountryScopeRefusedException
     */
    public function revoke(int $adminId, array $countries): array
    {
        return DB::transaction(function () use ($adminId, $countries): array {
            $held = DB::table(StaffCountryScope::TABLE)->where('admin_id', $adminId)->lockForUpdate()->pluck('country')->map(static fn ($country): string => (string) $country)->all();
            $removed = array_values(array_intersect($countries, $held));

            if ($removed !== [] && array_diff($held, $removed) === []) {
                throw new StaffCountryScopeRefusedException('Taking back ' . implode(', ', $removed) . " would leave admin {$adminId} with no country, which makes the employee unrestricted. Use --all if that is what you mean.");
            }

            DB::table(StaffCountryScope::TABLE)->where('admin_id', $adminId)->whereIn('country', $removed)->delete();

            return ['admin' => $this->adminOf(adminId: $adminId), 'removed' => $removed, 'absent' => array_values(array_diff($countries, $held)), 'held' => array_values(array_diff($held, $removed))];
        });
    }

    /** Takes back every country of an employee, or the rows an admin that was deleted left behind. The employee is unrestricted afterwards. */
    public function revokeAll(int $adminId): int
    {
        return DB::table(StaffCountryScope::TABLE)->where('admin_id', $adminId)->delete();
    }

    /**
     * Every employee that has at least one stored value, or the one named, with what is stored for them.
     *
     * @return list<array{admin_id: int, name: string|null, role_id: int|null, stored: list<string>}>
     */
    public function scopes(?int $adminId = null): array
    {
        $rows = DB::table(StaffCountryScope::TABLE)
            ->leftJoin('admins', 'admins.id', '=', StaffCountryScope::TABLE . '.admin_id')
            ->when($adminId !== null, static fn ($query) => $query->where(StaffCountryScope::TABLE . '.admin_id', $adminId))
            ->orderBy(StaffCountryScope::TABLE . '.admin_id')
            ->orderBy(StaffCountryScope::TABLE . '.country')
            ->get([StaffCountryScope::TABLE . '.admin_id', StaffCountryScope::TABLE . '.country', 'admins.name', 'admins.admin_role_id']);

        $byAdmin = [];
        foreach ($rows as $row) {
            $byAdmin[$row->admin_id] ??= ['admin_id' => (int) $row->admin_id, 'name' => $row->name, 'role_id' => $row->admin_role_id === null ? null : (int) $row->admin_role_id, 'stored' => []];
            $byAdmin[$row->admin_id]['stored'][] = (string) $row->country;
        }

        return array_values($byAdmin);
    }
}
