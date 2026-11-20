<?php

namespace Tests\Feature\Wallet;

use App\Events\AddFundToWalletEvent;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\PaymentRequest;
use App\Services\Payments\WalletTopUpRegister;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsWalletTopUpWorld;
use Tests\TestCase;

/**
 * A payment into the wallet is credited once, however many times it is handed over.
 *
 * `add_fund_to_wallet_success` is the hook every gateway calls with a payment it has confirmed, and it credited the wallet each
 * time it was called. A gateway calls it again whenever the same confirmation arrives again, and nothing in between asked
 * whether this payment had been credited: one payment of 100 handed over twice left 200 in the wallet, and the bonus twice.
 *
 * The hook is driven here directly, which is the place all gateways share; the last test drives it through the return address
 * of a gateway. What two deliveries do when they arrive at the same instant is not expressible in one process and has a test
 * of its own on a real database (tests/Feature/Concurrency/WalletTopUpConcurrencyTest.php).
 */
class WalletTopUpOncePerPaymentTest extends TestCase
{
    use BuildsWalletTopUpWorld;

    private const OPENING_BALANCE = 10.0;

    private const BONUS = 5.0;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->aTopUpBonus(bonus: self::BONUS, fromAmount: 50);
        $this->customer = $this->aCustomer(balance: self::OPENING_BALANCE);
        Event::fake([AddFundToWalletEvent::class]);
    }

    public function test_a_payment_handed_over_twice_is_credited_once_with_one_bonus_and_one_notice(): void
    {
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: 100);

        add_fund_to_wallet_success($payment);
        add_fund_to_wallet_success($payment);
        add_fund_to_wallet_success(PaymentRequest::findOrFail($payment->id));

        $this->assertSame(self::OPENING_BALANCE + 100 + self::BONUS, $this->walletBalanceOf($this->customer));
        $rows = DB::table('wallet_transactions')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(100.0, (float) $rows[0]->credit);
        $this->assertSame(self::BONUS, (float) $rows[0]->admin_bonus);
        Event::assertDispatchedTimes(AddFundToWalletEvent::class, 1);
    }

    public function test_the_register_names_the_payment_the_customer_and_the_ledger_row_of_the_credit(): void
    {
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: 100);

        add_fund_to_wallet_success($payment);

        $entries = DB::table(WalletTopUpRegister::TABLE)->get();
        $this->assertCount(1, $entries);
        $this->assertSame($payment->id, $entries[0]->payment_request_id);
        $this->assertSame($this->customer, (int) $entries[0]->user_id);
        $this->assertSame((int) DB::table('wallet_transactions')->value('id'), (int) $entries[0]->wallet_transaction_id);
    }

    public function test_every_payment_of_a_customer_is_credited(): void
    {
        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 100));
        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 60));

        $this->assertSame(self::OPENING_BALANCE + 100 + self::BONUS + 60 + self::BONUS, $this->walletBalanceOf($this->customer));
        $this->assertSame(2, DB::table('wallet_transactions')->count());
        $this->assertSame(2, DB::table(WalletTopUpRegister::TABLE)->count());
    }

    public function test_a_payment_that_was_not_confirmed_is_not_credited_and_not_entered(): void
    {
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: 100);
        $payment->is_paid = 0;

        add_fund_to_wallet_success($payment);

        $this->assertSame(self::OPENING_BALANCE, $this->walletBalanceOf($this->customer));
        $this->assertSame(0, DB::table(WalletTopUpRegister::TABLE)->count());
    }

    /** The wallet switched off refuses the credit. The payment was taken all the same, so it must stay creditable. */
    public function test_a_top_up_that_is_refused_leaves_no_entry_and_is_credited_once_when_it_is_handed_over_again(): void
    {
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: 100);
        $this->walletIs(on: false);

        add_fund_to_wallet_success($payment);

        $this->assertSame(self::OPENING_BALANCE, $this->walletBalanceOf($this->customer));
        $this->assertSame(0, DB::table(WalletTopUpRegister::TABLE)->count(), 'a refused credit must not leave the payment marked as credited');

        $this->walletIs(on: true);
        add_fund_to_wallet_success($payment);
        add_fund_to_wallet_success($payment);

        $this->assertSame(self::OPENING_BALANCE + 100 + self::BONUS, $this->walletBalanceOf($this->customer));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        Event::assertDispatchedTimes(AddFundToWalletEvent::class, 1);
    }

    /** The ledger cannot be written: the credit fails inside its own transaction and answers false. */
    public function test_a_credit_that_fails_takes_its_entry_and_the_balance_back(): void
    {
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: 100);
        Schema::rename('wallet_transactions', 'wallet_transactions_away');

        add_fund_to_wallet_success($payment);

        $this->assertSame(self::OPENING_BALANCE, $this->walletBalanceOf($this->customer));
        $this->assertSame(0, DB::table(WalletTopUpRegister::TABLE)->count());

        Schema::rename('wallet_transactions_away', 'wallet_transactions');
        add_fund_to_wallet_success($payment);

        $this->assertSame(self::OPENING_BALANCE + 100 + self::BONUS, $this->walletBalanceOf($this->customer));
        $this->assertSame(1, DB::table(WalletTopUpRegister::TABLE)->count());
    }

    public function test_a_credit_that_throws_takes_its_entry_back_and_is_not_swallowed(): void
    {
        $register = app(WalletTopUpRegister::class);

        try {
            $register->creditOnce(paymentId: 'a-payment', customerId: $this->customer, credit: fn () => throw new RuntimeException('the ledger is unreachable'));
            $this->fail('the failure of the credit must reach the caller');
        } catch (RuntimeException $failure) {
            $this->assertSame('the ledger is unreachable', $failure->getMessage());
        }

        $this->assertSame(0, DB::table(WalletTopUpRegister::TABLE)->count());
        $this->assertSame(0, DB::transactionLevel(), 'no transaction may be left open behind a failed credit');
    }

    public function test_the_database_itself_refuses_a_second_entry_for_one_payment(): void
    {
        $entry = ['payment_request_id' => 'a-payment', 'user_id' => $this->customer, 'created_at' => now()];
        DB::table(WalletTopUpRegister::TABLE)->insert($entry);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table(WalletTopUpRegister::TABLE)->insert($entry);
    }

    /**
     * The register is a table, and the code reaches the server before its migration does. Until then the top-up credits as it
     * always did. This test exists so that nobody reads the code as protection that the database has not been given yet.
     */
    public function test_until_the_register_is_installed_a_payment_handed_over_twice_is_still_credited_twice(): void
    {
        Schema::drop(WalletTopUpRegister::TABLE);
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: 100);

        add_fund_to_wallet_success($payment);
        add_fund_to_wallet_success($payment);

        $this->assertSame(self::OPENING_BALANCE + 2 * (100 + self::BONUS), $this->walletBalanceOf($this->customer));
    }

    public function test_installing_the_register_twice_keeps_what_it_holds(): void
    {
        add_fund_to_wallet_success($this->aPaidTopUp(customer: $this->customer, amount: 100));

        $this->installTheTopUpRegister();

        $this->assertSame(1, DB::table(WalletTopUpRegister::TABLE)->count());
    }

    /**
     * The same thing from outside, through a gateway: Paymob sends the customer back to an address that carries the result
     * and a signature over it, and the address can be opened again.
     */
    public function test_a_signed_return_from_the_gateway_that_is_opened_twice_credits_the_wallet_once(): void
    {
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: 100);
        DB::table('payment_requests')->where('id', $payment->id)->update(['is_paid' => 0]);
        $signedReturn = $this->aSignedPaymobReturn(secret: $this->paymobIsConfigured());
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);

        $first = $this->withSession(['payment_id' => $payment->id])->get($signedReturn);
        $again = $this->withSession(['payment_id' => $payment->id])->get($signedReturn);

        $first->assertRedirectContains('payment-success');
        $again->assertRedirectContains('payment-success');
        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'));
        $this->assertSame(self::OPENING_BALANCE + 100 + self::BONUS, $this->walletBalanceOf($this->customer));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    private function paymobIsConfigured(): string
    {
        $secret = 'the-signing-secret-of-the-test';
        DB::table('addon_settings')->insert([
            'id' => 'paymob-accept-of-the-test', 'key_name' => 'paymob_accept', 'settings_type' => 'payment_config', 'mode' => 'test', 'is_active' => 1,
            'test_values' => json_encode(['hmac' => $secret, 'supported_country' => 'KSA']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $secret;
    }

    /** The return address as Paymob builds it: the signature is over the values of the fields it names, in the order of their names. */
    private function aSignedPaymobReturn(string $secret): string
    {
        $result = ['amount_cents' => '10000', 'currency' => 'SAR', 'id' => '4455667', 'order' => '998877', 'pending' => 'false', 'success' => 'true'];
        ksort($result);

        return '/payment/paymob/callback?' . http_build_query($result + ['hmac' => hash_hmac('sha512', implode('', $result), $secret)]);
    }
}
