<?php

namespace Tests\Feature\StaffScope;

use App\Models\AuditLog;
use App\Services\Staff\StaffCountryAssignments;
use App\Services\AuditLogger;
use App\Services\Staff\StaffCountryScope;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * A staff country scope records who granted it and when it ends, and granting or taking one back writes an audit line (G1-14, G1-08).
 *
 * The rows said only "admin 7 works with Kuwait". Who decided that lived nowhere, nothing said whether it was meant to be permanent, and
 * the commands that change who may see a country's customers wrote no line at all -- so a change of permissions was the one kind of change
 * this platform did not record.
 *
 * **The expiry is the dangerous half, and it is dangerous in the direction nobody expects.** An employee with NO row is UNRESTRICTED here,
 * so "ignore the expired rows" written as one query would turn the last row's expiry into a privilege escalation on a timer. An employee
 * whose every row has expired must be restricted to NOTHING, and that is the test this class exists for.
 */
class StaffScopeGrantTrailTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use BuildsStorefrontCatalog;

    private const EMPLOYEE = 2;

    private const MASTER = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->runStorefrontCountryMigration();
        $this->buildStaffAdminTables();
        $this->runStaffScopeMigration();
        $this->switchStaffScopesOn();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_logs');

        parent::tearDown();
    }

    public function test_a_grant_records_who_decided_it_and_when_it_ends(): void
    {
        $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['KW'], grantedBy: self::MASTER, expiresAt: now()->addDays(7));

        $row = DB::table(StaffCountryScope::TABLE)->where('admin_id', self::EMPLOYEE)->first();

        $this->assertSame(self::MASTER, (int) $row->granted_by);
        $this->assertNotNull($row->expires_at);
    }

    public function test_a_grant_with_no_trail_lasts_until_it_is_revoked(): void
    {
        $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['KW']);

        $row = DB::table(StaffCountryScope::TABLE)->where('admin_id', self::EMPLOYEE)->first();

        $this->assertNull($row->granted_by);
        $this->assertNull($row->expires_at);
        $this->assertSame(['KW'], $this->scope()->resolve(adminId: self::EMPLOYEE, roleId: 2));
    }

    /**
     * The one that matters: an employee whose only country has expired holds NOTHING, and is not read as unrestricted. Getting this
     * backwards would give a temporary employee every country the day their grant ended.
     */
    public function test_an_employee_whose_every_grant_has_expired_is_restricted_to_nothing_and_never_widened(): void
    {
        $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['KW'], expiresAt: now()->addHour());

        $this->assertSame(['KW'], $this->scope()->resolve(adminId: self::EMPLOYEE, roleId: 2));

        $this->travelTo(now()->addDay());

        $this->assertSame([], $this->scope()->resolve(adminId: self::EMPLOYEE, roleId: 2), 'restricted to nothing, NOT unrestricted');
        $this->assertSame([], $this->scope()->countriesStillInForceFor(adminId: self::EMPLOYEE));
        $this->assertSame(['KW'], $this->scope()->storedCountriesOf(adminId: self::EMPLOYEE), 'the row is still there: an expiry is not a deletion');
    }

    public function test_an_expired_grant_beside_a_live_one_leaves_only_the_live_one(): void
    {
        $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['KW'], expiresAt: now()->addHour());
        $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['SA']);

        $this->travelTo(now()->addDay());

        $this->assertSame(['SA'], $this->scope()->resolve(adminId: self::EMPLOYEE, roleId: 2));
    }

    public function test_an_expiry_already_past_and_a_granter_that_does_not_exist_are_refused(): void
    {
        foreach ([
            ['expiresAt' => now()->subDay(), 'grantedBy' => null, 'says' => 'expiry in the past'],
            ['expiresAt' => null, 'grantedBy' => 9999, 'says' => 'granter that does not exist'],
        ] as $refused) {
            try {
                $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['KW'], grantedBy: $refused['grantedBy'], expiresAt: $refused['expiresAt']);
                $this->fail('the ' . $refused['says'] . ' was accepted');
            } catch (\App\Exceptions\StaffCountryScopeRefusedException) {
                $this->assertSame(0, DB::table(StaffCountryScope::TABLE)->count(), 'and nothing was written');
            }
        }
    }

    public function test_granting_taking_back_and_taking_back_everything_each_write_a_line(): void
    {
        $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['KW', 'SA'], grantedBy: self::MASTER);
        $this->assignments()->revoke(adminId: self::EMPLOYEE, countries: ['SA']);
        $this->assignments()->revokeAll(adminId: self::EMPLOYEE);

        $this->assertSame(
            ['staff_country_scope.granted', 'staff_country_scope.revoked', 'staff_country_scope.revoked_all'],
            AuditLog::query()->orderBy('id')->pluck('action')->all(),
        );

        $granted = AuditLog::query()->where('action', 'staff_country_scope.granted')->firstOrFail();
        $this->assertSame('admin', $granted->subject_type);
        $this->assertSame((string) self::EMPLOYEE, (string) $granted->subject_id);
        $this->assertSame([], $granted->before['countries'], 'before: the employee held nothing');
        $this->assertSame(['KW', 'SA'], $granted->after['countries']);
        $this->assertSame(self::MASTER, $granted->context['granted_by']);

        $all = AuditLog::query()->where('action', 'staff_country_scope.revoked_all')->firstOrFail();
        $this->assertFalse($all->after['restricted'], 'the line says the employee is unrestricted afterwards, which is what revoke --all means');
    }

    /** A change of permissions whose line cannot be written is not a change this platform makes: recordOrFail, not record. */
    public function test_a_grant_is_refused_when_the_trail_cannot_be_written(): void
    {
        Schema::drop('audit_logs');
        AuditLogger::forget();

        try {
            $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['KW']);
            $this->fail('the grant went through with no line to show for it');
        } catch (\RuntimeException $refused) {
            $this->assertStringContainsString('audit_logs', $refused->getMessage());
        }

        $this->runStaffTrailMigrations();
    }

    /** A line names the market only where a market is bound. A console command is bound to none, which is the honest record. */
    public function test_a_console_grant_names_no_market_because_no_market_asked_for_it(): void
    {
        $this->assignments()->assign(adminId: self::EMPLOYEE, countries: ['KW']);

        $line = AuditLog::query()->firstOrFail();

        $this->assertNull($line->storefront_country);
        $this->assertNull($line->legal_entity_id);
    }

    /**
     * And the other half, without which the test above would pass for a stamp that never worked: an action recorded while a storefront
     * IS bound names it. The caller may also name a market itself, which is how a panel action about one country's row is filed.
     */
    public function test_an_action_of_a_bound_storefront_names_its_market(): void
    {
        app()->instance(StorefrontContext::class, new StorefrontContext(country: 'KW', currency: 'KWD', storefrontsEnabled: true));

        app(AuditLogger::class)->record(action: 'coupon.updated', subject: ['type' => 'coupon', 'id' => 7]);
        app(AuditLogger::class)->record(action: 'coupon.updated', subject: ['type' => 'coupon', 'id' => 8], country: 'AE');

        $this->assertSame(['KW', 'AE'], AuditLog::query()->orderBy('id')->pluck('storefront_country')->all());
    }

    public function test_the_command_takes_the_granter_and_the_expiry_and_refuses_what_it_cannot_read(): void
    {
        $this->assertSame(0, Artisan::call('gcc:staff-country:assign', [
            'admin_id' => self::EMPLOYEE, 'countries' => 'KW', '--granted-by' => (string) self::MASTER, '--expires' => '2099-01-31',
        ]));

        $row = DB::table(StaffCountryScope::TABLE)->where('admin_id', self::EMPLOYEE)->firstOrFail();
        $this->assertSame(self::MASTER, (int) $row->granted_by);
        // 2099 on purpose: a TIMESTAMP column would store `0000-00-00` for it on MySQL and MariaDB without a word, read back as already
        // expired. The column is DATETIME, and only a run on a real engine can tell the two apart.
        $this->assertStringStartsWith('2099-01-31', (string) $row->expires_at);

        $this->assertSame(1, Artisan::call('gcc:staff-country:assign', [
            'admin_id' => 3, 'countries' => 'KW', '--granted-by' => 'the master',
        ]));
        $this->assertSame(0, DB::table(StaffCountryScope::TABLE)->where('admin_id', 3)->count());
    }

    private function assignments(): StaffCountryAssignments
    {
        return app(StaffCountryAssignments::class);
    }

    private function scope(): StaffCountryScope
    {
        return app(StaffCountryScope::class);
    }
}
