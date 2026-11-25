<?php

namespace Tests\Feature\Refund;

use App\Enums\MyFatoorah\AttemptStatus;
use App\Http\Controllers\Admin\MyFatoorah\SettingsController;
use App\Models\MyFatoorahPaymentAttempt;
use App\Models\MyFatoorahRefund;
use App\Services\MyFatoorah\GatewayConfigRepository;
use App\Services\Refund\GatewayRefundLink;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMyFatoorahSchema;
use Tests\TestCase;

/**
 * D2-15: a refund sent through the gateway is linked to the ORDER and to the REFUND REQUEST it is for.
 *
 * `MyFatoorah\RefundService::request()` has taken `$orderId` and `$refundRequestId` since it was written, and `myfatoorah_refunds` has
 * had the columns for them just as long. Its one caller -- the gateway screen's refund button -- passed NEITHER. So every refund this
 * platform has ever sent through a gateway was recorded against a payment attempt and against nothing a customer, an accountant or a
 * seller could find it by, which is a problem on the one money movement somebody always comes back to ask about.
 *
 * Both links are now worked out by `App\Services\Refund\GatewayRefundLink`, and what this file mostly pins is WHERE IT ANSWERS NOTHING,
 * because that is the half that matters on a refund:
 *
 *   - a payment that produced SEVERAL orders -- a multi-vendor basket is one payment and one order per vendor -- records no order, since
 *     a single column cannot say "these three" and naming one of three files the refund against an arbitrary vendor's sale;
 *   - an amount that matches TWO returned lines of the same order records no refund request;
 *   - a line that was never agreed (a request still `pending`, or denied) is not treated as the one being paid back.
 *
 * And the ledger half of the criterion is asserted as a NEGATIVE: the gateway send must not move the vendor or commission ledger, because
 * the refund DECISION already did, under its own claim. A gateway refund that reversed the commission as well would reverse one refund
 * twice. The test drives the real controller action, not the service, because the whole defect was in the caller.
 *
 * TEETH, each mutation applied and restored: taking the two arguments back out of the screen's call -> 5 red; naming the first of several
 * orders instead of refusing -> 1 red; matching a refund request of any amount -> 2 red; dropping the agreed-status filter -> 2 red.
 *
 * One of those started out INERT and the test was changed rather than the claim: the unagreed-line case first used TWO lines, one pending
 * and one denied, so dropping the status filter left two candidates and the ambiguity rule refused them anyway. With one line the status
 * is the only thing between the amount and a link, and the mutation turns it red.
 *
 * ENGINES: 13 tests / 25 assertions green on SQLite and on MariaDB 10.11.14, with tests/Feature/Refund and tests/Feature/MyFatoorah
 * (255 tests / 1,158 assertions) green beside them on MariaDB as the regression check on the new controller argument.
 */
class TheGatewayRefundNamesItsOrderTest extends TestCase
{
    use BuildsMyFatoorahSchema;

    private const REFERENCE = 'TXN-GATEWAY-REFUND';

    private const PAID = 150.00;

    private MyFatoorahPaymentAttempt $attempt;

    private string $paymentRow;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->buildMyFatoorahSchema();
        $this->createRefundRequestsTable();

        Http::preventStrayRequests();
        config(['myfatoorah.enabled' => true, 'myfatoorah.currency' => 'SAR', 'myfatoorah.http.max_retries' => 0, 'myfatoorah.http.retry_base_ms' => 1]);

        $this->sarIsActive();
        $this->digitalPaymentOn();
        $this->configureGateway();

