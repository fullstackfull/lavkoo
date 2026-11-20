<?php

namespace Tests\Feature\Payments;

use App\Events\AddFundToWalletEvent;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\PaymentRequest;
use App\Services\Payments\GatewayConfirmations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletTopUpWorld;
use Tests\TestCase;

/**
 * A confirmation of the gateway the shop uses settles the payment it is for, once.
 *
 * Paymob signs its own transaction, its own order and the amount it took. It does not say which payment of this shop was paid:
 * the shop took that from the browser session, compared nothing, and marked that payment paid. So one real payment of 100
 * settled a second top-up of any amount: the customer paid once, kept the address of the return, started another top-up, paid
 * nothing, and opened the kept address again.
 *
 * The confirmation is now held to the payment it is for -- its amount and its currency must be that payment's -- and one
 * transaction of Paymob settles one payment and no second one. The requests here are the real signed returns over the real
 * route, and a wallet top-up is the payment, because what follows it is one number to read.
 */
class AGatewayConfirmationSettlesItsOwnPaymentTest extends TestCase
{
    use BuildsWalletTopUpWorld;

    private const OPENING_BALANCE = 10.0;

    private const GATEWAY = 'paymob_accept';

    private string $secret;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->installTheConfirmationRegister();
        $this->secret = $this->paymobIsConfigured();
        $this->customer = $this->aCustomer(balance: self::OPENING_BALANCE);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        Event::fake([AddFundToWalletEvent::class]);
        Http::preventStrayRequests();
    }

    public function test_the_confirmation_of_one_payment_does_not_settle_another_payment_of_another_amount(): void
    {
        $paid = $this->anUnpaidTopUp(amount: 100);
        $neverPaid = $this->anUnpaidTopUp(amount: 5000);
        $confirmation = $this->aSignedReturn(transaction: '4455667', amount: 100);

        $this->openTheReturn($confirmation, forPayment: $paid)->assertRedirectContains('payment-success');
        $this->openTheReturn($confirmation, forPayment: $neverPaid)->assertRedirectContains('payment-fail');

        $this->assertWasPaid($paid);
        $this->assertWasNotPaid($neverPaid);
        $this->assertSame(self::OPENING_BALANCE + 100, $this->walletBalanceOf($this->customer));
    }

    /** The amount is the same, so only "one transaction, one payment" can tell these two apart. */
    public function test_the_confirmation_of_one_payment_does_not_settle_another_payment_of_the_same_amount(): void
    {
        $paid = $this->anUnpaidTopUp(amount: 100);
        $neverPaid = $this->anUnpaidTopUp(amount: 100);
        $confirmation = $this->aSignedReturn(transaction: '4455667', amount: 100);

        $this->openTheReturn($confirmation, forPayment: $paid)->assertRedirectContains('payment-success');
        $this->openTheReturn($confirmation, forPayment: $neverPaid)->assertRedirectContains('payment-fail');

        $this->assertWasPaid($paid);
        $this->assertWasNotPaid($neverPaid);
        $this->assertSame(self::OPENING_BALANCE + 100, $this->walletBalanceOf($this->customer));
    }

    /** @return array<string, array{float, float}> what the payment is for, and what the confirmation says was taken */
    public static function anAmountThatIsNotThePayments(): array
    {
        return [
            'less than the payment' => [100.0, 99.0],
            'more than the payment' => [100.0, 101.0],
            'a minor unit less' => [100.0, 99.99],
            'nothing' => [100.0, 0.0],
        ];
    }

    #[DataProvider('anAmountThatIsNotThePayments')]
    public function test_a_confirmation_that_carries_another_amount_settles_nothing(float $payment, float $confirmed): void
    {
        $waiting = $this->anUnpaidTopUp(amount: $payment);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: $confirmed), forPayment: $waiting)->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($waiting);
        $this->assertSame(self::OPENING_BALANCE, $this->walletBalanceOf($this->customer));
    }

    public function test_a_confirmation_that_carries_another_currency_settles_nothing(): void
    {
        $waiting = $this->anUnpaidTopUp(amount: 100);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 100, currency: 'AED'), forPayment: $waiting)->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($waiting);
    }

    public function test_a_payment_that_is_settled_already_is_not_settled_again_by_another_confirmation(): void
    {
        $paid = $this->anUnpaidTopUp(amount: 100);
        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 100), forPayment: $paid)->assertRedirectContains('payment-success');

        $this->openTheReturn($this->aSignedReturn(transaction: '7788990', amount: 100), forPayment: $paid)->assertRedirectContains('payment-fail');

        $this->assertSame(self::OPENING_BALANCE + 100, $this->walletBalanceOf($this->customer));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    public function test_a_confirmation_whose_signature_is_not_the_shops_settles_nothing(): void
    {
        $waiting = $this->anUnpaidTopUp(amount: 100);
        $signedByAnotherSecret = $this->aSignedReturn(transaction: '4455667', amount: 100, secret: 'not-the-secret-of-this-shop');

        $this->openTheReturn($signedByAnotherSecret, forPayment: $waiting)->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($waiting);
    }

    public function test_each_payment_is_settled_by_its_own_confirmation(): void
    {
        $first = $this->anUnpaidTopUp(amount: 100);
        $second = $this->anUnpaidTopUp(amount: 5000);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 100), forPayment: $first)->assertRedirectContains('payment-success');
        $this->openTheReturn($this->aSignedReturn(transaction: '7788990', amount: 5000), forPayment: $second)->assertRedirectContains('payment-success');

        $this->assertWasPaid($first);
        $this->assertWasPaid($second);
        $this->assertSame(self::OPENING_BALANCE + 100 + 5000, $this->walletBalanceOf($this->customer));
    }

    public function test_the_register_names_the_gateway_its_transaction_and_the_payment_it_settled(): void
    {
        $paid = $this->anUnpaidTopUp(amount: 100);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 100), forPayment: $paid);

        $entries = DB::table(GatewayConfirmations::TABLE)->get();
        $this->assertCount(1, $entries);
        $this->assertSame(self::GATEWAY, $entries[0]->gateway);
        $this->assertSame('4455667', $entries[0]->reference);
        $this->assertSame($paid->id, $entries[0]->payment_request_id);
        $this->assertSame('4455667', DB::table('payment_requests')->where('id', $paid->id)->value('transaction_id'));
    }

    /**
     * Until the register's migration has run, one transaction is not yet held to one payment -- but the amount is already
     * compared, so the attack of a larger top-up is closed by the code alone. This is pinned so that nobody reads the register
     * as protection the database has not been given yet.
     */
    public function test_without_the_register_the_amount_alone_still_refuses_a_payment_of_another_amount(): void
    {
        DB::statement('DROP TABLE ' . GatewayConfirmations::TABLE);
        $paid = $this->anUnpaidTopUp(amount: 100);
        $neverPaid = $this->anUnpaidTopUp(amount: 5000);
        $confirmation = $this->aSignedReturn(transaction: '4455667', amount: 100);

        $this->openTheReturn($confirmation, forPayment: $paid)->assertRedirectContains('payment-success');
        $this->openTheReturn($confirmation, forPayment: $neverPaid)->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($neverPaid);
        $this->assertSame(self::OPENING_BALANCE + 100, $this->walletBalanceOf($this->customer));
    }

    private function assertWasPaid(PaymentRequest $payment): void
    {
        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'), 'the payment that was really paid must be settled');
    }

    private function assertWasNotPaid(PaymentRequest $payment): void
    {
        $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'), 'a payment nobody paid must not be settled');
    }

    private function anUnpaidTopUp(float $amount): PaymentRequest
    {
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: $amount);
        DB::table('payment_requests')->where('id', $payment->id)->update(['is_paid' => 0, 'payment_method' => self::GATEWAY]);

        return $payment->refresh();
    }

    private function openTheReturn(string $address, PaymentRequest $forPayment): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['payment_id' => $forPayment->id])->get($address);
    }

    /** The return address as Paymob builds it: the signature is over the values of the fields it names, in the order of their names. */
    private function aSignedReturn(string $transaction, float $amount, string $currency = 'SAR', ?string $secret = null): string
    {
        $result = ['amount_cents' => (string) (int) round($amount * 100), 'currency' => $currency, 'id' => $transaction, 'order' => '998877', 'pending' => 'false', 'success' => 'true'];
        ksort($result);

        return '/payment/paymob/callback?' . http_build_query($result + ['hmac' => hash_hmac('sha512', implode('', $result), $secret ?? $this->secret)]);
    }

    private function paymobIsConfigured(): string
    {
        $secret = 'the-signing-secret-of-the-test';
        DB::table('addon_settings')->insert([
            'id' => 'paymob-accept-of-the-test', 'key_name' => self::GATEWAY, 'settings_type' => 'payment_config', 'mode' => 'test', 'is_active' => 1,
            'test_values' => json_encode(['hmac' => $secret, 'supported_country' => 'KSA']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $secret;
    }
}
