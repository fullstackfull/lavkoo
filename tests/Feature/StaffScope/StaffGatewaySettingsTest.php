<?php

namespace Tests\Feature\StaffScope;

use App\Http\Controllers\Admin\Payment\OfflinePaymentMethodController;
use App\Http\Controllers\Admin\ThirdParty\PaymentMethodController;
use App\Http\Controllers\Payment_Methods\MtnCashPaymentController;
use App\Http\Middleware\ScopeGatewaySettingsToStaffCountries;
use App\Services\Staff\StaffGatewayRoutes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\StaffGovernanceInventory;
use Tests\TestCase;

/**
 * D2-08: an employee scoped to one country cannot open or change the gateway settings of a company that is not theirs.
 *
 * What was there before this: `module:3rd_party_setup` and nothing else. An employee given Kuwait and that module could open the shared
 * gateway page -- which prints every gateway's secret key, integration id and HMAC out of `addon_settings.live_values` -- rewrite them,
 * switch a gateway off, point it at the sandbox, change the bank account the offline methods tell customers to pay into, and generate or
 * import the MTN Cash terminal keys. `Tests\Support\StaffGovernanceInventory` has recorded `PaymentMethodController` as still OUT since the
 * governance survey, with the reason that the credentials are one global row per gateway; this is the mechanism that takes it.
 *
 * **Why the answer is the whole screen and not a subset of its rows**, which is the one design decision here. There is ONE gateway
 * configuration for the installation. `payment_gateway_accounts` does not replace it -- `gcc.storefronts.payment_accounts.enabled` ships
 * off, the table ships empty, `paymob_accept` is the only gateway that may be configured per company at all, and a market with no account of
 * its own reads the platform row. So a write here is a write to every market's merchant account at once, and its gentlest form stops the shop
 * taking money in five countries the employee was never given. A read is no better: the page prints the secret with which somebody else's
 * payment can be accepted. `unavailable` is the same answer slice 6 already gives the MyFatoorah settings screen
 * ({@see \App\Services\Staff\StaffIntegrationRoutes}) and `StaffOrderRoutes` gives a hand-made wallet credit, and it does NOT answer the
 * owner's open question of whether a gateway belongs to a market at all (G1-13): a refusal needs no answer to that, a narrowing would.
 *
 * **The first test is the dormancy.** `gcc.staff.gateway_settings.scoped` ships off, and with it off a restricted employee reaches every one
 * of these actions exactly as today -- the rules all read `none`, which is asserted as the registry's answer and then driven through the real
 * routes.
 *
 * **The routes are driven, not the helper.** Each case builds the action's own URL, matches it out of the real route table, asserts the match
 * really is that action, binds the real request and runs the real middleware -- so a route that stops carrying the middleware, or an action
 * whose name changes, fails here. Every refusal is paired with an employee the scopes do not restrict reaching the same URL, because a
 * refusal case with no unrestricted twin cannot tell a working guard from a broken route.
 */
class StaffGatewaySettingsTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    /** An employee of Kuwait, an employee of Saudi Arabia and one with no scope row at all, so the scopes do not restrict them. */
    private const KUWAITI_STAFF = 71;

    private const SAUDI_STAFF = 72;

    private const UNRESTRICTED_STAFF = 73;

    /** Every action of every covered screen: the URL it answers, its verb, and the controller and method the route table must resolve to. */
    private const ACTIONS = [
        'the shared gateway page' => ['/admin/third-party/payment-method', 'GET', PaymentMethodController::class, 'index'],
        'writing a gateway configuration' => ['/admin/third-party/payment-method/addon-payment-set', 'PUT', PaymentMethodController::class, 'UpdatePaymentConfig'],
        'switching a gateway on or off' => ['/admin/third-party/payment-method/payment-status', 'POST', PaymentMethodController::class, 'UpdateStatus'],
        'the offline payment methods' => ['/admin/third-party/offline-payment-method/index', 'GET', OfflinePaymentMethodController::class, 'index'],
        'the form that adds one' => ['/admin/third-party/offline-payment-method/add', 'GET', OfflinePaymentMethodController::class, 'getAddView'],
        'adding one' => ['/admin/third-party/offline-payment-method/add', 'POST', OfflinePaymentMethodController::class, 'add'],
        'the form that edits one' => ['/admin/third-party/offline-payment-method/update/1', 'GET', OfflinePaymentMethodController::class, 'getUpdateView'],
        'editing one' => ['/admin/third-party/offline-payment-method/update/1', 'POST', OfflinePaymentMethodController::class, 'update'],
        'switching one on or off' => ['/admin/third-party/offline-payment-method/update-status', 'POST', OfflinePaymentMethodController::class, 'updateStatus'],
        'deleting one' => ['/admin/third-party/offline-payment-method/delete', 'POST', OfflinePaymentMethodController::class, 'delete'],
        'generating the terminal keys' => ['/admin/third-party/payment-method/mtn-cash/generate-keys', 'POST', MtnCashPaymentController::class, 'generateKeys'],
        'the activation precheck' => ['/admin/third-party/payment-method/mtn-cash/activation-precheck', 'GET', MtnCashPaymentController::class, 'activationPrecheck'],
        'activating the terminal' => ['/admin/third-party/payment-method/mtn-cash/activate', 'POST', MtnCashPaymentController::class, 'activate'],
        'the connection test' => ['/admin/third-party/payment-method/mtn-cash/auth-test', 'GET', MtnCashPaymentController::class, 'authTest'],
        'importing the original keys' => ['/admin/third-party/payment-method/mtn-cash/import-original-keys', 'GET', MtnCashPaymentController::class, 'importOriginalKeys'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStaffAdminTables();
        // The products table as well: `StaffCountryScope::enabled()` asks for the country column of a product before it enforces
        // anything, so a world without it would read every employee as unrestricted and every case below would pass for the wrong reason.
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();

        DB::table('admin_roles')->insert(['id' => 70, 'name' => 'Third-party setup', 'module_access' => json_encode(['3rd_party_setup']), 'status' => 1]);
        foreach ([self::KUWAITI_STAFF, self::SAUDI_STAFF, self::UNRESTRICTED_STAFF] as $id) {
            DB::table('admins')->insert(['id' => $id, 'name' => "Hand {$id}", 'email' => "hand{$id}@example.test", 'admin_role_id' => 70]);
        }

        $this->switchStaffScopesOn();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => 'SA', StaffGatewayRoutes::SWITCH => false]);
        $this->giveCountries(adminId: self::KUWAITI_STAFF, countries: ['KW']);
        $this->giveCountries(adminId: self::SAUDI_STAFF, countries: ['SA']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------- dormancy */

    /** Off, which is how it ships: every action's rule is `none` -- the registry says nothing belongs to a market. */
    public function test_with_the_switch_off_every_gateway_action_keeps_the_rule_it_has_always_had(): void
    {
        $this->assertFalse(StaffGatewayRoutes::areScoped(), 'the switch does not ship off');

        foreach (StaffGatewayRoutes::controllers() as $controller) {
            $rules = StaffGatewayRoutes::rulesOf(controller: $controller);
            $this->assertNotSame([], $rules, $controller . ' is covered by the registry');
            $this->assertSame(
                array_fill_keys(array_keys($rules), StaffGatewayRoutes::NONE),
                $rules,
                $controller . ' takes a rule while the switch is off',
            );
        }
    }

    /** Off: a restricted employee reaches every gateway settings action, which is this platform today. */
    #[DataProvider('everyGatewayAction')]
    public function test_with_the_switch_off_a_restricted_employee_reaches_every_gateway_action(string $uri, string $method, string $controller, string $action): void
    {
        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);

        $this->assertTrue(
            $this->reaches(uri: $uri, method: $method, controller: $controller, action: $action),
            $action . ' is refused while the switch is off',
        );
    }

    /* ------------------------------------------------------------------- on it */

    /** On: a restricted employee is answered like for a page that does not exist, and an unrestricted one still reaches it. */
    #[DataProvider('everyGatewayAction')]
    public function test_an_employee_of_one_market_reaches_no_gateway_settings_action(string $uri, string $method, string $controller, string $action): void
    {
        config([StaffGatewayRoutes::SWITCH => true]);

        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);
        $this->assertFalse($this->reaches(uri: $uri, method: $method, controller: $controller, action: $action), $action . ' is reached by an employee of Kuwait');

        $this->signInAsStaff(adminId: self::SAUDI_STAFF);
        $this->assertFalse($this->reaches(uri: $uri, method: $method, controller: $controller, action: $action), $action . ' is reached by an employee of Saudi Arabia');

        $this->signInAsStaff(adminId: self::UNRESTRICTED_STAFF);
        $this->assertTrue($this->reaches(uri: $uri, method: $method, controller: $controller, action: $action), $action . ' is refused to an employee the scopes do not restrict');
    }

    /**
     * On: the employee who holds the DEFAULT market is refused too.
     *
     * The one case worth naming on its own, because `coversTheDefaultMarket()` is how every other row that names no market is handled, and
     * it is the wrong rule here: these credentials are not the default market's, they are the installation's. An employee of Saudi Arabia
     * on a platform whose default is Saudi Arabia would otherwise hold the keys to Kuwait's, Bahrain's and Oman's card payments.
     */
    public function test_the_employee_of_the_default_market_is_refused_as_well(): void
    {
        config([StaffGatewayRoutes::SWITCH => true, 'gcc.storefronts.default_country' => 'SA']);
        $this->signInAsStaff(adminId: self::SAUDI_STAFF);

        $this->assertFalse(
            $this->reaches(uri: '/admin/third-party/payment-method', method: 'GET', controller: PaymentMethodController::class, action: 'index'),
            'the default market\'s employee holds the whole installation\'s gateway keys',
        );
    }

    /** On: a new action nobody has classified is refused rather than let through, so a credential screen is never open by default. */
    public function test_an_action_with_no_rule_is_refused(): void
    {
        config([StaffGatewayRoutes::SWITCH => true]);

        $this->assertNull(StaffGatewayRoutes::ruleOf(controller: PaymentMethodController::class, method: 'somethingAddedLater'));

        Route::middleware(ScopeGatewaySettingsToStaffCountries::class)
            ->get('/admin/third-party/payment-method/something-added-later', [PaymentMethodController::class, 'somethingAddedLater']);

        $this->signInAsStaff(adminId: self::KUWAITI_STAFF);

        $this->assertFalse(
            $this->reaches(uri: '/admin/third-party/payment-method/something-added-later', method: 'GET', controller: PaymentMethodController::class, action: 'somethingAddedLater'),
            'an unclassified action of a covered screen is reachable',
        );
    }

    /** On: the customer-facing and point-of-sale halves of the MTN Cash controller are not settings and are not listed. */
    public function test_the_customer_facing_actions_of_the_mtn_cash_controller_are_not_covered(): void
    {
        config([StaffGatewayRoutes::SWITCH => true]);

        foreach (['payment', 'initiate', 'confirm', 'checkInvoice'] as $action) {
            $this->assertNull(
                StaffGatewayRoutes::ruleOf(controller: MtnCashPaymentController::class, method: $action),
                $action . ' is a customer paying, not a settings screen',
            );
        }
    }

    /** Every covered action carries the middleware, read off the real route table. A screen that stops registering it fails here. */
    #[DataProvider('everyGatewayAction')]
    public function test_every_covered_action_carries_the_scope_middleware(string $uri, string $method, string $controller, string $action): void
    {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        $this->assertContains(ScopeGatewaySettingsToStaffCountries::class, $route->gatherMiddleware(), $uri);
    }

    /**
     * And the customer-facing half of the MTN Cash controller does NOT, which is the whole reason the registration carries `only()`.
     *
     * A route is declared here rather than matched out of the table: what is under test is which ACTIONS the controller hands the
     * middleware to, and the customer routes of this gateway are in the website's own route file, which is not always mapped.
     */
    #[DataProvider('theCustomerFacingActionsOfTheMtnCashController')]
    public function test_a_customer_facing_action_does_not_carry_the_scope_middleware(string $action): void
    {
        $route = Route::get('/probe-mtn-cash-' . $action, [MtnCashPaymentController::class, $action]);

        $this->assertNotContains(
            ScopeGatewaySettingsToStaffCountries::class,
            $route->gatherMiddleware(),
            $action . ' is a customer paying and is inside the staff gateway scope',
        );
    }

    /** The twin of the case above: the five administrative actions of that same controller DO carry it, so `only()` names the right five. */
    #[DataProvider('theAdministrativeActionsOfTheMtnCashController')]
    public function test_an_administrative_action_of_the_same_controller_does_carry_it(string $action): void
    {
        $route = Route::get('/probe-mtn-cash-admin-' . $action, [MtnCashPaymentController::class, $action]);

        $this->assertContains(ScopeGatewaySettingsToStaffCountries::class, $route->gatherMiddleware(), $action);
    }

    public static function theCustomerFacingActionsOfTheMtnCashController(): array
    {
        return [['payment'], ['initiate'], ['confirm'], ['checkInvoice']];
    }

    public static function theAdministrativeActionsOfTheMtnCashController(): array
    {
        return [['generateKeys'], ['activationPrecheck'], ['activate'], ['authTest'], ['importOriginalKeys']];
    }

    /** The vocabulary, so a rule nobody understands cannot be written into the registry unnoticed. */
    public function test_the_registry_knows_only_the_two_rules_it_has(): void
    {
        $this->assertTrue(StaffGatewayRoutes::isKnown(StaffGatewayRoutes::NONE));
        $this->assertTrue(StaffGatewayRoutes::isKnown(StaffGatewayRoutes::UNAVAILABLE));
        $this->assertFalse(StaffGatewayRoutes::isKnown(''));
        $this->assertFalse(StaffGatewayRoutes::isKnown('list'));
    }

    /**
     * The MyFatoorah settings screen is slice 6's and stays there.
     *
     * Two registries naming one route would be two places with one rule between them, which is how the two halves of a rule come to
     * disagree. This asserts the division rather than trusting it.
     */
    public function test_the_myfatoorah_screen_is_slice_sixs_and_is_not_claimed_here(): void
    {
        $this->assertNotContains('App\Http\Controllers\Admin\MyFatoorah\SettingsController', StaffGatewayRoutes::controllers());
        $this->assertSame(
            ['unavailable'],
            \App\Services\Staff\StaffIntegrationRoutes::ruleOf(controller: 'App\Http\Controllers\Admin\MyFatoorah\SettingsController', method: 'index'),
            'slice 6 stopped refusing the MyFatoorah settings screen',
        );
    }

    /** The governance survey still names this screen, and names the switch that takes it. */
    public function test_the_governance_survey_records_what_takes_this_screen(): void
    {
        $entry = StaffGovernanceInventory::CONTROLLERS[PaymentMethodController::class];

        $this->assertSame(StaffGovernanceInventory::OUT, $entry['class'], 'the screen is out while the switch ships off');
        $this->assertStringContainsString(StaffGatewayRoutes::SWITCH, $entry['reason'], 'the survey does not name the switch that takes it');
    }

    /* ------------------------------------------------------------------ provider */

    public static function everyGatewayAction(): array
    {
        return array_map(
            static fn (array $action): array => [$action[0], $action[1], $action[2], $action[3]],
            self::ACTIONS,
        );
    }

    /* --------------------------------------------------------------------- world */

    /**
     * Whether the signed-in employee gets past the guard of this action.
     *
     * The URL is matched out of the real route table and the match is asserted to be the action's own, because a refusal is only evidence
     * about the guard when the URL really belongs to the action: a URL that resolves somewhere else would read as a refusal.
     *
     * The middleware is then asserted to be ON that route before it is run. Running it by hand proves what the guard DECIDES and nothing
     * about whether anything asks it -- a controller that stopped registering it would leave every case here green, which is the shape of
     * toothlessness this pair of assertions exists to close.
     */
    private function reaches(string $uri, string $method, string $controller, string $action): bool
    {
        $request = Request::create($uri, $method);
        $route = Route::getRoutes()->match($request);

        $this->assertSame(ltrim($controller, '\\'), ltrim((string) $route->getControllerClass(), '\\'), $uri . ' resolves to another controller');
        $this->assertSame($action, $route->getActionMethod(), $uri . ' resolves to another action');
        $this->assertContains(
            ScopeGatewaySettingsToStaffCountries::class,
            $route->gatherMiddleware(),
            $uri . ' does not carry the gateway settings scope at all',
        );

        $request->setRouteResolver(static fn () => $route);
        $route->bind($request);
        $this->app->instance('request', $request);

        try {
            app(ScopeGatewaySettingsToStaffCountries::class)->handle($request, static fn () => response('reached'));
        } catch (NotFoundHttpException) {
            return false;
        }

        return true;
    }
}
