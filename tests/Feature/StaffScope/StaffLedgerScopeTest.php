<?php

namespace Tests\Feature\StaffScope;

use App\Enums\Wallet\MarketBalanceKind;
use App\Http\Controllers\Admin\Customer\CustomerLoyaltyController;
use App\Http\Controllers\Admin\Customer\CustomerWalletController;
use App\Models\LoyaltyPointTransaction;
use App\Models\WalletTransaction;
use App\Services\Staff\StaffCountryScope;
use App\Services\Staff\StaffOrderRoutes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsStaffScopeWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A customer's wallet and loyalty movements, narrowed to the markets of the signed-in employee (GCC-14c; A2-V1).
 *
 * The panel's own rule was written down and was TRUE when it was written: `unavailable`, "it shows what cannot be told apart by country (a
 * wallet, a total of every order)". It stopped being true when the ledgers gained `storefront_country`. Until now a restricted employee was
 * shown a customer's whole money history or nothing at all, and the panel chose nothing -- so the two screens had no rule and no middleware
 * at all, and `StaffOrderRoutes` refused an employee every action of a controller with no rule only for the controllers it listed.
 *
 * The rows: 10 is Kuwaiti, 20 is Saudi, 30 names no market, which makes it the DEFAULT market's. Both ledgers carry the same three.
 */
class StaffLedgerScopeTest extends TestCase
{
    use BuildsStaffScopeWorld;
    use RestoresLanguageFiles;

    private const KUWAITI = 10;

    private const SAUDI = 20;

    private const NO_MARKET = 30;

    private const STAFF = 70;

    private const CUSTOMER = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->buildTheTwoLedgers();
        $this->runStaffScopeMigration();
        $this->switchStaffScopesOn();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.default_country' => 'SA']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('loyalty_point_transactions');

