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
use App\Services\Payments\GatewayConfirmations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsCompanyGatewayAccounts;
use Tests\TestCase;

/**
 * A customer's return from a gateway proves nothing by itself (D2-11, D2-12).
 *
 * The return is a request made by the customer's browser, and the browser carries whatever it is handed. So it is signed with the secret of the account
 * the payment was taken through, and when the owner has turned confirmation on it settles only after the server has asked Paymob itself. A return
 * the server cannot confirm settles nothing and fails nothing, and a confirmed return settles its payment once.
 *
 * Every return here is a real GET of /payment/paymob/callback through the real middleware, and every stamp on every payment was written by
 * App\Traits\Payment::generate_link().
 */
class GatewayReturnIsConfirmedWithTheGatewayTest extends TestCase
{
    use BuildsCompanyGatewayAccounts;

    private const OPENING_BALANCE = 10.0;

    private const KUWAIT_HMAC = 'kuwait-account-hmac-secret';

    private const SAUDI_HMAC = 'saudi-account-hmac-secret';

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->installTheConfirmationRegister();
        $this->installTheCompanyAndAccountTables();
        $this->thePlatformPaymobRow();
        $this->aCurrency(code: 'KWD', rate: 0.307);
        $this->turnCompanyAccountsOn();
        $this->customer = $this->aCustomer(balance: self::OPENING_BALANCE);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        Event::fake([AddFundToWalletEvent::class]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();

        parent::tearDown();
    }

