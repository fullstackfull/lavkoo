<?php

namespace Tests\Feature\MyFatoorah;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahRefund;
use App\Services\MyFatoorah\Money;
use App\Services\MyFatoorah\PaymentVerificationService;
use App\Services\MyFatoorah\RefundMoney;
use App\Services\MyFatoorah\RefundService;
use Illuminate\Support\Facades\Http;

/**
 * D2-10: a refund is counted in the smallest coin the currency it is paid back in actually has, and the ceiling on it is never lifted by
 * rounding. The owner approved option A on 2026-11-26.
 *
 * **The two defects, both measured before this change and both reachable.** `RefundService` rounded every refund with `round($amount, 2)`
 * and compared the headroom through {@see Money}, which works in whole halalas because the riyal has two decimals. Three of the six markets
 * price in thousandths -- 1 KWD is 1,000 fils, 1 BHD 1,000 fils, 1 OMR 1,000 baisa, which is ISO 4217 and what
 * `gcc.storefronts.currency.decimals` says -- and MyFatoorah is a Kuwaiti gateway:
 *
 *  - a refund of 10.234 KWD was sent and stored as 10.23, four fils short of what the customer was owed;
 *  - a payment of 10.005 KWD read as 10.01 of headroom, five fils MORE than was ever paid, so the platform could refund more than it took.
 *
 * The second is the one the owner named, and it is why nothing here rounds upwards: a ceiling that rounds up is not a ceiling.
 *
 * **What option A keeps out of it, and the reason it is worth a test of its own.** {@see Money} and every payment VERIFICATION path are
 * untouched. That class decides whether a payment is accepted, where an amount check answering wrongly releases an order that was
 * underpaid; widening its scale is a larger money change with its own evidence to gather. The last two tests here are the proof rather than
 * the claim -- the class still answers in halalas with the switch on, and a payment is still confirmed and still flagged on the widened
 * columns.
 *
 * **The switch ships OFF** and the first test is what that means: with `gcc.refunds.gateway_refunds_in_the_currencys_minor_unit` off, both
 * defects above are still exactly present, which is the only honest way to assert that a financial change has not been activated.
 *
 * Engine: SQLite (PHP 8.3.6) and MariaDB 10.11.14 (`phpunit.mysql.xml`, database `pharmacy_testing_money`).
 */
