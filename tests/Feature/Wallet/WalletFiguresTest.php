<?php

namespace Tests\Feature\Wallet;

use App\Models\WalletTransaction;
use App\Services\Customer\CustomerWalletWriter;
use App\Services\Customer\WalletPosting;
use Illuminate\Support\Facades\DB;
use Tests\Support\Wallet\WalletTables;
use Tests\TestCase;

/**
 * The figures every path that moves a customer's wallet leaves behind, pinned one by one.
 *
 * This is the net the single writer was built inside. Four copies of the write were collapsed into
 * App\Services\Customer\CustomerWalletWriter, and the copies did not agree with each other, so the question that matters is
 * not whether the new code is reasonable but whether it credits and debits exactly what the old code credited and debited.
 * Every case below was measured against origin/main first and carries the figure main produced.
 *
 * The ONE figure that is different on purpose is the `balance` of an `add_fund` row that earned a bonus: main wrote
 * `previous + credit`, leaving the row short of the account by exactly the bonus, and it now carries what the account holds.
 * That case says so where it is asserted.
 */
class WalletFiguresTest extends TestCase
{
    private const CUSTOMER_ID = 1;
    private const OPENING_BALANCE = 350.0;

    protected function setUp(): void
    {
        parent::setUp();
        WalletTables::reset();
        WalletTables::setSetting('wallet_status', '1');
        WalletTables::setSetting('loyalty_point_exchange_rate', '100');
        WalletTables::setSetting('currency_model', 'single_currency');
        WalletTables::setSetting('decimal_point_settings', '2');
        WalletTables::seedCustomer(self::CUSTOMER_ID, self::OPENING_BALANCE);
        session(['currency_exchange_rate' => 1]);
    }

    protected function tearDown(): void
    {
        WalletTables::drop();
        parent::tearDown();
    }

    public function test_a_top_up_without_a_bonus_credits_its_amount(): void
    {
        $returned = $this->record(amount: 100.0, transactionType: 'add_fund', reference: 'add_funds_to_wallet');

        $this->assertInstanceOf(WalletTransaction::class, $returned);
        $this->assertFigures(credit: 100.0, debit: 0.0, bonus: 0.0, balance: 450.0, wallet: 450.0);
    }

    /**
     * A top-up that earned a bonus: 10 percent of 100, capped at 50.
     *
     * main credited the account 460.00 and wrote 450.00 on the row — the row short of the account by the 10.00 bonus it was
     * printing in the next column. This is the one figure this branch moves, and it moves to the balance the account holds.
     */
    public function test_a_top_up_with_a_bonus_credits_the_bonus_and_the_row_says_so(): void
    {
        WalletTables::addFundBonus(bonusType: 'percentage', amount: 10.0, minimumAmount: 50.0, maximumBonus: 50.0);

        $returned = $this->record(amount: 100.0, transactionType: 'add_fund', reference: 'add_funds_to_wallet');

        $this->assertInstanceOf(WalletTransaction::class, $returned);
        $this->assertFigures(credit: 100.0, debit: 0.0, bonus: 10.0, balance: 460.0, wallet: 460.0);
        $this->assertSame(450.0, (float) WalletTables::ledgerRow()->balance - (float) WalletTables::ledgerRow()->admin_bonus, 'main wrote this figure on the row');
    }

    /** A flat bonus band, to show the bonus is taken from the band and not computed from the amount. */
    public function test_a_top_up_with_a_flat_bonus_credits_that_bonus(): void
    {
        WalletTables::addFundBonus(bonusType: 'fixed', amount: 25.0, minimumAmount: 50.0, maximumBonus: 0.0);

        $this->record(amount: 100.0, transactionType: 'add_fund', reference: 'add_funds_to_wallet');

        $this->assertFigures(credit: 100.0, debit: 0.0, bonus: 25.0, balance: 475.0, wallet: 475.0);
    }

    /** A bonus band the amount does not reach pays nothing. */
    public function test_a_top_up_below_the_bonus_band_earns_nothing(): void
    {
        WalletTables::addFundBonus(bonusType: 'percentage', amount: 10.0, minimumAmount: 500.0, maximumBonus: 50.0);

        $this->record(amount: 100.0, transactionType: 'add_fund', reference: 'add_funds_to_wallet');

        $this->assertFigures(credit: 100.0, debit: 0.0, bonus: 0.0, balance: 450.0, wallet: 450.0);
    }