        $this->paymentRow = $this->makePaymentRequest(['transaction_id' => self::REFERENCE, 'is_paid' => true]);
        $this->attempt = $this->paidAttempt();
    }

    private function configureGateway(): void
    {
        $repository = app(GatewayConfigRepository::class);
        $repository->save(active: true, mode: 'test', secrets: ['api_key' => 'test-api-key', 'webhook_secret' => 'test-webhook-secret'], secretsMode: 'test');
        $repository->forget();
    }

    /** The real `refund_requests` migration: the status and amount columns this reads are the ones a refund decision writes. */
    private function createRefundRequestsTable(): void
    {
        if (Schema::hasTable('refund_requests')) {
            return;
        }

        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('status');
            $table->decimal('amount', 24, 2);
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });
    }

    private function paidAttempt(): MyFatoorahPaymentAttempt
    {
        return MyFatoorahPaymentAttempt::create([
            'payment_request_id' => $this->paymentRow,
            'attempt_number' => 1,
            'external_identifier' => 'pk-gateway-refund-1',
            'expected_amount' => '150.00',
            'expected_currency' => 'SAR',
            'captured_amount' => '150.00',
            'captured_currency' => 'SAR',
            'status' => AttemptStatus::Paid->value,
            'mode' => 'test',
            'payment_id' => 'PAY-GR-1',
            'invoice_id' => 'INV-GR-1',
            'verified_at' => now(),
            'settled_at' => now(),
            'hook_completed_at' => now(),
        ]);
    }

    /** An order of this payment: it carries the gateway's reference, which is how the platform's own settlement guard finds it. */
    private function orderOfThePayment(int $id, string $reference = self::REFERENCE): int
    {
        DB::table('orders')->insert([
            'id' => $id, 'customer_id' => 7, 'order_status' => 'confirmed', 'payment_status' => 'paid',
            'payment_method' => 'fatoorah', 'transaction_ref' => $reference, 'order_amount' => self::PAID,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function refundRequestOf(int $orderId, float $amount, string $status = 'approved'): int
    {
        return (int) DB::table('refund_requests')->insertGetId([
            'order_details_id' => $orderId * 10, 'customer_id' => 7, 'status' => $status, 'amount' => $amount,
            'product_id' => 1, 'order_id' => $orderId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fakeTheGatewayAccepting(): void
    {
        Http::fake(['*/v3/refunds' => Http::response([
            'IsSuccess' => true,
            'Message' => 'Refund Created Successfully!',
            'Data' => ['Refund' => ['Id' => 'RF-GR-1', 'Reference' => '2026000099', 'Status' => 'Pending', 'InvoiceId' => 'INV-REFUND-GR']],
        ], 201)]);
    }

    /** The screen's own refund button, driven through the real controller action: the whole defect was in this caller. */
    private function refundThroughTheScreen(float $amount, string $comment = 'The box arrived open.'): void
    {
        $this->fakeTheGatewayAccepting();

        app(SettingsController::class)->refund(
            Request::create('/admin/third-party/payment-method/myfatoorah/attempts/' . $this->attempt->id . '/refund', 'POST', [
                'amount' => $amount,
                'comment' => $comment,
            ]),
            $this->attempt->id,
            app(GatewayRefundLink::class),
        );
    }

    private function theRefundRow(): MyFatoorahRefund
    {
        return MyFatoorahRefund::query()->orderByDesc('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- the link

    public function test_the_gateway_refund_names_the_order_the_payment_paid_for(): void
    {
        $orderId = $this->orderOfThePayment(5001);

        $this->refundThroughTheScreen(self::PAID);

        $this->assertSame($orderId, (int) $this->theRefundRow()->order_id);
    }

    public function test_the_gateway_refund_names_the_agreed_refund_request_of_that_order(): void
    {
        $orderId = $this->orderOfThePayment(5001);
        $refundRequestId = $this->refundRequestOf($orderId, amount: 40.00);

        $this->refundThroughTheScreen(40.00);

        $row = $this->theRefundRow();
        $this->assertSame($orderId, (int) $row->order_id);
        $this->assertSame($refundRequestId, (int) $row->refund_request_id);
    }

    public function test_a_payment_that_became_several_orders_records_no_order(): void
    {
        $this->orderOfThePayment(5001);
        $this->orderOfThePayment(5002);

        $this->refundThroughTheScreen(self::PAID);

        $row = $this->theRefundRow();
        $this->assertNull($row->order_id, 'a single column cannot name three vendors\' orders, so it names none');
        $this->assertNull($row->refund_request_id);
        $this->assertSame(MyFatoorahRefund::STATUS_PENDING, $row->status, 'and the refund itself still goes through');
    }

    public function test_an_amount_matching_two_returned_lines_records_no_refund_request(): void
    {
        $orderId = $this->orderOfThePayment(5001);
        $this->refundRequestOf($orderId, amount: 40.00);
        $this->refundRequestOf($orderId, amount: 40.00);

        $this->refundThroughTheScreen(40.00);

        $row = $this->theRefundRow();
        $this->assertSame($orderId, (int) $row->order_id, 'the order is still certain');
        $this->assertNull($row->refund_request_id, 'which of two identical lines the money is for is not a thing to deduce');
    }

    /**
     * A line nobody has agreed to pay back is not taken as the one being refunded -- ONE such line, deliberately.
     *
     * Written with two at first, one `pending` and one `denied`, and that version had no teeth: dropping the status filter altogether left
     * TWO candidates, which the ambiguity rule refuses anyway, so the test passed either way and proved nothing about the status. One line
     * is what makes the status the only thing standing between the amount and a link.
     */
    #[DataProvider('statusesNobodyAgreedTo')]
    public function test_a_line_nobody_agreed_to_pay_back_is_not_taken_as_the_one_being_refunded(string $status): void
    {
        $orderId = $this->orderOfThePayment(5001);
        $this->refundRequestOf($orderId, amount: 40.00, status: $status);

        $this->refundThroughTheScreen(40.00);

        $row = $this->theRefundRow();
        $this->assertSame($orderId, (int) $row->order_id, 'the order is certain whatever the line says');
        $this->assertNull($row->refund_request_id, "a {$status} line is not a figure anybody has agreed to pay back");
    }

    /** @return array<string, array{0: string}> */
    public static function statusesNobodyAgreedTo(): array
    {
        return ['still being decided' => ['pending'], 'refused' => ['denied']];
    }

    public function test_an_amount_no_returned_line_asks_for_records_no_refund_request(): void
    {
        $orderId = $this->orderOfThePayment(5001);
        $this->refundRequestOf($orderId, amount: 40.00);

        $this->refundThroughTheScreen(55.00);

        $row = $this->theRefundRow();
        $this->assertSame($orderId, (int) $row->order_id);
        $this->assertNull($row->refund_request_id);
    }

    public function test_a_payment_with_no_order_at_all_still_refunds_and_records_nothing(): void
    {
        $this->refundThroughTheScreen(self::PAID);

        $row = $this->theRefundRow();
        $this->assertNull($row->order_id);
        $this->assertNull($row->refund_request_id);
        $this->assertSame(MyFatoorahRefund::STATUS_PENDING, $row->status, 'a missing link must never stop a customer being refunded');
    }

    /** Another payment's order must not be picked up: the reference is what ties the two together. */
    public function test_an_order_of_another_payment_is_not_named(): void
    {
        $this->orderOfThePayment(5001, reference: 'TXN-SOMEBODY-ELSE');

        $this->refundThroughTheScreen(self::PAID);

        $this->assertNull($this->theRefundRow()->order_id);
    }

    // ---------------------------------------------------------------- the ledger

    /**
     * The other half of the criterion, as a negative: the gateway send moves no vendor or commission ledger row.
     *
     * The refund DECISION moves them, once, under `RefundClaims` and capped by the commission snapshot's `reversed_amount`. If the send
     * moved them as well, one refund would be reversed twice -- the seller debited for a return they gave back once. So the send is
     * asserted to add nothing: with the two ledger tables present and empty, they are still empty afterwards.
     */
    public function test_sending_the_money_back_through_the_gateway_moves_no_vendor_or_commission_ledger_row(): void
    {
        $orderId = $this->orderOfThePayment(5001);
        $this->refundRequestOf($orderId, amount: 40.00);
        $this->createLedgerTables();

        $this->refundThroughTheScreen(40.00);

        $this->assertSame(0, DB::table('vendor_ledger_entries')->count(), 'the gateway send must not reverse a commission the decision already reversed');
        $this->assertSame(0, DB::table('order_item_commissions')->where('reversed_amount', '>', 0)->count());
        $this->assertNotNull($this->theRefundRow()->refund_request_id, 'and the link is still recorded');
    }

    /**
     * The two ledger tables the refund decision writes, empty.
     *
     * `order_item_commissions` has no migration in this repository -- it comes from the installation dump -- so only the columns the
     * reversal reads are built, which is what every other test of that service does.
     */
    private function createLedgerTables(): void
    {
        if (!Schema::hasTable('vendor_ledger_entries')) {
            Schema::create('vendor_ledger_entries', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seller_id');
                $table->string('entry_type');
                $table->decimal('debit', 24, 4)->default(0);
                $table->decimal('credit', 24, 4)->default(0);
                $table->string('status')->nullable();
                $table->string('reference_type')->nullable();
                $table->string('reference_id')->nullable();
                $table->string('description')->nullable();
                $table->string('currency', 8)->nullable();
                $table->unsignedBigInteger('legal_entity_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_item_commissions')) {
            Schema::create('order_item_commissions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('order_details_id');
                $table->unsignedBigInteger('seller_id')->nullable();
                $table->string('seller_is')->default('admin');
                $table->decimal('commissionable_amount', 24, 4)->default(0);
                $table->decimal('commission_amount', 24, 4)->default(0);
                $table->decimal('reversed_amount', 24, 4)->default(0);
                $table->string('currency', 8)->nullable();
                $table->timestamps();
            });
        }
    }

    // ---------------------------------------------------------------- the service's own answer

    /** The linkage asked directly, so the reason an answer is null is readable without going through the screen. */
    public function test_the_link_answers_both_or_neither_and_never_guesses(): void
    {
        $orderId = $this->orderOfThePayment(5001);
        $refundRequestId = $this->refundRequestOf($orderId, amount: 40.00);

        $this->assertSame(
            ['order_id' => $orderId, 'refund_request_id' => $refundRequestId],
            app(GatewayRefundLink::class)->of(attempt: $this->attempt, amount: 40.00),
        );

        // No refund request can be named for an amount nobody asked for, and the order still can.
        $this->assertSame(
            ['order_id' => $orderId, 'refund_request_id' => null],
            app(GatewayRefundLink::class)->of(attempt: $this->attempt, amount: 99.00),
        );
    }

    /** Before the refund table exists -- the shape of an installation that has not migrated it -- nothing is read and nothing throws. */
    public function test_with_no_refund_requests_table_the_order_is_still_named(): void
    {
        $orderId = $this->orderOfThePayment(5001);
        Schema::dropIfExists('refund_requests');

        $this->assertSame(
            ['order_id' => $orderId, 'refund_request_id' => null],
            app(GatewayRefundLink::class)->of(attempt: $this->attempt, amount: 40.00),
        );

        $this->createRefundRequestsTable();
    }

    /** A refund row's own id is a uuid-free integer, so the assertions above are reading what the screen wrote and not a stale row. */
    public function test_each_refund_sent_is_one_row(): void
    {
        $this->orderOfThePayment(5001);

        $this->refundThroughTheScreen(40.00, comment: 'First ' . Str::random(6));

        $this->assertSame(1, MyFatoorahRefund::query()->count());
    }
}