    /** The cross-company case, and the first one to write: company A's secret decides nothing about company B's payment. */
    public function test_a_return_signed_with_another_companys_secret_settles_nothing(): void
    {
        $kuwait = $this->aKuwaitPayment(amount: 12.5);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::SAUDI_HMAC, currency: 'KWD'), forPayment: $kuwait)
            ->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($kuwait);
        $this->assertSame(self::OPENING_BALANCE, $this->walletBalanceOf($this->customer), 'no money moved');
    }

    /** And neither does the platform-wide secret, once the payment belongs to a company's account. */
    public function test_a_return_signed_with_the_platform_secret_settles_nothing_for_a_payment_of_a_companys_account(): void
    {
        $kuwait = $this->aKuwaitPayment(amount: 12.5);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: 'the-platform-signing-secret', currency: 'KWD'), forPayment: $kuwait)
            ->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($kuwait);
    }

    /** The account's own secret settles the account's own payment, so the refusals above are about whose secret it is and nothing else. */
    public function test_the_accounts_own_secret_settles_the_accounts_own_payment(): void
    {
        $kuwait = $this->aKuwaitPayment(amount: 12.5);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD'), forPayment: $kuwait)
            ->assertRedirectContains('payment-success');

        $this->assertWasPaid($kuwait);
    }

    /** An account with no signing secret of its own verifies nothing: the empty key anybody can compute is not a signature. */
    public function test_an_account_with_no_secret_settles_nothing_however_the_return_is_signed(): void
    {
        $this->aCompanyIn('KW');
        $this->anAccountFor(country: 'KW', secrets: ['secret_key' => 'kuwait-secret-key']);
        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5);
        $this->paymentIsAWalletTopUpOf($payment);

        foreach ([self::KUWAIT_HMAC, 'the-platform-signing-secret', ''] as $attempt) {
            $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: $attempt, currency: 'KWD'), forPayment: $payment)
                ->assertRedirectContains('payment-fail');
        }

        $this->assertWasNotPaid($payment);
    }

    /** A return with no signature at all, which is what a hand-made address is. */
    public function test_an_unsigned_return_settles_nothing(): void
    {
        $kuwait = $this->aKuwaitPayment(amount: 12.5);

        $this->openTheReturn('/payment/paymob/callback?' . http_build_query(['amount_cents' => '1250', 'currency' => 'KWD', 'id' => '4455667', 'success' => 'true']), forPayment: $kuwait)
            ->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($kuwait);
    }

    /** A crafted address refuses, rather than answering 500: an array where Paymob sends a value is not part of what Paymob signs. */
    public function test_a_return_whose_parameters_are_not_values_is_refused_and_does_not_fail_the_request(): void
    {
        $kuwait = $this->aKuwaitPayment(amount: 12.5);

        $this->openTheReturn('/payment/paymob/callback?' . http_build_query(['id' => ['1', '2'], 'amount_cents' => '1250', 'currency' => 'KWD', 'success' => 'true', 'hmac' => 'anything']), forPayment: $kuwait)
            ->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($kuwait);
    }

    /** D2-11: 'a return is accepted only after the provider confirms it and amount, currency and reference match the stored payment'. */
    public function test_with_confirmation_on_a_return_the_gateway_confirms_settles_the_payment(): void
    {
        config(['gcc.storefronts.payment_accounts.confirm_returns' => true]);
        $kuwait = $this->aKuwaitPayment(amount: 12.5);
        Http::fake(['*' => Http::response(['success' => true, 'pending' => false, 'amount_cents' => 1250, 'currency' => 'KWD'], 200)]);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD'), forPayment: $kuwait)
            ->assertRedirectContains('payment-success');

        $this->assertWasPaid($kuwait);
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/api/acceptance/transactions/4455667'));
        Http::assertSent(static fn ($request): bool => str_contains((string) $request->header('Authorization')[0], 'kuwait-secret-key'));
    }

    /** An unknown outcome settles nothing AND fails nothing: a dropped connection says nothing about whether the money moved. */
    public function test_a_return_the_server_cannot_confirm_settles_nothing_and_runs_no_failure_hook(): void
    {
        config(['gcc.storefronts.payment_accounts.confirm_returns' => true]);
        $kuwait = $this->aKuwaitPayment(amount: 12.5);
        Http::fake(static fn () => throw new ConnectionException('Paymob did not answer'));

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD'), forPayment: $kuwait)
            ->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($kuwait);
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'an unknown outcome writes nothing to the wallet, in either direction');
    }

    /** A gateway that answers "paid" for another amount is not a confirmation of this payment. */
    public function test_a_confirmation_for_another_amount_settles_nothing(): void
    {
        config(['gcc.storefronts.payment_accounts.confirm_returns' => true]);
        $kuwait = $this->aKuwaitPayment(amount: 12.5);
        Http::fake(['*' => Http::response(['success' => true, 'pending' => false, 'amount_cents' => 500000, 'currency' => 'KWD'], 200)]);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD'), forPayment: $kuwait)
            ->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($kuwait);
    }

    /** And a return the browser says succeeded, which the gateway says it did not, is a failure and not a settlement. */
    public function test_a_return_the_gateway_refuses_settles_nothing_even_when_the_browser_says_it_succeeded(): void
    {
        config(['gcc.storefronts.payment_accounts.confirm_returns' => true]);
        $kuwait = $this->aKuwaitPayment(amount: 12.5);
        Http::fake(['*' => Http::response(['success' => false, 'pending' => false, 'amount_cents' => 1250, 'currency' => 'KWD'], 200)]);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD'), forPayment: $kuwait)
            ->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($kuwait);
    }

    /** A pending answer is an unknown answer, not a refusal. */
    public function test_a_pending_confirmation_settles_nothing(): void
    {
        config(['gcc.storefronts.payment_accounts.confirm_returns' => true]);
        $kuwait = $this->aKuwaitPayment(amount: 12.5);
        Http::fake(['*' => Http::response(['success' => true, 'pending' => true, 'amount_cents' => 1250, 'currency' => 'KWD'], 200)]);

        $this->openTheReturn($this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD'), forPayment: $kuwait)
            ->assertRedirectContains('payment-fail');

        $this->assertWasNotPaid($kuwait);
    }

    /** D2-12's half that was already built, reused rather than rebuilt: a confirmed return settles once and a replay settles nothing further. */
    public function test_a_confirmed_return_settles_once_and_a_replay_settles_nothing_further(): void
    {
        config(['gcc.storefronts.payment_accounts.confirm_returns' => true]);
        $first = $this->aKuwaitPayment(amount: 12.5);
        $second = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: 12.5);
        $this->paymentIsAWalletTopUpOf($second);
        Http::fake(['*' => Http::response(['success' => true, 'pending' => false, 'amount_cents' => 1250, 'currency' => 'KWD'], 200)]);
        $return = $this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD');

        $this->openTheReturn($return, forPayment: $first)->assertRedirectContains('payment-success');
        $this->openTheReturn($return, forPayment: $first)->assertRedirectContains('payment-fail');
        $this->openTheReturn($return, forPayment: $second)->assertRedirectContains('payment-fail');

        $this->assertWasPaid($first);
        $this->assertWasNotPaid($second);
        $this->assertSame(1, DB::table(GatewayConfirmations::TABLE)->count(), 'one transaction of the gateway, one entry in the register');
        $this->assertSame(self::OPENING_BALANCE + 12.5, $this->walletBalanceOf($this->customer));
    }

    /** A payment of one company's account is not reachable at a gateway address for another gateway, and not at all once its account is gone. */
    public function test_the_gateway_address_refuses_a_payment_whose_account_is_not_this_gateways(): void
    {
        $kuwait = $this->aKuwaitPayment(amount: 12.5);
        $return = $this->aSignedReturn(transaction: '4455667', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD');

        DB::table('payment_gateway_accounts')->where('storefront_country', 'KW')->update(['gateway' => 'fatoorah']);
        $this->forgetWhatWasRemembered();

        $this->withSession(['payment_id' => $kuwait->id])->get($return . '&payment_id=' . $kuwait->id)->assertNotFound();
        $this->assertWasNotPaid($kuwait);

        DB::table('payment_gateway_accounts')->where('storefront_country', 'KW')->delete();
        $this->forgetWhatWasRemembered();

        $this->withSession(['payment_id' => $kuwait->id])->get($return . '&payment_id=' . $kuwait->id)->assertNotFound();
        $this->assertWasNotPaid($kuwait);
    }

    /** A Kuwait payment and a Saudi payment, each settled by its own account's secret and by no other. */
    public function test_two_companies_settle_their_own_payments_and_only_their_own(): void
    {
        $kuwait = $this->aKuwaitPayment(amount: 12.5);
        $saudi = $this->aSaudiAccountPayment(amount: 100);

        $this->openTheReturn($this->aSignedReturn(transaction: '111', amount: 12.5, secret: self::SAUDI_HMAC, currency: 'KWD'), forPayment: $kuwait)->assertRedirectContains('payment-fail');
        $this->openTheReturn($this->aSignedReturn(transaction: '222', amount: 100, secret: self::KUWAIT_HMAC, currency: 'SAR'), forPayment: $saudi)->assertRedirectContains('payment-fail');
        $this->assertWasNotPaid($kuwait);
        $this->assertWasNotPaid($saudi);

        $this->openTheReturn($this->aSignedReturn(transaction: '333', amount: 12.5, secret: self::KUWAIT_HMAC, currency: 'KWD'), forPayment: $kuwait)->assertRedirectContains('payment-success');
        $this->openTheReturn($this->aSignedReturn(transaction: '444', amount: 100, secret: self::SAUDI_HMAC, currency: 'SAR'), forPayment: $saudi)->assertRedirectContains('payment-success');
        $this->assertWasPaid($kuwait);
        $this->assertWasPaid($saudi);
        $this->assertSame(self::OPENING_BALANCE + 12.5 + 100, $this->walletBalanceOf($this->customer));
    }

    private function aKuwaitPayment(float $amount): object
    {
        if (DB::table('legal_entities')->where('country', 'KW')->doesntExist()) {
            $this->aCompanyIn('KW');
            $this->anAccountFor(country: 'KW', secrets: ['hmac' => self::KUWAIT_HMAC, 'secret_key' => 'kuwait-secret-key']);
        }

        $payment = $this->aPaymentStartedIn(country: 'KW', currency: 'KWD', amount: $amount);
        $this->paymentIsAWalletTopUpOf($payment);

        return $payment;
    }

    private function aSaudiAccountPayment(float $amount): object
    {
        $this->aCompanyIn('SA');
        $this->anAccountFor(country: 'SA', secrets: ['hmac' => self::SAUDI_HMAC, 'secret_key' => 'saudi-secret-key']);

        $payment = $this->aPaymentStartedIn(country: 'SA', currency: 'SAR', amount: $amount);
        $this->paymentIsAWalletTopUpOf($payment);

        return $payment;
    }

    /** The payment is a top-up of this test's customer, so what follows a settlement is one number to read. */
    private function paymentIsAWalletTopUpOf(object $payment): void
    {
        DB::table('payment_requests')->where('id', $payment->id)->update(['payer_id' => (string) $this->customer, 'attribute' => 'add_fund']);
    }

    private function openTheReturn(string $address, object $forPayment): TestResponse
    {
        return $this->withSession(['payment_id' => $forPayment->id])->get($address);
    }

    private function assertWasPaid(object $payment): void
    {
        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'), 'the payment that was really paid must be settled');
    }

    private function assertWasNotPaid(object $payment): void
    {
        $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'), 'a payment nobody confirmed must not be settled');
    }
}
