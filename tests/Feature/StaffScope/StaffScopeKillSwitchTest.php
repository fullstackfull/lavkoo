<?php

namespace Tests\Feature\StaffScope;

use App\Models\Admin;
use App\Models\Product;
use App\Services\Staff\StaffCountryScope;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\TestCase;

/**
 * How to turn the staff country scopes OFF AGAIN once the panel holds more than one market's rows (M1-18).
 *
 * `gcc.staff.country_scopes` off means "narrow nothing", which is the right answer for the platform of today and the WRONG answer after the
 * switch has been used: every scope row that was written stops being read, and the employee who was restricted to Dubai is served Riyadh's
 * products, orders, refunds and money. The first test below is that measurement, and it is the reason the criterion could not be called met
 * on this switch -- turning it off was never a way of stopping the feature, it was a way of handing every employee every market.
 *
 * `gcc.staff.off_is_one_market` is the answer, in the same shape `gcc.storefronts.off_is_one_market` already has for the catalogue
 * ({@see \Tests\Feature\Storefront\StorefrontKillSwitchTest}). It ships OFF, and the tests of the first section are that with it off nothing
 * about the panel changed -- including the SHAPE of the answer, which is what a caller reads: `null` is "this request is not narrowed" and
 * `[]` is "this request may see nothing", and a dormant switch must keep answering the first.
 *
 * With it on, off means ONE MARKET: every employee the scopes could restrict works in the default market and in no other, and an employee
 * whose rows never named that market works in none -- this branch may only narrow, because a switch being turned off is not a grant.
 * Nothing is deleted and no row is rewritten, so turning `country_scopes` back on restores exactly what was granted.
 */
class StaffScopeKillSwitchTest extends TestCase
{
    use BuildsStaffScopeWorld;

    /** An employee (role 2 is not the master admin's role 1). */
    private const EMPLOYEE = 2;

