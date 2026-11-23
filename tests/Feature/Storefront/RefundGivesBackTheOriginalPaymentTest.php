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
     * and the digest of those statements in order. Read-only questions about the schema are left out of the digest and counted separately.
     *
     * ONE statement was added to both routes after those captures were taken, and it is named rather than folded in: the question of whether the orders
     * carrying the payment's reference are one cart, which is what stops a refund being worked out from, and recorded against, another company's payment
     * (App\Services\Payments\PaymentBelongsToOrder). It is part of the FIRST half's account recording, so it runs while per-company accounts are on,
     * which is the configuration this test runs in and not the one the application ships. It is counted on its own below, and with it set aside both
     * routes still issue exactly the base's statements, in the base's order. The payout switch itself still adds nothing at all.
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

    /** The one statement added since the base was captured: whether the orders carrying the payment's reference are one cart. */
    private static function isTheCartQuestion(string $sql): bool
    {
        return preg_match('/^\s*select sum\(order_amount\).*\bfrom orders\b.*\btransaction_ref\b/i', self::plain($sql)) === 1;
    }

    /** @param list<string> $statements @return list<string> what a route does to the data, with the schema questions left out */
    private function workAmong(array $statements): array
    {
        return array_values(array_map(self::plain(...), array_filter($statements, static fn (string $sql): bool => !self::isASchemaQuestion($sql))));
    }

    /** @return array{work: list<string>, digest: string, carts: int} */
    private function whatTheRouteDid(callable $route): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $route();
        $all = $this->workAmong($statements);
        $work = array_values(array_filter($all, static fn (string $sql): bool => !self::isTheCartQuestion($sql)));

        return ['work' => $work, 'digest' => hash('sha256', implode("\n", $work)), 'carts' => count($all) - count($work)];
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
        $this->assertSame([1, 1], [$asked['carts'], $paid['carts']], 'and each route asks once, and only once, whether the orders under the payment\'s reference are one cart');
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

    /**
     * A settings change that puts the payment's currency out of the wallet's reach: the platform's decimals and its default currency both move after
     * the sale, so a riyal payment would have to be handed back through a dirham wallet.
     *
     * The mechanism declines that conversion and the buyer keeps the figure the flow has always given, while the amount owed is recorded. The test was
     * called `..._gives_back_exactly_what_the_buyer_paid` and asserted the opposite of its own name; it is named for what it measures now.
     */
    public function test_a_refund_whose_payment_cannot_reach_the_wallet_keeps_the_old_figure_and_records_what_is_owed(): void
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

    /**
     * Criterion 6 for a settings change that leaves the money of the payment reachable: the platform's decimals and its rate both move after the sale,
     * and the buyer still gets back what they paid, to the last minor unit of the currency.
     *
     * 375.00 riyals at a rate of 4.2 is 89.285714285714... in the amounts balances are kept in, which does not end: what the buyer gets back is
     * therefore exact only to the decimals of the column that holds it, and the installed database holds twenty (DECIMAL(40,20), set by
     * database/migrations/2026_05_07_000001). The real engine is what made this visible -- the first fixture here gave the column six decimals, SQLite
     * ignored the scale and answered 375.0 while MariaDB answered 374.9999988 -- and the fixture now has the precision the real column has, so what is
     * measured is what a shop would pay.
     */
    public function test_a_refund_after_the_decimals_and_the_rate_changed_gives_back_exactly_what_the_buyer_paid(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ON-7');
        $this->assertSame(['375.00', 'SAR'], $this->whatWasPaid('TXN-ON-7'));

        // The shop is reconfigured after the sale: three decimals instead of two, and another rate.
        $this->theShopConvertsAtTheRateOfTheDay('SAR', decimals: 3);
        $this->theRateOfBecomes('SAR', 4.2);

        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $given = (float) $credits[0]['credit'] * 4.2;
        $this->assertSame('375.00', self::money($given, 2), 'the buyer gets back the 375.00 SAR they paid, at the rate and the decimals of the day of the refund');
        $this->assertEqualsWithDelta(375.0, $given, 0.0000001, 'to the decimals the balance column holds, which the installed database sets to twenty: ' . $given);
        $this->assertEqualsWithDelta(420.0, (float) usdToDefaultCurrency(amount: 100), 0.000001, 'while the conversion of today would have given them 420.00 SAR for the same refund');
    }

    /** Criterion 6, in three decimals: a dinar payment comes back in thirds that add up to the dinar exactly. */
    public function test_a_three_decimal_payment_refunded_in_parts_adds_up_to_exactly_what_was_paid(): void
    {
        $products = $this->addSaudiProducts([1, 1, 1]);
        $this->theShopConvertsAtTheRateOfTheDay('KWD', decimals: 3);
        $this->theRateOfBecomes('KWD', 10 / 3);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, $orderId, , $refunds] = $this->sellPayAndAskForRefund($products, 'TXN-KWD-1', gateway: 'hyper_pay', currentCurrency: 'KWD');
        $this->assertSame(['10.000', 'KWD'], $this->whatWasPaid('TXN-KWD-1', decimals: 3), 'three lines of one each, paid for as ten dinars');
        $this->assertEqualsWithDelta(3.0, (float) DB::table('orders')->where('id', $orderId)->value('order_amount'), 0.000001);
        $this->assertCount(3, $refunds);

        // The rate moves between the parts, not merely before them: what each part gives back is its share of the payment, never a conversion of today.
        $this->theRateOfBecomes('KWD', 2.5);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $this->theRateOfBecomes('KWD', 1.8);
        $this->refundItThroughTheAdminScreen($refunds[1])->assertOk();

        $this->theRateOfBecomes('KWD', 5.5);
        $this->refundItThroughTheAdminScreen($refunds[2])->assertOk();

        $given = array_map(static fn (mixed $amount): string => self::money($amount, 3), DB::table('refund_transactions')->orderBy('id')->pluck('original_payment_amount')->all());
        $this->assertSame(['3.333', '3.334', '3.333'], $given, 'each part is the difference of two roundings of the cumulative share, so no fils is lost or made up');
        $this->assertSame('10.000', self::money(array_sum(array_map('floatval', $given)), 3), 'the three parts add up to exactly the ten dinars that were paid');
        $this->assertSame(['KWD', 'KWD', 'KWD'], DB::table('refund_transactions')->orderBy('id')->pluck('original_payment_currency')->all());

        // What the buyer was HANDED, beside what was recorded. Each part is exactly its share at the moment it is paid; the balance they end up
        // holding is in the platform's base unit and every later rate move revalues the earlier parts, so the three credits are worth 20.853 KWD at
        // the last rate and 12.638 KWD at the sale rate -- the ten dinars at neither. That is a property of a wallet balance across a rate change and
        // not of the share, and it is why the sum above is a statement about the RECORD and not about what the buyer can spend.
        $credits = array_map(static fn (array $credit): float => (float) $credit['credit'], $this->theWalletCreditsOf($customer));
        $this->assertSame(['3.333', '3.334', '3.333'], array_map(
            static fn (float $credit, float $rate): string => self::money($credit * $rate, 3),
            $credits,
            [2.5, 1.8, 5.5],
        ), 'each part is worth exactly its share of the ten dinars at the rate in force when it was paid');
        $this->assertSame('20.853', self::money(array_sum($credits) * 5.5, 3), 'and the balance they are left holding is worth this at the rate of the last payout, which is a wallet in base units across a moving rate and not the share being wrong');
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

    /**
     * A shop that keeps ONE currency, where no rate can move a figure: the buyer gets back exactly what they paid, and the switch changes nothing.
     *
     * No test on this branch ran the mechanism under `single_currency` before. Under it `currencyConverter` is the identity, so a stored amount, a
     * default-currency amount and a wallet balance are all the same unit, and the payment was recorded in that unit at the sale.
     */
    public function test_a_shop_that_keeps_one_currency_gives_back_exactly_what_the_buyer_paid(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->shopSetting('currency_model', json_encode('single_currency'));
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ONE-1');
        $this->assertSame(['100.00', 'SAR'], $this->whatWasPaid('TXN-ONE-1'), 'one currency, so the gateway took the stored amount itself');

        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(100, (float) $credits[0]['credit'], 0.000001, 'the 100.00 SAR they paid');
        $this->assertEqualsWithDelta(100.0, (float) usdToDefaultCurrency(amount: (float) $credits[0]['credit']), 0.000001, 'and that is 100.00 SAR of what they can spend, because one currency means one unit');
    }

    /**
     * A shop whose money settings change from several currencies to one between the sale and the refund still gives back the money that was paid.
     *
     * A review read the figure here as a 3.75x overpayment by comparing stored units across the settings change. They are not the same unit: under
     * `single_currency` a stored unit IS a unit of the default currency, so the 375 the buyer is credited is the 375.00 SAR the gateway took, and it
     * is the switch-off figure of 100.00 SAR that is 275.00 SAR short of what they handed over.
     */
    public function test_a_refund_after_the_shop_went_down_to_one_currency_gives_back_the_money_that_was_paid(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-ONE-2');
        $this->assertSame(['375.00', 'SAR'], $this->whatWasPaid('TXN-ONE-2'));

        $this->shopSetting('currency_model', json_encode('single_currency'));
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(375.0, (float) usdToDefaultCurrency(amount: (float) $credits[0]['credit']), 0.000001, 'the 375.00 SAR the buyer paid, in the money the shop now keeps');
        $this->assertEqualsWithDelta(100.0, (float) usdToDefaultCurrency(amount: 100), 0.000001, 'where the conversion of today would have given them 100.00 SAR of a 375.00 SAR payment');
    }

    // ---- the orders this mechanism does not answer for -------------------------------------------------------------------------------------

    /**
     * An order edited after it was paid for gives back what it always gave back.
     *
     * Measured before this was fixed, through these same routes: two lines of 50 bought in one cart for 375.00 SAR, both refunds asked for, the order
     * then edited down to one line the way App\Traits\OrderEditManager leaves it. The remaining refund of HALF the goods credited 375.00 SAR -- the
     * whole payment -- on top of the 50.00 SAR the edit itself returned through CustomerManager, so the buyer held 425.00 SAR of a 375.00 SAR payment.
     * The order's stored amount had moved while the payment had not, and the share was taken of the amount as it stands today.
     */
    public function test_an_order_edited_after_it_was_paid_for_gives_back_what_it_always_gave_back(): void
    {
        $products = $this->addSaudiProducts([50, 50]);
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, $orderId, , $refunds] = $this->sellPayAndAskForRefund($products, 'TXN-EDIT-1');
        $this->assertSame(['375.00', 'SAR'], $this->whatWasPaid('TXN-EDIT-1'));

        // What the administrator's order-edit screen leaves on the order when a line is taken off it.
        DB::table('orders')->where('id', $orderId)->update(['order_amount' => 50, 'init_order_amount' => 50, 'edit_return_amount' => 50, 'edited_status' => 1]);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(50, (float) $credits[0]['credit'], 0.000001, 'the stored amount of the refund, as the flow has always given it, and not a share of a payment the order no longer describes');
        $this->assertEqualsWithDelta(187.5, (float) $credits[0]['credit'] * self::RATE_AT_THE_SALE, 0.000001, 'which with the 50.00 the edit returned is less than the 375.00 that was paid, where before it was 50.00 more');
        $this->assertNull(DB::table('refund_transactions')->value('original_payment_amount'), 'and nothing of the original payment is recorded, because the order no longer says what that payment covered');
    }

    /**
     * An order paid a SECOND time, which is what an order edited upwards is, gives back what it always gave back.
     *
     * `customer_order_edit_pay_due_amount_success` raises `orders.order_amount` and writes the second payment's reference on the edit history; it never
     * touches `orders.transaction_ref`. Measured before this was fixed: a buyer who handed over 750.00 SAR in two payments was given back 375.00 SAR --
     * exactly half their money, and half of what the switch-off path pays them.
     */
    public function test_an_order_paid_a_second_time_gives_back_what_it_always_gave_back(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, $orderId, , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-DUE-1');
        $first = DB::table('payment_requests')->where('transaction_id', 'TXN-DUE-1')->firstOrFail();

        // The due payment of the edit: its own paid record under its own reference, and the order raised to what it now costs.
        DB::table('payment_requests')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'payer_id' => $first->payer_id, 'payment_amount' => 375.00, 'currency_code' => 'SAR',
            'payment_method' => self::GATEWAY, 'transaction_id' => 'TXN-DUE-1-B', 'is_paid' => 1, 'additional_data' => $first->additional_data,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->where('id', $orderId)->update(['order_amount' => 200, 'edited_status' => 1]);
        DB::table('refund_requests')->where('id', $refunds[0])->update(['amount' => 200]);

        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(750.0, (float) $credits[0]['credit'] * self::RATE_AT_THE_SALE, 0.000001, 'all 750.00 SAR of the two payments, which is what the flow has always given back here');
        $this->assertNull(DB::table('refund_transactions')->value('original_payment_amount'));
    }

    /**
     * A refund of an order whose payment cannot be found by its reference is paid exactly as it always was, and records nothing.
     *
     * `orders.transaction_ref` is varchar(30) in the installed database and `payment_requests.transaction_id` is varchar(100), so a gateway reference
     * longer than thirty characters -- an OPP or HyperPay id is thirty-two -- is kept whole on the payment and cut short on the order. The join then
     * finds nothing. This is measured rather than described, because it is engine-dependent: a real server truncates and SQLite does not, and the
     * fixture used to declare the column wider than production so no test in the repository could see it at all.
     */
    public function test_a_gateway_reference_longer_than_the_order_can_hold_is_paid_as_before_and_records_nothing(): void
    {
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->anAccountFor('SA', $this->aCompanyIn('SA')->id);
        $this->turnTheOriginalPaymentPayoutOn();

        $reference = '8ac7a4a08f7b1a9f018f7b2c3d4e5f60';
        [$customer, $orderId, , $refunds] = $this->sellPayAndAskForRefund([1], $reference);

        $kept = (string) DB::table('orders')->where('id', $orderId)->value('transaction_ref');
        $this->assertSame($reference, (string) DB::table('payment_requests')->where('is_paid', 1)->value('transaction_id'), 'the payment keeps the gateway reference whole');

        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $recorded = DB::table('refund_transactions')->value('original_payment_amount');
        $credit = (float) $this->theWalletCreditsOf($customer)[0]['credit'];

        if ($kept === $reference) {
            $this->assertNotNull($recorded, 'where the column held the whole reference the payment is found and the buyer is paid what they paid');
            $this->assertEqualsWithDelta(375.0, $credit * self::RATE_AT_THE_REFUND, 0.000001);

            return;
        }

        $this->assertSame(30, strlen($kept), 'the order kept what the column can hold');
        $this->assertNull($recorded, 'the reference the order kept names no payment, so nothing of an original payment is recorded');
        $this->assertEqualsWithDelta(100, $credit, 0.000001, 'and the buyer is paid the conversion of today, exactly as the flow paid before: the mechanism is silent, not wrong');
    }

    /** The money mechanism does not run while per-company payment accounts are off, so no refund is changed where no merchant account is recorded. */
    public function test_the_payout_does_nothing_while_per_company_payment_accounts_are_off(): void
    {
        config(['gcc.storefronts.payment_accounts.enabled' => false]);
        $this->forgetWhatWasRemembered();
        $this->theShopConvertsAtTheRateOfTheDay('SAR');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_SALE);
        $this->turnTheOriginalPaymentPayoutOn();

        [$customer, , , $refunds] = $this->sellPayAndAskForRefund([1], 'TXN-NOACC-1');
        $this->theRateOfBecomes('SAR', self::RATE_AT_THE_REFUND);
        $this->refundItThroughTheAdminScreen($refunds[0])->assertOk();

        $credits = $this->theWalletCreditsOf($customer);
        $this->assertEqualsWithDelta(100, (float) $credits[0]['credit'], 0.000001, 'the conversion of today, as before');
        $this->assertSame([null, null], [DB::table('refund_transactions')->value('original_payment_amount'), DB::table('refund_transactions')->value('original_payment_currency')]);
    }


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
