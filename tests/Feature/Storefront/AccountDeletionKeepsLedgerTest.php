<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\User;
use App\Services\CustomerAccountDeletionGuardService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsApiAddressSchema;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * E-08 of the master plan: what deleting a customer account does to the money the store still holds for them. Three routes delete one: the customer's own
 * two, `GET /api/v1/customer/account-delete/{id}` for the app and `GET /account-delete/{id}` for the website, which the guard answers; and an
 * administrator's, `DELETE admin/customer/delete/{id}`, which it deliberately does not, because with the switch on an administrator is the only actor left
 * who can act on an account a residual holding has blocked -- a customer cannot spend a residue (wallet payment at checkout is all or nothing), cannot
 * withdraw it (there is no customer withdrawal in this platform) and cannot be zeroed by support (the only admin wallet action adds funds).
 *
 * The `current_behaviour` tests are characterisation tests: the storefront ones were written and watched pass before the guard existed, and they state what
 * the platform does today, which all three routes keep doing while `gcc.account_deletion.refuse_while_balance_remains` is off, as it ships. Today every one
 * of them deletes the row of a customer whose wallet still holds money or whose loyalty points still stand, says nothing about either, and leaves the wallet
 * and loyalty rows behind naming a customer id that names nobody; so do the referral grant and the `referred_by` of whoever this customer invited.
 *
 * The `with_the_guard_on` tests turn the switch on and ask for the refusal E-08 wants. Each customer route answers in the shape it already refuses an
 * ongoing order with and keeps that older refusal's precedence, the refusal leaves the customer signed in, nothing is written either way, and a holding
 * counts from one halala up, in either direction, so that a residual left by a float round trip is not mistaken for money a person has.
 */
class AccountDeletionKeepsLedgerTest extends TestCase
{
    use BuildsApiAddressSchema;
    use BuildsWalletAndVendorLedgerSchema;
    use RestoresLanguageFiles;

    private const APP_DELETE = '/api/v1/customer/account-delete/';

    private const WEBSITE_DELETE = '/account-delete/';

    private const ADMIN_DELETE = '/admin/customer/delete/';

