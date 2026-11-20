<?php

namespace Tests\Feature\Customer;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deleting a customer account keeps the money and its history (E-08.1). The customer row carries the balance and is the only parent the
 * two ledgers have, so an account that holds money, or has ever moved any, keeps its row with the personal details scrubbed out of it;
 * an account that holds nothing is removed, as it always was.
 *
 * Measured through every way in: the mobile app's REST v1 route, the website's, and the admin panel's — which deleted the row outright
 * and stranded the ledger rows on a parent that no longer existed.
 */
class AccountDeletionKeepsLedgerTest extends CustomerLedgerTestCase
{
    public function test_the_api_keeps_the_ledger_rows_and_the_balance_record_of_a_customer_who_held_money(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory(walletBalance: 150.25, loyaltyPoints: 400.0);
        $before = $this->moneyRowsOf($customer->id);

        $response = $this->deleteThroughTheApi($customer);

        $response->assertOk()->assertJson(['message' => 'Your account deleted successfully']);
        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $before);
        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($customer->id), 'The money history and the row that carries the balance both survive the deletion.');
        $closed = DB::table('users')->where('id', $customer->id)->first();
        $this->assertSame(150.25, (float) $closed->wallet_balance);
        $this->assertSame(400.0, (float) $closed->loyalty_point);
    }

    public function test_the_website_keeps_the_ledger_rows_and_the_balance_record_of_a_customer_who_held_money(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory(walletBalance: 77.5, loyaltyPoints: 12.0);

        $this->deleteThroughTheWebsite($customer)->assertRedirect(route('home'));

        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($customer->id));
        $closed = DB::table('users')->where('id', $customer->id)->first();
        $this->assertSame(77.5, (float) $closed->wallet_balance);
        $this->assertSame(12.0, (float) $closed->loyalty_point);
    }

    public function test_the_admin_panel_keeps_the_ledger_rows_and_the_balance_record_of_a_customer_who_held_money(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory(walletBalance: 150.25, loyaltyPoints: 400.0);

        $this->deleteThroughTheAdminPanel($customer);

        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($customer->id), 'Deleting a customer from the panel is the same act with the same consequence: the balance record and the history stay.');
        $closed = DB::table('users')->where('id', $customer->id)->first();
        $this->assertSame(150.25, (float) $closed->wallet_balance);
        $this->assertSame(400.0, (float) $closed->loyalty_point);
        $this->assertSame('deleted', $closed->f_name, 'The panel closes the account the same way the customer\'s own routes do.');
    }

    public function test_the_admin_panel_removes_an_account_that_never_held_money_as_it_always_did(): void
    {
        $customer = $this->makeCustomer();

        $this->deleteThroughTheAdminPanel($customer);

        $this->assertSame(['users' => 0, 'wallet' => 0, 'loyalty' => 0], $this->moneyRowsOf($customer->id), 'Saudi behaviour for a customer with nothing to keep is what it was: the row goes.');
    }

    public function test_the_closed_account_carries_none_of_the_customers_details_any_more(): void
    {
        $customer = $this->makeCustomer([
            'wallet_balance' => 10.0, 'f_name' => 'Sara', 'l_name' => 'Alharbi', 'email' => 'sara@example.test', 'phone' => '+966551234567',
            'image' => 'sara.webp', 'street_address' => 'King Fahd Road', 'city' => 'Riyadh', 'zip' => '11564', 'country' => 'SA',
            'house_no' => '12', 'apartment_no' => '3', 'cm_firebase_token' => 'firebase-token', 'cm_firebase_token_web' => 'firebase-web',
            'temporary_token' => 'temporary-token', 'social_id' => 'google-123', 'login_medium' => 'google', 'referral_code' => 'SARA10',
            'payment_card_last_four' => '4242', 'payment_card_brand' => 'visa', 'payment_card_fawry_token' => 'fawry-token',
            'remember_token' => 'remember-me', 'is_phone_verified' => 1, 'is_email_verified' => 1,
        ]);

        $this->deleteThroughTheApi($customer)->assertOk();

        $closed = (array) DB::table('users')->where('id', $customer->id)->first();
        foreach (['name', 'image', 'street_address', 'city', 'zip', 'country', 'house_no', 'apartment_no', 'cm_firebase_token', 'cm_firebase_token_web', 'temporary_token', 'social_id', 'login_medium', 'referral_code', 'payment_card_last_four', 'payment_card_brand', 'payment_card_fawry_token', 'remember_token'] as $column) {
            $this->assertNull($closed[$column], "{$column} still holds the customer's own data.");
        }
        $this->assertSame('deleted', $closed['f_name']);
        $this->assertSame('deleted', $closed['l_name']);
        $this->assertSame('deleted-' . $customer->id . '@deleted.invalid', $closed['email']);
        $this->assertSame('deleted-' . $customer->id, $closed['phone']);
        $this->assertSame(0, (int) $closed['is_active']);
        $this->assertSame(0, (int) $closed['is_phone_verified']);
        $this->assertSame(0, (int) $closed['is_email_verified']);
        $this->assertSame(10.0, (float) $closed['wallet_balance'], 'The balance is the one thing the scrub leaves alone.');
    }

    public function test_the_closed_account_cannot_be_signed_in_to_again(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();
        $addressTheCustomerKnew = $customer->email;
        DB::table('oauth_access_tokens')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $customer->id, 'client_id' => 1, 'revoked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteThroughTheApi($customer)->assertOk();

        $this->assertFalse(
            Auth::guard('customer')->attempt(['email' => $addressTheCustomerKnew, 'password' => self::CUSTOMER_PASSWORD]),
            'The address and password the customer signed in with still open a session.',
        );
        $this->assertFalse(
            Auth::guard('customer')->attempt(['email' => 'deleted-' . $customer->id . '@deleted.invalid', 'password' => self::CUSTOMER_PASSWORD]),
            'The row that is left opens with the password the customer knew.',
        );
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $customer->id)->where('revoked', false)->count(), 'A token issued before the account was closed still opens every customer route while it is unrevoked.');
        $this->assertFalse((bool) User::find($customer->id)->is_active);
    }

    public function test_an_account_that_never_held_money_is_removed_as_it_always_was(): void
    {
        $customer = $this->makeCustomer();
        $before = $this->moneyRowsOf($customer->id);

        $this->deleteThroughTheApi($customer)->assertOk();

        $this->assertSame(['users' => 1, 'wallet' => 0, 'loyalty' => 0], $before);
        $this->assertSame(['users' => 0, 'wallet' => 0, 'loyalty' => 0], $this->moneyRowsOf($customer->id), 'Saudi behaviour for a customer with nothing to keep is what it was: the row goes.');
    }

    public function test_an_account_that_never_held_money_is_removed_by_the_website_too(): void
    {
        $customer = $this->makeCustomer();

        $this->deleteThroughTheWebsite($customer)->assertRedirect(route('home'));

        $this->assertSame(['users' => 0, 'wallet' => 0, 'loyalty' => 0], $this->moneyRowsOf($customer->id));
    }

    /**
     * A zero balance with movements behind it is history: the two cancel out but the books still have to show them, so the row stays.
     */
    public function test_an_account_whose_balance_is_back_to_zero_still_keeps_its_movements(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => 0.0, 'loyalty_point' => 0.0]);
        $this->appendWalletMovement($customer, credit: 90.0, debit: 0.0, balance: 90.0, type: 'add_fund');
        $this->appendWalletMovement($customer, credit: 0.0, debit: 90.0, balance: 0.0, type: 'order_place');

        $this->deleteThroughTheApi($customer)->assertOk();

        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 0], $this->moneyRowsOf($customer->id));
    }

    /** A debt is money too: an account that owes the store is not closed by making the debt disappear. */
    public function test_an_account_with_a_negative_balance_keeps_its_row(): void
    {
        $customer = $this->makeCustomer(['wallet_balance' => -25.0]);

        $this->deleteThroughTheApi($customer)->assertOk();

        $this->assertSame(1, DB::table('users')->where('id', $customer->id)->count());
        $this->assertSame(-25.0, (float) DB::table('users')->where('id', $customer->id)->value('wallet_balance'));
    }

    public function test_only_points_are_enough_to_keep_the_row(): void
    {
        $customer = $this->makeCustomer(['loyalty_point' => 300.0]);

        $this->deleteThroughTheApi($customer)->assertOk();

        $this->assertSame(300.0, (float) DB::table('users')->where('id', $customer->id)->value('loyalty_point'));
    }

    public function test_deleting_the_same_account_twice_changes_nothing_the_second_time(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();
        $this->deleteThroughTheApi($customer)->assertOk();
        $afterTheFirst = (array) DB::table('users')->where('id', $customer->id)->first();

        $this->deleteThroughTheApi(User::find($customer->id))->assertOk();

        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($customer->id));
        $this->assertSame(
            array_diff_key($afterTheFirst, ['password' => null, 'updated_at' => null]),
            array_diff_key((array) DB::table('users')->where('id', $customer->id)->first(), ['password' => null, 'updated_at' => null]),
            'The second request leaves the row as the first one left it.',
        );
    }

    /**
     * `email` and `phone` are unique on the live store, so two closed accounts cannot carry the same placeholder. Built from the row id
     * rather than a fixed marker, and this is the test that makes the index say so.
     */
    public function test_two_accounts_can_both_be_closed_without_colliding_on_the_address_or_the_number(): void
    {
        $sara = $this->makeCustomerWithMoneyHistory();
        $omar = $this->makeCustomerWithMoneyHistory();

        $this->deleteThroughTheApi($sara)->assertOk();
        $this->deleteThroughTheApi($omar)->assertOk();

        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($sara->id));
        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($omar->id));
        $closed = DB::table('users')->whereIn('id', [$sara->id, $omar->id])->orderBy('id')->get();
        $this->assertSame(['deleted-' . $sara->id . '@deleted.invalid', 'deleted-' . $omar->id . '@deleted.invalid'], $closed->pluck('email')->all());
        $this->assertSame(['deleted-' . $sara->id, 'deleted-' . $omar->id], $closed->pluck('phone')->all());
    }

    public function test_one_customer_cannot_close_another_customers_account_through_the_api(): void
    {
        $sara = $this->makeCustomerWithMoneyHistory();
        $omar = $this->makeCustomerWithMoneyHistory();

        $this->deleteThroughTheApi($omar, id: $sara->id)->assertForbidden()->assertJson(['message' => 'access_denied!!']);

        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($sara->id));
        $this->assertSame('Sara', DB::table('users')->where('id', $sara->id)->value('f_name'), 'Nothing of the other customer was touched.');
    }

    public function test_one_customer_cannot_close_another_customers_account_through_the_website(): void
    {
        $sara = $this->makeCustomerWithMoneyHistory();
        $omar = $this->makeCustomerWithMoneyHistory();

        $this->deleteThroughTheWebsite($omar, id: $sara->id)->assertRedirect();

        $this->assertSame('Sara', DB::table('users')->where('id', $sara->id)->value('f_name'));
        $this->assertSame(['users' => 1, 'wallet' => 2, 'loyalty' => 2], $this->moneyRowsOf($sara->id));
    }

    public function test_an_ongoing_order_still_stops_the_deletion_before_anything_is_touched(): void
    {
        $customer = $this->makeCustomerWithMoneyHistory();
        DB::table('orders')->insert(['customer_id' => $customer->id, 'is_guest' => 0, 'order_status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteThroughTheApi($customer)->assertForbidden();

        $this->assertSame('Sara', DB::table('users')->where('id', $customer->id)->value('f_name'));
        $this->assertSame(1, (int) DB::table('users')->where('id', $customer->id)->value('is_active'));
    }
}
