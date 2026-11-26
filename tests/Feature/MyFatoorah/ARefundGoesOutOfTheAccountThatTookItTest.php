<?php

namespace Tests\Feature\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahRefund;
use App\Models\PaymentGatewayAccount;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\MyFatoorah\RefundService;
use App\Services\Payments\GatewayAccountSecrets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * D2-15's last criterion: a refund is sent through the gateway account and the currency that took the payment.
 *
 * **What was wrong, and it was the one reader of the stamp that was missing.** Everything else about a company's gateway account was
 * wired -- a payment is started, verified and settled through the account it was stamped with -- and
 * {@see \App\Services\Payments\PaymentAccountBinding} recorded in its own docblock that the refund was not: it went to the single
 * platform-wide `addon_settings` row. So company A's customer would have been refunded out of the platform merchant account: money
 * leaving the wrong company's balance, under the wrong trading licence, in a statement the company that took the payment cannot
 * reconcile. On this gateway the key is also what tells the provider WHICH merchant to refund from, so it is not bookkeeping.
 *
 * **The refusal is the half worth reading.** Where this platform cannot authenticate as the account that took the payment -- the account
 * row has been deleted, or holds no key -- the refund is REFUSED. It is the opposite of every other fallback in this family, and
 * deliberately: falling back to the platform row is exactly the defect. The headroom is not consumed either, so an operator is not left
 * with a reservation they cannot send.
 *
 * **What does NOT change, which is every payment on this platform today.** A payment with no account stamp was taken through the platform
 * settings row, so that row is the right answer for it and nothing moves: the first test asserts a Saudi refund is byte-identical, request
 * and stored row.
 *
 * Nothing here adds this gateway to `gcc.storefronts.payment_accounts.gateways`. The config's own comment says MyFatoorah joins that list
 * "in the same change that makes MyFatoorah read its api_key from the account", and this change makes the REFUND read it while initiation
 * and verification still read the platform row -- so listing it now would start a payment on one key and refund it on another, which is
 * worse than either. That is a finding for the owner and is recorded in the config beside the list.
 *
 * Engine: SQLite (PHP 8.3.6) and MariaDB 10.11.14 (`phpunit.mysql.xml`, database `pharmacy_testing_id`).
 */
