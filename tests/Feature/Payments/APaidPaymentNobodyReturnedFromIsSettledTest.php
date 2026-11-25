<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\UnsettledPaymentSweep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BuildsWalletTopUpWorld;
use Tests\TestCase;

/**
 * A payment Paymob took and no browser ever came back from is found by the server and settled (D2-12, D2-16).
 *
 * Every Paymob payment on this platform is settled by the CUSTOMER'S BROWSER returning, and the callback
 * reads `session('payment_id')` to know which payment it is about. So a customer who paid and then closed
 * the tab, lost signal, or whose session had expired left the money with Paymob, the payment row unpaid
 * and the order or wallet credit never made -- and nothing on the platform noticed. That is what these
 * cases are about, and the thing being proved is not "the service works" but that the MONEY ARRIVES: the
 * hook a browser return would have run is run, so the wallet is credited, and the figure is read out of
 * the customer's balance.
 *
 * The sweep asks with the only reference the shop is sure to hold -- the payment's own id, which the
 * payment start sends Paymob as `special_reference` for exactly this reason. Paymob is faked at the HTTP
 * boundary and nowhere deeper, so the answer every case gives is the shape of a real answer.
 */
class APaidPaymentNobodyReturnedFromIsSettledTest extends TestCase
{
    use BuildsWalletTopUpWorld;

    /**
     * The Saudi region's host, which is the one this platform is configured for -- pinned here rather than
     * left to the environment, because a fake that does not match the configured host turns every case
     * into a stray-request error instead of an answer, which is how this test first ran.
     */
    private const HOST = 'https://ksa.paymob.com';

    private const INQUIRY = 'ksa.paymob.com/api/ecommerce/orders/transaction_inquiry';

    /** Paymob's own transaction id, which is what the confirmation register is keyed on and never our reference. */
    private const PAYMOBS_REFERENCE = '998877';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->buildWalletTopUpWorld();
        $this->installTheTopUpRegister();
        $this->installTheConfirmationRegister();
        $this->thePlatformPaymobRow();