    /**
     * What a customer refused by the money guard reads, in the English `resources/lang/en/new-messages.php` ships. The sentence is written out here rather
     * than asked of translate(), because translate() on both sides of an assertion pins the key and nothing else: it writes a humanised fallback for a key
     * it does not know, so a missing language line would answer the same sentence and the test would not notice.
     */
    private const REFUSAL_ENGLISH = [
        'you_can_not_delete_account_while_your_wallet_holds_money' => 'You can not delete account while your wallet holds money',
        'you_can_not_delete_account_while_you_still_have_loyalty_points' => 'You can not delete account while you still have loyalty points',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCustomerLedgerTables();
        $this->useThrowawayPassportKeys();
        Storage::fake('public');
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        DB::table('business_settings')->insert([
            ['type' => 'wallet_status', 'value' => json_encode(1)],
            ['type' => 'loyalty_point_status', 'value' => json_encode(1)],
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function customerHolding(float $walletBalance, float $loyaltyPoints): User
    {
        $customer = User::findOrFail($this->makeCustomer(['wallet_balance' => $walletBalance, 'loyalty_point' => $loyaltyPoints])->id);
        $this->walletRow(customer: $customer, transactionId: 'aaaaaaaa-0000-4000-8000-' . str_pad((string) $customer->id, 12, '0', STR_PAD_LEFT), credit: $walletBalance, balance: $walletBalance);
        $this->loyaltyRow(customer: $customer, transactionId: 'bbbbbbbb-0000-4000-8000-' . str_pad((string) $customer->id, 12, '0', STR_PAD_LEFT), credit: $loyaltyPoints, balance: $loyaltyPoints);

        return $customer;
    }

    private function walletRow(User $customer, string $transactionId, float $credit, float $balance): void
    {
        DB::table('wallet_transactions')->insert([
            'user_id' => $customer->id, 'transaction_id' => $transactionId, 'credit' => $credit, 'debit' => 0, 'admin_bonus' => 0, 'balance' => $balance,
            'transaction_type' => 'add_fund', 'reference' => 'online-top-up', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    private function loyaltyRow(User $customer, string $transactionId, float $credit, float $balance): void
    {
        DB::table('loyalty_point_transactions')->insert([
            'user_id' => $customer->id, 'transaction_id' => $transactionId, 'credit' => $credit, 'debit' => 0, 'balance' => $balance,
            'transaction_type' => 'order_place', 'reference' => '1001', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    private function appDeletes(User $customer): TestResponse
    {
        $this->actingAs($customer, 'api');

        return $this->getJson(self::APP_DELETE . $customer->id);
    }

    private function websiteDeletes(User $customer): TestResponse
    {
        $this->actingAs($customer, 'customer');

        return $this->get(self::WEBSITE_DELETE . $customer->id);
    }

    /**
     * The third routed path that deletes a customer account, the one an administrator uses: DELETE admin/customer/delete/{id}. The admin sign-in, the
     * module permission and the activation check stand in front of it and are pinned by Tests\Feature\Security\AdminRouteAuthenticationTest, so what is
     * asked here is only what the action itself does about the money.
     */
    private function adminDeletes(User $customer): TestResponse
    {
        $this->withoutMiddleware([AdminMiddleware::class, ModulePermissionMiddleware::class, ActivationCheckMiddleware::class]);

        return $this->delete(self::ADMIN_DELETE . $customer->id);
    }

    /** @return list<string> the messages ToastMagic flashed into the session, in order: the admin panel's own toast, not the storefront's */
    private function toastMagicMessages(): array
    {
        return array_column(session('laravel-toaster-magic::messages') ?? [], 'message');
    }

    /** @return list<string> the messages Toastr flashed into the session, in order */
    private function toastrMessages(): array
    {
        return array_column(session('toastr::messages') ?? [], 'message');
    }

    private function assertAccountIsGone(User $customer): void
    {
        $this->assertNull(User::find($customer->id), 'the customer row is still there');
    }

    private function assertLedgerRowsOf(User $customer, int $walletRows, int $loyaltyRows): void
    {
        $this->assertSame($walletRows, DB::table('wallet_transactions')->where('user_id', $customer->id)->count(), 'the wallet ledger of the customer changed');
        $this->assertSame($loyaltyRows, DB::table('loyalty_point_transactions')->where('user_id', $customer->id)->count(), 'the loyalty ledger of the customer changed');
    }

    private function orderOnItsWayFor(User $customer, int $id): void
    {
        DB::table('orders')->insert(['id' => $id, 'customer_id' => $customer->id, 'is_guest' => 0, 'order_status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * A refund that lands while a deletion is already on its way: the credit is written when the route counts the customer's orders, which both routes do
     * after they have hydrated the customer and before they ask the money guard anything.
     */
    private function creditTheWalletWhileTheOrdersAreCounted(User $customer, float $amount): void
    {
        $credited = false;
        DB::listen(function ($query) use ($customer, $amount, &$credited): void {
            if ($credited || !str_contains($query->sql, 'count(*)') || !str_contains($query->sql, 'orders')) {
                return;
            }

            $credited = true;
            DB::table('users')->where('id', $customer->id)->update(['wallet_balance' => $amount]);
            $this->walletRow(customer: $customer, transactionId: 'cccccccc-0000-4000-8000-' . str_pad((string) $customer->id, 12, '0', STR_PAD_LEFT), credit: $amount, balance: $amount);
        });
    }

    private function refuseWhileBalanceRemains(): void
    {
        config(['gcc.account_deletion.refuse_while_balance_remains' => true]);
    }

    /**
     * config/gcc.php evaluated with its variable out of the environment, which is what a store that never sets the variable gets. The variable is taken out
     * of all three places env() reads -- $_ENV, $_SERVER and the process environment -- because a .env naming it, which each of the four CI jobs that run
     * phpunit has since each copies .env.example, would otherwise answer for the file.
     */
    private function theSwitchWithTheEnvironmentSilent(): bool
    {
        $variable = 'GCC_REFUSE_ACCOUNT_DELETE_WITH_BALANCE';
        $fromEnv = $_ENV[$variable] ?? null;
        $fromServer = $_SERVER[$variable] ?? null;
        $fromProcess = getenv($variable);
        unset($_ENV[$variable], $_SERVER[$variable]);
        putenv($variable);

        $shipped = include base_path('config/gcc.php');

        if ($fromEnv !== null) {
            $_ENV[$variable] = $fromEnv;
        }
        if ($fromServer !== null) {
            $_SERVER[$variable] = $fromServer;
        }
        if ($fromProcess !== false) {
            putenv($variable . '=' . $fromProcess);
        }

        return (bool) $shipped['account_deletion']['refuse_while_balance_remains'];
    }

    private function assertAppRefused(TestResponse $response, string $message, User $customer): void
    {
        $response->assertForbidden();
        $this->assertSame(['message' => self::REFUSAL_ENGLISH[$message]], $response->json());
        $this->assertNotNull(User::find($customer->id), 'the account was deleted after the refusal');
    }

    private function assertWebsiteRefused(TestResponse $response, string $message, User $customer): void
    {
        $response->assertRedirect();
        $this->assertSame([self::REFUSAL_ENGLISH[$message]], $this->toastrMessages());
        $this->assertNotNull(User::find($customer->id), 'the account was deleted after the refusal');
        $this->assertTrue(auth('customer')->check(), 'the refusal signed the customer out of the website');
    }

    public function test_current_behaviour_the_app_deletes_the_account_of_a_customer_whose_wallet_still_holds_money(): void
    {
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 0.0);

        $response = $this->appDeletes($customer);

        $response->assertOk();
        $this->assertSame('Your account deleted successfully', $response->json('message'));
        $this->assertAccountIsGone($customer);
    }

    public function test_current_behaviour_the_website_deletes_the_account_of_a_customer_whose_wallet_still_holds_money(): void
    {
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 0.0);

        $response = $this->websiteDeletes($customer);

        $response->assertRedirect(route('home'));
        $this->assertSame(['Your account deleted successfully!!'], $this->toastrMessages());
        $this->assertAccountIsGone($customer);
    }

    public function test_current_behaviour_the_app_deletes_the_account_of_a_customer_who_still_holds_loyalty_points(): void
    {
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 340.0);

        $this->appDeletes($customer)->assertOk();

        $this->assertAccountIsGone($customer);
    }

    public function test_current_behaviour_the_website_deletes_the_account_of_a_customer_who_still_holds_loyalty_points(): void
    {
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 340.0);

        $this->websiteDeletes($customer)->assertRedirect(route('home'));

        $this->assertAccountIsGone($customer);
    }

    public function test_current_behaviour_the_app_deletes_the_account_of_a_customer_who_holds_both_money_and_points(): void
    {
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);

        $this->appDeletes($customer)->assertOk();

        $this->assertAccountIsGone($customer);
    }

    public function test_current_behaviour_the_website_deletes_the_account_of_a_customer_who_holds_both_money_and_points(): void
    {
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);

        $this->websiteDeletes($customer)->assertRedirect(route('home'));

        $this->assertAccountIsGone($customer);
    }

    public function test_current_behaviour_the_app_deletes_the_account_of_a_customer_who_holds_nothing(): void
    {
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);

        $this->appDeletes($customer)->assertOk();

        $this->assertAccountIsGone($customer);
    }

    public function test_current_behaviour_the_website_deletes_the_account_of_a_customer_who_holds_nothing(): void
    {
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);

        $this->websiteDeletes($customer)->assertRedirect(route('home'));

        $this->assertAccountIsGone($customer);
    }

    public function test_the_wallet_and_loyalty_rows_of_a_customer_who_held_nothing_survive_the_deletion_and_stay_readable(): void
    {
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $walletBefore = DB::table('wallet_transactions')->where('user_id', $customer->id)->get()->toArray();
        $loyaltyBefore = DB::table('loyalty_point_transactions')->where('user_id', $customer->id)->get()->toArray();

        $this->appDeletes($customer)->assertOk();

        $this->assertAccountIsGone($customer);
        $this->assertEquals($walletBefore, DB::table('wallet_transactions')->where('user_id', $customer->id)->get()->toArray());
        $this->assertEquals($loyaltyBefore, DB::table('loyalty_point_transactions')->where('user_id', $customer->id)->get()->toArray());
        $this->assertLedgerRowsOf(customer: $customer, walletRows: 1, loyaltyRows: 1);
    }

    public function test_current_behaviour_the_ledger_rows_of_a_customer_who_held_money_are_left_as_orphans_the_deletion_does_not_touch_them(): void
    {
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);

        $this->websiteDeletes($customer)->assertRedirect(route('home'));

        $this->assertAccountIsGone($customer);
        $this->assertLedgerRowsOf(customer: $customer, walletRows: 1, loyaltyRows: 1);
        $this->assertSame(125.5, (float) DB::table('wallet_transactions')->where('user_id', $customer->id)->value('balance'));
        $this->assertSame(340.0, (float) DB::table('loyalty_point_transactions')->where('user_id', $customer->id)->value('balance'));
    }

    public function test_current_behaviour_the_referral_grant_of_a_deleted_customer_stays_and_keeps_naming_an_id_that_names_nobody(): void
    {
        $referrer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $invited = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        DB::table('users')->where('id', $invited->id)->update(['referred_by' => $referrer->id]);
        DB::table('referral_customers')->insert([
            'user_id' => $invited->id, 'refer_by' => $referrer->id, 'ref_by_earning_amount' => 25.0, 'customer_discount_amount' => 10.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->appDeletes($referrer)->assertOk();

        $this->assertAccountIsGone($referrer);
        $grant = DB::table('referral_customers')->where('user_id', $invited->id)->first();
        $this->assertNotNull($grant, 'the referral grant was removed with the account');
        $this->assertSame($referrer->id, (int) $grant->refer_by);
        $this->assertSame(25.0, (float) $grant->ref_by_earning_amount);
        $this->assertSame($referrer->id, (int) DB::table('users')->where('id', $invited->id)->value('referred_by'));
    }

    public function test_current_behaviour_the_admin_panel_deletes_a_customer_who_holds_money_and_leaves_both_ledgers_behind(): void
    {
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);

        $this->adminDeletes($customer)->assertRedirect();

        $this->assertSame(['Customer deleted successfully'], $this->toastMagicMessages());
        $this->assertAccountIsGone($customer);
        $this->assertLedgerRowsOf(customer: $customer, walletRows: 1, loyaltyRows: 1);
        $this->assertSame(125.5, (float) DB::table('wallet_transactions')->where('user_id', $customer->id)->value('balance'));
        $this->assertSame(340.0, (float) DB::table('loyalty_point_transactions')->where('user_id', $customer->id)->value('balance'));
    }

    public function test_current_behaviour_the_app_refuses_while_an_order_is_on_its_way_and_the_website_says_the_same_in_its_own_way(): void
    {
        $appCustomer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $websiteCustomer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $this->orderOnItsWayFor($appCustomer, id: 1001);
        $this->orderOnItsWayFor($websiteCustomer, id: 1002);

        $appAnswer = $this->appDeletes($appCustomer);
        $websiteAnswer = $this->websiteDeletes($websiteCustomer);

        $appAnswer->assertForbidden();
        $this->assertSame('Due to ongoing orders, you are unable to delete your account.', $appAnswer->json('message'));
        $websiteAnswer->assertRedirect();
        $this->assertSame(['You can not delete account due ongoing order'], $this->toastrMessages());
        $this->assertNotNull(User::find($appCustomer->id));
        $this->assertNotNull(User::find($websiteCustomer->id));
    }

    public function test_current_behaviour_neither_route_deletes_the_account_of_another_customer(): void
    {
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $neighbour = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);

        $this->actingAs($customer, 'api');
        $appAnswer = $this->getJson(self::APP_DELETE . $neighbour->id);
        $this->actingAs($customer, 'customer');
        $websiteAnswer = $this->get(self::WEBSITE_DELETE . $neighbour->id);

        $appAnswer->assertForbidden();
        $this->assertSame('access_denied!!', $appAnswer->json('message'));
        $websiteAnswer->assertRedirect();
        $this->assertNotNull(User::find($neighbour->id));
    }

    /**
     * Three things have to say off before a store that pulls this branch behaves as it does today: the value the application reads, the default
     * config/gcc.php falls back to when the environment is silent, and the line .env.example ships -- which is the .env each of the four CI jobs that run
     * phpunit works from, since each copies it, and the one a new install starts from. The first assertion alone would pin whatever .env this machine carries.
     */
    public function test_the_switch_ships_off(): void
    {
        $this->assertFalse(config('gcc.account_deletion.refuse_while_balance_remains'), 'the account-deletion guard must ship off');
        $this->assertFalse($this->theSwitchWithTheEnvironmentSilent(), 'config/gcc.php refuses a deletion on a store that never sets the variable');
        $this->assertStringContainsString("\nGCC_REFUSE_ACCOUNT_DELETE_WITH_BALANCE=false", (string) file_get_contents(base_path('.env.example')));
    }

    public function test_the_two_refusals_are_english_the_language_file_already_ships(): void
    {
        $shipped = include base_path('resources/lang/en/new-messages.php');

        foreach (self::REFUSAL_ENGLISH as $key => $english) {
            $this->assertArrayHasKey($key, $shipped, 'the first customer refused would have translate() rewrite the whole language file from inside the refusal to add this key');
            $this->assertSame($english, $shipped[$key]);
        }
    }

    public function test_with_the_guard_on_the_app_refuses_while_the_wallet_still_holds_money(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 0.0);

        $this->assertAppRefused($this->appDeletes($customer), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $customer);
        $this->assertLedgerRowsOf(customer: $customer, walletRows: 1, loyaltyRows: 1);
        $this->assertSame(125.5, (float) DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
    }

    public function test_with_the_guard_on_the_website_refuses_while_the_wallet_still_holds_money(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 0.0);

        $this->assertWebsiteRefused($this->websiteDeletes($customer), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $customer);
        $this->assertLedgerRowsOf(customer: $customer, walletRows: 1, loyaltyRows: 1);
    }

    public function test_with_the_guard_on_the_app_refuses_while_loyalty_points_still_stand(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 340.0);

        $this->assertAppRefused($this->appDeletes($customer), message: 'you_can_not_delete_account_while_you_still_have_loyalty_points', customer: $customer);
        $this->assertSame(340.0, (float) DB::table('users')->where('id', $customer->id)->value('loyalty_point'));
    }

    public function test_with_the_guard_on_the_website_refuses_while_loyalty_points_still_stand(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 340.0);

        $this->assertWebsiteRefused($this->websiteDeletes($customer), message: 'you_can_not_delete_account_while_you_still_have_loyalty_points', customer: $customer);
    }