class ARefundIsExactInTheCurrencysMinorUnitTest extends MyFatoorahTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGateway();
    }

    // ---- the switch is off, so nothing has changed ------------------------------------------------------------------------------------

    /**
     * Off as it ships, both defects are still there, to the digit.
     *
     * A financial change that is "shipped off" and quietly behaves differently anyway is the worst of both, so this is asserted before
     * anything else and against the same figures the rest of the file corrects.
     */
    public function test_with_the_switch_off_a_refund_is_still_rounded_to_two_decimals_and_the_ceiling_still_rounds_up(): void
    {
        self::assertFalse(
            (bool) config(RefundMoney::EXACT_MINOR_UNIT_SWITCH),
            'the switch must ship off: the owner said not to activate a financial change in production',
        );

        $this->fakeRefundAccepted();
        $truncated = app(RefundService::class)->request($this->aPaidAttempt(currency: 'KWD', amount: '10.234'), 10.234);

        self::assertSame('10.23', $truncated['refund']->amount, 'the four-fils loss is unchanged while the switch is off');
        self::assertSame(10.23, $this->theLastRefundRequest()['Amount']);

        // And the over-refund: 10.005 paid still reads as 10.01 of headroom, so 10.01 is still accepted.
        $overRefundable = app(RefundService::class)->request($this->aPaidAttempt(currency: 'KWD', amount: '10.005'), 10.01);

        self::assertTrue($overRefundable['ok'], 'half a fils more than was paid is still allowed while the switch is off');
        self::assertSame('10.01', $overRefundable['refund']->amount);
    }

    public function test_with_the_switch_off_a_riyal_refund_is_what_it_always_was(): void
    {
        $this->fakeRefundAccepted();

        $result = app(RefundService::class)->request($this->aPaidAttempt(currency: 'SAR', amount: '150.00'), 150.00);

        self::assertSame('150.00', $result['refund']->amount);
        self::assertSame('SAR', $result['refund']->currency);
        self::assertSame(150.0, $this->theLastRefundRequest()['Amount']);
    }

    // ---- the exact amount, and the coin either side of it -----------------------------------------------------------------------------

    public function test_the_exact_full_amount_may_be_refunded(): void
    {
        $this->exactHandlingOn();
        $this->fakeRefundAccepted();

        $result = app(RefundService::class)->request($this->aPaidAttempt(currency: 'KWD', amount: '10.234'), '10.234');

        self::assertTrue($result['ok'], 'a refund of exactly what was paid is not an over-refund');
        self::assertSame('10.234', $result['refund']->amount);
        self::assertSame(10.234, $this->theLastRefundRequest()['Amount']);
    }

    public function test_one_fils_below_the_full_amount_is_allowed_and_one_fils_above_it_is_refused(): void
    {
        $this->exactHandlingOn();
        $this->fakeRefundAccepted();

        $below = app(RefundService::class)->request($this->aPaidAttempt(currency: 'KWD', amount: '10.234'), '10.233');

        self::assertTrue($below['ok']);
        self::assertSame('10.233', $below['refund']->amount);

        $above = app(RefundService::class)->request($this->aPaidAttempt(currency: 'KWD', amount: '10.234'), '10.235');

        self::assertFalse($above['ok'], 'one fils more than was paid is one fils of the platform\'s money');
        self::assertSame('exceeds_paid', $above['code']);
        self::assertStringContainsString('10.234', $above['error'], 'and the operator is told the real remainder, to the fils');
    }

    public function test_partial_refunds_may_add_up_to_exactly_what_was_paid_and_not_one_fils_more(): void
    {
        $this->exactHandlingOn();
        $this->fakeRefundAccepted();
        $attempt = $this->aPaidAttempt(currency: 'KWD', amount: '10.234');
        $service = app(RefundService::class);

        self::assertTrue($service->request($attempt, '3.411', idempotencyKey: 'a')['ok']);
        self::assertTrue($service->request($attempt->refresh(), '3.411', idempotencyKey: 'b')['ok']);

        // 6.822 of 10.234 asked for. The remainder is 3.412 exactly: 3.413 is refused and 3.412 is not.
        $tooMuch = $service->request($attempt->refresh(), '3.413', idempotencyKey: 'c');

        self::assertFalse($tooMuch['ok']);
        self::assertSame('exceeds_paid', $tooMuch['code']);
        self::assertStringContainsString('3.412', $tooMuch['error']);

        self::assertTrue($service->request($attempt->refresh(), '3.412', idempotencyKey: 'd')['ok']);

        // Exactly what was paid, and nothing further may be asked for.
        self::assertSame(
            '10.234',
            number_format((float) MyFatoorahRefund::query()->sum('amount'), 3, '.', ''),
            'the partial refunds total what the customer paid, to the fils',
        );
        self::assertSame('exceeds_paid', $service->request($attempt->refresh(), '0.001', idempotencyKey: 'e')['code']);
    }

    // ---- the ceiling is never lifted by rounding, which is the owner's own case -------------------------------------------------------

    /**
     * The case the owner named: 10.005 paid leaves 10.005 to refund and never 10.01.
     *
     * `round(10.005, 2)` is 10.01, so the old headroom was half a fils above what the customer ever handed over, and the platform would
     * have paid that half fils out of its own money on every such payment.
     */
    public function test_a_payment_of_ten_point_zero_zero_five_leaves_at_most_ten_point_zero_zero_five_to_refund(): void
    {
        $this->exactHandlingOn();
        $this->fakeRefundAccepted();
        $service = app(RefundService::class);

        $refused = $service->request($this->aPaidAttempt(currency: 'KWD', amount: '10.005'), 10.01);

        self::assertFalse($refused['ok'], 'rounding the ceiling up is how the platform over-refunds');
        self::assertSame('exceeds_paid', $refused['code']);
        self::assertStringContainsString('10.005', $refused['error']);
        self::assertStringNotContainsString('10.01 ', $refused['error'], 'the operator is never shown a remainder above what was paid');

        $allowed = $service->request($this->aPaidAttempt(currency: 'KWD', amount: '10.005'), '10.005');

        self::assertTrue($allowed['ok'], 'and every fils that WAS paid may still be returned');
        self::assertSame('10.005', $allowed['refund']->amount);
    }

    /**
     * A riyal payment whose column now has room for a third decimal does not gain a halala of headroom either.
     *
     * The migration widened `captured_amount` for the three-decimal markets, and a two-place currency must not inherit a ceiling rounded up
     * from a digit its own money cannot express. 10.005 SAR leaves 10.00, not 10.01.
     */
    public function test_a_two_decimal_currency_rounds_its_headroom_down_rather_than_up(): void
    {
        $this->exactHandlingOn();
        $this->fakeRefundAccepted();
        $service = app(RefundService::class);

        $refused = $service->request($this->aPaidAttempt(currency: 'SAR', amount: '10.005'), 10.01);

        self::assertFalse($refused['ok']);
        self::assertSame('exceeds_paid', $refused['code']);
        self::assertStringContainsString('10.00', $refused['error']);

        self::assertTrue($service->request($this->aPaidAttempt(currency: 'SAR', amount: '10.005'), 10.00)['ok']);
    }

    // ---- the third decimal at each end of its range -----------------------------------------------------------------------------------

    public function test_the_third_decimal_is_carried_whole_at_five_and_at_ninety_five(): void
    {
        $this->exactHandlingOn();
        $this->fakeRefundAccepted();

        $five = app(RefundService::class)->request($this->aPaidAttempt(currency: 'KWD', amount: '10.005'), '10.005');

        self::assertSame('10.005', $five['refund']->amount, 'the digit round() would have carried into the halala');
        self::assertSame(10.005, $this->theLastRefundRequest()['Amount']);

        $ninetyFive = app(RefundService::class)->request($this->aPaidAttempt(currency: 'BHD', amount: '10.995'), '10.995');

        self::assertSame('10.995', $ninetyFive['refund']->amount, 'and the digit round() would have carried into the dinar');
        self::assertSame(10.995, $this->theLastRefundRequest()['Amount']);
    }

    public function test_a_fraction_of_a_fils_is_never_rounded_up_into_one(): void
    {
        $this->exactHandlingOn();
        $this->fakeRefundAccepted();

        // 10.2349 is not an amount any Kuwaiti customer can be paid. The fraction is dropped, never carried: carrying it would hand out a
        // fils nobody asked for and could take the refund past the headroom.
        $result = app(RefundService::class)->request($this->aPaidAttempt(currency: 'KWD', amount: '10.234'), 10.2349);

        self::assertTrue($result['ok']);
        self::assertSame('10.234', $result['refund']->amount);
    }

    public function test_an_omani_rial_and_a_qatari_riyal_are_each_counted_in_their_own_coin(): void
    {
        $this->exactHandlingOn();
        $this->fakeRefundAccepted();

        $baisa = app(RefundService::class)->request($this->aPaidAttempt(currency: 'OMR', amount: '10.234'), '10.234');
        self::assertSame('10.234', $baisa['refund']->amount, 'the rial is 1,000 baisa');

        $dirham = app(RefundService::class)->request($this->aPaidAttempt(currency: 'QAR', amount: '10.23'), '10.234');
        self::assertSame('10.23', $dirham['refund']->amount, 'the Qatari riyal has two places, so nothing about it moves');
    }

    // ---- and the payment verification path did not move -------------------------------------------------------------------------------

    /**
     * {@see Money} still answers in whole halalas with the exact refund handling switched ON.
     *
     * This is the half of option A that is a promise rather than a feature: the comparison that decides whether a payment is ACCEPTED is
     * untouched, so no order is released or flagged differently than it was yesterday. The figures are the ones `MoneyTest` pins; they are
     * asserted again here, with the switch on, because a refund change that silently widened this class is exactly the failure option A was
     * chosen to avoid.
     */
    public function test_the_class_that_verifies_a_payment_still_compares_in_halalas_with_the_switch_on(): void
    {
        $this->exactHandlingOn();

        self::assertSame('1234.35', Money::format('1234.345'), 'still two decimals, as the verification messages print');
        self::assertSame('10.01', Money::format('10.005'));
        self::assertTrue(Money::equal('10.005', '10.01'), 'still the same amount to this class, which is what it has always said');
        self::assertFalse(Money::greaterThan('10.005', '10.01'));
        self::assertTrue(Money::equal('150.00', 150.0));
        self::assertFalse(Money::equal('150.00', '150.01'));
    }

    /**
     * A payment is still confirmed for the right amount and still flagged for the wrong one, on the widened columns.
     *
     * The migration changed the scale of `expected_amount` and `captured_amount`, which verification reads. Widening a decimal is lossless,
     * but "lossless" is an argument and this is a measurement.
     */
    public function test_a_payment_for_the_right_amount_is_still_confirmed_on_the_widened_columns(): void
    {
        $this->exactHandlingOn();
        $attempt = $this->anAttemptAwaitingVerification();

        Http::fake(['*/v3/payments/*' => Http::response($this->paymentDetails($attempt->external_identifier))]);

        $confirmed = app(PaymentVerificationService::class)->verify($attempt, 'PAY-1');

        self::assertSame(AttemptStatus::Paid, $confirmed['status']);
        self::assertSame('150.00', $attempt->refresh()->captured_amount, 'read back at two decimals, as the riyal has always been');
        self::assertSame('SAR', $attempt->captured_currency);
    }

    /** The case that loses money if verification ever moves: the gateway says paid, for less than was owed. */
    public function test_an_underpaid_order_is_still_flagged_and_never_released_on_the_widened_columns(): void
    {
        $this->exactHandlingOn();
        $underpaid = $this->anAttemptAwaitingVerification();

        Http::fake(['*/v3/payments/*' => Http::response(
            $this->paymentDetails($underpaid->external_identifier, amount: '1.00'),
        )]);

        $flagged = app(PaymentVerificationService::class)->verify($underpaid, 'PAY-1');

        self::assertSame(AttemptStatus::Mismatch, $flagged['status']);
        self::assertSame('amount_mismatch', $underpaid->refresh()->last_error_code);
        self::assertSame('1.00', $underpaid->captured_amount);
    }

    // ---- the world --------------------------------------------------------------------------------------------------------------------

    private function exactHandlingOn(): void
    {
        config([RefundMoney::EXACT_MINOR_UNIT_SWITCH => true]);
    }

    /** A verified, paid attempt for one amount in one currency — what a refund is asked against. */
    private function aPaidAttempt(string $currency, string $amount): MyFatoorahPaymentAttempt
    {
        $identifier = 'pk-paid-' . strtolower($currency) . '-' . str_replace('.', '-', $amount) . '-' . MyFatoorahPaymentAttempt::count();

        return MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->makePaymentRequest(),
            'attempt_number' => 1,
            'external_identifier' => $identifier,
            'expected_amount' => $amount,
            'expected_currency' => $currency,
            'captured_amount' => $amount,
            'captured_currency' => $currency,
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
            'payment_id' => 'PAY-' . $identifier,
            'invoice_id' => 'INV-1',
            'verified_at' => now(),
            'settled_at' => now(),
            'hook_completed_at' => now(),
        ]);
    }

    /** An attempt the customer has been sent to the gateway for, which is the state verification is asked about. */
    private function anAttemptAwaitingVerification(string $externalIdentifier = 'pk-verify-1'): MyFatoorahPaymentAttempt
    {
        return MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->makePaymentRequest(),
            'attempt_number' => 1,
            'external_identifier' => $externalIdentifier,
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'status' => AttemptStatus::Redirected->value,
            'mode' => 'test',
            'invoice_id' => 'INV-1',
        ]);
    }

    private function fakeRefundAccepted(): void
    {
        Http::fake(['*/v3/refunds' => Http::response([
            'IsSuccess' => true,
            'Message' => 'Refund Created Successfully!',
            'Data' => ['Refund' => ['Id' => 'RF-1', 'Reference' => '2026000012', 'Status' => 'Pending', 'InvoiceId' => 'INV-REFUND-1']],
        ], 201)]);
    }

    private function theLastRefundRequest(): \Illuminate\Http\Client\Request
    {
        $sent = Http::recorded(static fn ($request): bool => str_contains($request->url(), '/v3/refunds'))
            ->map(static fn (array $exchange) => $exchange[0])
            ->values()
            ->all();

        self::assertNotEmpty($sent, 'a refund request left the server');

        return $sent[count($sent) - 1];
    }
}
