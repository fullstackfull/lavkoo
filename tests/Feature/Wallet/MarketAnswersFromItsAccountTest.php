<?php

namespace Tests\Feature\Wallet;

use App\Enums\Wallet\MarketBalanceKind;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Storefront\LedgerAttribution;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Wallet\CustomerMarketBalances;
use App\Services\Customer\CustomerWalletWriter;
use App\Services\Wallet\MarketBalanceReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\TestCase;

/**
 * E-01 and GCC-15 slice 3: one market at a time answers from its own account, and every other market answers what it always has.
 *
 * The accounts exist and every movement is mirrored into them (E-07), but until now nothing read them: a customer with a hundred dinars in
 * Kuwait City and nothing in Riyadh was shown one number that was neither. The switch here is a **list of markets**, empty as it ships, and
 * what it moves is the reading: the balance a customer is shown, and the balance a debit is judged against. What is written does not move --
 * the one `users` column still takes every movement and still mirrors it into the market's account.
 *
 * The three things that have to be true, and are each measured below:
 *
 *  - **empty list, nothing changes.** Every market reads the `users` row even when the accounts hold something else entirely, so the
 *    platform of today is the platform of today whatever the two switches under it are set to;
 *  - **a listed market answers its own account**, and a market that is not listed still answers the row -- the two live side by side,
 *    which is what "one market at a time" means;
 *  - **a reader that is serving no market reads the row.** A queue worker, a panel and a console command see every market at once or
 *    none, and the one global balance is the only answer that is not a claim about a market they did not ask about.
 */
class MarketAnswersFromItsAccountTest extends TestCase
{
    use BuildsMarketAccountSchema;

    private const CUSTOMER = 1;

    /** What the one `users` row holds, which is the Saudi money of a platform that has never had a second market. */
    private const IN_THE_USERS_ROW = 250.5;

    private const POINTS_IN_THE_USERS_ROW = 80;