class ARefundGoesOutOfTheAccountThatTookItTest extends CompanyAccountsTestCase
{
    private const PAID_AMOUNT = '150.00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
        $this->installTheCompanyAndAccountTables();
    }

    protected function tearDown(): void
    {
        $this->dropTheCompanyAndAccountTables();

        parent::tearDown();
    }

    // ---- nothing changes for a payment that was taken through the platform row ---------------------------------------------------------

    public function test_a_payment_with_no_account_stamp_is_refunded_through_the_platform_row_exactly_as_before(): void
    {
        $attempt = $this->aPaidAttempt();
        $this->fakeRefundAccepted();

        $result = app(RefundService::class)->request($attempt, 150.00, 'Customer returned the order');

        self::assertTrue($result['ok']);
        self::assertSame(MyFatoorahRefund::STATUS_PENDING, $result['refund']->status);
        self::assertSame('150.00', $result['refund']->amount);

        $sent = $this->theOneRefundRequest();
        self::assertSame('https://apitest.myfatoorah.com/v3/refunds', $sent->url(), 'the platform host for the attempt\'s own mode');
        self::assertSame('Bearer test-api-key', $sent->header('Authorization')[0] ?? '', 'the platform key, as every refund today is sent with');
        self::assertSame('PAY-1', $sent['PaymentId']);
        self::assertSame(150.0, $sent['Amount']);
    }

    public function test_the_gateway_ships_unlisted_so_no_payment_of_it_is_stamped_at_all(): void
    {
        self::assertNotContains(
            GatewayConfigRepository::KEY,
            $this->shippedGatewayList,
            'this change makes the REFUND read the account; initiation and verification still read the platform row, so listing the'
            . ' gateway would start a payment on one key and refund it on another',
        );
    }

    // ---- a company's refund leaves that company's account ------------------------------------------------------------------------------

    public function test_a_kuwaiti_payment_is_refunded_with_the_kuwaiti_accounts_own_key_and_never_the_platforms(): void
    {
        $this->turnCompanyAccountsOn();
        $account = $this->anAccountWithAnApiKey(country: 'KW', apiKey: 'kuwait-merchant-key');
        $attempt = $this->aPaidAttemptStampedWith($account);
        $this->fakeRefundAccepted();

        $result = app(RefundService::class)->request($attempt, 150.00);

        self::assertTrue($result['ok'], 'the refund was sent');

        $sent = $this->theOneRefundRequest();
        self::assertSame('Bearer kuwait-merchant-key', $sent->header('Authorization')[0] ?? '');
        self::assertStringNotContainsString(
            'test-api-key',
            (string) json_encode($sent->headers(), JSON_THROW_ON_ERROR),
            'the platform key was not sent, not even beside the account\'s',
        );
    }

    public function test_the_account_the_payment_was_taken_through_is_used_after_it_has_been_re_pointed(): void
    {
        $this->turnCompanyAccountsOn();
        $account = $this->anAccountWithAnApiKey(country: 'KW', apiKey: 'kuwait-merchant-key');
        $attempt = $this->aPaidAttemptStampedWith($account);

        // The registry is changed after the payment: the Kuwaiti account is disabled and another market's becomes the only active one.
        // A refund resolved fresh would find the Emirati account or nothing; the stamp is what makes it find the right one.
        $this->anAccountWithAnApiKey(country: 'AE', apiKey: 'emirati-merchant-key');
        $account->forceFill(['status' => false])->save();
        $this->flushTheRegistries();

        $this->fakeRefundAccepted();
        self::assertTrue(app(RefundService::class)->request($attempt, 20.00)['ok']);

        self::assertSame('Bearer kuwait-merchant-key', $this->theOneRefundRequest()->header('Authorization')[0] ?? '');
    }

    // ---- where it cannot answer, the refund is refused ---------------------------------------------------------------------------------

    public function test_a_payment_whose_account_row_is_gone_is_refused_rather_than_refunded_out_of_the_platform_account(): void
    {
        $this->turnCompanyAccountsOn();
        $account = $this->anAccountWithAnApiKey(country: 'KW', apiKey: 'kuwait-merchant-key');
        $attempt = $this->aPaidAttemptStampedWith($account);

        PaymentGatewayAccount::query()->whereKey($account->id)->delete();
        $this->flushTheRegistries();

        Http::fake();
        $result = app(RefundService::class)->request($attempt, 150.00);

        self::assertFalse($result['ok']);
        self::assertSame('account_gone', $result['code']);
        Http::assertNothingSent();
        self::assertSame(0, MyFatoorahRefund::query()->count(), 'and the headroom is not consumed by a refund that cannot be sent');
    }

    public function test_an_account_that_holds_no_key_refuses_the_refund_instead_of_falling_back(): void
    {
        $this->turnCompanyAccountsOn();
        $account = $this->anAccountFor(country: 'KW', webhookSecret: 'kw-hook', token: 'kw-token');
        $attempt = $this->aPaidAttemptStampedWith($account);

        Http::fake();
        $result = app(RefundService::class)->request($attempt, 150.00);

        self::assertFalse($result['ok']);
        self::assertSame('account_has_no_key', $result['code']);
        self::assertStringContainsString((string) $account->id, $result['error'], 'the operator is told which account to fix');
        Http::assertNothingSent();
    }

    public function test_reading_a_refunds_state_again_goes_to_the_same_account(): void
    {
        $this->turnCompanyAccountsOn();
        $account = $this->anAccountWithAnApiKey(country: 'KW', apiKey: 'kuwait-merchant-key');
        $attempt = $this->aPaidAttemptStampedWith($account);
        $this->fakeRefundAccepted();
        $refund = app(RefundService::class)->request($attempt, 150.00)['refund'];

        Http::fake(['*' => Http::response($this->paymentDetails(externalIdentifier: 'pk-paid-attempt-1'))]);
        app(RefundService::class)->refreshStatus($refund);

        // A merchant's invoice is not readable with another merchant's key, so reading it with the platform's would answer "no such
        // payment" about a payment that exists -- and this path is what decides whether a refund is still pending.
        Http::assertSent(static fn ($request): bool => ($request->header('Authorization')[0] ?? '') === 'Bearer kuwait-merchant-key');
    }

    // ---- and the currency that took the payment ----------------------------------------------------------------------------------------

    public function test_the_refund_is_recorded_in_the_currency_the_payment_was_captured_in_and_names_no_other(): void
    {
        $this->turnCompanyAccountsOn();
        $account = $this->anAccountWithAnApiKey(country: 'KW', apiKey: 'kuwait-merchant-key');
        $attempt = $this->aPaidAttemptStampedWith($account, capturedCurrency: 'KWD', expectedCurrency: 'SAR');
        $this->fakeRefundAccepted();

        $refund = app(RefundService::class)->request($attempt, 10.00)['refund'];

        self::assertSame('KWD', $refund->currency, 'what the gateway CAPTURED, not what the attempt asked for');

        // The request names the payment and no currency of its own, which is the other half of "in the currency that took the payment":
        // the provider refunds the invoice it is given, so there is no field through which a refund could leave in another currency.
        $sent = $this->theOneRefundRequest();
        self::assertSame(['PaymentId', 'Amount', 'ExternalIdentifier', 'Comment', 'ServiceChargeOnCustomer'], array_keys($sent->data()));
    }

    /**
     * A FINDING, pinned rather than fixed: a three-decimal currency loses its last digit on this path.
     *
     * KWD, BHD and OMR have three decimal places and MyFatoorah is a Kuwaiti gateway, so this is reachable rather than theoretical.
     * `RefundService::request()` does `round($amount, 2)` and stores `number_format($amount, 2)`, and the headroom is compared by
     * {@see \App\Services\MyFatoorah\Money}, whose own docblock says it works "in whole halalas" because "SAR has two decimal places".
     * So a refund of 10.234 KWD is sent and recorded as 10.23 -- four fils the customer is owed and does not get -- and a payment of
     * 10.005 reads as 10.01 of headroom, which is five fils MORE than was paid.
     *
     * This is D2-10's own criterion ("amounts are stored and compared in each currency's minor unit") and it is not fixed here: the
     * comparison class is shared with the payment VERIFICATION path, where an amount check deciding wrongly releases an underpaid order,
     * so changing its scale is a money change of its own with its own evidence. Characterised and NOT endorsed.
     */
    public function test_a_three_decimal_refund_is_truncated_to_two_which_is_a_defect_and_not_the_behaviour_wanted(): void
    {
        $this->turnCompanyAccountsOn();
        $account = $this->anAccountWithAnApiKey(country: 'KW', apiKey: 'kuwait-merchant-key');
        $attempt = $this->aPaidAttemptStampedWith($account, capturedCurrency: 'KWD', expectedCurrency: 'KWD');
        $this->fakeRefundAccepted();

        $refund = app(RefundService::class)->request($attempt, 10.234)['refund'];

        self::assertSame('10.23', $refund->amount, 'four fils of a Kuwaiti customer\'s refund are rounded away');
        self::assertSame(10.23, $this->theOneRefundRequest()['Amount'], 'and the provider is asked for the truncated figure');
    }

    // ---- the world ---------------------------------------------------------------------------------------------------------------------

    private function aPaidAttempt(array $overrides = [], array $paymentOverrides = []): MyFatoorahPaymentAttempt
    {
        return MyFatoorahPaymentAttempt::create(array_merge([
            'payment_request_id' => $this->makePaymentRequest($paymentOverrides),
            'attempt_number' => 1,
            'external_identifier' => 'pk-paid-attempt-1',
            'expected_amount' => self::PAID_AMOUNT,
            'expected_currency' => 'SAR',
            'captured_amount' => self::PAID_AMOUNT,
            'captured_currency' => 'SAR',
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
            'payment_id' => 'PAY-1',
            'invoice_id' => 'INV-1',
            'verified_at' => now(),
            'settled_at' => now(),
            'hook_completed_at' => now(),
        ], $overrides));
    }

    /** A paid attempt whose payment carries the stamp the production writer produces for this account's market. */
    private function aPaidAttemptStampedWith(
        PaymentGatewayAccount $account,
        string $capturedCurrency = 'SAR',
        string $expectedCurrency = 'SAR',
    ): MyFatoorahPaymentAttempt {
        return $this->aPaidAttempt(
            overrides: ['captured_currency' => $capturedCurrency, 'expected_currency' => $expectedCurrency],
            paymentOverrides: ['additional_data' => $this->stampedForTheMarketOf($account)],
        );
    }

    private function anAccountWithAnApiKey(string $country, string $apiKey): PaymentGatewayAccount
    {
        $account = $this->anAccountFor(country: $country, webhookSecret: $country . '-hook', token: strtolower($country) . '-token');

        app(GatewayAccountSecrets::class)->put(account: $account, secrets: ['api_key' => $apiKey]);
        $this->flushTheRegistries();

        return $account->refresh();
    }

    private function flushTheRegistries(): void
    {
        app(\App\Services\Storefront\LegalEntityRegistry::class)->flush();
        app(\App\Services\Payments\GatewayAccountRegistry::class)->flush();
    }

    private function theOneRefundRequest(): \Illuminate\Http\Client\Request
    {
        $sent = Http::recorded(static fn ($request): bool => str_contains($request->url(), '/v3/refunds'))
            ->map(static fn (array $exchange) => $exchange[0])
            ->values()
            ->all();

        self::assertCount(1, $sent, 'exactly one refund request left the server');

        return $sent[0];
    }

    private function fakeRefundAccepted(string $refundId = 'RF-1'): void
    {
        Http::fake(['*/v3/refunds' => Http::response([
            'IsSuccess' => true,
            'Message' => 'Refund Created Successfully!',
            'Data' => ['Refund' => ['Id' => $refundId, 'Reference' => '2026000012', 'Status' => 'Pending', 'InvoiceId' => 'INV-REFUND-1']],
        ], 201)]);
    }
}