        config([
            'gcc.storefronts.payment_accounts.inquiry.paymob_host' => self::HOST,
            'gcc.payments.recovery_sweep.enabled' => true,
            'gcc.payments.recovery_sweep.minimum_age_minutes' => 15,
            'gcc.payments.recovery_sweep.oldest_days' => 7,
        ]);
    }

    /**
     * The one platform-wide gateway row, which is the credential every Paymob payment on this platform is
     * actually taken with: no company account exists, and the sweep has to be able to ask with this.
     */
    private function thePlatformPaymobRow(string $mode = 'test'): void
    {
        DB::table('addon_settings')->updateOrInsert(['key_name' => 'paymob_accept', 'settings_type' => 'payment_config'], [
            'id' => (string) Str::uuid(),
            'mode' => $mode,
            'is_active' => 1,
            'live_values' => json_encode(['secret_key' => 'sk_live_platform', 'integration_id' => '1', 'public_key' => 'pk_live']),
            'test_values' => json_encode(['secret_key' => 'sk_test_platform', 'integration_id' => '1', 'public_key' => 'pk_test']),
        ]);
    }

    /** A top-up handed to Paymob and never heard of again: unpaid, and old enough that nobody is still at the gateway. */
    private function aPaymentNobodyReturnedFrom(int $customer, float $amount = 100.0, int $minutesAgo = 30): string
    {
        $id = (string) Str::uuid();
        DB::table('payment_requests')->insert([
            'id' => $id, 'payer_id' => (string) $customer, 'payment_amount' => $amount, 'currency_code' => 'SAR',
            'payment_method' => 'paymob_accept', 'success_hook' => 'add_fund_to_wallet_success',
            'failure_hook' => 'add_fund_to_wallet_fail', 'additional_data' => json_encode(['payment_mode' => 'web']),
            'attribute' => 'add_fund', 'payment_platform' => 'web', 'is_paid' => false,
            'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function paymobSays(array $overrides = []): void
    {
        Http::fake([self::INQUIRY => Http::response($overrides + [
            'id' => self::PAYMOBS_REFERENCE,
            'pending' => false,
            'success' => true,
            'amount_cents' => 10000,
            'currency' => 'SAR',
        ])]);
    }

    private function paymobAnswers(int $status): void
    {
        Http::fake([self::INQUIRY => Http::response(status: $status)]);
    }

    private function theSweep(): UnsettledPaymentSweep
    {
        return app(UnsettledPaymentSweep::class);
    }

    /** @return array{is_paid: int, transaction_id: ?string} */
    private function thePaymentRow(string $id): array
    {
        $row = DB::table('payment_requests')->where('id', $id)->first(['is_paid', 'transaction_id']);

        return ['is_paid' => (int) $row->is_paid, 'transaction_id' => $row->transaction_id];
    }

    // ---------------------------------------------------------------- the money arrives

    public function test_a_paid_payment_is_settled_and_the_wallet_is_credited(): void
    {
        $customer = $this->aCustomer();
        $payment = $this->aPaymentNobodyReturnedFrom($customer);
        $this->paymobSays();

        $found = $this->theSweep()->sweep();

        $this->assertSame(1, $found['settled'], 'the sweep settled nothing');
        $this->assertSame(['is_paid' => 1, 'transaction_id' => self::PAYMOBS_REFERENCE], $this->thePaymentRow($payment));
        $this->assertSame(100.0, $this->walletBalanceOf($customer), 'the payment was marked paid and the money never reached the customer');
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $customer)->count());
    }

    /** The register holds PAYMOB's reference, which is what one-transaction-settles-one-payment is keyed on. */
    public function test_the_confirmation_register_holds_the_gateways_own_reference(): void
    {
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer());
        $this->paymobSays();

        $this->theSweep()->sweep();

        $this->assertSame(1, DB::table('gateway_confirmations')
            ->where(['gateway' => 'paymob_accept', 'reference' => self::PAYMOBS_REFERENCE, 'payment_request_id' => $payment])
            ->count());
    }

    /** Two runs are one credit. A sweep that could pay twice would be worse than one that never ran. */
    public function test_a_second_run_settles_nothing_and_credits_nothing_further(): void
    {
        $customer = $this->aCustomer();
        $this->aPaymentNobodyReturnedFrom($customer);
        $this->paymobSays();

        $this->theSweep()->sweep();
        $second = $this->theSweep()->sweep();

        $this->assertSame(0, $second['considered'], 'a settled payment was considered again');
        $this->assertSame(100.0, $this->walletBalanceOf($customer), 'the second run credited the wallet again');
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $customer)->count());
    }

    // ---------------------------------------------------------------- what it refuses to do

    public function test_it_does_nothing_at_all_while_it_is_switched_off(): void
    {
        config(['gcc.payments.recovery_sweep.enabled' => false]);
        $customer = $this->aCustomer();
        $payment = $this->aPaymentNobodyReturnedFrom($customer);
        // No fake and strays are prevented, so asking Paymob at all would fail this test outright.

        $found = $this->theSweep()->sweep();

        $this->assertSame(0, $found['considered']);
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
        $this->assertSame(0.0, $this->walletBalanceOf($customer));
    }

    public function test_a_dry_run_asks_the_gateway_and_writes_nothing(): void
    {
        $customer = $this->aCustomer();
        $payment = $this->aPaymentNobodyReturnedFrom($customer);
        $this->paymobSays();

        $found = $this->theSweep()->sweep(dryRun: true);

        $this->assertSame(1, $found['settled'], 'the dry run did not report what it would have settled');
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
        $this->assertSame(0.0, $this->walletBalanceOf($customer));
        $this->assertSame(0, DB::table('gateway_confirmations')->count());
    }

    /**
     * A refusal is reported and the row is left exactly as it was. The failure hook is NOT run: moving an
     * order's state from a sweep, with no customer present and on the strength of one answer, is a worse
     * mistake than leaving a never-paid payment in the state it is already in.
     */
    public function test_a_payment_paymob_says_it_did_not_take_is_reported_and_left_alone(): void
    {
        $customer = $this->aCustomer();
        $payment = $this->aPaymentNobodyReturnedFrom($customer);
        $this->paymobSays(['success' => false]);

        $found = $this->theSweep()->sweep();

        $this->assertSame(1, $found['refused']);
        $this->assertSame(0, $found['settled']);
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
        $this->assertSame(0.0, $this->walletBalanceOf($customer));
    }

    public function test_a_transaction_paymob_is_still_deciding_settles_nothing(): void
    {
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer());
        $this->paymobSays(['pending' => true]);

        $found = $this->theSweep()->sweep();

        $this->assertSame(1, $found['unknown']);
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
    }

    /** 404 is Paymob saying it has no transaction of this reference, which is the ordinary answer for a customer who abandoned before paying. */
    public function test_a_payment_paymob_has_never_heard_of_settles_nothing(): void
    {
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer());
        $this->paymobAnswers(404);

        $found = $this->theSweep()->sweep();

        $this->assertSame(1, $found['unknown']);
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
    }

    /**
     * THE GUARD THAT MATTERS MOST. What Paymob answers about is its own transaction; that it is this
     * payment's is a claim, and an amount that is not the payment's means it belongs to another payment.
     */
    public function test_an_answer_for_another_amount_settles_nothing(): void
    {
        $customer = $this->aCustomer();
        $payment = $this->aPaymentNobodyReturnedFrom($customer, amount: 100.0);
        $this->paymobSays(['amount_cents' => 25000]);

        $found = $this->theSweep()->sweep();

        $this->assertSame(0, $found['settled']);
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
        $this->assertSame(0.0, $this->walletBalanceOf($customer));
    }

    public function test_an_answer_in_another_currency_settles_nothing(): void
    {
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer());
        $this->paymobSays(['currency' => 'AED']);

        $this->assertSame(0, $this->theSweep()->sweep()['settled']);
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
    }

    // ---------------------------------------------------------------- which payments it looks at

    /** A customer at the gateway right now is not an abandoned payment, and settling one under them would have the shop and the browser settling at once. */
    public function test_a_payment_started_minutes_ago_is_left_alone(): void
    {
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer(), minutesAgo: 5);

        $found = $this->theSweep()->sweep();

        $this->assertSame(0, $found['considered'], 'a payment a customer may still be paying was swept');
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
    }

    /** Beyond the window a payment is a reconciliation question for a person, not an order to be created now. */
    public function test_a_payment_older_than_the_window_is_left_alone(): void
    {
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer(), minutesAgo: 60 * 24 * 30);

        $found = $this->theSweep()->sweep();

        $this->assertSame(0, $found['considered']);
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
    }

    public function test_another_gateways_unpaid_payment_is_not_asked_about(): void
    {
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer());
        DB::table('payment_requests')->where('id', $payment)->update(['payment_method' => 'fatoorah']);

        $this->assertSame(0, $this->theSweep()->sweep()['considered']);
    }

    /** A gateway row the sweep cannot read a key out of is reported as unaskable, never guessed at. */
    public function test_a_platform_row_with_no_usable_mode_cannot_be_asked_with(): void
    {
        $this->thePlatformPaymobRow(mode: 'neither');
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer());

        $found = $this->theSweep()->sweep();

        $this->assertSame(1, $found['unaskable']);
        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
    }

    // ---------------------------------------------------------------- the command

    public function test_the_command_says_so_and_stops_while_the_sweep_is_off(): void
    {
        config(['gcc.payments.recovery_sweep.enabled' => false]);
        $payment = $this->aPaymentNobodyReturnedFrom($this->aCustomer());

        $this->artisan('payments:sweep-unsettled')->assertExitCode(0);

        $this->assertSame(['is_paid' => 0, 'transaction_id' => null], $this->thePaymentRow($payment));
    }

    public function test_the_command_settles_through_the_same_path(): void
    {
        $customer = $this->aCustomer();
        $payment = $this->aPaymentNobodyReturnedFrom($customer);
        $this->paymobSays();

        $this->artisan('payments:sweep-unsettled')->assertExitCode(0);

        $this->assertSame(['is_paid' => 1, 'transaction_id' => self::PAYMOBS_REFERENCE], $this->thePaymentRow($payment));
        $this->assertSame(100.0, $this->walletBalanceOf($customer));
    }
}
