<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ChecksPlatformAdminCommandInput;
use App\Exceptions\PlatformAdminGrantRefusedException;
use App\Models\Admin;
use App\Models\PlatformAdminGrant;
use App\Services\PlatformAdmin\PlatformAdminGrantService;
use App\Services\PlatformAdmin\PlatformAdminMfaFactors;
use App\Services\PlatformAdmin\PlatformAdminSchema;
use App\Services\PlatformAdmin\PlatformAdminSettings;
use App\Services\PlatformAdmin\PlatformEffectiveMarkets;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class PlatformAdminGrantCommand extends Command
{
    use ChecksPlatformAdminCommandInput;

    protected $signature = 'gcc:platform-admin:grant
        {admin_id : The id of the admin}
        {--countries= : Comma-separated country codes. Leave it out for every market (the platform owner), which needs --confirm-owner}
        {--capabilities= : Comma-separated parts of the API the grant includes: overview, markets, alerts, audit, sessions, health, reports, export, orders, returns, products, inventory. Leave it out for every one a grant holds by default (not health, reports, export, orders, returns, products or inventory)}
        {--expires= : The last day the grant is good for, written YYYY-MM-DD. Leave it out for no end}
        {--confirm-owner : Says that a grant for every market, the platform owner\'s, is what is meant. Refused beside --countries}
        {--level=1 : 1 is monitor, the only level there is}';

    protected $description = 'Make the whole grant of an admin to use the platform admin API: the markets, the parts of the API and the day it ends. Only an operator on the server can do this; no request can';

    public function handle(PlatformAdminGrantService $grants, PlatformAdminSchema $schema, PlatformAdminSettings $settings, PlatformAdminMfaFactors $factors, PlatformEffectiveMarkets $effectiveMarkets): int
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
            $countries = $this->countriesOption();
            $this->requireTheOwnerToBeConfirmedForEveryMarket(countries: $countries);
            $grant = $grants->grant(adminId: $adminId, countries: $countries, level: $level, capabilities: $this->capabilitiesOption(), expiresAt: $this->expiresOption());
        } catch (PlatformAdminGrantRefusedException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Admin %d may use the platform admin API at level %d (monitor) for %s.', $adminId, $level, $this->describedGrant(grant: $grant)));

        if ($grant->wasChanged('admin_fingerprint')) {
            $this->warn('The grant that existed had been made for another admin row that carried this id. It now belongs to the row that carries the id, and the sessions of the old row have ended.');
        }

        $this->adviseOnTheSecondFactor(adminId: $adminId, settings: $settings, factors: $factors);
        $this->adviseOnTheStaffScope(adminId: $adminId, grant: $grant, effectiveMarkets: $effectiveMarkets);

        return self::SUCCESS;
    }

    /**
     * Read-only advice: while the staff country scopes are on, an employee they restrict in the admin panel is restricted here too, whatever markets the grant names. The grant is
     * as the operator gave it; the staff scope is for the staff commands to change.
     */
    private function adviseOnTheStaffScope(int $adminId, PlatformAdminGrant $grant, PlatformEffectiveMarkets $effectiveMarkets): void
    {
        if (!$effectiveMarkets->staffScopeIsEnforced()) {
            return;
        }

        $reach = $effectiveMarkets->of(grant: $grant, admin: Admin::query()->select(['id', 'admin_role_id'])->findOrFail($adminId));

        if (!$reach->narrowsTheGrant()) {
            return;
        }

        $staffCountries = $reach->staffCountries ?? [];
        $this->warn(sprintf(
            'Staff country scopes are ON and admin %d is restricted to %s in the admin panel: through the platform admin API they will see %s, not all of %s. The grant is as given; nothing was changed.',
            $adminId,
            $staffCountries === [] ? 'no country' : implode(', ', $staffCountries),
            $reach->markets === [] ? 'no market' : implode(', ', $reach->markets),
            $grant->countryList() === null ? 'every market' : implode(', ', $reach->granted),
        ));
    }

    /** What the grant does not give the admin: while the second factor is required they cannot sign in without a confirmed one; while it is not, nobody is asked for one. */
    private function adviseOnTheSecondFactor(int $adminId, PlatformAdminSettings $settings, PlatformAdminMfaFactors $factors): void
    {
        if (!$settings->mfaRequired()) {
            $this->warn('The second factor is NOT required (GCC_PLATFORM_ADMIN_MFA_REQUIRED is off): every sign-in is an e-mail and a password alone. That is for staging only.');

            return;
        }

        $admin = Admin::query()->select(['id', 'created_at'])->find($adminId);
        $factor = $factors->forAdmin(adminId: $adminId);

        if ($admin === null || $factor === null || !$factor->isActiveFor(admin: $admin)) {
            $this->warn("Admin {$adminId} has no confirmed second factor, so they cannot sign in yet. Run: php artisan gcc:platform-admin:mfa:enroll {$adminId}, then gcc:platform-admin:mfa:confirm {$adminId} <code>.");
        }
    }

    /**
     * A grant that names no country is a grant for every market, and that is not to be made by leaving something out: the operator says it is meant.
     *
     * @param list<string>|null $countries
     *
     * @throws PlatformAdminGrantRefusedException
     */
    private function requireTheOwnerToBeConfirmedForEveryMarket(?array $countries): void
    {
        $confirmed = (bool) $this->option('confirm-owner');

        if ($countries === null && !$confirmed) {
            throw new PlatformAdminGrantRefusedException('A grant without --countries is a grant for every market, the platform owner\'s. Run it again with --confirm-owner to say that is meant, or name the countries.');
        }

        if ($countries !== null && $confirmed) {
            throw new PlatformAdminGrantRefusedException('--confirm-owner says the grant is for every market, and --countries names some: leave one of them out.');
        }
    }

    /** What the grant is, in the words of the line the command ends with: the markets, the parts of the API, and when it ends. */
    private function describedGrant(PlatformAdminGrant $grant): string
    {
        $countries = $grant->countryList();
        $capabilities = ($grant->getAttributes()['capabilities'] ?? null) === null ? null : $grant->capabilityList();

        return implode(', ', [
            $countries === null ? 'every market' : implode(', ', $countries) . ' only',
            $capabilities === null ? 'every capability' : 'capabilities: ' . implode(', ', $capabilities),
            $grant->expires_at === null ? 'with no end' : 'until the end of ' . $grant->expires_at->format('Y-m-d'),
        ]);
    }

    /**
     * @return list<string>|null null when the option is absent, which is every capability
     */
    private function capabilitiesOption(): ?array
    {
        $given = $this->option('capabilities');

        return $given === null ? null : explode(',', (string) $given);
    }

    /**
     * The end of the day the operator named, in the time zone the platform stamps its rows in, or null when none was named.
     *
     * @throws PlatformAdminGrantRefusedException
     */
    private function expiresOption(): ?CarbonInterface
    {
        $given = $this->option('expires');

        if ($given === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $given, $parts) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new PlatformAdminGrantRefusedException('The day a grant ends on must be written YYYY-MM-DD, as a day that exists.');
        }

        return Carbon::create((int) $parts[1], (int) $parts[2], (int) $parts[3])->endOfDay();
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