    public function test_with_the_guard_on_a_customer_who_holds_both_is_told_about_the_wallet_first(): void
    {
        $this->refuseWhileBalanceRemains();
        $appCustomer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);
        $websiteCustomer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);

        $this->assertAppRefused($this->appDeletes($appCustomer), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $appCustomer);
        $this->assertWebsiteRefused($this->websiteDeletes($websiteCustomer), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $websiteCustomer);
    }

    public function test_with_the_guard_on_the_app_still_tells_a_customer_about_the_order_on_its_way_before_the_money(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);
        $this->orderOnItsWayFor($customer, id: 3001);

        $response = $this->appDeletes($customer);

        $response->assertForbidden();
        $this->assertSame('Due to ongoing orders, you are unable to delete your account.', $response->json('message'));
        $this->assertNotNull(User::find($customer->id), 'the account was deleted after the refusal');
    }

    public function test_with_the_guard_on_the_website_still_tells_a_customer_about_the_order_on_its_way_before_the_money(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);
        $this->orderOnItsWayFor($customer, id: 3002);

        $response = $this->websiteDeletes($customer);

        $response->assertRedirect();
        $this->assertSame(['You can not delete account due ongoing order'], $this->toastrMessages());
        $this->assertNotNull(User::find($customer->id), 'the account was deleted after the refusal');
    }

    public function test_with_the_guard_on_a_customer_who_holds_nothing_still_deletes_the_account_over_both_routes(): void
    {
        $this->refuseWhileBalanceRemains();
        $appCustomer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $websiteCustomer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);

        $this->appDeletes($appCustomer)->assertOk();
        $this->websiteDeletes($websiteCustomer)->assertRedirect(route('home'));

        $this->assertAccountIsGone($appCustomer);
        $this->assertAccountIsGone($websiteCustomer);
        $this->assertLedgerRowsOf(customer: $appCustomer, walletRows: 1, loyaltyRows: 1);
        $this->assertLedgerRowsOf(customer: $websiteCustomer, walletRows: 1, loyaltyRows: 1);
    }

    public function test_with_the_guard_on_the_admin_panel_still_deletes_a_customer_who_holds_money(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 340.0);

        $this->adminDeletes($customer)->assertRedirect();

        $this->assertSame(['Customer deleted successfully'], $this->toastMagicMessages());
        $this->assertAccountIsGone($customer);
        $this->assertLedgerRowsOf(customer: $customer, walletRows: 1, loyaltyRows: 1);
        $this->assertSame(125.5, (float) DB::table('wallet_transactions')->where('user_id', $customer->id)->value('balance'));
    }

    public function test_with_the_guard_on_a_debt_is_held_as_surely_as_a_credit(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: -40.0, loyaltyPoints: 0.0);

        $this->assertAppRefused($this->appDeletes($customer), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $customer);
    }

    public function test_with_the_guard_on_a_holding_of_one_halala_is_money_and_anything_under_it_is_rounding_dust(): void
    {
        $this->refuseWhileBalanceRemains();
        $oneHalala = $this->customerHolding(walletBalance: 0.01, loyaltyPoints: 0.0);
        $justUnder = $this->customerHolding(walletBalance: 0.009, loyaltyPoints: 0.0);
        $dust = $this->customerHolding(walletBalance: 0.0000000001, loyaltyPoints: 0.0000000001);

        $this->assertAppRefused($this->appDeletes($oneHalala), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $oneHalala);
        $this->appDeletes($justUnder)->assertOk();
        $this->appDeletes($dust)->assertOk();

        $this->assertAccountIsGone($justUnder);
        $this->assertAccountIsGone($dust);
    }

    public function test_with_the_guard_on_one_hundredth_of_a_point_is_a_holding_too(): void
    {
        $this->refuseWhileBalanceRemains();
        $oneHundredthOfAPoint = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.01);
        $justUnder = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.009);

        $this->assertAppRefused($this->appDeletes($oneHundredthOfAPoint), message: 'you_can_not_delete_account_while_you_still_have_loyalty_points', customer: $oneHundredthOfAPoint);
        $this->appDeletes($justUnder)->assertOk();
        $this->assertAccountIsGone($justUnder);
    }

    /**
     * The one test here that asks the guard rather than a route: a row deleted between the caller hydrating the customer and the guard weighing them cannot
     * be reached over HTTP, and what the guard does then is worth pinning, because reading the row again is what the two tests further down, the ones that
     * credit a wallet mid-request, rest on.
     */
    public function test_with_the_guard_on_a_customer_row_that_has_gone_is_weighed_as_the_caller_last_read_it(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 0.0);
        DB::table('users')->where('id', $customer->id)->delete();

        $this->assertSame(
            'you_can_not_delete_account_while_your_wallet_holds_money',
            app(CustomerAccountDeletionGuardService::class)->blockingReason(customer: $customer),
        );
    }

    public function test_with_the_guard_on_what_a_neighbour_holds_is_not_what_this_customer_holds(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $neighbour = $this->customerHolding(walletBalance: 900.0, loyaltyPoints: 700.0);

        $this->appDeletes($customer)->assertOk();

        $this->assertAccountIsGone($customer);
        $this->assertNotNull(User::find($neighbour->id));
        $this->assertSame(900.0, (float) DB::table('users')->where('id', $neighbour->id)->value('wallet_balance'));
        $this->assertLedgerRowsOf(customer: $neighbour, walletRows: 1, loyaltyRows: 1);
    }

    public function test_with_the_guard_on_the_customer_who_holds_the_money_is_the_one_refused_and_their_neighbour_is_not(): void
    {
        $this->refuseWhileBalanceRemains();
        $holder = $this->customerHolding(walletBalance: 900.0, loyaltyPoints: 0.0);
        $neighbour = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);

        $this->assertAppRefused($this->appDeletes($holder), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $holder);
        $this->appDeletes($neighbour)->assertOk();
        $this->assertAccountIsGone($neighbour);
    }

    public function test_with_the_guard_on_the_app_weighs_money_that_landed_after_it_read_the_customer(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $this->creditTheWalletWhileTheOrdersAreCounted($customer, amount: 500.0);

        $this->assertAppRefused($this->appDeletes($customer), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $customer);
        $this->assertLedgerRowsOf(customer: $customer, walletRows: 2, loyaltyRows: 1);
        $this->assertSame(500.0, (float) DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
    }

    public function test_with_the_guard_on_the_website_weighs_money_that_landed_after_it_read_the_customer(): void
    {
        $this->refuseWhileBalanceRemains();
        $customer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $this->creditTheWalletWhileTheOrdersAreCounted($customer, amount: 500.0);

        $this->assertWebsiteRefused($this->websiteDeletes($customer), message: 'you_can_not_delete_account_while_your_wallet_holds_money', customer: $customer);
        $this->assertLedgerRowsOf(customer: $customer, walletRows: 2, loyaltyRows: 1);
    }

    public function test_with_the_guard_on_the_refusal_answers_in_the_shape_each_route_already_refuses_an_ongoing_order_with(): void
    {
        $this->refuseWhileBalanceRemains();
        $appCustomer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 0.0);
        $websiteCustomer = $this->customerHolding(walletBalance: 125.5, loyaltyPoints: 0.0);
        $appOrderCustomer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $websiteOrderCustomer = $this->customerHolding(walletBalance: 0.0, loyaltyPoints: 0.0);
        $this->orderOnItsWayFor($appOrderCustomer, id: 2001);
        $this->orderOnItsWayFor($websiteOrderCustomer, id: 2002);

        $appMoney = $this->appDeletes($appCustomer);
        $appOrder = $this->appDeletes($appOrderCustomer);
        $websiteMoney = $this->websiteDeletes($websiteCustomer);
        $websiteOrder = $this->websiteDeletes($websiteOrderCustomer);

        $this->assertSame($appOrder->getStatusCode(), $appMoney->getStatusCode());
        $this->assertSame(array_keys($appOrder->json()), array_keys($appMoney->json()));
        $this->assertSame($websiteOrder->getStatusCode(), $websiteMoney->getStatusCode());
        $this->assertSame(['warning', 'warning'], array_column(session('toastr::messages'), 'type'));
    }
}