        parent::tearDown();
    }

    private function buildTheTwoLedgers(): void
    {
        foreach (['wallet_transactions' => true, 'loyalty_point_transactions' => false] as $ledger => $withAdminBonus) {
            Schema::dropIfExists($ledger);
            Schema::create($ledger, function (Blueprint $table) use ($withAdminBonus): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->uuid('transaction_id')->nullable();
                $table->decimal('credit', 18, 4)->default(0);
                $table->decimal('debit', 18, 4)->default(0);
                if ($withAdminBonus) {
                    $table->decimal('admin_bonus', 18, 4)->default(0);
                }
                $table->decimal('balance', 18, 4)->default(0);
                $table->string('transaction_type')->nullable();
                $table->string('reference')->nullable();
                $table->char(MarketBalanceKind::MARKET_COLUMN, 2)->nullable();
                $table->timestamps();
            });

            foreach ([self::KUWAITI => 'KW', self::SAUDI => 'SA', self::NO_MARKET => null] as $id => $market) {
                DB::table($ledger)->insert([
                    'id' => $id,
                    'user_id' => self::CUSTOMER,
                    'transaction_id' => sprintf('aaaaaaaa-0000-4000-8000-%012d', $id),
                    'credit' => 10,
                    'balance' => 10,
                    'transaction_type' => 'add_fund_by_admin',
                    MarketBalanceKind::MARKET_COLUMN => $market,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** @return list<int> the ids of the rows the ledger answers, once the request has been narrowed to [$countries] */
    private function rowsSeenBy(array $countries, string $subject, string $model): array
    {
        request()->attributes->set('staff_country_scope', $countries);
        request()->attributes->set('staff_country_scope_subjects', [$subject]);

        return $model::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    public function test_an_unnarrowed_request_reads_every_row_of_both_ledgers(): void
    {
        $this->assertSame([self::KUWAITI, self::SAUDI, self::NO_MARKET], WalletTransaction::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertSame([self::KUWAITI, self::SAUDI, self::NO_MARKET], LoyaltyPointTransaction::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    public function test_an_employee_of_kuwait_reads_the_kuwaiti_rows_of_both_ledgers_and_no_others(): void
    {
        $this->assertSame([self::KUWAITI], $this->rowsSeenBy(['KW'], StaffCountryScope::SUBJECT_WALLET_TRANSACTIONS, WalletTransaction::class));
        $this->assertSame([self::KUWAITI], $this->rowsSeenBy(['KW'], StaffCountryScope::SUBJECT_LOYALTY_TRANSACTIONS, LoyaltyPointTransaction::class));
    }

    /** The rows written before the ledgers carried a market are the DEFAULT market's, so the employee who holds it reads them. */
    public function test_an_employee_of_the_default_market_reads_its_rows_and_the_ones_that_name_no_market(): void
    {
        $this->assertSame([self::SAUDI, self::NO_MARKET], $this->rowsSeenBy(['SA'], StaffCountryScope::SUBJECT_WALLET_TRANSACTIONS, WalletTransaction::class));
        $this->assertSame([self::SAUDI, self::NO_MARKET], $this->rowsSeenBy(['SA'], StaffCountryScope::SUBJECT_LOYALTY_TRANSACTIONS, LoyaltyPointTransaction::class));
    }

    public function test_an_employee_of_both_markets_reads_every_row(): void
    {
        $this->assertSame([self::KUWAITI, self::SAUDI, self::NO_MARKET], $this->rowsSeenBy(['KW', 'SA'], StaffCountryScope::SUBJECT_WALLET_TRANSACTIONS, WalletTransaction::class));
    }

    /** Each ledger is its own subject: a request narrowed to the wallet does not narrow the points, and the other way round. */
    public function test_one_ledger_is_narrowed_without_the_other(): void
    {
        $this->assertSame([self::KUWAITI], $this->rowsSeenBy(['KW'], StaffCountryScope::SUBJECT_WALLET_TRANSACTIONS, WalletTransaction::class));
        $this->assertSame(
            [self::KUWAITI, self::SAUDI, self::NO_MARKET],
            LoyaltyPointTransaction::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            'the loyalty ledger was not the subject of this request',
        );
    }

    /** A request narrowed to another subject entirely leaves both ledgers alone: only what a screen asked for is narrowed. */
    public function test_a_request_narrowed_to_the_orders_leaves_the_ledgers_alone(): void
    {
        $this->assertSame(
            [self::KUWAITI, self::SAUDI, self::NO_MARKET],
            $this->rowsSeenBy(['KW'], StaffCountryScope::SUBJECT_ORDERS, WalletTransaction::class),
        );
    }

    /**
     * With the switch off nothing is bound at all, so nothing is narrowed.
     *
     * Driven through `bind()` and not by setting the attributes, deliberately: the attributes are what a BOUND request carries, and a
     * request can only be bound while the switch is on. Setting them by hand with the switch off would be a state the application cannot
     * reach, and would have this test pass for a reason that does not exist.
     */
    public function test_with_the_staff_scopes_off_nothing_is_bound_and_nothing_is_narrowed(): void
    {
        $this->giveCountries(self::STAFF, ['KW']);
        config(['gcc.staff.country_scopes' => false]);
        $this->signInAsStaff(self::STAFF);

        $this->assertNull(app(StaffCountryScope::class)->bind(request: request(), admin: auth('admin')->user()), 'nothing is bound');
        $this->assertSame(
            [self::KUWAITI, self::SAUDI, self::NO_MARKET],
            WalletTransaction::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
    }

    /** And with the switch on, a restricted employee's own request binds and narrows: the same thing through the real door. */
    public function test_a_bound_request_of_a_restricted_employee_narrows_the_ledger(): void
    {
        $this->giveCountries(self::STAFF, ['KW']);
        $this->signInAsStaff(self::STAFF);
        $scope = app(StaffCountryScope::class);

        $this->assertSame(['KW'], $scope->bind(request: request(), admin: auth('admin')->user()));
        $scope->narrowTo(request: request(), subjects: [StaffCountryScope::SUBJECT_WALLET_TRANSACTIONS]);

        $this->assertSame(
            [self::KUWAITI],
            WalletTransaction::query()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
    }

    /** Every action of both controllers has a rule, so a restricted employee is never let into one by accident. */
    public function test_every_action_of_both_screens_has_a_rule(): void
    {
        foreach ([CustomerWalletController::class, CustomerLoyaltyController::class] as $controller) {
            $rules = StaffOrderRoutes::rulesOf(controller: $controller);
            $this->assertNotSame([], $rules, $controller);

            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (ltrim((string) $route->getControllerClass(), '\\') !== $controller) {
                    continue;
                }

                $this->assertArrayHasKey($route->getActionMethod(), $rules, $controller . '@' . $route->getActionMethod());
            }
        }
    }

    /** The two reports list their ledger, and the bonus setup of the platform lists nothing. */
    public function test_the_reports_list_their_ledger_and_the_bonus_setup_lists_nothing(): void
    {
        $this->assertSame(['list:wallet_transactions'], StaffOrderRoutes::ruleOf(CustomerWalletController::class, 'index'));
        $this->assertSame(['list:wallet_transactions'], StaffOrderRoutes::ruleOf(CustomerWalletController::class, 'exportList'));
        $this->assertSame(['list:loyalty_point_transactions'], StaffOrderRoutes::ruleOf(CustomerLoyaltyController::class, 'index'));
        $this->assertSame(['none'], StaffOrderRoutes::ruleOf(CustomerWalletController::class, 'addBonusSetup'));
    }
}
