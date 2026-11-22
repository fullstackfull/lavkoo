<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use App\Services\PlatformAdmin\PlatformEffectiveMarkets;
use Illuminate\Console\Command;

class PlatformAdminListCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:list';

    protected $description = 'Show every grant of the platform admin API: the admin, the markets, the parts of the API, the day it ends and where it stands. Reads only';

    public function handle(PlatformAdminSchema $schema, PlatformEffectiveMarkets $effectiveMarkets): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $grants = PlatformAdminGrant::query()->orderBy('admin_id')->get();

        if ($grants->isEmpty()) {
            $this->line('No grant has been made.');

            return self::SUCCESS;
        }

        $admins = Admin::query()->select($effectiveMarkets->columnsToRead(columns: ['id', 'name', 'status', 'created_at']))->whereIn('id', $grants->pluck('admin_id'))->get()->keyBy('id');
        $staffScope = $effectiveMarkets->staffScopeIsEnforced() ? $effectiveMarkets : null;
        $columns = ['Admin', 'Name', 'Level', 'Markets', 'Capabilities', 'Ends', 'State'];

        $this->table(
            $staffScope === null ? $columns : [...$columns, 'Staff countries', 'Effective markets'],
            $grants->map(fn (PlatformAdminGrant $grant): array => $this->rowOf(grant: $grant, admin: $admins->get($grant->admin_id), staffScope: $staffScope))->all(),
        );

        return self::SUCCESS;
    }

    /** @return list<int|string> */
    private function rowOf(PlatformAdminGrant $grant, ?Admin $admin, ?PlatformEffectiveMarkets $staffScope): array
    {
        $countries = $grant->countryList();
        $row = [
            $grant->admin_id,
            $admin?->name ?? '-',
            $grant->level,
            $countries === null ? 'every market' : implode(', ', $countries),
            ($grant->getAttributes()['capabilities'] ?? null) === null ? 'every capability' : implode(', ', $grant->capabilityList()),
            $grant->expires_at?->toDateTimeString() ?? '-',
            $this->stateOf(grant: $grant, admin: $admin),
        ];

        return $staffScope === null ? $row : [...$row, ...$this->staffCellsOf(grant: $grant, admin: $admin, staffScope: $staffScope)];
    }

    /**
     * What the staff scope restricts the admin to, and the markets they come to once it is put on the grant. An admin it does not restrict has the markets of the grant.
     *
     * @return list<string>
     */
    private function staffCellsOf(PlatformAdminGrant $grant, ?Admin $admin, PlatformEffectiveMarkets $staffScope): array
    {
        if ($admin === null) {
            return ['-', '-'];
        }

        $reach = $staffScope->of(grant: $grant, admin: $admin);

        return [
            $reach->staffCountries === null ? '-' : $this->countriesCell(countries: $reach->staffCountries),
            $reach->restrictedByStaffScope() ? $this->countriesCell(countries: $reach->markets) : $this->countriesCell(countries: $grant->countryList(), whenNull: 'every market'),
        ];
    }

    /** @param list<string>|null $countries */
    private function countriesCell(?array $countries, string $whenNull = '-'): string
    {
        return match (true) {
            $countries === null => $whenNull,
            $countries === [] => 'none',
            default => implode(', ', $countries),
        };
    }

    /** Where the grant stands, the first of these that is so: its admin is gone, is another row, the grant was revoked, it has ended, its admin is inactive; else active. */
    private function stateOf(PlatformAdminGrant $grant, ?Admin $admin): string
    {
        return match (true) {
            $admin === null => 'admin missing',
            !$grant->isMadeFor(admin: $admin) => 'admin row replaced',
            $grant->isWithdrawn() => 'revoked',
            $grant->hasExpired() => 'expired',
            (int) $admin->getRawOriginal('status') !== 1 => 'admin inactive',
            default => 'active',
        };
    }
}
