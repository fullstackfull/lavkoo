<?php

namespace App\Services\Staff;

use App\Exceptions\StaffCountryScopeRefusedException;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
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

    public function __construct(private readonly StaffCountryScope $scope, private readonly AuditLogger $audit)
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
    public function assign(int $adminId, array $countries, ?int $grantedBy = null, ?CarbonInterface $expiresAt = null): array
    {
        $admin = $this->adminOf(adminId: $adminId) ?? throw new StaffCountryScopeRefusedException("There is no admin {$adminId}.");
        if ((int) $admin->admin_role_id === self::MASTER_ADMIN_ROLE_ID) {
            throw new StaffCountryScopeRefusedException("Admin {$adminId} is the master admin. The master admin is never restricted, so a country on it would do nothing.");
        }

        if ($grantedBy !== null && $this->adminOf(adminId: $grantedBy) === null) {
            throw new StaffCountryScopeRefusedException("There is no admin {$grantedBy} to record as the granter.");
        }

        if ($expiresAt !== null && !$expiresAt->isFuture()) {
            throw new StaffCountryScopeRefusedException('An expiry in the past would grant nothing. Name a time in the future, or leave it out for a scope that lasts until it is revoked.');
        }

        $held = $this->scope->storedCountriesOf(adminId: $adminId);
        $added = array_values(array_diff($countries, $held));
        $now = now();
        $trail = $this->grantTrail(grantedBy: $grantedBy, expiresAt: $expiresAt);
        DB::table(StaffCountryScope::TABLE)->insertOrIgnore(array_map(static fn (string $country): array => $trail + ['admin_id' => $adminId, 'country' => $country, 'created_at' => $now, 'updated_at' => $now], $added));

        $outcome = ['admin' => $admin, 'added' => $added, 'held' => $this->scope->storedCountriesOf(adminId: $adminId)];

        // A permission change whose line cannot be written is not a permission change this platform makes (G1-08): recordOrFail, not
        // record. The rows and the trail are the whole record of who may see which country's customers.
        if ($added !== []) {
            $this->audit->recordOrFail(
                action: 'staff_country_scope.granted',
                subject: ['type' => 'admin', 'id' => $adminId],
                before: ['countries' => $held],
                after: ['countries' => $outcome['held']],
                context: array_filter(['granted' => $added, 'granted_by' => $grantedBy, 'expires_at' => $expiresAt?->toIso8601String(), 'by' => 'console']),
            );
        }

        return $outcome;
    }

    /**
     * The two facts a grant records about itself, while the columns are there to hold them (G1-14).
     *
     * Before the migration this is empty and a grant is the row it has always been. `granted_by` left empty means "granted on the server
     * console before this was recorded", which is what every existing row is.
     *
     * @return array<string, mixed>
     */
    private function grantTrail(?int $grantedBy, ?CarbonInterface $expiresAt): array
    {
        if (!$this->scope->rowsRecordTheirGrant()) {
            return [];
        }

        return ['granted_by' => $grantedBy, 'expires_at' => $expiresAt];
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

            $left = array_values(array_diff($held, $removed));

            if ($removed !== []) {
                $this->audit->recordOrFail(
                    action: 'staff_country_scope.revoked',
                    subject: ['type' => 'admin', 'id' => $adminId],
                    before: ['countries' => $held],
                    after: ['countries' => $left],
                    context: ['revoked' => $removed, 'by' => 'console'],
                );
            }

            return ['admin' => $this->adminOf(adminId: $adminId), 'removed' => $removed, 'absent' => array_values(array_diff($countries, $held)), 'held' => $left];
        });
    }

    /**
     * Takes back every country of an employee, or the rows an admin that was deleted left behind. The employee is unrestricted afterwards,
     * which is why this one is asked for by name -- and why its line names what it widened.
     */
    public function revokeAll(int $adminId): int
    {
        $held = $this->scope->storedCountriesOf(adminId: $adminId);
        $removed = DB::table(StaffCountryScope::TABLE)->where('admin_id', $adminId)->delete();

        if ($removed > 0) {
            $this->audit->recordOrFail(
                action: 'staff_country_scope.revoked_all',
                subject: ['type' => 'admin', 'id' => $adminId],
                before: ['countries' => $held],
                after: ['countries' => [], 'restricted' => false],
                context: ['rows' => $removed, 'by' => 'console', 'widens' => 'the employee holds no country afterwards, which makes them unrestricted'],
            );
        }

        return $removed;
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
