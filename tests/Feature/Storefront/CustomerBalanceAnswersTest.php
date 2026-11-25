<?php

namespace Tests\Feature\Storefront;

use App\Enums\Wallet\MarketBalanceKind;
use App\Models\User;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Wallet\CustomerMarketBalances;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsApiAddressSchema;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\Support\BuildsWalletAndVendorLedgerSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * E-04.2 of the master plan: the API v1 answers the customer app reads keep the keys and the meaning they have in the default (Saudi) market, which is the only one a request answers for while it carries no
 * storefront. `wallet_balance` and `loyalty_point` in the profile, `total_wallet_balance` in the wallet list and `total_loyalty_point` in the loyalty list are the customer's own balance columns
 * (`users.wallet_balance`, `users.loyalty_point`), sent as JSON numbers; they are not added up from the ledgers, which the two lists only page through. The two lists keep the keys around them and the keys of
 * their rows. A later answer may add a key; it may not rename, drop or reshape one of these.
 *
 * Every value asserted here is one this test wrote into the tables; none is read back from the code. The balances go through the database and the model's float cast, as they do for a real request: the
 * customer is read from the table before the request is made. A whole number reaches the app without a decimal point (`340`, not `340.0`) because the answer is a plain `json_encode`; that is what is pinned.
 * The seeds of setUp() are consistent (the balance column is what the ledger adds up to and what its newest row says), so on their own they cannot tell the column from a number derived from the ledger. Two tests
 * do: one gives a customer whose ledgers add up to something else than the balance columns, the other a customer with no ledger rows at all. The web theme reads `$user->wallet_balance` from the model and not
 * these answers; `Web\UserWalletController` and `Web\UserLoyaltyController` are not covered here.
 *
 * It uses `BuildsApiAddressSchema` only for the throwaway key pair Passport's guard needs and for `makeCustomer()`.
 */
class CustomerBalanceAnswersTest extends TestCase
{
    use BuildsApiAddressSchema;
    // For runMarketAccountMigration() and withMarketAccountsOn() only: the schema this test needs is the API's, built above.
    use BuildsMarketAccountSchema;
    use BuildsWalletAndVendorLedgerSchema;
    use RestoresLanguageFiles;

    private const PROFILE = '/api/v1/customer/info';

    private const WALLET_LIST = '/api/v1/customer/wallet/list?limit=10&offset=1';

    private const LOYALTY_LIST = '/api/v1/customer/loyalty/list?limit=10&offset=1';

    private const WALLET_KEYS = ['limit', 'offset', 'total_wallet_balance', 'total_size', 'wallet_transaction_list', 'filter_by', 'start_date', 'end_date', 'transaction_types'];

    private const LOYALTY_KEYS = ['limit', 'offset', 'total_loyalty_point', 'total_size', 'loyalty_point_list', 'filter_by', 'start_date', 'end_date', 'transaction_types'];

    private const WALLET_ROW_KEYS = ['id', 'user_id', 'transaction_id', 'credit', 'debit', 'admin_bonus', 'balance', 'transaction_type', 'payment_method', 'reference', 'created_at', 'updated_at'];

    private const LOYALTY_ROW_KEYS = ['id', 'user_id', 'transaction_id', 'credit', 'debit', 'balance', 'reference', 'transaction_type', 'created_at', 'updated_at'];

    private User $customer;

    private User $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCustomerLedgerTables();
        $this->useThrowawayPassportKeys();
        DB::table('business_settings')->insert([
            ['type' => 'wallet_status', 'value' => json_encode(1)],
            ['type' => 'loyalty_point_status', 'value' => json_encode(1)],
        ]);

