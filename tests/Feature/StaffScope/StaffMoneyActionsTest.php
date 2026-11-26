<?php

namespace Tests\Feature\StaffScope;

use App\Http\Controllers\Admin\Customer\CustomerWalletController;
use App\Http\Controllers\Admin\Vendor\VendorController;
use App\Http\Middleware\ScopeOrdersToStaffCountries;
use App\Services\Marketplace\SellerMarketScope;
use App\Services\Staff\StaffOrderRoutes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The panel's own MONEY actions, checked against the countries of the signed-in employee (G-14, second criterion).
 *
 * Three actions carried the rule `none` -- "names nothing that belongs to a market" -- while each of them moves money: approving or
 * denying a seller's withdrawal, opening that request's screen with its amount and bank details, and crediting a customer's wallet by
 * hand. `gcc.staff.money_actions.scoped` is the switch, OFF as it ships, and the first test here is that with it off every one of them
 * answers a restricted employee exactly as it does today.
 *
 * **The routes are driven, not the helper.** Each case puts the id where the action's own rule looks for it, matches the real route out of
 * the real route table, binds the real request and runs the real middleware -- so a rule that stopped being read, or a route that nothing
 * binds, fails here. A refusal case is worthless without its unrestricted twin, so every refusal below is paired with an employee the
 * scopes do not restrict reaching the same URL.
 *
 * The world: seller 1's shop is granted Kuwait, seller 2's Saudi Arabia, seller 3's both. Withdrawal 501 is seller 1's, 502 is seller 2's,
 * 503 is seller 3's and 504 is a delivery man's, which names no seller at all.
 */
class StaffMoneyActionsTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    /** Employees 2 and 3 of the shared world (role 2), and 6, who is given no scope row and so is not restricted. */
    private const KUWAITI_STAFF = 2;

    private const SAUDI_STAFF = 3;

    private const UNRESTRICTED_STAFF = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();
        $this->buildTheWithdrawalRequests();
        $this->buildTheSellerGrants();
        $this->switchStaffScopesOn();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => 'SA']);
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => false, SellerMarketScope::SWITCH => false]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        Schema::dropIfExists('withdraw_requests');
        Schema::dropIfExists(SellerMarketScope::TABLE);

        parent::tearDown();
    }

    // ---- the switch is off, and nothing moved ---------------------------------------------------------------------------

    public function test_with_the_money_switch_off_the_three_actions_keep_the_rule_they_have_always_had(): void
    {
        $this->assertSame(['none'], StaffOrderRoutes::ruleOf(controller: VendorController::class, method: 'withdrawStatus'));
        $this->assertSame(['none'], StaffOrderRoutes::ruleOf(controller: VendorController::class, method: 'getWithdrawView'));
        $this->assertSame(['none'], StaffOrderRoutes::ruleOf(controller: CustomerWalletController::class, method: 'addFund'));
        $this->assertFalse(StaffOrderRoutes::moneyActionsAreScoped());
    }

    public function test_with_the_money_switch_off_a_restricted_employee_reaches_every_money_action(): void
    {
        $this->giveCountries(adminId: self::KUWAITI_STAFF, countries: ['KW']);
        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);

        $this->assertTrue($this->reachesWithdrawStatus(withdrawId: '502'), 'another market\'s withdrawal is decided today');
        $this->assertTrue($this->reachesWithdrawView(withdrawId: '502'));
        $this->assertTrue($this->reachesAddFund());
    }

    // ---- the switch is on, and the seller market grants are not ---------------------------------------------------------

    /**
     * **The measurement that decides whether this gate is usable.** With the seller market grants off -- every installation today -- no
     * shop names a market, so a shop's money is the DEFAULT storefront's, by the rule the whole platform follows for a row naming none.
     * The employees of that market decide its payouts and nobody else does. Reading only `soleMarketOfShop()` would refuse every payout
     * to every restricted employee, which is safe and useless.
     */
    public function test_with_the_grants_off_a_shops_money_is_the_default_markets(): void
    {
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => true]);

        $this->giveCountries(adminId: self::SAUDI_STAFF, countries: ['SA']);
        $this->signInAsStaff(adminId: self::SAUDI_STAFF);
        $this->assertTrue($this->reachesWithdrawStatus(withdrawId: '501'), 'the default market\'s employee decides it');
        $this->assertTrue($this->reachesWithdrawView(withdrawId: '501'));

        $this->giveCountries(adminId: self::KUWAITI_STAFF, countries: ['KW']);
        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);
        $this->assertFalse($this->reachesWithdrawStatus(withdrawId: '501'), 'an employee of another market does not');
        $this->assertFalse($this->reachesWithdrawView(withdrawId: '501'));
    }

    // ---- the switch is on and the grants are in force -------------------------------------------------------------------

    public function test_a_withdrawal_is_decided_only_by_an_employee_of_the_shops_own_market(): void
    {
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => true, SellerMarketScope::SWITCH => true]);

        $this->giveCountries(adminId: self::KUWAITI_STAFF, countries: ['KW']);
        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);
        $this->assertTrue($this->reachesWithdrawStatus(withdrawId: '501'), 'the Kuwaiti shop\'s withdrawal');
        $this->assertFalse($this->reachesWithdrawStatus(withdrawId: '502'), 'the Saudi shop\'s withdrawal');

        $this->giveCountries(adminId: self::SAUDI_STAFF, countries: ['SA']);
        $this->signInAsStaff(adminId: self::SAUDI_STAFF);
        $this->assertFalse($this->reachesWithdrawStatus(withdrawId: '501'));
        $this->assertTrue($this->reachesWithdrawStatus(withdrawId: '502'));
    }

    /** The screen that shows the amount and the bank details follows the same rule as the decision, or the details leak to the wrong market. */
    public function test_the_withdrawal_screen_follows_the_same_rule_as_the_decision(): void
    {
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => true, SellerMarketScope::SWITCH => true]);
        $this->giveCountries(adminId: self::KUWAITI_STAFF, countries: ['KW']);
        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);

        $this->assertTrue($this->reachesWithdrawView(withdrawId: '501'));
        $this->assertFalse($this->reachesWithdrawView(withdrawId: '502'));
    }

    /**
     * A shop granted TWO markets belongs to no single one, so its money is refused rather than filed under whichever market the employee
     * happens to hold -- the answer a sales-channel account naming no country gets, and for the same reason.
     */
    public function test_a_shop_granted_two_markets_has_its_money_refused_to_an_employee_of_either(): void
    {
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => true, SellerMarketScope::SWITCH => true]);

        foreach ([self::KUWAITI_STAFF => 'KW', self::SAUDI_STAFF => 'SA'] as $staff => $country) {
            $this->giveCountries(adminId: $staff, countries: [$country]);
            $this->signInAsStaff(adminId: $staff);

            $this->assertFalse($this->reachesWithdrawStatus(withdrawId: '503'), $country . ' is refused a shop of two markets');
        }
    }

    /** A shop with no grant at all is UNRESTRICTED, which is no single market either, so its money is refused the same way. */
    public function test_a_shop_with_no_grant_has_its_money_refused_while_the_grants_are_in_force(): void
    {
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => true, SellerMarketScope::SWITCH => true]);
        DB::table(SellerMarketScope::TABLE)->delete();

        $this->giveCountries(adminId: self::SAUDI_STAFF, countries: ['SA']);
        $this->signInAsStaff(adminId: self::SAUDI_STAFF);

        $this->assertFalse($this->reachesWithdrawStatus(withdrawId: '501'));
    }

    /**
     * A request that is not a vendor withdrawal, and a number that is not a request, are answered like a request that does not exist --
     * so which ids are withdrawals of which market is not something an employee can find out by asking.
     */
    public function test_an_unknown_request_a_delivery_mans_row_and_a_value_that_is_not_an_id_are_all_refused(): void
    {
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => true, SellerMarketScope::SWITCH => true]);
        $this->giveCountries(adminId: self::KUWAITI_STAFF, countries: ['KW']);
        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);

        // Every value here still RESOLVES to the withdraw-status route -- `reaches()` fails the test if it does not -- so each refusal is
        // this guard's answer and not the router's. An empty value is left out on purpose: it makes the URL a DIFFERENT route
        // (`DELETE admin/vendors/{id}`), so a refusal of it would say nothing about the scope.
        foreach (['504', '999999', '501abc', '0501', '5e2', '-501', '501.0', '0'] as $value) {
            $this->assertFalse($this->reachesWithdrawStatus(withdrawId: $value), json_encode($value) . ' is refused');
        }

        $this->signInAsStaff(adminId: self::UNRESTRICTED_STAFF);
        $this->assertTrue($this->reachesWithdrawStatus(withdrawId: '504'), 'the delivery man\'s row is a real route, which is what makes the refusal above a guard');
    }

    // ---- the wallet adjustment, which names no market at all -----------------------------------------------------------

    /**
     * `users.wallet_balance` is ONE global balance and a customer belongs to no market in this schema, so there is nothing a hand-made
     * credit could be checked against: it is a platform act, and a restricted employee may not make it. The per-market accounts of
     * GCC-15 are what would change that answer, and they are dormant.
     */
    public function test_a_hand_made_wallet_credit_is_refused_to_a_restricted_employee_and_allowed_to_everyone_else(): void
    {
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => true]);

        $this->giveCountries(adminId: self::KUWAITI_STAFF, countries: ['KW']);
        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);
        $this->assertFalse($this->reachesAddFund());

        $this->signInAsStaff(adminId: self::UNRESTRICTED_STAFF);
        $this->assertTrue($this->reachesAddFund(), 'an employee the scopes do not restrict credits a wallet as they always did');
    }

    /** With the staff country scopes themselves off nothing is bound, so the money switch reaches nobody. */
    public function test_with_the_staff_country_scopes_off_the_money_switch_changes_nothing(): void
    {
        config([StaffOrderRoutes::MONEY_ACTIONS_SWITCH => true, SellerMarketScope::SWITCH => true, 'gcc.staff.country_scopes' => false]);
        $this->giveCountries(adminId: self::KUWAITI_STAFF, countries: ['KW']);
        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);

        $this->assertTrue($this->reachesWithdrawStatus(withdrawId: '502'));
        $this->assertTrue($this->reachesAddFund());
    }

    // ---- what the tests build and drive --------------------------------------------------------------------------------

    private function reachesWithdrawStatus(string $withdrawId): bool
    {
        return $this->reaches(routeName: 'admin.vendors.withdraw_status', method: 'POST', path: ['id' => $withdrawId]);
    }

    private function reachesWithdrawView(string $withdrawId): bool
    {
        return $this->reaches(routeName: 'admin.vendors.withdraw_view', method: 'GET', path: ['withdrawId' => $withdrawId, 'vendorId' => '1']);
    }

    private function reachesAddFund(): bool
    {
        return $this->reaches(routeName: 'admin.customer.wallet.add-fund', method: 'POST', path: []);
    }

    /**
     * Drives the real middleware on the real route, with the id where the action's own rule looks for it.
     *
     * @param array<string, string> $path
     */
    private function reaches(string $routeName, string $method, array $path): bool
    {
        $declared = Route::getRoutes()->getByName($routeName);
        $this->assertNotNull($declared, 'the route ' . $routeName . ' is declared');

        $uri = preg_replace_callback('/\{(\w+)\??\}/', static fn (array $match): string => rawurlencode($path[$match[1]] ?? '1'), '/' . $declared->uri());
        $request = Request::create($uri, $method);

        $route = Route::getRoutes()->match($request);
        // A refusal is only evidence about the guard when the URL really is the action's own. A value that sends the request to another
        // route fails here rather than reading as a refusal.
        $this->assertSame($routeName, $route->getName(), 'the URL built for ' . $routeName . ' resolves to ' . (string) $route->getName());

        $request->setRouteResolver(static fn () => $route);
        $route->bind($request);
        $this->app->instance('request', $request);

        try {
            app(ScopeOrdersToStaffCountries::class)->handle($request, static fn () => response('reached'));
        } catch (NotFoundHttpException) {
            return false;
        }

        return true;
    }

    private function buildTheWithdrawalRequests(): void
    {
        Schema::dropIfExists('withdraw_requests');
        Schema::create('withdraw_requests', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->unsignedTinyInteger('approved')->default(0);
            $table->timestamps();
        });

        foreach ([501 => 1, 502 => 2, 503 => 3] as $id => $sellerId) {
            DB::table('withdraw_requests')->insert(['id' => $id, 'seller_id' => $sellerId, 'amount' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('withdraw_requests')->insert(['id' => 504, 'seller_id' => null, 'delivery_man_id' => 9, 'amount' => 10, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function buildTheSellerGrants(): void
    {
        Schema::dropIfExists(SellerMarketScope::TABLE);
        Schema::create(SellerMarketScope::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('staff_id')->default(0);
            $table->char('country', 2);
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->timestamps();
        });

        foreach ([[1, 'KW'], [2, 'SA'], [3, 'KW'], [3, 'SA']] as [$sellerId, $country]) {
            DB::table(SellerMarketScope::TABLE)->insert([
                'seller_id' => $sellerId,
                'staff_id' => SellerMarketScope::OWNER_STAFF_ID,
                'country' => $country,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
