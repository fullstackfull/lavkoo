<?php

namespace Tests\Feature\Storefront;

use App\Events\RefundEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsTheRefundPayoutWorld;

/**
 * D1-10, criteria 3 and 6: what a refund hands the buyer back after the rates or the money settings have moved.
 *
 * Nothing here is a stub. A customer fills a cart, pays through the real gateway payment route (which converts the order's stored amount into the
 * currency the gateway takes, at the rate of that day, and writes the payment record), the gateway's own success hook builds the order, the customer
 * asks for a refund through the route the app calls, and an administrator marks it refunded through the screen's own route. The rate is then moved the
 * way an operator moves it, on the currency rows, between the sale and the refund.
 *
 * What the application did before this change, measured through exactly these routes: an order stored as 100 was paid for with 375.00 SAR at a rate of
 * 3.75, and the refund credited the buyer's wallet with 100 — which at the rate of the day of the refund, 3.00, is 300.00 SAR. The buyer paid 375 and
 * got 300 back. That is what `usdToDefaultCurrency` of a stored amount means on a historical sale, and the first test here pins it as the behaviour the
 * switch leaves untouched.
 */
class RefundGivesBackTheOriginalPaymentTest extends ApiAddressTestCase
{
    use BuildsTheRefundPayoutWorld;

    /** The rate the sale was made at, and the rate the refund is paid out at. */
    private const RATE_AT_THE_SALE = 3.75;