        $this->customer = $this->customerWith(walletBalance: 125.5, loyaltyPoints: 340.0);
        $this->walletRow(customer: $this->customer, transactionId: 'aaaaaaaa-0000-4000-8000-000000000001', daysAgo: 3, type: 'add_fund_by_admin', credit: 100.0, debit: 0.0, balance: 100.0, reference: 'admin-top-up');
        $this->walletRow(customer: $this->customer, transactionId: 'aaaaaaaa-0000-4000-8000-000000000002', daysAgo: 2, type: 'order_place', credit: 0.0, debit: 40.0, balance: 60.0, reference: 'order_place_in_pos');
        $this->walletRow(customer: $this->customer, transactionId: 'aaaaaaaa-0000-4000-8000-000000000003', daysAgo: 1, type: 'loyalty_point', credit: 65.5, debit: 0.0, balance: 125.5, reference: 'point_to_wallet');
        $this->loyaltyRow(customer: $this->customer, transactionId: 'bbbbbbbb-0000-4000-8000-000000000001', daysAgo: 3, type: 'order_place', credit: 400.0, debit: 0.0, balance: 400.0, reference: '1001');
        $this->loyaltyRow(customer: $this->customer, transactionId: 'bbbbbbbb-0000-4000-8000-000000000002', daysAgo: 1, type: 'point_to_wallet', credit: 0.0, debit: 60.0, balance: 340.0, reference: 'aaaaaaaa-0000-4000-8000-000000000003');

        $this->neighbour = $this->customerWith(walletBalance: 999.25, loyaltyPoints: 7.0);
        $this->walletRow(customer: $this->neighbour, transactionId: 'cccccccc-0000-4000-8000-000000000001', daysAgo: 5, type: 'add_fund', credit: 1000.0, debit: 0.0, balance: 1000.0, reference: 'online-top-up');
        $this->walletRow(customer: $this->neighbour, transactionId: 'cccccccc-0000-4000-8000-000000000002', daysAgo: 4, type: 'order_place', credit: 0.0, debit: 0.75, balance: 999.25, reference: 'order_place_in_pos');
        $this->loyaltyRow(customer: $this->neighbour, transactionId: 'dddddddd-0000-4000-8000-000000000001', daysAgo: 4, type: 'order_place', credit: 7.0, debit: 0.0, balance: 7.0, reference: '2002');
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function customerWith(float $walletBalance, float $loyaltyPoints): User
    {
        $id = $this->makeCustomer(['wallet_balance' => $walletBalance, 'loyalty_point' => $loyaltyPoints])->id;

        return User::findOrFail($id);
    }

