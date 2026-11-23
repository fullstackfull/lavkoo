<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\RefundFromOriginalPayment;
use App\Services\Storefront\RefundPayout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * D1-10, criteria 2 and 4, prepared and not adopted: a refund worked out from the original payment, in the currency the customer paid in, is the share of what was paid that the
 * refunded amount stands for. It does not move with the exchange rate (the conversion the platform makes today does), the refunds of one order add up to exactly what was paid
 * whatever the decimals of the currency, a payment of several orders is shared among them, and an order that recorded no payment is not guessed at. It only reads, and no
 * refund calls it: which of the two a refund gives back is the owner's decision.
 */
class RefundFromOriginalPaymentTest extends TestCase
{
    /** The platform runs the multi-currency model with the Saudi riyal as its default: the case where the rate of the day of the refund matters. */
    private const CURRENCIES = [1 => ['USD', 1], 2 => ['SAR', 3.75], 3 => ['AED', 3.6725], 4 => ['KWD', 0.307]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTheTablesOfTheTest();
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        foreach (self::CURRENCIES as $id => [$code, $rate]) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1]);
        }
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => 'multi_currency'],
            ['type' => 'system_default_currency', 'value' => '2'],
            ['type' => 'decimal_point_settings', 'value' => '2'],
        ]);
        clearWebConfigCacheKeys();
        cache()->forget('active_currency_list');
        Schema::create('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->decimal('order_amount', 16, 6)->default(0);
            $table->string('transaction_ref')->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->decimal('currency_rate', 16, 8)->nullable();
        });
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->decimal('payment_amount', 24, 6)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->boolean('is_paid')->default(false);
            $table->string('transaction_id')->nullable();
        });
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('amount', 16, 6)->default(0);
            $table->string('status')->default('pending');
        });
    }

    protected function tearDown(): void
    {
        $this->dropTheTablesOfTheTest();

        parent::tearDown();
    }

    private function dropTheTablesOfTheTest(): void
    {
        foreach (['refund_requests', 'payment_requests', 'orders', 'currencies', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function computation(): RefundFromOriginalPayment
    {
        return app(RefundFromOriginalPayment::class);
    }

    /** @param array<string, mixed> $attributes */
    private function order(int $id, string $amount, array $attributes = []): object
    {
        DB::table('orders')->insert(['id' => $id, 'order_amount' => $amount] + $attributes);

        return DB::table('orders')->where('id', $id)->first();
    }

    private function gatewayPaid(string $transaction, string $amount, string $currency, bool $paid = true): void
    {
        DB::table('payment_requests')->insert(['id' => (string) str()->uuid(), 'payment_amount' => $amount, 'currency_code' => $currency, 'is_paid' => $paid, 'transaction_id' => $transaction]);
    }

    private function changeTheRateOf(string $code, float $rate): void
    {
        DB::table('currencies')->where('code', $code)->update(['exchange_rate' => $rate]);
        session()->flush();
        cache()->forget('active_currency_list');
    }

    // ---- the original payment, not today's rate ------------------------------------------------------------------------------------------

    public function test_a_refund_is_its_share_of_what_the_gateway_took_in_the_currency_it_took_it_in(): void
    {
        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-1']);
        $this->gatewayPaid('TX-1', '100.00', 'SAR');

        $worked = $this->computation()->of(order: $order, refundAmount: '10.00');

        $this->assertSame(['currency' => 'SAR', 'amount' => '37.50', 'paid' => '100.00', 'basis' => 'gateway_record', 'transaction' => 'TX-1', 'capped' => false], $worked);
    }

    public function test_the_refund_does_not_move_when_the_exchange_rate_does_and_the_conversion_of_today_does(): void
    {
        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-1']);
        $this->gatewayPaid('TX-1', '100.00', 'SAR');
        $beforeTheRateMoved = [$this->computation()->of(order: $order, refundAmount: '10.00')['amount'], round(usdToDefaultCurrency(amount: 10.0), 3)];

        $this->changeTheRateOf('SAR', 3.5);
        $afterTheRateMoved = [$this->computation()->of(order: $order, refundAmount: '10.00')['amount'], round(usdToDefaultCurrency(amount: 10.0), 3)];

        $this->assertSame(['37.50', 37.5], $beforeTheRateMoved);
        $this->assertSame(['37.50', 35.0], $afterTheRateMoved, 'what the customer paid is 37.50 of it; the conversion of today says 35.00');
    }

    public function test_a_three_decimal_currency_is_given_back_in_three_decimals_and_the_refunds_add_up_to_exactly_what_was_paid(): void
    {
        $order = $this->order(1, '3.00', ['transaction_ref' => 'TX-K']);
        $this->gatewayPaid('TX-K', '10.000', 'KWD');

        $refunded = '0';
        $given = [];
        foreach (range(1, 3) as $line) {
            $given[] = $this->computation()->of(order: $order, refundAmount: '1.00', alreadyRefunded: $refunded)['amount'];
            $refunded = bcadd($refunded, '1.00', 2);
        }

        $this->assertSame(['3.333', '3.334', '3.333'], $given);
        $this->assertSame('10.000', bcadd(bcadd($given[0], $given[1], 3), $given[2], 3), 'no minor unit is lost or made up');
    }

    public function test_however_an_order_is_split_its_refunds_add_up_to_exactly_what_was_paid(): void
    {
        $order = $this->order(1, '100.00', ['transaction_ref' => 'TX-S']);
        $this->gatewayPaid('TX-S', '137.41', 'AED');

        foreach ([[33.33, 33.33, 33.34], [14.29, 14.29, 14.29, 14.29, 14.28, 14.28, 14.28], [0.01, 99.99], [1.0, 1.0, 1.0, 1.0, 1.0, 95.0]] as $split) {
            $refunded = '0';
            $total = '0';
            foreach ($split as $part) {
                $total = bcadd($total, $this->computation()->of(order: $order, refundAmount: (string) $part, alreadyRefunded: $refunded)['amount'], 2);
                $refunded = bcadd($refunded, (string) $part, 6);
            }

            $this->assertSame('137.41', $total, implode(' + ', $split));
        }
    }

    public function test_a_refund_that_would_take_more_than_the_order_is_capped_at_what_was_paid_for_it(): void
    {
        $order = $this->order(1, '100.00', ['transaction_ref' => 'TX-C']);
        $this->gatewayPaid('TX-C', '375.00', 'SAR');

        $worked = $this->computation()->of(order: $order, refundAmount: '80.00', alreadyRefunded: '50.00');

        $this->assertSame(['187.50', true], [$worked['amount'], $worked['capped']], 'only the half of the order that is left');
        $this->assertSame('0.00', $this->computation()->of(order: $order, refundAmount: '10.00', alreadyRefunded: '100.00')['amount']);
    }

    public function test_a_payment_of_several_orders_is_shared_among_them_by_their_amounts(): void
    {
        $first = $this->order(1, '100.00', ['transaction_ref' => 'TX-G']);
        $second = $this->order(2, '50.00', ['transaction_ref' => 'TX-G']);
        $this->gatewayPaid('TX-G', '300.00', 'AED');

        $this->assertSame('200.00', $this->computation()->of(order: $first, refundAmount: '100.00')['amount']);
        $this->assertSame('100.00', $this->computation()->of(order: $second, refundAmount: '50.00')['amount']);
        $this->assertSame('50.00', $this->computation()->of(order: $first, refundAmount: '25.00')['amount']);
    }

    // ---- the order's own record ----------------------------------------------------------------------------------------------------------

    public function test_an_order_with_no_gateway_record_is_worked_from_the_currency_and_rate_it_was_sold_in(): void
    {
        $order = $this->order(1, '31.500', ['currency_code' => 'KWD', 'currency_rate' => 1]);

        $worked = $this->computation()->of(order: $order, refundAmount: '10.500');

        $this->assertSame(['currency' => 'KWD', 'amount' => '10.500', 'paid' => '31.500', 'basis' => 'order_record', 'transaction' => null, 'capped' => false], $worked);
    }

    public function test_the_rate_of_the_order_record_turns_the_stored_amount_into_its_currency(): void
    {
        $order = $this->order(1, '26.67', ['currency_code' => 'SAR', 'currency_rate' => 3.75]);

        $this->assertSame('37.50', $this->computation()->of(order: $order, refundAmount: '10.00')['amount']);
    }

    public function test_the_gateway_record_wins_over_the_order_record(): void
    {
        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-W', 'currency_code' => 'SAR', 'currency_rate' => 3.75]);
        $this->gatewayPaid('TX-W', '98.00', 'SAR');

        $worked = $this->computation()->of(order: $order, refundAmount: '26.67');

        $this->assertSame(['98.00', 'gateway_record'], [$worked['amount'], $worked['basis']]);
    }

    // ---- what is not guessed -------------------------------------------------------------------------------------------------------------

    public function test_an_order_that_recorded_no_payment_and_no_currency_is_not_guessed_at(): void
    {
        $this->assertNull($this->computation()->of(order: $this->order(1, '26.67'), refundAmount: '10.00'));
        $this->assertNull($this->computation()->of(order: $this->order(2, '26.67', ['transaction_ref' => 'TX-NONE']), refundAmount: '10.00'));
    }

    public function test_a_payment_that_was_not_paid_is_not_an_original_payment(): void
    {
        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-U']);
        $this->gatewayPaid('TX-U', '100.00', 'SAR', paid: false);

        $this->assertNull($this->computation()->of(order: $order, refundAmount: '10.00'));
    }

    public function test_two_paid_records_with_one_reference_are_ambiguous_and_the_computation_will_not_pick_one(): void
    {
        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-D']);
        $this->gatewayPaid('TX-D', '100.00', 'SAR');
        $this->gatewayPaid('TX-D', '100.00', 'SAR');

        $this->assertNull($this->computation()->of(order: $order, refundAmount: '10.00'));
    }

    public function test_a_database_without_the_gateway_ledger_falls_back_to_the_order_record_or_nothing(): void
    {
        Schema::drop('payment_requests');

        $this->assertNull($this->computation()->of(order: $this->order(1, '26.67', ['transaction_ref' => 'TX-1']), refundAmount: '10.00'));
        $this->assertSame('10.00', $this->computation()->of(order: $this->order(2, '26.67', ['transaction_ref' => 'TX-1', 'currency_code' => 'SAR', 'currency_rate' => 1]), refundAmount: '10.00')['amount']);
    }

    public function test_a_gateway_record_with_no_currency_or_no_amount_is_not_used(): void
    {
        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-N']);
        $this->gatewayPaid('TX-N', '0.00', 'SAR');

        $this->assertNull($this->computation()->of(order: $order, refundAmount: '10.00'));

        DB::table('payment_requests')->update(['payment_amount' => '100.00', 'currency_code' => '']);

        $this->assertNull($this->computation()->of(order: $order, refundAmount: '10.00'));
    }

    /** @return array<string, array{0: string|int|float, 1: string|int|float}> */
    public static function amountsThatAreNotPlainNumbers(): array
    {
        return ['text' => ['ten', 0], 'negative' => ['-1', 0], 'a negative float' => [-0.5, 0], 'earlier refunds that are negative' => ['1.00', '-5'], 'a scientific notation' => ['1e3', 0]];
    }

    #[DataProvider('amountsThatAreNotPlainNumbers')]
    public function test_an_amount_that_is_not_a_plain_number_is_refused(string|int|float $refund, string|int|float $alreadyRefunded): void
    {
        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-1']);
        $this->gatewayPaid('TX-1', '100.00', 'SAR');

        $this->expectException(InvalidArgumentException::class);

        $this->computation()->of(order: $order, refundAmount: $refund, alreadyRefunded: $alreadyRefunded);
    }

    // ---- reading only, and not adopted ---------------------------------------------------------------------------------------------------

    public function test_it_only_reads_every_statement_is_a_select(): void
    {
        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-R']);
        $this->gatewayPaid('TX-R', '100.00', 'SAR');
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->computation()->of(order: $order, refundAmount: '10.00');

        $this->assertNotSame([], $statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql, 'the computation wrote: ' . $sql);
        }
    }

    /**
     * The computation is reached from two files and no others: the read-only preview, and the one service the refund flow asks, which answers nothing
     * at all until the owner's switch is turned on.
     *
     * This began as the record that the computation was built and deliberately not adopted. It is now the record of exactly where it is adopted from,
     * which is the same guard doing the same job: a third file reaching the computation -- a controller, a repository, a listener working the figure out
     * for itself -- turns this red and has to be a decision, not a drift. The dormancy that the old record stood for is not lost; it moved to the test
     * below, which proves the one service answers nothing while the switch is off.
     *
     * The comments of a file are taken out before it is read, so a class named in a doc block -- App\Services\Payments\OrderPaymentAccount names this
     * one to say that it refuses an ambiguous payment by the same rule -- is not counted as a caller of it. What is counted is the code: an import, a
     * type, a constructor argument, an app() resolution.
     */
    public function test_only_the_preview_and_the_one_guarded_service_reach_the_computation(): void
    {
        $callers = [];
        foreach (File::allFiles(app_path()) as $file) {
            if (str_contains(self::codeWithoutComments($file->getContents()), 'RefundFromOriginalPayment') && $file->getFilename() !== 'RefundFromOriginalPayment.php') {
                $callers[] = $file->getFilename();
            }
        }
        sort($callers);

        $this->assertSame(['RefundOriginalPaymentPreviewCommand.php', 'RefundPayout.php'], $callers, 'the refund flow reaches the computation through RefundPayout, which is off by default, and through nothing else');
    }

    /**
     * And the service it is reached from is dormant as shipped: config/gcc.php leaves `gcc.storefronts.refunds.from_original_payment` off, so a refund
     * of a paid order is answered with nothing and the flow converts the stored amount exactly as it always did.
     *
     * This is the record the old guard kept -- that no refund gives back the original payment until somebody decides it should -- proved on the shipped
     * configuration rather than on the absence of a caller.
     */
    public function test_the_service_that_reaches_it_is_off_as_the_application_ships(): void
    {
        $this->assertFalse((bool) config(RefundPayout::SETTING), 'the switch is off in config/gcc.php, with no environment variable set');

        $order = $this->order(1, '26.67', ['transaction_ref' => 'TX-OFF']);
        $this->gatewayPaid('TX-OFF', '100.00', 'SAR');

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->assertNull(app(RefundPayout::class)->forRefund(order: $order, refund: ['id' => 7, 'order_id' => 1, 'amount' => '10.00']));
        $this->assertSame([], $statements, 'and it asks the database nothing at all while it is off');
    }

    /** The file's code with every comment taken out, so that what a comment mentions is not read as what the code does. */
    private static function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token;
        }

        return $code;
    }

    // ---- the preview ---------------------------------------------------------------------------------------------------------------------

    private function refundRequest(int $id, int $orderId, string $amount, string $status = 'pending'): void
    {
        DB::table('refund_requests')->insert(['id' => $id, 'order_id' => $orderId, 'amount' => $amount, 'status' => $status]);
    }

    /** @return array{0: int, 1: string} */
    private function preview(int $refundId): array
    {
        $exit = Artisan::call('refunds:preview-original-payment', ['refund' => $refundId]);

        return [$exit, Artisan::output()];
    }

    public function test_the_preview_puts_the_original_payment_next_to_the_conversion_of_today(): void
    {
        $this->order(1, '26.67', ['transaction_ref' => 'TX-P']);
        $this->gatewayPaid('TX-P', '100.00', 'SAR');
        $this->refundRequest(7, 1, '10.00');
        $this->changeTheRateOf('SAR', 3.5);

        [$exit, $output] = $this->preview(7);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Refund request 7: order 1, 10', $output);
        $this->assertStringContainsString('Original payment: 100.00 SAR (gateway record, transaction TX-P).', $output);
        $this->assertStringContainsString('Worked out from the original payment, this refund gives back 37.50 SAR.', $output);
        $this->assertStringContainsString('Converted today at the platform\'s rate it would give back 35.000 SAR: the difference between the two is -2.500 SAR.', $output);
        $this->assertStringContainsString('Nothing was written and nothing was paid.', $output);
    }

    public function test_the_preview_counts_the_refunds_the_order_already_gave_back_and_not_the_one_it_shows(): void
    {
        $this->order(1, '100.00', ['transaction_ref' => 'TX-P']);
        $this->gatewayPaid('TX-P', '375.00', 'SAR');
        $this->refundRequest(1, 1, '50.00', status: 'refunded');
        $this->refundRequest(2, 1, '10.00', status: 'pending');
        $this->refundRequest(3, 1, '5.00', status: 'rejected');

        [, $output] = $this->preview(2);

        $this->assertStringContainsString('the earlier refunds of the order gave back 50', $output);
        $this->assertStringContainsString('this refund gives back 37.50 SAR', $output);
    }

    public function test_the_preview_says_so_when_there_is_no_original_payment_and_shows_the_conversion_that_stays(): void
    {
        $this->order(1, '26.67');
        $this->refundRequest(7, 1, '10.00');

        [$exit, $output] = $this->preview(7);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No original payment is recorded for this order', $output);
        $this->assertStringContainsString('The conversion the platform makes today stays what decides: 37.500 SAR.', $output);
    }

    public function test_the_preview_refuses_a_refund_request_that_does_not_exist_and_one_whose_order_is_gone(): void
    {
        [$exit, $output] = $this->preview(99);
        $this->assertSame([1, true], [$exit, str_contains($output, 'There is no refund request with that id.')]);

        $this->refundRequest(8, 404, '10.00');
        [$exit, $output] = $this->preview(8);
        $this->assertSame([1, true], [$exit, str_contains($output, 'The order of that refund request does not exist any more.')]);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $this->order(1, '26.67', ['transaction_ref' => 'TX-P']);
        $this->gatewayPaid('TX-P', '100.00', 'SAR');
        $this->refundRequest(7, 1, '10.00');
        $tables = ['orders', 'payment_requests', 'refund_requests'];
        $before = array_map(static fn (string $table): array => DB::table($table)->get()->all(), $tables);

        $this->preview(7);

        $this->assertEquals($before, array_map(static fn (string $table): array => DB::table($table)->get()->all(), $tables));
    }
}