    private const RATE_AT_THE_REFUND = 3.00;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-05 09:00:00');
        Event::fake([RefundEvent::class]);
        $this->buildTheRefundPayoutWorld();
        $this->turnCompanyAccountsOn();
    }

    protected function tearDown(): void
    {
        $this->dropTheRefundPayoutWorld();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- the sale, the rate change and the refund ------------------------------------------------------------------------------------------

    /**
     * A paid order of the platform's own storefront, refunded after the rate has moved.
     *
     * @return array{0: User, 1: int, 2: list<int>, 3: list<int>} who bought, the order, its lines and the refund requests they are waiting on
     */
    private function sellPayAndAskForRefund(array $productIds, string $transaction, string $gateway = self::GATEWAY, ?string $currentCurrency = null): array
    {
        [$customer, $orderId, $lines] = $this->payFrom('SA', $productIds, $transaction, $gateway, $currentCurrency);

        foreach ($lines as $line) {
            $this->askForRefund($customer, 'SA', $line);
        }

        $refunds = DB::table('refund_requests')->where('order_id', $orderId)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return [$customer, $orderId, $lines, $refunds];
    }

    /** A second SA product, so that one order can be refunded in more than one part through the real route. */
    private function addSaudiProducts(array $prices): array
    {
        $template = ['added_by' => 'admin', 'user_id' => 1, 'code' => 'SKU-1', 'variation' => '[]', 'images' => '[]', 'thumbnail' => 'x.png', 'category_ids' => '[]', 'discount' => 0, 'discount_type' => 'flat', 'choice_options' => '[]', 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude', 'current_stock' => 10, 'storefront_country' => 'SA'];
        $ids = [];

        foreach ($prices as $index => $price) {
            $id = 20 + $index;
            DB::table('products')->insert($template + ['id' => $id, 'name' => "Saudi product {$id}", 'slug' => "saudi-product-{$id}", 'unit_price' => $price]);
            $ids[] = $id;
        }

        $this->forgetWhatWasRemembered();

        return $ids;
    }

    // ---- the switch off: exactly what the flow did before -----------------------------------------------------------------------------------

    /**
     * The behaviour this change leaves alone, measured rather than described: a 375.00 SAR payment refunded at a rate of 3.00 gives the buyer 100 in
     * the amounts balances are kept in, which is 300.00 SAR of what they can spend. 75.00 SAR of their own money does not come back.
     */
    public function test_with_the_switch_off_a_refund_gives_back_the_conversion_of_today_and_not_what_was_paid(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-OFF-1');
        $this->assertSame(['375.00', 'SAR'], $this->whatWasPaid('TXN-OFF-1'), 'the gateway took the order\'s amount converted at the rate of the day of the sale');

        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertCount(1, $credits);
        $this->assertEqualsWithDelta(100, (float) $credits[0]['credit'], 0.000001, 'the stored amount, as it always was');
        $this->assertEqualsWithDelta(300.0, (float) $credits[0]['credit'] * self::RATE_AT_THE_REFUND, 0.000001, 'which is 300.00 SAR of a 375.00 SAR payment');

        $transaction = DB::table('refund_transactions')->first();
        $this->assertSame([null, null], [$transaction->original_payment_amount, $transaction->original_payment_currency], 'while the switch is off nothing of the original payment is recorded');
    }

    /**
     * What the whole money path did on the base of this branch (014db1a8), measured through exactly these routes in a SEPARATE CHECKOUT of that
     * commit, with its own composer autoloader, on a frozen clock, before a line of this change ran in it. The two captures were byte for byte the
     * same once the clock and the one identifier the application generates at random (a wallet row's uuid) were masked:
     *
     *   SQLite   base 92f74753ac1ef401d553aa2ce806c4de7c21984ebbd60ef655f8f8089000c702 == head 92f74753...
     *   MariaDB  base 35ce420d67db40194d006015de368485f33bb94a40d947a5d491a92382e009b9 == head 35ce420d...
     *
     * Those captures cover the statements of both routes with their values, the wallet rows and the refund transaction row. What is kept here, so
     * that the proof survives as a test rather than as a measurement, is the shape of them: how many statements each route issues that touch the data,
     * and the digest of those statements in order. Read-only questions about the schema are left out of the digest and counted separately, because
     * those are the only statements this change may add while it is off -- and while it is off it adds none.
     *
     * Per engine, because MariaDB qualifies the column of an update with its table where SQLite does not; both were measured on the base.
     *
     * @var array<string, array{ask: int, ask_digest: string, pay: int, pay_digest: string}>
     */
    private const BASE = [
        'sqlite' => [
            'ask' => 21,
            'ask_digest' => '7952ca17c1dc0de60c41b75a4239c982efdada3e444e5d23b1c3fc1242146f26',
            'pay' => 30,
            'pay_digest' => '896195f05bc4233749f1b01739a419b5e6e506d8b8c7aeed51ffa45abc896afe',
        ],
        'mysql' => [
            'ask' => 21,
            'ask_digest' => '23e0b0857cb449e6df10e8fd572137d147b18002675b73f94cfc4b0dff61f09b',
            'pay' => 30,
            'pay_digest' => '35633164c8dee1d90a6c34b629e200b033204d2a636b5a5f245f0bc334e3d9ff',
        ],
    ];

    /** The statement with the quoting of its identifiers taken off, so the same statement reads the same on SQLite and on MySQL. */
    private static function plain(string $sql): string
    {
        return str_replace(['"', '`'], '', $sql);
    }

    /** A read-only question to the database about its own schema, which is the only kind of statement this change could add while it is off. */
    private static function isASchemaQuestion(string $sql): bool
    {
        return preg_match('/sqlite_master|pragma_|information_schema|^\s*show (columns|tables|full columns)/i', $sql) === 1;
    }

    /** @param list<string> $statements @return list<string> what a route does to the data, with the schema questions left out */
    private function workAmong(array $statements): array
    {
        return array_values(array_map(self::plain(...), array_filter($statements, static fn (string $sql): bool => !self::isASchemaQuestion($sql))));
    }

    /** @return array{work: list<string>, digest: string} */
    private function whatTheRouteDid(callable $route): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $route();
        $work = $this->workAmong($statements);

        return ['work' => $work, 'digest' => hash('sha256', implode("\n", $work))];
    }

    /** @return array{ask: int, ask_digest: string, pay: int, pay_digest: string} what the base did on the engine this run is against */
    private function whatTheBaseDid(): array
    {
        return self::BASE[DB::connection()->getDriverName() === 'sqlite' ? 'sqlite' : 'mysql'];
    }

    /**
     * The dormant path, statement by statement: with the switch off, both routes of the money path do exactly the work the base did, in the same
     * order, and nothing else.
     */
    public function test_with_the_switch_off_both_routes_do_exactly_the_work_the_base_did(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);

        [$customer, , $lines] = $this->payFrom('SA', [1], 'TXN-OFF-3');
        $asked = $this->whatTheRouteDid(fn () => $this->askForRefund($customer, 'SA', $lines[0]));

        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $refund = (int) DB::table('refund_requests')->value('id');
        $paid = $this->whatTheRouteDid(fn () => $this->refundItThroughTheAdminScreen($refund)->assertOk());

        $base = $this->whatTheBaseDid();
        $this->assertSame($base['ask'], count($asked['work']), 'asking for a refund does the work the base did, and no more');
        $this->assertSame($base['ask_digest'], $asked['digest'], 'every statement of the refund request that touches the data, in order, is the one the base issued');
        $this->assertSame($base['pay'], count($paid['work']), 'paying the refund out does the work the base did, and no more');
        $this->assertSame($base['pay_digest'], $paid['digest'], 'every statement of the payout that touches the data, in order, is the one the base issued');
    }

    /** While the switch is off the refund flow never asks the payments table what was paid. */
    public function test_with_the_switch_off_the_refund_flow_never_asks_what_was_paid(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);

        [, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-OFF-2');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $askedWhatWasPaid = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'payment_requests') && str_contains($sql, 'payment_amount')));
        $this->assertSame([], $askedWhatWasPaid, 'while the payout switch is off the flow never asks what the gateway took');
        $this->assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'refund_requests') && str_contains($sql, 'sum('))), 'and never sums what the earlier refunds of the order gave back');
    }

    // ---- the switch on: exactly what was paid ----------------------------------------------------------------------------------------------

    /** Criterion 6, in two decimals: the buyer paid 375.00 SAR and gets 375.00 SAR back, at whatever rate the refund is paid out. */
    public function test_a_refund_after_the_rate_moved_gives_back_exactly_what_the_buyer_paid(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ON-1');
        $this->assertSame(['375.00', 'SAR'], $this->whatWasPaid('TXN-ON-1'));

        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertCount(1, $credits);
        $this->assertEqualsWithDelta(375.0, (float) $credits[0]['credit'] * self::RATE_AT_THE_REFUND, 0.000001, 'the buyer gets back exactly the 375.00 SAR they paid');
        $this->assertEqualsWithDelta(125, (float) $credits[0]['credit'], 0.000001, 'which is 125 in the amounts balances are kept in, not the 100 the stored amount converts to today');
    }

    /** Criterion 6, for a change of settings rather than of rate: the platform's decimals and its default currency both move after the sale. */
    public function test_a_refund_after_the_money_settings_changed_gives_back_exactly_what_the_buyer_paid(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ON-2');

        // The shop is reconfigured after the sale: another default currency, other decimals, another rate.
        $this->theShopConvertsAtTheRateOfTheDay('AED', decimals: 3);
        $this->theRateOfBecomes('AED', 2.5);
        $this->theRateOfBecomes('SAR', 9.99);

        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $transaction = DB::table('refund_transactions')->first();
        $this->assertSame(['375.00', 'SAR'], [self::money($transaction->original_payment_amount, 2), $transaction->original_payment_currency], 'what is owed is what was paid, whatever the settings say now');

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(100, (float) $credits[0]['credit'], 0.000001, 'the wallet is in the platform\'s money, which is now the dirham: a riyal payment cannot be handed back through it, so the figure the flow always used stands and the amount owed is recorded instead');
    }

    /** Criterion 6, in three decimals: a dinar payment comes back in thirds that add up to the dinar exactly. */
    public function test_a_three_decimal_payment_refunded_in_parts_adds_up_to_exactly_what_was_paid(): void
    {
        $products = $this->addSaudiProducts([1, 1, 1]);
        $this->theShopConvertsAtTheRateOfTheDay('KWD', decimals: 3);
        $this->theRateOfBecomes('KWD', 10 / 3);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [, $orderId, , $refunds] = $this->sellPayAndAskForRefund($products, 'TXN-KWD-1', gateway: 'hyper_pay', currentCurrency: 'KWD');
        $this->assertSame(['10.000', 'KWD'], $this->whatWasPaid('TXN-KWD-1', decimals: 3), 'three lines of one each, paid for as ten dinars');
        $this->assertEqualsWithDelta(3.0, (float) DB::table('orders')->where('id', $orderId)->value('order_amount'), 0.000001);
        $this->assertCount(3, $refunds);

        $this->theRateOfBecomes('KWD', 2.5);

        foreach ($refunds as $refund) {
            $this->refundItThroughTheAdminScreen($refund)->assertOk();
        }

        $given = array_map(static fn (mixed $amount): string => self::money($amount, 3), DB::table('refund_transactions')->orderBy('id')->pluck('original_payment_amount')->all());
        $this->assertSame(['3.333', '3.334', '3.333'], $given, 'each part is the difference of two roundings of the cumulative share, so no fils is lost or made up');
        $this->assertSame('10.000', self::money(array_sum(array_map('floatval', $given)), 3), 'the three parts add up to exactly the ten dinars that were paid');
        $this->assertSame(['KWD', 'KWD', 'KWD'], DB::table('refund_transactions')->orderBy('id')->pluck('original_payment_currency')->all());
    }

    // ---- what the refund records, and what it does not touch -------------------------------------------------------------------------------

    /** The refund transaction names the account and company that took the money, and what it gives back of that payment. */
    public function test_the_refund_transaction_records_the_payment_it_gives_back_and_the_account_that_took_it(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $company = $this->aCompanyIn('SA');
        $account = $this->anAccountFor('SA', $company->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ON-3');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $transaction = DB::table('refund_transactions')->first();
        $this->assertSame(
            ['375.00', 'SAR', $account->id, $company->id],
            [self::money($transaction->original_payment_amount, 2), $transaction->original_payment_currency, (int) $transaction->payment_gateway_account_id, (int) $transaction->payment_legal_entity_id],
            'the share of the original payment, in the currency it was taken in, and the merchant account and company that took it',
        );
        $this->assertEqualsWithDelta(100, (float) $transaction->amount, 0.000001, 'the refund amount in the amounts the order is stored in is not touched: the seller\'s earnings and the reports read it');
    }

    /** The seller's earnings are reduced by the stored amount, as before: a payout in the buyer's currency is not a change to what the seller is charged. */
    public function test_the_seller_earnings_are_reduced_by_the_stored_amount_as_before(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        DB::table('admin_wallets')->where('admin_id', 1)->update(['inhouse_earning' => 500]);
        $this->turnTheOriginalPaymentPayoutOn();

        [, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ON-4');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $this->assertEqualsWithDelta(400, (float) DB::table('admin_wallets')->where('admin_id', 1)->value('inhouse_earning'), 0.000001, '500 less the 100 the order is stored as, not less the 375 the buyer paid');
    }

    // ---- the orders this mechanism does not answer for -------------------------------------------------------------------------------------

    /** A cash-on-delivery order has no payment at all, which is most orders: the refund gives back what it always gave back. */
    public function test_a_cash_on_delivery_refund_gives_back_what_it_always_did(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , $line] = $this->placeFrom('SA', 1);
        $this->askForRefund($customer, 'SA', $line);
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);

        $refund = (int) DB::table('refund_requests')->value('id');
        $this->refundItThroughTheAdminScreen($refund)->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(100, (float) $credits[0]['credit'], 0.000001, 'the order records no currency of its own and has no payment, so there is nothing to work from');
        $this->assertNull(DB::table('refund_transactions')->value('original_payment_amount'));
    }

    /** A payment that was never marked paid is not the original payment of anything. */
    public function test_a_payment_that_was_not_paid_is_not_worked_from(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ON-5');
        DB::table('payment_requests')->where('transaction_id', 'TXN-ON-5')->update(['is_paid' => 0]);

        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(100, (float) $credits[0]['credit'], 0.000001, 'the conversion of today, because no paid payment answers for this order');
        $this->assertNull(DB::table('refund_transactions')->value('original_payment_amount'));
    }

    /** A shop that has not run this migration pays the refund out and records nothing, rather than failing on a column it has not got. */
    public function test_a_refund_before_the_migration_is_paid_and_records_nothing(): void
    {
        (require database_path('migrations/2026_12_05_100000_add_original_payment_to_refund_transactions_table.php'))->down();
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ON-6');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $this->assertSame(1, DB::table('refund_transactions')->count());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('refund_transactions', 'original_payment_amount'));
        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(375.0, (float) $credits[0]['credit'] * self::RATE_AT_THE_REFUND, 0.000001, 'the buyer is still paid what they paid: the columns are a record, not the mechanism');
    }
}