    /** An admin top-up and a referral credit both take this path, and neither has ever earned a bonus. */
    public function test_an_admin_credit_pays_no_bonus_even_when_a_band_is_live(): void
    {
        WalletTables::addFundBonus(bonusType: 'percentage', amount: 10.0, minimumAmount: 50.0, maximumBonus: 50.0);

        $returned = $this->record(amount: 100.0, transactionType: 'add_fund_by_admin', reference: 'earned_by_referral');

        $this->assertInstanceOf(WalletTransaction::class, $returned);
        $this->assertFigures(credit: 100.0, debit: 0.0, bonus: 0.0, balance: 450.0, wallet: 450.0);
    }

    public function test_an_order_payment_debits_its_amount(): void
    {
        $returned = $this->record(amount: 100.0, transactionType: 'order_place', reference: 'order payment');

        $this->assertInstanceOf(WalletTransaction::class, $returned);
        $this->assertFigures(credit: 0.0, debit: 100.0, balance: 250.0, wallet: 250.0);
    }

    /** A POS sale is an order payment written from the counter, with POS's own constant reference. */
    public function test_a_pos_sale_debits_its_amount(): void
    {
        $this->record(amount: 100.0, transactionType: 'order_place', reference: 'order_place_in_pos');

        $this->assertFigures(credit: 0.0, debit: 100.0, balance: 250.0, wallet: 250.0);
        $this->assertSame('order_place_in_pos', WalletTables::ledgerRow()->reference);
    }

    /** A refund answers `true` and not the row, which is what the one copy that served it did. */
    public function test_a_refund_credits_its_amount_and_answers_true(): void
    {
        $returned = $this->record(amount: 100.0, transactionType: 'order_refund', reference: 'order_refund');

        $this->assertTrue($returned);
        $this->assertFigures(credit: 100.0, debit: 0.0, balance: 450.0, wallet: 450.0);
    }

    public function test_an_edited_order_due_payment_debits_its_amount(): void
    {
        $returned = $this->record(amount: 100.0, transactionType: 'due_payment_for_order', reference: 'reduce_wallet_amount', paymentData: ['payment_method' => 'wallet']);

        $this->assertInstanceOf(WalletTransaction::class, $returned);
        $this->assertFigures(credit: 0.0, debit: 100.0, balance: 250.0, wallet: 250.0);
        $this->assertSame('wallet', WalletTables::ledgerRow()->payment_method);
    }

    public function test_an_edited_order_return_credits_its_amount(): void
    {
        $returned = $this->record(amount: 100.0, transactionType: 'return_order_amount_by_admin', reference: 'add_wallet_amount', paymentData: ['payment_method' => 'wallet']);

        $this->assertInstanceOf(WalletTransaction::class, $returned);
        $this->assertFigures(credit: 100.0, debit: 0.0, balance: 450.0, wallet: 450.0);
    }

    /**
     * The two loyalty conversions, which do NOT agree, and did not agree before this branch either.
     *
     * 1000 points at an exchange rate of 100 is 10 units of store money. The web path then divides once more by the session's
     * exchange rate and the API path does not, so at a rate of 2 the web credits 5.00 and the API credits 10.00 for the same
     * thousand points. Both figures are main's. Collapsing them into one would change what one of the two paths pays.
     */
    public function test_the_web_loyalty_conversion_credits_what_it_always_has(): void
    {
        session(['currency_exchange_rate' => 2]);

        $returned = CustomerWalletWriter::write(
            customerId: self::CUSTOMER_ID,
            posting: WalletPosting::loyaltyPointsConvertedOnTheWeb(points: 1000.0, reference: 'point_to_wallet'),
        );

        $this->assertInstanceOf(WalletTransaction::class, $returned);
        $this->assertFigures(credit: 5.0, debit: 0.0, balance: 355.0, wallet: 355.0);
    }

    public function test_the_api_loyalty_conversion_credits_what_it_always_has(): void
    {
        session(['currency_exchange_rate' => 2]);

        $returned = CustomerWalletWriter::write(
            customerId: self::CUSTOMER_ID,
            posting: WalletPosting::loyaltyPointsConvertedInTheApi(points: 1000.0, reference: 'point_to_wallet'),
        );

        $this->assertInstanceOf(WalletTransaction::class, $returned);
        $this->assertFigures(credit: 10.0, debit: 0.0, balance: 360.0, wallet: 360.0);
    }

    /** Under a single currency and a session rate of 1 the two agree, which is why the disagreement goes unnoticed. */
    public function test_the_two_loyalty_conversions_agree_at_a_session_rate_of_one(): void
    {
        $web = WalletPosting::loyaltyPointsConvertedOnTheWeb(points: 1000.0);
        $api = WalletPosting::loyaltyPointsConvertedInTheApi(points: 1000.0);

        $this->assertSame(10.0, $web->credit);
        $this->assertSame(10.0, $api->credit);
    }

