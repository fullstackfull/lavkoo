<?php

namespace Tests\Feature\Loyalty;

use App\Contracts\Repositories\LoyaltyPointTransactionRepositoryInterface;
use App\Enums\Wallet\MarketBalanceKind;
use App\Models\User;
use App\Services\Storefront\LedgerOrigin;
use App\Services\Storefront\StorefrontResolver;
use App\Services\Wallet\CustomerMarketBalances;
use App\Services\Wallet\MarketBalanceReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsMarketAccountSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * E-02, end to end: points earned on a Kuwaiti order leave the Saudi points balance where it was.
 *
 * Every piece of this was built and tested apart -- the accounts and their isolation (MarketAccountIsolationTest), the mirror that moves
 * the account of the market a ledger row names (MarketShadowWriteTest), the reader that answers a switched-over market from its own
 * account (MarketAnswersFromItsAccountTest), and the rate the order's own market pays (LoyaltyEarnedInTheOrdersMarketTest). What none of
 * them says is the criterion itself, which is a sentence about TWO markets at once and about a real earn rather than a hand-built row: a
 * customer earns in Kuwait and what Riyadh shows them does not move.
 *
 * So this drives the earn through the writer the admin and vendor order pages use, with the origin of a Kuwaiti order, and reads both
 * markets back through the reader every customer-facing balance goes through.
 *
 * **It takes three switches, and the first test is what happens without them.** With `answers_for` empty -- which is how the platform
 * ships -- a Kuwaiti earn DOES move what Riyadh shows, because there is one column and both markets read it. That is the defect the
 * criterion names, and it is measured here rather than described, so the figures on both sides of the switch are in one place.
 *
 * The one `users` column still takes every movement in both positions: it stays the authority for every market that has not been switched
 * over, which is what makes the change reversible (MarketShadowWriteTest::test_switching_a_market_back_to_the_one_balance_restores_it).
 *
 * **The criterion is NOT whole, and the last test is why.** It holds for a customer who HAS an account in the market they earned in. A
 * customer who has never traded there has none, and the mirror may not open one from inside the writer's transaction, so their first earn
 * in a switched-over market shows as nothing in that market. That is an opener run before switch-over, named in that test, and it is not a
 * decision to take from a test file.
 */
class PointsEarnedInOneMarketLeaveAnothersAloneTest extends TestCase
{
    use BuildsMarketAccountSchema;
    use RestoresLanguageFiles;

    private const CUSTOMER = 1;

    /** What the one row holds, which is the Saudi points of a platform that has never had a second market. */
    private const POINTS_IN_THE_USERS_ROW = 80.0;

    /** The platform pays 10% of the order, and Kuwait is given no programme of its own so that this is about the BALANCE and not the rate. */
    private const ORDER_AMOUNT = 100.0;

    private const EARNED = 10.0;

    /** What Kuwait's own account already holds, as the backfill carried it for a customer who has traded there. */
    private const POINTS_IN_THE_KUWAITI_ACCOUNT = 5.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildMarketAccountSchema();
        $this->runMarketAccountMigration();
        $this->withMarketAccountsOn();
        config(['gcc.storefronts.market_accounts.shadow_writes' => true]);
        $this->buildTheOrdersAMarketIsRecordedOn();

        DB::table('business_settings')->insert([
            ['type' => 'loyalty_point_status', 'value' => '1'],
            ['type' => 'loyalty_point_item_purchase_point', 'value' => '10'],
            ['type' => 'loyalty_point_exchange_rate', 'value' => '100'],
        ]);
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        clearWebConfigCacheKeys();