    private MarketBalanceReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarketAccountSchema();
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        $this->seedCustomer(self::CUSTOMER, walletBalance: self::IN_THE_USERS_ROW, loyaltyPoints: self::POINTS_IN_THE_USERS_ROW);
        // The one writer refuses every movement while the wallet is switched off, which the market account world does not set either way.
        DB::table('business_settings')->insert(['type' => 'wallet_status', 'value' => '1']);
        clearWebConfigCacheKeys();
        // The ledger of that world is the one the mirror reads; the writer also records how a movement was paid for.
        Schema::table(MarketBalanceKind::Wallet->ledgerTable(), static function (Blueprint $table): void {
            $table->string('payment_method', 191)->nullable();
        });
        $this->reader = app(MarketBalanceReader::class);
    }

    private function customer(): User
    {
        return User::query()->findOrFail(self::CUSTOMER);
    }

    /** Gives the market its own money, as the backfill and the mirror between them do. */
    private function theAccountOf(string $market, float $wallet = 0, float $points = 0): void
    {
        $accounts = app(CustomerMarketBalances::class);

        if ($wallet !== 0.0) {
            $accounts->credit(kind: MarketBalanceKind::Wallet, customerId: self::CUSTOMER, market: $market, amount: $wallet);
        }

        if ($points !== 0.0) {
            $accounts->credit(kind: MarketBalanceKind::Loyalty, customerId: self::CUSTOMER, market: $market, amount: $points);
        }
    }

    private function marketsAnsweringFromTheirAccount(string ...$markets): void
    {
        config(['gcc.storefronts.market_accounts.answers_for' => $markets]);
    }

    /** Reads as a request that market is serving, which is how every reader of a balance is reached. */
    private function servedBy(string $market, callable $read): mixed
    {
        return StorefrontResolver::fromConfig()->context(explicitChoice: $market)->serve($read);
    }

    // ---- empty list: the platform of today ----------------------------------------------------------------------------------

    public function test_with_no_market_listed_every_market_reads_the_one_balance_the_customers_row_holds(): void
    {
        $this->theAccountOf('KW', wallet: 12.345, points: 7);
        $this->theAccountOf('SA', wallet: 999.0, points: 999);

        foreach (['SA', 'KW', 'AE'] as $market) {
            $this->assertSame(self::IN_THE_USERS_ROW, (float) $this->servedBy($market, fn (): float|int => $this->reader->wallet($this->customer())));
            $this->assertSame(self::POINTS_IN_THE_USERS_ROW, $this->servedBy($market, fn (): int => $this->reader->points($this->customer())));
        }

        $this->assertSame([], $this->reader->marketsAnsweringFromTheirAccount());
        $this->assertFalse($this->reader->answersFromItsAccount('KW'));
    }

    // ---- one market at a time ------------------------------------------------------------------------------------------------

    public function test_a_listed_market_answers_its_own_account_while_every_other_market_still_answers_the_row(): void
    {
        $this->theAccountOf('KW', wallet: 12.345, points: 7);
        $this->marketsAnsweringFromTheirAccount('KW');

        $this->assertSame(12.345, (float) $this->servedBy('KW', fn (): float|int => $this->reader->wallet($this->customer())));
        $this->assertSame(7, $this->servedBy('KW', fn (): int => $this->reader->points($this->customer())));

        $this->assertSame(self::IN_THE_USERS_ROW, (float) $this->servedBy('SA', fn (): float|int => $this->reader->wallet($this->customer())));
        $this->assertSame(self::POINTS_IN_THE_USERS_ROW, $this->servedBy('AE', fn (): int => $this->reader->points($this->customer())));
    }

    /** A market switched over before its accounts were filled reads as a customer with nothing, which is why the list waits for the backfill. */
    public function test_a_listed_market_with_no_account_yet_answers_nothing(): void
    {
        $this->marketsAnsweringFromTheirAccount('KW');

        $this->assertSame(0.0, (float) $this->servedBy('KW', fn (): float|int => $this->reader->wallet($this->customer())));
        $this->assertSame(0, $this->servedBy('KW', fn (): int => $this->reader->points($this->customer())));
    }

    public function test_the_list_is_read_whatever_case_and_spacing_it_is_written_in(): void
    {
        $this->theAccountOf('KW', wallet: 12.345);
        config(['gcc.storefronts.market_accounts.answers_for' => [' kw ', '', 'KW']]);

        $this->assertSame(['KW'], $this->reader->marketsAnsweringFromTheirAccount());
        $this->assertSame(12.345, (float) $this->servedBy('KW', fn (): float|int => $this->reader->wallet($this->customer())));
    }

    // ---- the list alone does nothing -----------------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function switchesThatHoldTheWholeFeature(): array
    {
        return [
            'the market accounts key' => ['gcc.storefronts.market_accounts.enabled'],
            'the storefronts switch itself' => ['gcc.storefronts.enabled'],
        ];
    }

    #[DataProvider('switchesThatHoldTheWholeFeature')]
    public function test_a_listed_market_answers_the_row_while_a_switch_above_it_is_off(string $switch): void
    {
        $this->theAccountOf('KW', wallet: 12.345);
        $this->marketsAnsweringFromTheirAccount('KW');
        config([$switch => false]);

        $this->assertFalse($this->reader->answersFromItsAccount('KW'));
        $this->assertSame(self::IN_THE_USERS_ROW, (float) $this->reader->wallet($this->customer(), 'KW'));
    }

    // ---- a reader serving no market ------------------------------------------------------------------------------------------

    public function test_a_reader_that_is_serving_no_market_reads_the_one_balance(): void
    {
        $this->theAccountOf('KW', wallet: 12.345);
        $this->marketsAnsweringFromTheirAccount('KW');

        $this->assertFalse($this->reader->answersFromItsAccount(), 'nothing is being served in a console command, a queue or a panel');
        $this->assertSame(self::IN_THE_USERS_ROW, (float) $this->reader->wallet($this->customer()));
        $this->assertSame(self::POINTS_IN_THE_USERS_ROW, $this->reader->points($this->customer()));
    }

    // ---- the statement behind the balance ------------------------------------------------------------------------------------

    /** The rows of each market, plus the rows written before any market existed, which carry none. */
    private function threeKindsOfMovement(): void
    {
        $this->recordMovement(MarketBalanceKind::Wallet->ledgerTable(), customerId: self::CUSTOMER, market: 'KW', credit: 12.345);
        $this->recordMovement(MarketBalanceKind::Wallet->ledgerTable(), customerId: self::CUSTOMER, market: 'SA', credit: 40);
        $this->recordMovement(MarketBalanceKind::Wallet->ledgerTable(), customerId: self::CUSTOMER, market: null, credit: 7);
    }

    /** @return list<string> the markets of the rows a statement shows, as the four statement queries read them */
    private function statementUnder(string $market): array
    {
        return $this->servedBy($market, fn (): array => $this->reader
            ->onlyTheMarketsRows(WalletTransaction::where('user_id', self::CUSTOMER), MarketBalanceKind::Wallet)
            ->pluck(MarketBalanceKind::MARKET_COLUMN)
            ->map(static fn (mixed $market): string => (string) $market)
            ->all());
    }

    public function test_a_statement_under_a_switched_over_market_shows_that_markets_rows_and_no_others(): void
    {
        $this->threeKindsOfMovement();
        $this->marketsAnsweringFromTheirAccount('KW');

        $this->assertSame(['KW'], $this->statementUnder('KW'), 'a market reading its own account is shown its own movements');
    }

    /** A market that has not been switched over is shown every movement, which is the statement every customer sees today. */
    public function test_a_statement_under_a_market_that_reads_the_one_balance_shows_every_row(): void
    {
        $this->threeKindsOfMovement();
        $this->marketsAnsweringFromTheirAccount('KW');

        $this->assertSame(['KW', 'SA', ''], $this->statementUnder('SA'), 'including the rows written before any market existed');
    }

    public function test_with_no_market_listed_every_statement_shows_every_row(): void
    {
        $this->threeKindsOfMovement();

        foreach (['SA', 'KW'] as $market) {
            $this->assertSame(['KW', 'SA', ''], $this->statementUnder($market));
        }
    }

    /** A ledger whose attribution migration has not run records no market, so nothing is narrowed and no query can fail on a column that is not there. */
    public function test_a_ledger_that_records_no_market_narrows_nothing(): void
    {
        $this->threeKindsOfMovement();
        $this->marketsAnsweringFromTheirAccount('KW');
        Schema::table(MarketBalanceKind::Wallet->ledgerTable(), static function (Blueprint $table): void {
            $table->dropColumn(MarketBalanceKind::MARKET_COLUMN);
        });
        app(LedgerAttribution::class)->flush();

        $shown = $this->servedBy('KW', fn (): int => $this->reader
            ->onlyTheMarketsRows(WalletTransaction::where('user_id', self::CUSTOMER), MarketBalanceKind::Wallet)
            ->count());

        $this->assertSame(3, $shown, 'every row, because there is no market on a row to narrow by');
    }

    // ---- what a debit is judged against --------------------------------------------------------------------------------------

    /**
     * The refusal E-01 asks for: money in Riyadh is not money in Kuwait City.
     *
     * The write is not switched over -- `CustomerWalletWriter` still writes the one column and the mirror still copies it into the market's
     * account -- so what this measures is the question asked before the write: *does this customer have it to spend HERE?*
     */
    public function test_a_wallet_payment_in_a_switched_over_market_is_refused_when_only_another_markets_wallet_holds_money(): void
    {
        $this->theAccountOf('KW', wallet: 5.0);
        $this->marketsAnsweringFromTheirAccount('KW');

        $refused = $this->servedBy('KW', fn (): bool => CustomerWalletWriter::record(
            customerId: self::CUSTOMER,
            amount: 100.0,
            transactionType: 'order_place',
        ) === false);

        $this->assertTrue($refused, 'the customer holds 5 in Kuwait and 250.5 in the one column, and 100 is more than Kuwait has');
        $this->assertSame(self::IN_THE_USERS_ROW, (float) $this->customer()->wallet_balance, 'and nothing was taken from the one column');
    }

    public function test_the_same_payment_is_taken_in_a_market_that_has_not_been_switched_over(): void
    {
        $this->theAccountOf('KW', wallet: 5.0);
        $this->marketsAnsweringFromTheirAccount('KW');

        $taken = $this->servedBy('SA', fn (): bool => CustomerWalletWriter::record(
            customerId: self::CUSTOMER,
            amount: 100.0,
            transactionType: 'order_place',
        ) !== false);

        $this->assertTrue($taken, 'the Saudi market still reads the one column, which holds 250.5');
        $this->assertSame(self::IN_THE_USERS_ROW - 100.0, (float) $this->customer()->wallet_balance);
    }

    public function test_a_payment_in_a_switched_over_market_that_has_the_money_is_taken(): void
    {
        $this->theAccountOf('KW', wallet: 500.0);
        $this->marketsAnsweringFromTheirAccount('KW');

        $taken = $this->servedBy('KW', fn (): bool => CustomerWalletWriter::record(
            customerId: self::CUSTOMER,
            amount: 100.0,
            transactionType: 'order_place',
        ) !== false);

        $this->assertTrue($taken);
        $this->assertSame(self::IN_THE_USERS_ROW - 100.0, (float) $this->customer()->wallet_balance, 'the write itself is unchanged: the one column still takes it');
    }
}
