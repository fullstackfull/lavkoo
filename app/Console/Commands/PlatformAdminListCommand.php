<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use Illuminate\Console\Command;

class PlatformAdminListCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:list';

    protected $description = 'Show every grant of the platform admin API: the admin, the markets, the parts of the API, the day it ends and where it stands. Reads only';

    public function handle(PlatformAdminSchema $schema): int
    {
        if ($this->migrationsMissing(schema: $schema)) {
            return self::FAILURE;
        }

        $grants = PlatformAdminGrant::query()->orderBy('admin_id')->get();

        if ($grants->isEmpty()) {
            $this->line('No grant has been made.');

            return self::SUCCESS;
        }

        $admins = Admin::query()->select(['id', 'name', 'status', 'created_at'])->whereIn('id', $grants->pluck('admin_id'))->get()->keyBy('id');

        $this->table(
            ['Admin', 'Name', 'Level', 'Markets', 'Capabilities', 'Ends', 'State'],
            $grants->map(fn (PlatformAdminGrant $grant): array => $this->rowOf(grant: $grant, admin: $admins->get($grant->admin_id)))->all(),
        );

        return self::SUCCESS;
    }

    /** @return list<int|string> */
    private function rowOf(PlatformAdminGrant $grant, ?Admin $admin): array
    {
        $countries = $grant->countryList();

        return [
            $grant->admin_id,
            $admin?->name ?? '-',
            $grant->level,
            $countries === null ? 'every market' : implode(', ', $countries),
            ($grant->getAttributes()['capabilities'] ?? null) === null ? 'every capability' : implode(', ', $grant->capabilityList()),
            $grant->expires_at?->toDateTimeString() ?? '-',
            $this->stateOf(grant: $grant, admin: $admin),
        ];
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