        // The customer of a one-market platform: points on the row, and the Saudi account the backfill opened from it.
        $this->seedCustomer(self::CUSTOMER, loyaltyPoints: self::POINTS_IN_THE_USERS_ROW);
        app(CustomerMarketBalances::class)->credit(
            kind: MarketBalanceKind::Loyalty,
            customerId: self::CUSTOMER,
            market: 'SA',
            amount: self::POINTS_IN_THE_USERS_ROW,
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** Only what the attribution reads: the row's id and the market the sale was made in. */
    private function buildTheOrdersAMarketIsRecordedOn(): void
    {
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('order_amount', 18, 4)->default(0);
            $table->char('storefront_country', 2)->nullable();
            $table->timestamps();
        });
    }

    // ---- the platform as it ships: one column, and both markets read it --------------------------------------------------

    /**
     * No market switched over. A Kuwaiti earn moves what Riyadh shows, by exactly what was earned -- which is the criterion failing, and
     * the figure is written down so that the other side of the switch can be compared with it rather than merely asserted.
     */
    public function test_with_no_market_switched_over_a_kuwaiti_earn_moves_what_riyadh_shows(): void
    {
        $this->assertSame([], (array) config('gcc.storefronts.market_accounts.answers_for'), 'the list ships empty');

        $this->earnOnAnOrderSoldIn('KW');

        $this->assertSame(self::POINTS_IN_THE_USERS_ROW + self::EARNED, $this->pointsShownIn('SA'), 'one column, read by both markets');
        $this->assertSame(self::POINTS_IN_THE_USERS_ROW + self::EARNED, $this->pointsShownIn('KW'));
    }

    // ---- both markets switched over: the criterion ----------------------------------------------------------------------

    public function test_points_earned_on_a_kuwaiti_order_leave_the_saudi_balance_unchanged(): void
    {
        $this->kuwaitHasAnAccount();
        $this->bothMarketsAnswerFromTheirAccounts();

        $this->earnOnAnOrderSoldIn('KW');

        $this->assertSame(self::POINTS_IN_THE_USERS_ROW, $this->pointsShownIn('SA'), 'Riyadh shows what it showed before the Kuwaiti sale');
        $this->assertSame(self::POINTS_IN_THE_KUWAITI_ACCOUNT + self::EARNED, $this->pointsShownIn('KW'), 'and Kuwait shows its own, grown by what was earned there');
    }

    /** The other direction, because "one market does not move the other" is two statements and a single direction could pass by accident. */
    public function test_points_earned_on_a_saudi_order_leave_the_kuwaiti_balance_unchanged(): void
    {
        $this->kuwaitHasAnAccount();
        $this->bothMarketsAnswerFromTheirAccounts();

        $this->earnOnAnOrderSoldIn('SA');

        $this->assertSame(self::POINTS_IN_THE_USERS_ROW + self::EARNED, $this->pointsShownIn('SA'));
        $this->assertSame(self::POINTS_IN_THE_KUWAITI_ACCOUNT, $this->pointsShownIn('KW'), 'Kuwait keeps its own, and a Saudi sale adds nothing to it');
    }

    /** The one column still takes every movement, in both positions, because it is the authority for every market not switched over. */
    public function test_the_one_column_still_takes_the_movement_whichever_market_earned_it(): void
    {
        $this->bothMarketsAnswerFromTheirAccounts();

        $this->earnOnAnOrderSoldIn('KW');

        $this->assertSame(
            self::POINTS_IN_THE_USERS_ROW + self::EARNED,
            (float) DB::table('users')->where('id', self::CUSTOMER)->value('loyalty_point'),
            'the row is unchanged in what it does, which is what makes switching a market back lose nothing',
        );
    }

    /** One market at a time is the whole design: Kuwait reading its own account does not stop Riyadh reading the row. */
    public function test_with_only_kuwait_switched_over_riyadh_still_reads_the_one_column(): void
    {
        $this->kuwaitHasAnAccount();
        config(['gcc.storefronts.market_accounts.answers_for' => ['KW']]);

        $this->earnOnAnOrderSoldIn('KW');

        $this->assertSame(self::POINTS_IN_THE_KUWAITI_ACCOUNT + self::EARNED, $this->pointsShownIn('KW'), 'Kuwait answers its own account');
        $this->assertSame(self::POINTS_IN_THE_USERS_ROW + self::EARNED, $this->pointsShownIn('SA'), 'and Riyadh answers the row, which took the movement');
    }

    /** An order that records no market is attributed to nothing, so no account moves and both markets read the row that did. */
    public function test_an_order_that_records_no_market_moves_no_account(): void
    {
        $this->kuwaitHasAnAccount();
        $this->bothMarketsAnswerFromTheirAccounts();

        $this->earnOnAnOrderSoldIn(null);

        $this->assertSame(self::POINTS_IN_THE_USERS_ROW, $this->pointsShownIn('SA'), 'the Saudi account was not moved by a sale of no market');
        $this->assertSame(self::POINTS_IN_THE_KUWAITI_ACCOUNT, $this->pointsShownIn('KW'));
        $this->assertSame(
            self::POINTS_IN_THE_USERS_ROW + self::EARNED,
            (float) DB::table('users')->where('id', self::CUSTOMER)->value('loyalty_point'),
            'and the row took it, as it takes everything',
        );
    }

    /**
     * **THE FINDING, and the reason this criterion is not whole.** A customer who has never traded in Kuwait has no Kuwaiti account, and
     * their first Kuwaiti earn is not mirrored into one: the shadow write moves an account that is already open and never opens one,
     * because opening it from inside the writer's own transaction is the one thing `MarketAccountRepository::open` forbids -- both of
     * InnoDB's orders deadlock ({@see \App\Services\Wallet\CustomerMarketBalances::moveOpenAccount}).
     *
     * So with Kuwait switched over, that first earn shows as NOTHING in Kuwait while the one column holds it, and the only trace of the
     * mirror refusing is the `wallet.market_shadow_refused` line it writes. No money is lost -- the column is still the authority, and
     * switching Kuwait back shows the whole balance again -- but the market's own answer is wrong until an account exists.
     *
     * Closing it is an OPENER run before a market is switched over, outside any writer's transaction: `wallet:backfill-market-accounts`
     * opens an account only where there is a balance to carry, so a customer with nothing in Kuwait gets none. Opening one for every
     * customer in every market is a write across the whole customer table and is not a decision to take from here.
     */
    public function test_a_market_whose_account_was_never_opened_shows_nothing_of_what_it_earned(): void
    {
        $this->bothMarketsAnswerFromTheirAccounts();

        $this->earnOnAnOrderSoldIn('KW');

        $this->assertFalse(
            app(CustomerMarketBalances::class)->hasAccount(kind: MarketBalanceKind::Loyalty, customerId: self::CUSTOMER, market: 'KW'),
            'the mirror did not open an account, which is what it is forbidden to do',
        );
        $this->assertSame(0.0, $this->pointsShownIn('KW'), 'so Kuwait shows nothing of what was earned in Kuwait');
        $this->assertSame(self::POINTS_IN_THE_USERS_ROW, $this->pointsShownIn('SA'), 'and Riyadh is still right');
        $this->assertSame(
            self::POINTS_IN_THE_USERS_ROW + self::EARNED,
            (float) DB::table('users')->where('id', self::CUSTOMER)->value('loyalty_point'),
            'the points are not lost: the one column holds them, and switching Kuwait back shows them again',
        );
    }

    private function bothMarketsAnswerFromTheirAccounts(): void
    {
        config(['gcc.storefronts.market_accounts.answers_for' => ['SA', 'KW']]);
    }

    /** Opens Kuwait's account the way the backfill opens one, which is the prerequisite of switching a market over. */
    private function kuwaitHasAnAccount(): void
    {
        app(CustomerMarketBalances::class)->credit(
            kind: MarketBalanceKind::Loyalty,
            customerId: self::CUSTOMER,
            market: 'KW',
            amount: self::POINTS_IN_THE_KUWAITI_ACCOUNT,
        );
    }

    /** The earn the admin and vendor order pages make, on an order sold in `$market`. */
    private function earnOnAnOrderSoldIn(?string $market): void
    {
        $orderId = (int) DB::table('orders')->insertGetId([
            'customer_id' => self::CUSTOMER,
            'order_amount' => self::ORDER_AMOUNT,
            'storefront_country' => $market,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $earned = app(LoyaltyPointTransactionRepositoryInterface::class)->addLoyaltyPointTransaction(
            userId: self::CUSTOMER,
            reference: (string) $orderId,
            amount: self::ORDER_AMOUNT,
            transactionType: 'order_place',
            origin: LedgerOrigin::order(id: $orderId),
        );

        $this->assertTrue((bool) $earned, 'the writer really credited the points');
    }

    /** What a customer is shown in one market, read the way every customer-facing balance reads it. */
    private function pointsShownIn(string $market): float
    {
        return (float) StorefrontResolver::fromConfig()->context(explicitChoice: $market)->serve(
            fn (): float|int|null => app(MarketBalanceReader::class)->points(User::query()->findOrFail(self::CUSTOMER)),
        );
    }
}
