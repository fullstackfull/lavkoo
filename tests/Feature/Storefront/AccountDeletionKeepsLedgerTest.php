<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsApiAddressSchema;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * E-08 of the master plan: what a customer's own account deletion does to the money the store still holds for them, over the two routes that really delete
 * an account -- `GET /api/v1/customer/account-delete/{id}` for the customer app and `GET /account-delete/{id}` for the website.
 *
 * The tests of the switched-off half are characterisation tests: they state what the platform does TODAY, before any guard, and they must keep passing
 * unchanged while `gcc.account_deletion.refuse_while_balance_remains` is off, which is how it ships. Today both routes delete the row of a customer whose
 * wallet still holds money or whose loyalty points still stand, and they say nothing about either.
 */
class AccountDeletionKeepsLedgerTest extends TestCase
{
    use BuildsApiAddressSchema;
    use BuildsWalletAndVendorLedgerSchema;
    use RestoresLanguageFiles;

    private const APP_DELETE = '/api/v1/customer/account-delete/';

    private const WEBSITE_DELETE = '/account-delete/';

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

    private function orderOnItsWayFor(User $customer, int $id): void
    {
        DB::table('orders')->insert(['id' => $id, 'customer_id' => $customer->id, 'is_guest' => 0, 'order_status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
    }
}