    /** A type no branch recognises writes a row that moves nothing, which is what all four copies did with one. */
    public function test_an_unrecognised_type_moves_no_money_and_still_writes_a_row(): void
    {
        $returned = $this->record(amount: 100.0, transactionType: 'something_nobody_handles', reference: 'unknown');

        $this->assertTrue($returned);
        $this->assertFigures(credit: 0.0, debit: 0.0, balance: 350.0, wallet: 350.0);
    }

    public function test_a_wallet_that_is_switched_off_refuses_and_writes_nothing(): void
    {
        WalletTables::setSetting('wallet_status', '0');

        $this->assertFalse($this->record(amount: 100.0, transactionType: 'add_fund_by_admin', reference: 'earned_by_referral'));
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(self::OPENING_BALANCE, WalletTables::walletBalance(self::CUSTOMER_ID));
    }

    /**
     * Under several currencies every amount is divided by the default rate over the USD rate before it is stored.
     *
     * This is the case that decides whether converging Convert::usd() and currencyConverter() was safe: the four copies used
     * one or the other, and at a default rate of 3.75 against a USD rate of 1 both answer 26.666666666666668 for 100.
     */
    public function test_several_currencies_convert_a_top_up_and_an_order_payment_the_same_way(): void
    {
        $this->useSeveralCurrencies();

        $this->record(amount: 100.0, transactionType: 'add_fund_by_admin', reference: 'earned_by_referral');
        $credited = (float) WalletTables::ledgerRow()->credit;

        $this->assertEqualsWithDelta(100.0 / 3.75, $credited, 0.00000000001);
        $this->assertEqualsWithDelta(self::OPENING_BALANCE + $credited, WalletTables::walletBalance(self::CUSTOMER_ID), 0.0001);
        $this->assertEqualsWithDelta(self::OPENING_BALANCE + $credited, (float) WalletTables::ledgerRow()->balance, 0.0001);
    }

    /** The same conversion on the way out, so a wallet-paid order takes the same figure it would have credited. */
    public function test_several_currencies_convert_an_order_payment_the_same_way(): void
    {
        $this->useSeveralCurrencies();

        $this->record(amount: 100.0, transactionType: 'order_place', reference: 'order payment');

        $this->assertEqualsWithDelta(100.0 / 3.75, (float) WalletTables::ledgerRow()->debit, 0.00000000001);
        $this->assertEqualsWithDelta(self::OPENING_BALANCE - 100.0 / 3.75, WalletTables::walletBalance(self::CUSTOMER_ID), 0.0001);
    }

    /** The bonus is NOT converted again: the band is read with a converted amount and answers in the money the balance is kept in. */
    public function test_several_currencies_add_the_bonus_to_the_balance_untouched(): void
    {
        $this->useSeveralCurrencies();
        WalletTables::addFundBonus(bonusType: 'fixed', amount: 25.0, minimumAmount: 10.0, maximumBonus: 0.0);

        $this->record(amount: 100.0, transactionType: 'add_fund', reference: 'add_funds_to_wallet');

        $this->assertSame(25.0, (float) WalletTables::ledgerRow()->admin_bonus);
        $this->assertEqualsWithDelta(self::OPENING_BALANCE + 100.0 / 3.75 + 25.0, WalletTables::walletBalance(self::CUSTOMER_ID), 0.0001);
    }

    private function useSeveralCurrencies(): void
    {
        $default = WalletTables::addCurrency(code: 'SAR', exchangeRate: 3.75);
        WalletTables::addCurrency(code: 'USD', exchangeRate: 1.0);
        WalletTables::setSetting('currency_model', 'multi_currency');
        WalletTables::setSetting('system_default_currency', (string) $default);
    }

    /** @param array<string, mixed> $paymentData */
    private function record(float $amount, string $transactionType, string $reference, array $paymentData = []): mixed
    {
        return CustomerWalletWriter::record(
            customerId: self::CUSTOMER_ID,
            amount: $amount,
            transactionType: $transactionType,
            reference: $reference,
            paymentData: $paymentData,
        );
    }

    /** One ledger row with these figures, and a balance on it that is the balance the account now holds. */
    private function assertFigures(float $credit, float $debit, float $balance, float $wallet, float $bonus = 0.0): void
    {
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $row = WalletTables::ledgerRow();

        $this->assertSame($credit, (float) $row->credit, 'credit');
        $this->assertSame($debit, (float) $row->debit, 'debit');
        $this->assertSame($bonus, (float) $row->admin_bonus, 'admin_bonus');
        $this->assertSame($balance, (float) $row->balance, 'the row says the account holds this');
        $this->assertSame($wallet, WalletTables::walletBalance(self::CUSTOMER_ID), 'the account holds this');
        $this->assertSame($wallet, (float) $row->balance, 'the row and the account disagree');
    }
}
