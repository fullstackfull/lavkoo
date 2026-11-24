<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Models\LoyaltyPointTransaction;
use App\Models\WalletTransaction;
use App\Services\Wallet\CustomerMarketBalances;
use App\Services\Wallet\MarketShadowWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * E-07, shadow mode: every movement written to the one balance a customer has is mirrored into their account in the market that movement
 * names, in the same transaction, and the two are compared.
 *
 * The mirror is never an authority. What these cases hold is that it writes where it should, writes down what it cannot do, and never
 * breaks the write it follows: a ledger row stands whatever the mirror makes of it, and the `users` balance is never touched by it.
 */
class MarketShadowWriteTest extends TestCase
{
    use BuildsMarketAccountSchema;

    private const CUSTOMER = 51;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
    }

    protected function tearDown(): void
    {
        $this->startFromAnEmptyMarketAccountDatabase();

        parent::tearDown();
    }

    private function withShadowOn(): void
    {
        $this->withMarketAccountsOn();
        config(['gcc.storefronts.market_accounts.shadow_writes' => true]);
    }

    /** A customer with a balance whose market account the backfill has already opened, which is what shadow mode runs behind. */
    private function aCustomerWithAnOpenAccount(string $market = 'SA', float $wallet = 100.0, float $points = 50.0): void
    {
        $this->seedCustomer(self::CUSTOMER, walletBalance: $wallet, loyaltyPoints: $points);

        if ($wallet > 0) {
            app(CustomerMarketBalances::class)->credit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: $market, amount: $wallet);
        }
        if ($points > 0) {
            app(CustomerMarketBalances::class)->credit(kind: MarketBalanceKind::Loyalty, customerId: self::CUSTOMER, market: $market, amount: $points);
        }
    }

    /**
     * One movement, written the way the balance writers write it: the ledger row inside the same transaction that moves the `users`
     * column, with the market the attribution put on it.
     */
    private function recordMovementAsAWriterWould(string $ledger, ?string $market, float $credit = 0, float $debit = 0, float $adminBonus = 0): void
    {
        DB::transaction(function () use ($ledger, $market, $credit, $debit, $adminBonus): void {
            $column = $ledger === 'wallet_transactions' ? 'wallet_balance' : 'loyalty_point';
            $held = (float) DB::table('users')->where('id', self::CUSTOMER)->value($column);
            DB::table('users')->where('id', self::CUSTOMER)->update([$column => $held + $credit + $adminBonus - $debit]);

            $row = $ledger === 'wallet_transactions' ? new WalletTransaction() : new LoyaltyPointTransaction();
            $row->forceFill([
                'user_id' => self::CUSTOMER,
                'transaction_id' => (string) Str::uuid(),
                'credit' => $credit,
                'debit' => $debit,
                'balance' => $held + $credit - $debit,
                'transaction_type' => 'test',
                MarketBalanceKind::MARKET_COLUMN => $market,
            ] + ($ledger === 'wallet_transactions' ? ['admin_bonus' => $adminBonus] : []))->save();
        });
    }

    /**
     * E-03: points of one market cannot become the wallet of another.
     *
     * A conversion is two movements in one transaction -- a loyalty debit and a wallet credit -- and both name the market of the request
     * that made it ({@see \App\Services\Loyalty\PointsToWalletConversion}). So what moves is that market's two accounts, and the other
     * five markets' accounts are not part of the operation at all.
     */
    public function test_a_conversion_of_points_into_the_wallet_moves_one_markets_two_accounts_and_no_other(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount(market: 'KW', wallet: 100.0, points: 50.0);
        app(CustomerMarketBalances::class)->credit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'SA', amount: 70.0);
        app(CustomerMarketBalances::class)->credit(kind: MarketBalanceKind::Loyalty, customerId: self::CUSTOMER, market: 'SA', amount: 20.0);

        DB::transaction(function (): void {
            $this->recordMovementAsAWriterWould('loyalty_point_transactions', 'KW', debit: 30);
            $this->recordMovementAsAWriterWould('wallet_transactions', 'KW', credit: 8);
        });

        $this->assertSame(20.0, $this->marketLoyaltyPoints(self::CUSTOMER, 'KW'), 'the points left the market they were held in');
        $this->assertSame(108.0, $this->marketWalletBalance(self::CUSTOMER, 'KW'), 'and the money arrived in the same market');
        $this->assertSame(70.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'), 'Saudi Arabia was not part of it');
        $this->assertSame(20.0, $this->marketLoyaltyPoints(self::CUSTOMER, 'SA'));
        $this->assertNull($this->marketWalletBalance(self::CUSTOMER, 'AE'), 'and no account was opened anywhere else');
    }

    /**
     * And the mirror cannot be made to move money BETWEEN markets, even by a pair of rows that name two.
     *
     * The application writes no such pair -- both rows of a conversion are written in one request, in one market -- but the mirror must not
     * invent a transfer if it ever met one: each row moves the account of the market it names and nothing else, so the points leave one
     * market and the money appears in the other without any path between the two accounts.
     */
    public function test_two_rows_naming_two_markets_each_move_their_own_account_and_nothing_crosses(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount(market: 'KW', wallet: 100.0, points: 50.0);
        app(CustomerMarketBalances::class)->credit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'AE', amount: 10.0);

        DB::transaction(function (): void {
            $this->recordMovementAsAWriterWould('loyalty_point_transactions', 'KW', debit: 30);
            $this->recordMovementAsAWriterWould('wallet_transactions', 'AE', credit: 8);
        });

        $this->assertSame(20.0, $this->marketLoyaltyPoints(self::CUSTOMER, 'KW'));
        $this->assertSame(100.0, $this->marketWalletBalance(self::CUSTOMER, 'KW'), 'the Kuwaiti wallet is where it was');
        $this->assertSame(18.0, $this->marketWalletBalance(self::CUSTOMER, 'AE'));
        $this->assertNull($this->marketLoyaltyPoints(self::CUSTOMER, 'AE'), 'and nothing opened a Emirati points account to take the Kuwaiti points');
    }

    public function test_a_credit_of_the_wallet_reaches_the_account_of_its_market(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 25);

        $this->assertSame(125.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'));
        $this->assertSame(125.0, (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'), 'and the one balance moved by the same amount');
    }

    public function test_a_debit_of_the_wallet_reaches_the_account_of_its_market(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', debit: 40);

        $this->assertSame(60.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'));
    }

    /** The add-fund bonus is part of what the `users` column moves by, so a mirror that left it out would disagree by exactly the bonus. */
    public function test_the_add_fund_bonus_moves_the_account_as_it_moves_the_balance(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 100, adminBonus: 10);

        $this->assertSame(210.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'));
        $this->assertSame(210.0, (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'));
    }

    public function test_a_movement_of_the_points_reaches_the_loyalty_account(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();

        $this->recordMovementAsAWriterWould('loyalty_point_transactions', 'SA', credit: 30);

        $this->assertSame(80.0, $this->marketLoyaltyPoints(self::CUSTOMER, 'SA'));
    }

    public function test_nothing_is_mirrored_while_the_shadow_key_is_off(): void
    {
        $this->withMarketAccountsOn();
        config(['gcc.storefronts.market_accounts.shadow_writes' => false]);
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 25);

        $this->assertSame(100.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'), 'the account was left where the backfill put it');
        $this->assertSame(125.0, (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'), 'and the write itself went through');
    }

    public function test_nothing_is_mirrored_while_the_market_accounts_are_off(): void
    {
        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.market_accounts.enabled' => false,
            'gcc.storefronts.market_accounts.shadow_writes' => true,
        ]);
        $this->runMarketAccountMigration();
        $this->seedCustomer(self::CUSTOMER, walletBalance: 100);

        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 25);

        $this->assertNull($this->marketWalletBalance(self::CUSTOMER, 'SA'), 'the shadow needs the accounts themselves to be on');
        $this->assertFalse(app(MarketShadowWrite::class)->isEnabled());
    }

    /** A row whose market nobody could tell — every row written before the attribution, and every one of a request bound to no storefront. */
    public function test_a_movement_that_names_no_market_is_mirrored_nowhere(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();

        $this->recordMovementAsAWriterWould('wallet_transactions', null, credit: 25);

        $this->assertSame(100.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'), 'it was not guessed into the default market');
        $this->assertSame(125.0, (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'));
    }

    /**
     * A mirror never opens an account: opening one inside the writer's own transaction is what deadlocks. So a movement in a market the
     * backfill has not reached is written down and the write it follows still stands.
     */
    public function test_a_market_with_no_account_is_written_down_and_breaks_nothing(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();
        Log::spy();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'AE', credit: 25);

        $this->assertNull($this->marketWalletBalance(self::CUSTOMER, 'AE'), 'no account was opened from inside the transaction');
        $this->assertSame(125.0, (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'), 'and the balance write stands');
        $this->assertSame(1, WalletTransaction::query()->count(), 'as does the ledger row');
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $event, array $context): bool => $event === 'wallet.market_shadow_refused' && $context['refusal'] === 'no_account',
        )->once();
    }

    /** A debit the account cannot cover is refused by the account and written down; the balance it mirrors is not touched by the refusal. */
    public function test_a_debit_the_account_cannot_cover_is_written_down_and_breaks_nothing(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount(wallet: 10.0);
        Log::spy();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', debit: 40);

        $this->assertSame(10.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'), 'the account kept what it held');
        $this->assertSame(-30.0, (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'), 'the write it followed is untouched, overdraft and all');
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $event, array $context): bool => $event === 'wallet.market_shadow_refused' && $context['refusal'] === 'overdraft',
        )->once();
    }

    /**
     * The comparison the shadow exists for. A second market's movement moves that market's account and the same one global balance, so the
     * two legitimately part company: the shadow writes the difference down rather than deciding which of them is wrong.
     */
    public function test_a_difference_between_the_account_and_the_balance_is_written_down(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->seedCustomer(self::CUSTOMER, walletBalance: 100);
        app(CustomerMarketBalances::class)->credit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'AE', amount: 5);
        Log::spy();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'AE', credit: 25);

        $this->assertSame(30.0, $this->marketWalletBalance(self::CUSTOMER, 'AE'), 'the Emirates account took the movement');
        $this->assertSame(125.0, (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'));
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $event, array $context): bool => $event === 'wallet.market_shadow_divergence'
                && abs((float) $context['difference'] + 95.0) < 0.000001,
        )->once();
    }

    public function test_an_agreeing_mirror_writes_nothing_down(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();
        Log::spy();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 25);

        Log::shouldNotHaveReceived('warning');
    }

    /** The mirror and the write it follows are one transaction: a rollback leaves neither. */
    public function test_a_rolled_back_write_leaves_no_mirror_behind(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();

        try {
            DB::transaction(function (): void {
                $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 25);

                throw new \RuntimeException('the write failed after the mirror');
            });
        } catch (\RuntimeException) {
            // the writer's own failure, which is what the rollback follows
        }

        $this->assertSame(100.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'), 'the mirror went back with it');
        $this->assertSame(100.0, (float) DB::table('users')->where('id', self::CUSTOMER)->value('wallet_balance'));
        $this->assertSame(0, WalletTransaction::query()->count());
    }

    /**
     * The read switch, both ways (E-07): with the keys off every market reads the one balance again, exactly as it did before the accounts
     * existed, and the accounts keep what they hold. Switching back is not a one-way door and loses nothing.
     */
    public function test_switching_a_market_back_to_the_one_balance_restores_it_and_loses_nothing(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();
        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 25);
        $balances = app(CustomerMarketBalances::class);
        $this->assertSame(125.0, $balances->amount(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'SA'), 'while it is on, the account answers');

        config(['gcc.storefronts.market_accounts.enabled' => false, 'gcc.storefronts.market_accounts.shadow_writes' => false]);

        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM'] as $market) {
            $this->assertSame(
                125.0,
                $balances->amount(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: $market),
                "with the key off {$market} reads the one balance the users row holds, as every market did before the accounts",
            );
        }
        $this->assertSame(125.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'), 'and the account still holds what it held, so turning it on again resumes');
    }

    public function test_turning_it_on_again_answers_from_the_account_it_left(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();
        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 25);
        config(['gcc.storefronts.market_accounts.enabled' => false, 'gcc.storefronts.market_accounts.shadow_writes' => false]);
        DB::table('users')->where('id', self::CUSTOMER)->update(['wallet_balance' => 999]);

        $this->withShadowOn();

        $this->assertSame(
            125.0,
            app(CustomerMarketBalances::class)->amount(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: 'SA'),
            'the account is where it was left, whatever happened to the one balance while the key was off',
        );
    }

    public function test_a_movement_of_nothing_is_mirrored_nowhere(): void
    {
        $this->withShadowOn();
        $this->runMarketAccountMigration();
        $this->aCustomerWithAnOpenAccount();
        Log::spy();

        $this->recordMovementAsAWriterWould('wallet_transactions', 'SA', credit: 0, debit: 0);

        $this->assertSame(100.0, $this->marketWalletBalance(self::CUSTOMER, 'SA'));
        Log::shouldNotHaveReceived('warning');
    }
}