    private function walletRow(User $customer, string $transactionId, int $daysAgo, string $type, float $credit, float $debit, float $balance, string $reference): void
    {
        DB::table('wallet_transactions')->insert([
            'user_id' => $customer->id, 'transaction_id' => $transactionId, 'credit' => $credit, 'debit' => $debit, 'admin_bonus' => 0, 'balance' => $balance,
            'transaction_type' => $type, 'reference' => $reference, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    private function loyaltyRow(User $customer, string $transactionId, int $daysAgo, string $type, float $credit, float $debit, float $balance, string $reference): void
    {
        DB::table('loyalty_point_transactions')->insert([
            'user_id' => $customer->id, 'transaction_id' => $transactionId, 'credit' => $credit, 'debit' => $debit, 'balance' => $balance,
            'transaction_type' => $type, 'reference' => $reference, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    private function answerTo(User $customer, string $uri): TestResponse
    {
        $this->actingAs($customer, 'api');

        return $this->getJson($uri)->assertOk();
    }

    /** @param list<string> $keys */
    private function assertHasKeys(array $keys, array $answer): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $answer, "the answer lost its `{$key}` key");
        }
    }

    public function test_the_profile_answers_the_wallet_balance_and_the_loyalty_points_of_the_customer_as_numbers(): void
    {
        $response = $this->answerTo(customer: $this->customer, uri: self::PROFILE);
        $answer = $response->json();

        $this->assertSame($this->customer->id, $answer['id']);
        $this->assertSame(125.5, $answer['wallet_balance']);
        $this->assertSame(340, $answer['loyalty_point']);
        $this->assertStringContainsString('"wallet_balance":125.5,', $response->getContent());
        $this->assertStringContainsString('"loyalty_point":340,', $response->getContent());
    }

    /**
     * J-09 and E-01: the profile answers the balance of the MARKET being served, as every other customer-facing balance read already does.
     *
     * This was the one read that did not. The wallet list, the loyalty list, both web pages, the till's customer lookup and the overdraft
     * check that judges a wallet payment all ask MarketBalanceReader; the profile returned the `users` row, so a customer with a hundred
     * dinars in Kuwait and nothing in Riyadh was shown a number here that was neither -- beside a wallet screen that had it right.
     *
     * The test above is the one that protects what exists: with no market switched over, which is the platform as it ships, this answer is
     * the `users` column to the last decimal and to the shape of the JSON.
     */
    public function test_the_profile_answers_the_balance_of_the_market_being_served(): void
    {
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        config(['gcc.storefronts.market_accounts.answers_for' => ['KW']]);
        $this->theDinarOfKuwaitIsMoneyThePlatformKnows();
        $accounts = app(CustomerMarketBalances::class);
        $this->assertTrue($accounts->credit(kind: MarketBalanceKind::Wallet, customerId: $this->customer->id, market: 'KW', amount: 12.345)->applied, 'the Kuwaiti wallet account was opened');
        $this->assertTrue($accounts->credit(kind: MarketBalanceKind::Loyalty, customerId: $this->customer->id, market: 'KW', amount: 9)->applied, 'and the Kuwaiti points account');

        $inKuwait = $this->profileServedBy('KW');
        $inSaudiArabia = $this->profileServedBy('SA');

        $this->assertSame(12.345, $inKuwait['wallet_balance'], 'the Kuwaiti account, in the third decimal the dinar is written with');
        $this->assertSame(9, $inKuwait['loyalty_point'], 'a point has never had a decimal, whichever side it is read from');
        $this->assertSame(125.5, $inSaudiArabia['wallet_balance'], 'a market that has not been switched over answers the one column');
        $this->assertSame(340, $inSaudiArabia['loyalty_point']);
    }

    /** And nothing else about the answer moves: the keys the app reads are all still there, balances replaced in the payload and not on the model. */
    public function test_the_profile_keeps_every_key_it_carried_while_the_balances_follow_the_market(): void
    {
        $answer = $this->profileServedBy('SA');

        $this->assertHasKeys(
            keys: ['id', 'f_name', 'l_name', 'email', 'phone', 'wallet_balance', 'loyalty_point', 'referral_user_count', 'orders_count', 'is_phone_verified', 'email_verified_at', 'show_auction_menu_for_customer'],
            answer: $answer,
        );
        $this->assertSame(
            (float) DB::table('users')->where('id', $this->customer->id)->value('wallet_balance'),
            125.5,
            'the one column every movement is still written to was not overwritten by the market it was read for',
        );
    }

    /**
     * An account is opened in the money the market SELLS in, so the platform has to know that currency. This test's schema has no
     * currencies at all: without the row the credit is refused `currency_unknown`, the account stays empty, and the test would pass while
     * reading nothing.
     */
    private function theDinarOfKuwaitIsMoneyThePlatformKnows(): void
    {
        if (!Schema::hasTable('currencies')) {
            Schema::create('currencies', static function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('symbol')->nullable();
                $table->string('code')->nullable();
                $table->decimal('exchange_rate', 20, 8)->default(1);
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        DB::table('currencies')->insert(['name' => 'Kuwaiti Dinar', 'code' => 'KWD', 'symbol' => 'KWD', 'exchange_rate' => 1, 'status' => 1]);
    }

    /** @return array<string, mixed> the profile, read as a request that market is serving */
    private function profileServedBy(string $market): array
    {
        $this->actingAs($this->customer, 'api');

        return $this->withHeader(StorefrontResolver::HEADER, $market)->getJson(self::PROFILE)->assertOk()->json();
    }

    public function test_the_wallet_list_answers_the_balance_the_count_and_the_rows_of_the_customer_newest_first(): void
    {
        $response = $this->answerTo(customer: $this->customer, uri: self::WALLET_LIST);
        $answer = $response->json();

        $this->assertHasKeys(keys: self::WALLET_KEYS, answer: $answer);
        $this->assertSame(10, $answer['limit']);
        $this->assertSame(1, $answer['offset']);
        $this->assertSame(125.5, $answer['total_wallet_balance']);
        $this->assertStringContainsString('"total_wallet_balance":125.5,', $response->getContent());
        $this->assertSame(3, $answer['total_size']);
        $this->assertSame(['aaaaaaaa-0000-4000-8000-000000000003', 'aaaaaaaa-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-000000000001'], array_column($answer['wallet_transaction_list'], 'transaction_id'));
        $this->assertNull($answer['filter_by']);
        $this->assertNull($answer['start_date']);
        $this->assertNull($answer['end_date']);
        $this->assertSame([], $answer['transaction_types']);
    }

    public function test_a_row_of_the_wallet_list_keeps_the_keys_and_the_numbers_the_app_reads(): void
    {
        $row = $this->answerTo(customer: $this->customer, uri: self::WALLET_LIST)->json('wallet_transaction_list.0');

        $this->assertHasKeys(keys: self::WALLET_ROW_KEYS, answer: $row);
        $this->assertSame($this->customer->id, $row['user_id']);
        $this->assertSame('loyalty_point', $row['transaction_type']);
        $this->assertSame('point_to_wallet', $row['reference']);
        $this->assertSame(65.5, $row['credit']);
        $this->assertSame(0, $row['debit']);
        $this->assertSame(125.5, $row['balance']);
        $this->assertIsString($row['created_at']);
    }

    public function test_the_loyalty_list_answers_the_points_the_count_and_the_rows_of_the_customer_newest_first(): void
    {
        $response = $this->answerTo(customer: $this->customer, uri: self::LOYALTY_LIST);
        $answer = $response->json();

        $this->assertHasKeys(keys: self::LOYALTY_KEYS, answer: $answer);
        $this->assertSame(10, $answer['limit']);
        $this->assertSame(1, $answer['offset']);
        $this->assertSame(340, $answer['total_loyalty_point']);
        $this->assertStringContainsString('"total_loyalty_point":340,', $response->getContent());
        $this->assertSame(2, $answer['total_size']);
        $this->assertSame(['bbbbbbbb-0000-4000-8000-000000000002', 'bbbbbbbb-0000-4000-8000-000000000001'], array_column($answer['loyalty_point_list'], 'transaction_id'));
        $this->assertNull($answer['filter_by']);
        $this->assertNull($answer['start_date']);
        $this->assertNull($answer['end_date']);
        $this->assertSame([], $answer['transaction_types']);
    }

    public function test_a_row_of_the_loyalty_list_keeps_the_keys_and_the_numbers_the_app_reads(): void
    {
        $row = $this->answerTo(customer: $this->customer, uri: self::LOYALTY_LIST)->json('loyalty_point_list.0');

        $this->assertHasKeys(keys: self::LOYALTY_ROW_KEYS, answer: $row);
        $this->assertSame($this->customer->id, $row['user_id']);
        $this->assertSame('point_to_wallet', $row['transaction_type']);
        $this->assertSame(0, $row['credit']);
        $this->assertSame(60, $row['debit']);
        $this->assertSame(340, $row['balance']);
    }

    public function test_the_balance_of_the_profile_is_the_balance_of_the_wallet_list(): void
    {
        $profile = $this->answerTo(customer: $this->customer, uri: self::PROFILE)->json();
        $list = $this->answerTo(customer: $this->customer, uri: self::WALLET_LIST)->json();

        $this->assertSame($profile['wallet_balance'], $list['total_wallet_balance']);
        $this->assertSame(125.5, $list['total_wallet_balance']);
    }

    public function test_the_lists_page_by_limit_and_offset_where_offset_is_the_page(): void
    {
        $wallet = $this->answerTo(customer: $this->customer, uri: '/api/v1/customer/wallet/list?limit=2&offset=2')->json();
        $loyalty = $this->answerTo(customer: $this->customer, uri: '/api/v1/customer/loyalty/list?limit=1&offset=2')->json();

        $this->assertSame(2, $wallet['limit']);
        $this->assertSame(2, $wallet['offset']);
        $this->assertSame(3, $wallet['total_size']);
        $this->assertSame(['aaaaaaaa-0000-4000-8000-000000000001'], array_column($wallet['wallet_transaction_list'], 'transaction_id'));
        $this->assertSame(1, $loyalty['limit']);
        $this->assertSame(2, $loyalty['offset']);
        $this->assertSame(2, $loyalty['total_size']);
        $this->assertSame(['bbbbbbbb-0000-4000-8000-000000000001'], array_column($loyalty['loyalty_point_list'], 'transaction_id'));
    }

    public function test_each_customer_is_answered_with_their_own_balances_and_their_own_rows(): void
    {
        $profile = $this->answerTo(customer: $this->neighbour, uri: self::PROFILE)->json();
        $wallet = $this->answerTo(customer: $this->neighbour, uri: self::WALLET_LIST)->json();
        $loyalty = $this->answerTo(customer: $this->neighbour, uri: self::LOYALTY_LIST)->json();

        $this->assertSame(999.25, $profile['wallet_balance']);
        $this->assertSame(999.25, $wallet['total_wallet_balance']);
        $this->assertSame(2, $wallet['total_size']);
        $this->assertSame(['cccccccc-0000-4000-8000-000000000002', 'cccccccc-0000-4000-8000-000000000001'], array_column($wallet['wallet_transaction_list'], 'transaction_id'));
        $this->assertSame(7, $profile['loyalty_point']);
        $this->assertSame(7, $loyalty['total_loyalty_point']);
        $this->assertSame(['dddddddd-0000-4000-8000-000000000001'], array_column($loyalty['loyalty_point_list'], 'transaction_id'));
    }

    public function test_the_balances_are_the_columns_of_the_customer_and_not_what_the_ledgers_add_up_to(): void
    {
        $drifted = $this->customerWith(walletBalance: 250.75, loyaltyPoints: 12.0);
        $this->walletRow(customer: $drifted, transactionId: 'eeeeeeee-0000-4000-8000-000000000001', daysAgo: 2, type: 'add_fund', credit: 80.0, debit: 0.0, balance: 80.0, reference: 'online-top-up');
        $this->walletRow(customer: $drifted, transactionId: 'eeeeeeee-0000-4000-8000-000000000002', daysAgo: 1, type: 'order_place', credit: 0.0, debit: 5.0, balance: 75.0, reference: 'order_place_in_pos');
        $this->loyaltyRow(customer: $drifted, transactionId: 'ffffffff-0000-4000-8000-000000000001', daysAgo: 1, type: 'order_place', credit: 5.0, debit: 0.0, balance: 5.0, reference: '3003');

        $profile = $this->answerTo(customer: $drifted, uri: self::PROFILE)->json();
        $wallet = $this->answerTo(customer: $drifted, uri: self::WALLET_LIST)->json();
        $loyalty = $this->answerTo(customer: $drifted, uri: self::LOYALTY_LIST)->json();

        $this->assertSame(250.75, $profile['wallet_balance']);
        $this->assertSame(250.75, $wallet['total_wallet_balance']);
        $this->assertSame(2, $wallet['total_size']);
        $this->assertSame(12, $profile['loyalty_point']);
        $this->assertSame(12, $loyalty['total_loyalty_point']);
        $this->assertSame(1, $loyalty['total_size']);
    }

    public function test_a_customer_without_ledger_rows_is_answered_with_the_balance_columns_and_empty_lists(): void
    {
        $newcomer = $this->customerWith(walletBalance: 250.75, loyaltyPoints: 12.0);

        $profile = $this->answerTo(customer: $newcomer, uri: self::PROFILE)->json();
        $wallet = $this->answerTo(customer: $newcomer, uri: self::WALLET_LIST)->json();
        $loyalty = $this->answerTo(customer: $newcomer, uri: self::LOYALTY_LIST)->json();

        $this->assertSame(250.75, $profile['wallet_balance']);
        $this->assertSame(250.75, $wallet['total_wallet_balance']);
        $this->assertSame(0, $wallet['total_size']);
        $this->assertSame([], $wallet['wallet_transaction_list']);
        $this->assertSame(12, $profile['loyalty_point']);
        $this->assertSame(12, $loyalty['total_loyalty_point']);
        $this->assertSame(0, $loyalty['total_size']);
        $this->assertSame([], $loyalty['loyalty_point_list']);
    }

    /** @return array<string, array{string}> */
    public static function routesThatAnswerACustomer(): array
    {
        return ['the profile' => [self::PROFILE], 'the wallet list' => [self::WALLET_LIST], 'the loyalty list' => [self::LOYALTY_LIST]];
    }

    #[DataProvider('routesThatAnswerACustomer')]
    public function test_without_a_customer_nothing_is_answered(string $uri): void
    {
        $this->getJson($uri)->assertUnauthorized();
    }
}
