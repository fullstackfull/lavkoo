<?php

namespace Tests\Feature\Storefront;

use App\Services\Payments\PaymentAccountBinding;
use App\Services\Payments\PaymentTransactionRecord;
use App\Services\Storefront\RefundPayout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The rules of the one service the refund flow asks what a refund gives back (D1-10, criteria 3, 4 and 6).
 *
 * What it answers through the real routes, after a real rate change, is proved in Tests\Feature\Storefront\RefundGivesBackTheOriginalPaymentTest. This
 * is the service's own behaviour: what it does while it is off, what it counts as already given back, which payouts it will not decide, and what it
 * puts on a refund transaction.
 */
class RefundPayoutTest extends TestCase
{
    private const REFERENCE = 'TXN-PAID-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTheTables();

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
        foreach ([1 => ['USD', 1], 2 => ['SAR', 3.75], 3 => ['AED', 3.6725]] as $id => [$code, $rate]) {
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
            $table->text('additional_data')->nullable();
        });
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('amount', 16, 6)->default(0);
            $table->string('status')->default('pending');
        });
        Schema::create('refund_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->timestamps();
        });
        (require database_path('migrations/2026_12_05_100000_add_original_payment_to_refund_transactions_table.php'))->up();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.payment_accounts.enabled' => true,
            RefundPayout::SETTING => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropTheTables();

        parent::tearDown();
    }

    private function dropTheTables(): void
    {
        foreach (['refund_transactions', 'refund_requests', 'payment_requests', 'orders', 'currencies', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function payout(): RefundPayout
    {
        return app(RefundPayout::class);
    }

    /** @param array<string, mixed> $attributes */
    private function order(string $amount, array $attributes = []): object
    {
        DB::table('orders')->insert(['id' => 1, 'order_amount' => $amount] + $attributes);

        return DB::table('orders')->where('id', 1)->first();
    }

    private function paid(string $amount, string $currency, ?int $accountId = null, ?int $companyId = null, bool $isPaid = true): void
    {
        $stamp = $accountId === null ? null : json_encode([PaymentAccountBinding::KEY => (new PaymentTransactionRecord(
            market: 'SA', companyId: $companyId, company: ['legal_entity_id' => $companyId, 'country' => 'SA'], accountId: $accountId,
            gateway: 'paymob_accept', environment: 'test', currency: $currency, exchangeRate: '1', recordedAt: now()->toIso8601String(),
        ))->toStored()]);

        DB::table('payment_requests')->insert([
            'id' => (string) Str::uuid(), 'payment_amount' => $amount, 'currency_code' => $currency,
            'is_paid' => $isPaid, 'transaction_id' => self::REFERENCE, 'additional_data' => $stamp,
        ]);
    }

    private function refundOf(string $amount, int $id = 9): array
    {
        return ['id' => $id, 'order_id' => 1, 'amount' => $amount];
    }

    // ---- what it answers while the switch is off -------------------------------------------------------------------------------------------

    public function test_while_the_switch_is_off_it_answers_nothing_and_asks_nothing(): void
    {
        config([RefundPayout::SETTING => false]);
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR');

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->assertFalse($this->payout()->isEnabled());
        $this->assertNull($this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00')));
        $this->assertSame([], $statements);
    }

    // ---- what it answers while it is on ----------------------------------------------------------------------------------------------------

    public function test_a_refund_is_its_share_of_the_original_payment_and_names_the_account_that_took_it(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR', accountId: 4, companyId: 6);

        $payout = $this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00'));

        $this->assertSame(['37.50', 'SAR', '375.00', 'gateway_record', self::REFERENCE, false, 4, 6], [
            $payout['amount'], $payout['currency'], $payout['paid'], $payout['basis'], $payout['transaction'], $payout['capped'], $payout['account_id'], $payout['company_id'],
        ]);
    }

    /** The rate of the day is not an input: the same refund answers the same figure after the riyal has moved. */
    public function test_the_figure_does_not_move_when_the_rate_does(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR');

        $before = $this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00'))['amount'];
        DB::table('currencies')->where('code', 'SAR')->update(['exchange_rate' => 3.0]);
        clearWebConfigCacheKeys();
        cache()->forget('active_currency_list');
        session()->flush();

        $this->assertSame(['37.50', '37.50'], [$before, $this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00'))['amount']]);
        $this->assertSame(30.0, (float) usdToDefaultCurrency(amount: 10.0), 'while the conversion of today says 30.00 for the same refund');
    }

    /**
     * What the earlier refunds of the order gave back is counted; a refund that is only asked for is not, nor is another order's, nor is the refund
     * being paid out itself.
     *
     * The last of those is the one that bites. The administrator's flow writes the refund transaction BEFORE it marks the refund refunded, so on a
     * first pass the row is still pending and counting it or not makes no difference -- the shares are linear, and 40 to 50 is the same money as 50
     * to 60. It makes every difference on a second pass, or on any flow that marks the row first: the amounts here take the order to its whole, so
     * counting this refund twice puts the running total at the cap and the buyer is handed nothing at all.
     */
    public function test_it_counts_what_the_earlier_refunds_of_the_order_gave_back_and_not_this_one(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR');
        DB::table('refund_requests')->insert([
            ['id' => 1, 'order_id' => 1, 'amount' => '40.00', 'status' => 'refunded'],
            ['id' => 2, 'order_id' => 1, 'amount' => '25.00', 'status' => 'pending'],
            ['id' => 9, 'order_id' => 1, 'amount' => '60.00', 'status' => 'refunded'],
            ['id' => 3, 'order_id' => 2, 'amount' => '90.00', 'status' => 'refunded'],
        ]);

        $payout = $this->payout()->forRefund(order: $order, refund: $this->refundOf('60.00', id: 9));

        $this->assertSame('225.00', $payout['amount'], '40 of the order was already given back, so this refund is the rest of the payment: neither the one only asked for, nor another order\'s, nor this refund itself is counted among them');
        $this->assertFalse($payout['capped'], 'the order is given back in full, not over');
    }

    /** A refund that would take more than the order is capped at the order, so the refunds of one payment can never exceed it. */
    public function test_a_refund_that_would_take_more_than_the_order_is_capped(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR');

        $payout = $this->payout()->forRefund(order: $order, refund: $this->refundOf('500.00'));

        $this->assertSame(['375.00', true], [$payout['amount'], $payout['capped']]);
    }

    // ---- the payouts it will not decide ----------------------------------------------------------------------------------------------------

    /** A payment taken in the platform's own currency can be handed back through the wallet whole. */
    public function test_a_payment_in_the_platforms_own_currency_is_handed_back_through_the_wallet(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR');

        $this->assertSame('37.50', $this->payout()->walletCredit($this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00'))));
    }

    /**
     * A payment taken in another currency is not: a customer has one balance in the platform's money until balances are per market, and at what rate
     * one currency becomes another in a refund is nobody's decision yet. The amount owed is still answered, so it can be recorded.
     */
    public function test_a_payment_in_another_currency_is_not_handed_back_through_the_wallet(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('367.25', 'AED');

        $payout = $this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00'));

        $this->assertSame(['36.73', 'AED'], [$payout['amount'], $payout['currency']]);
        $this->assertNull($this->payout()->walletCredit($payout), 'the wallet is in riyals and the payment was in dirhams');
    }

    public function test_an_order_with_no_original_payment_is_not_guessed_at(): void
    {
        $order = $this->order('100.00');

        $this->assertNull($this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00')), 'no reference, no recorded currency: nothing to work from');
    }

    public function test_a_payment_that_was_not_paid_is_not_the_original_payment(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR', isPaid: false);

        $this->assertNull($this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00')));
    }

    public function test_an_amount_that_is_not_a_plain_number_is_refused_rather_than_guessed(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR');

        $this->assertNull($this->payout()->forRefund(order: $order, refund: ['id' => 1, 'order_id' => 1, 'amount' => 'ten']));
        $this->assertNull($this->payout()->forRefund(order: $order, refund: ['id' => 1, 'order_id' => 1, 'amount' => '-5.00']));
    }

    // ---- what it puts on a refund transaction ----------------------------------------------------------------------------------------------

    public function test_it_records_the_share_and_the_currency_on_a_table_that_has_the_columns(): void
    {
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR');
        $payout = $this->payout()->forRefund(order: $order, refund: $this->refundOf('10.00'));

        $this->assertSame(
            ['original_payment_amount' => '37.50', 'original_payment_currency' => 'SAR'],
            $this->payout()->recordOn(table: 'refund_transactions', payout: $payout),
        );
    }

    public function test_it_records_nothing_on_a_table_that_has_not_got_the_columns(): void
    {
        (require database_path('migrations/2026_12_05_100000_add_original_payment_to_refund_transactions_table.php'))->down();
        $order = $this->order('100.00', ['transaction_ref' => self::REFERENCE]);
        $this->paid('375.00', 'SAR');
        $payout = app(RefundPayout::class)->forRefund(order: $order, refund: $this->refundOf('10.00'));

        $this->assertSame([], app(RefundPayout::class)->recordOn(table: 'refund_transactions', payout: $payout));
    }

    public function test_the_rollback_is_refused_while_a_refund_records_what_it_gave_back(): void
    {
        DB::table('refund_transactions')->insert(['id' => 1, 'order_id' => 1, 'amount' => '10.00', 'original_payment_amount' => '37.50', 'original_payment_currency' => 'SAR']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rolling this migration back would forget it');

        (require database_path('migrations/2026_12_05_100000_add_original_payment_to_refund_transactions_table.php'))->down();
    }

    public function test_the_rollback_drops_the_columns_when_no_refund_records_one(): void
    {
        (require database_path('migrations/2026_12_05_100000_add_original_payment_to_refund_transactions_table.php'))->down();

        $this->assertFalse(Schema::hasColumn('refund_transactions', 'original_payment_amount'));
        $this->assertFalse(Schema::hasColumn('refund_transactions', 'original_payment_currency'));
    }
}
