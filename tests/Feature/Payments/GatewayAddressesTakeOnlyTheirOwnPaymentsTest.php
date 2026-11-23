<?php

namespace Tests\Feature\Payments;

use App\Events\AddFundToWalletEvent;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\EnsureGatewayTakesThisPayment;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWalletTopUpWorld;
use Tests\TestCase;

/**
 * An address of a payment gateway confirms nothing for a gateway the shop has not switched on, and touches only the payments
 * that were started with that gateway.
 *
 * The addresses of every gateway the code base knows are registered whether the shop uses the gateway or not. Two of them
 * (LiqPay and SenangPay) confirm a payment on the word of the request: one request with `status=success` marked any payment as
 * paid and ran what follows a payment -- a wallet credit here, an order elsewhere -- for a payment nobody made, through a
 * gateway that was not even configured.
 *
 * A payment into the wallet is the payment used here because what follows it is one number to read. The requests are the
 * real ones over the real routes; nothing is sent to any gateway.
 */
class GatewayAddressesTakeOnlyTheirOwnPaymentsTest extends TestCase
{
    use BuildsWalletTopUpWorld;

    private const OPENING_BALANCE = 10.0;

    /** Gateways whose addresses stand in the same group and settle through a verification of their own. */
    private const VERIFY_THEIR_OWN_CONFIRMATIONS = ['myfatoorah', 'fatoorah'];

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->customer = $this->aCustomer(balance: self::OPENING_BALANCE);
        $this->withoutMiddleware([
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class,
            RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
        Event::fake([AddFundToWalletEvent::class]);
        Http::preventStrayRequests();
    }

    public function test_a_gateway_that_is_not_switched_on_confirms_no_payment_on_the_word_of_a_request(): void
    {
        $payment = $this->anUnpaidTopUpStartedWith(gateway: 'liqpay', amount: 5000);

        $this->get('/payment/liqpay/callback?status=success&payment_id=' . $payment->id)->assertNotFound();

        $this->assertNothingWasPaid($payment);
    }

    public function test_a_gateway_that_takes_its_payment_from_the_session_confirms_none_while_it_is_not_switched_on(): void
    {
        $payment = $this->anUnpaidTopUpStartedWith(gateway: 'senang_pay', amount: 5000);

        $this->get('/payment/senang-pay/pay?payment_id=' . $payment->id)->assertNotFound();
        $this->withSession(['payment_id' => $payment->id])->get('/payment/senang-pay/callback?status_id=1')->assertNotFound();

        $this->assertNothingWasPaid($payment);
    }

    /** @return array<string, array{string}> the gateway a payment was started with */
    public static function anotherGateway(): array
    {
        return ['the gateway the shop uses' => ['paymob_accept'], 'a gateway that is not switched on either' => ['paytabs']];
    }

    #[DataProvider('anotherGateway')]
    public function test_a_gateway_that_is_switched_on_does_not_confirm_a_payment_that_was_started_with_another(string $startedWith): void
    {
        $this->switchOn(gateway: 'liqpay');
        $payment = $this->anUnpaidTopUpStartedWith(gateway: $startedWith, amount: 5000);

        $this->get('/payment/liqpay/callback?status=success&payment_id=' . $payment->id)->assertNotFound();

        $this->assertNothingWasPaid($payment);
    }

    /**
     * @return array<string, array{mixed}> things sent in the place of a payment id. On MySQL, which the application runs without
     *         strict mode, the number 0 compared with an id that starts with a letter is a match for every such payment.
     */
    public static function notAPaymentId(): array
    {
        return ['the number zero' => [0], 'a number' => [7], 'a list' => [['a']], 'text that is no id' => ['not-an-id']];
    }

    #[DataProvider('notAPaymentId')]
    public function test_a_payment_is_named_by_its_id_and_by_nothing_else(mixed $sent): void
    {
        $this->switchOn(gateway: 'liqpay');
        $payment = $this->anUnpaidTopUpStartedWith(gateway: 'liqpay', amount: 5000);

        $this->call('GET', '/payment/liqpay/callback', ['status' => 'success', 'payment_id' => $sent])->assertNotFound();

        $this->assertNothingWasPaid($payment);
    }

    /** The guard stands in front of a gateway and must not stand in its way: the shop's own gateway still settles its own payment. */
    public function test_a_gateway_that_is_switched_on_still_settles_a_payment_that_was_started_with_it(): void
    {
        $secret = $this->switchOn(gateway: 'paymob_accept', values: ['hmac' => 'the-signing-secret-of-the-test', 'supported_country' => 'KSA'])['hmac'];
        $payment = $this->anUnpaidTopUpStartedWith(gateway: 'paymob_accept', amount: 100);
        $result = ['amount_cents' => '10000', 'currency' => 'SAR', 'id' => '4455667', 'order' => '998877', 'pending' => 'false', 'success' => 'true'];
        ksort($result);

        $this->withSession(['payment_id' => $payment->id])
            ->get('/payment/paymob/callback?' . http_build_query($result + ['hmac' => hash_hmac('sha512', implode('', $result), $secret)]))
            ->assertRedirectContains('payment-success');

        $this->assertSame(1, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'));
        $this->assertSame(self::OPENING_BALANCE + 100, $this->walletBalanceOf($this->customer));
    }

    /**
     * Every group of addresses under /payment is either answered for by the guard or named above as verifying its own
     * confirmations. A gateway added later is neither, and fails here until somebody decides which it is.
     */
    public function test_every_gateway_under_payment_is_guarded_or_named_as_verifying_itself(): void
    {
        $families = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $segments = explode('/', $route->uri());
            if ($segments[0] === 'payment' && isset($segments[1])) {
                $families[$segments[1]] = in_array(EnsureGatewayTakesThisPayment::class, $route->gatherMiddleware(), true);
            }
        }

        $this->assertNotEmpty($families, 'the scan must see the gateway addresses');
        $this->assertSame([], array_keys(array_filter($families, static fn (bool $guarded): bool => !$guarded)), 'a gateway address stands outside the guard');
        $unknown = array_diff(array_keys($families), array_keys(EnsureGatewayTakesThisPayment::GATEWAY_OF_ADDRESS), self::VERIFY_THEIR_OWN_CONFIRMATIONS);
        $this->assertSame([], array_values($unknown), 'a gateway under /payment is neither answered for by the guard nor named as verifying its own confirmations');
    }

    private function assertNothingWasPaid(PaymentRequest $payment): void
    {
        $this->assertSame(0, (int) DB::table('payment_requests')->where('id', $payment->id)->value('is_paid'), 'a payment nobody made was marked as paid');
        $this->assertSame(self::OPENING_BALANCE, $this->walletBalanceOf($this->customer), 'a payment nobody made was credited to the wallet');
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    private function anUnpaidTopUpStartedWith(string $gateway, float $amount): PaymentRequest
    {
        $payment = $this->aPaidTopUp(customer: $this->customer, amount: $amount);
        DB::table('payment_requests')->where('id', $payment->id)->update(['is_paid' => 0, 'payment_method' => $gateway]);

        return $payment->refresh();
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function switchOn(string $gateway, array $values = []): array
    {
        DB::table('addon_settings')->insert([
            'id' => "{$gateway}-of-the-test", 'key_name' => $gateway, 'settings_type' => 'payment_config', 'mode' => 'test', 'is_active' => 1,
            'test_values' => json_encode($values), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $values;
    }
}