    private StaffCountryScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStaffProductTables();
        $this->buildStaffAdminTables();
        $this->runStaffScopeMigration();
        $this->insertStaffProduct(id: 1, country: 'SA');
        $this->insertStaffProduct(id: 2, country: 'AE');
        $this->scope = app(StaffCountryScope::class);
    }

    private function bindFor(int $adminId): ?array
    {
        $request = Request::create('/admin/products/list/in_house');
        $this->app->instance('request', $request);

        return $this->scope->bind(request: $request, admin: Admin::query()->findOrFail($adminId));
    }

    /** @return list<int> the products the bound request reaches, through the global scope every product query of the panel goes through */
    private function visibleProductIds(): array
    {
        return Product::withoutGlobalScope('translate')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function offMeansOneMarket(): void
    {
        config(['gcc.staff.country_scopes' => false, 'gcc.staff.off_is_one_market' => true]);
    }

    // ---- as it ships: off is today's panel, down to the shape of the answer ----------------------------------------------------------

    public function test_the_switch_ships_off(): void
    {
        $this->assertFalse((bool) config(StaffCountryScope::OFF_IS_ONE_MARKET), 'the switch that changes this ships off');
        $this->assertStringContainsString(
            "'off_is_one_market' => filter_var(env('GCC_STAFF_OFF_IS_ONE_MARKET', false), FILTER_VALIDATE_BOOLEAN),",
            (string) file_get_contents(config_path('gcc.php')),
            'and it ships off in the config file itself, not only in this process',
        );
    }

    /**
     * The measurement this criterion rested on: with the scopes switched off again, the employee restricted to the added market reads the
     * market that remains. Nothing here is a defect of the scopes -- off narrows nothing by design -- which is exactly why off was not a
     * way of stopping them.
     */
    public function test_turning_the_scopes_off_hands_a_restricted_employee_every_market_so_it_is_not_a_kill_switch(): void
    {
        $this->giveCountries(adminId: self::EMPLOYEE, countries: ['AE']);

        $this->assertNull($this->bindFor(adminId: self::EMPLOYEE));
        $this->assertSame([1, 2], $this->visibleProductIds(), 'the employee granted Dubai alone reads Riyadh');
    }

    public function test_with_the_kill_switch_off_an_unrestricted_employee_is_still_not_narrowed_and_the_answer_is_still_null(): void
    {
        $bound = $this->bindFor(adminId: self::EMPLOYEE);

        $this->assertNull($bound, 'null is "not narrowed" and [] is "may see nothing": a dormant switch must answer the first');
        $this->assertNull($this->scope->boundCountries());
        $this->assertFalse($this->scope->restricts());
        $this->assertSame([1, 2], $this->visibleProductIds());
    }

    public function test_with_the_kill_switch_off_nothing_is_asked_of_the_database_at_all(): void
    {
        $this->giveCountries(adminId: self::EMPLOYEE, countries: ['AE']);
        $admin = Admin::query()->findOrFail(self::EMPLOYEE);
        $request = Request::create('/admin/products/list/in_house');
        $this->app->instance('request', $request);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->scope->bind(request: $request, admin: $admin);

        $this->assertSame([], DB::getQueryLog(), 'no row read, no schema probe: the dormant switch is answered from the config alone');
    }

    // ---- with the kill switch on: off means one market -------------------------------------------------------------------------------

    public function test_off_means_one_market_so_the_added_market_s_products_stay_out_of_the_panel(): void
    {
        $this->offMeansOneMarket();

        $this->assertSame(['SA'], $this->bindFor(adminId: self::EMPLOYEE));
        $this->assertSame([1], $this->visibleProductIds(), 'the market the platform started as, and no other');
    }

    public function test_an_employee_granted_only_the_added_market_works_in_none_because_a_switch_being_turned_off_is_not_a_grant(): void
    {
        $this->giveCountries(adminId: self::EMPLOYEE, countries: ['AE']);
        $this->offMeansOneMarket();

        $this->assertSame([], $this->bindFor(adminId: self::EMPLOYEE), '[] is a scope that reaches nothing, never read as "no limit"');
        $this->assertSame([], $this->visibleProductIds());
    }

    public function test_an_employee_granted_both_markets_keeps_only_the_one_left_behind(): void
    {
        $this->giveCountries(adminId: self::EMPLOYEE, countries: ['AE', 'SA']);
        $this->offMeansOneMarket();

        $this->assertSame(['SA'], $this->bindFor(adminId: self::EMPLOYEE));
        $this->assertSame([1], $this->visibleProductIds(), 'this branch may only narrow, never widen');
    }

    public function test_the_master_admin_is_never_restricted_by_it(): void
    {
        $this->offMeansOneMarket();

        $this->assertNull($this->bindFor(adminId: 1), 'the master admin is the one employee no scope narrows');
        $this->assertSame([1, 2], $this->visibleProductIds());
    }

    public function test_it_is_inert_before_the_products_country_column_exists(): void
    {
        $this->giveCountries(adminId: self::EMPLOYEE, countries: ['AE']);
        Schema::table('products', static fn (Blueprint $table) => $table->dropColumn('storefront_country'));
        StaffCountryScope::forgetTheSchema();
        $this->offMeansOneMarket();

        $this->assertNull($this->bindFor(adminId: self::EMPLOYEE), 'no column, so no market was ever added and there is nothing to keep out');
    }

    public function test_it_is_inert_before_the_scope_table_exists(): void
    {
        $this->rollBackStaffScopeMigration();
        $this->offMeansOneMarket();

        $this->assertNull($this->bindFor(adminId: self::EMPLOYEE));
    }

    public function test_a_queue_or_a_console_command_is_untouched_because_nothing_binds_one(): void
    {
        $this->offMeansOneMarket();

        $this->assertNull($this->scope->boundCountries(), 'what a request is narrowed to lives on that request alone');
        $this->assertSame([1, 2], $this->visibleProductIds());
    }

    public function test_turning_the_scopes_on_again_restores_exactly_what_was_granted(): void
    {
        $this->giveCountries(adminId: self::EMPLOYEE, countries: ['AE']);
        $this->offMeansOneMarket();
        $this->assertSame([], $this->bindFor(adminId: self::EMPLOYEE));

        $this->switchStaffScopesOn();

        $this->assertSame(['AE'], $this->bindFor(adminId: self::EMPLOYEE), 'turning it off is reversible: no row was rewritten');
        $this->assertSame([2], $this->visibleProductIds());
    }
}
