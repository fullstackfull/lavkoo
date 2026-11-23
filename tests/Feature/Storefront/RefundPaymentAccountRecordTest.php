<?php

namespace Tests\Feature\Storefront;

use App\Models\RefundTransaction;
use App\Services\Payments\OrderPaymentAccount;
use App\Services\Payments\PaymentAccountBinding;
use App\Services\Storefront\RefundOrderMoney;
use App\Services\RefundTransactionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use RuntimeException;
use Tests\TestCase;

/**
 * D1-10, criterion 1 and the recording half of criterion 4: a refund request and a refund transaction name the merchant account the original payment was
 * taken through and the company that took it, read from the stamp that payment carries and never from the registry of the day.
 *
 * The stamp is written here by the production writer (App\Services\Payments\PaymentAccountBinding::record), never by hand, so a change to what a stamp
 * looks like fails these tests instead of passing them. What a refund row holds after a real checkout and a real refund is proved through the routes
 * themselves in Tests\Feature\Storefront\RefundNamesThePaymentAccountTest.
 */
class RefundPaymentAccountRecordTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_12_04_100000_add_payment_account_to_refund_tables.php';

    private const ORDER_MIGRATION = 'migrations/2026_12_03_100000_add_order_money_to_refund_tables.php';

    private const COLUMNS = ['payment_gateway_account_id', 'payment_legal_entity_id'];

    private const REFERENCE = 'TXN-ORIGINAL-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTheTables();

        foreach (['refund_requests', 'refund_transactions'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->unsignedBigInteger('order_id')->nullable();
                $blueprint->decimal('amount', 16, 2)->default(0);
                $blueprint->string('payment_for')->nullable();
                $blueprint->unsignedBigInteger('payer_id')->nullable();
                $blueprint->unsignedBigInteger('payment_receiver_id')->nullable();
                $blueprint->string('paid_by')->nullable();
                $blueprint->string('paid_to')->nullable();
                $blueprint->string('payment_method')->nullable();
                $blueprint->string('payment_status')->nullable();
                $blueprint->string('transaction_type')->nullable();
                $blueprint->unsignedBigInteger('order_details_id')->nullable();
                $blueprint->unsignedBigInteger('refund_id')->nullable();
                $blueprint->timestamps();
            });
        }

        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('transaction_id')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->text('additional_data')->nullable();
        });

        (require database_path(self::ORDER_MIGRATION))->up();
        $this->migration()->up();
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.payment_accounts.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->dropTheTables();

        parent::tearDown();
    }

    private function dropTheTables(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('refund_transactions');
        Schema::dropIfExists('payment_requests');
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /**
     * A paid payment carrying the stamp the production writer puts on it: the test asks PaymentAccountBinding to record the account, exactly as
     * App\Traits\Payment::generate_link does, rather than writing the shape of a stamp itself.
     */
    private function aPaidPaymentStampedWith(int $accountId, int $companyId, string $reference = self::REFERENCE, bool $paid = true): void
    {
        $record = app(PaymentAccountBinding::class);
        $stored = (new \App\Services\Payments\PaymentTransactionRecord(
            market: 'AE', companyId: $companyId, company: ['legal_entity_id' => $companyId, 'country' => 'AE'], accountId: $accountId,
            gateway: 'paymob_accept', environment: 'test', currency: 'AED', exchangeRate: '1', recordedAt: now()->toIso8601String(),
        ))->toStored();

        DB::table('payment_requests')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'transaction_id' => $reference,
            'is_paid' => $paid,
            'additional_data' => json_encode([$record::KEY => $stored]),
        ]);
    }

    /** @return array<string, mixed> an order of the Emirates, sold by company 5 and paid through the payment above */
    private function paidOrder(array $overrides = []): array
    {
        return $overrides + ['id' => 12, 'transaction_ref' => self::REFERENCE, 'storefront_country' => 'AE', 'currency_code' => 'AED', 'currency_rate' => '1.00000000', 'legal_entity_id' => 5];
    }

    // ---- what the refund row is given ----------------------------------------------------------------------------------------------------

    public function test_a_refund_row_names_the_account_and_the_company_that_took_the_payment(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);

        foreach (['refund_requests', 'refund_transactions'] as $table) {
            $this->assertSame(
                ['storefront_country' => 'AE', 'currency_code' => 'AED', 'currency_rate' => '1.00000000', 'legal_entity_id' => 5, 'payment_gateway_account_id' => 7, 'payment_legal_entity_id' => 9],
                app(RefundOrderMoney::class)->of(order: $this->paidOrder(), table: $table),
            );
        }
    }

    public function test_the_company_that_took_the_payment_is_kept_apart_from_the_company_that_sold(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);

        $values = app(RefundOrderMoney::class)->of(order: $this->paidOrder(), table: 'refund_requests');

        $this->assertSame(5, $values['legal_entity_id'], 'the company that sold the order');
        $this->assertSame(9, $values['payment_legal_entity_id'], 'the company whose merchant account took the money');
    }

    public function test_a_payment_that_carries_no_stamp_names_no_account(): void
    {
        DB::table('payment_requests')->insert(['id' => 'p1', 'transaction_id' => self::REFERENCE, 'is_paid' => true, 'additional_data' => json_encode(['payment_mode' => 'web'])]);

        $this->assertSame(
            ['storefront_country' => 'AE', 'currency_code' => 'AED', 'currency_rate' => '1.00000000', 'legal_entity_id' => 5],
            app(RefundOrderMoney::class)->of(order: $this->paidOrder(), table: 'refund_requests'),
        );
    }

    /**
     * A cash-on-delivery order has no payment, and a payment whose reference was never filled in has no order: the two must not find each other.
     *
     * The payment with the empty reference is the one that makes this test discriminating -- without it an order with no reference finds nothing
     * whatever the code does, and the test would pass for the wrong reason.
     */
    public function test_an_order_that_was_never_paid_through_a_gateway_names_no_account(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9, reference: '');
        $this->aPaidPaymentStampedWith(accountId: 8, companyId: 10, reference: self::REFERENCE);

        foreach ([null, '', '  '] as $reference) {
            $this->assertArrayNotHasKey(
                'payment_gateway_account_id',
                app(RefundOrderMoney::class)->of(order: $this->paidOrder(['transaction_ref' => $reference]), table: 'refund_requests'),
                'an order with no payment reference must not be given the account of a payment whose reference is empty',
            );
        }
    }

    public function test_a_payment_that_was_not_paid_is_not_the_original_payment(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9, paid: false);

        $this->assertArrayNotHasKey('payment_gateway_account_id', app(RefundOrderMoney::class)->of(order: $this->paidOrder(), table: 'refund_requests'));
    }

    public function test_two_paid_payments_under_one_reference_are_ambiguous_and_name_no_account(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);
        $this->aPaidPaymentStampedWith(accountId: 8, companyId: 10);

        $this->assertArrayNotHasKey('payment_gateway_account_id', app(RefundOrderMoney::class)->of(order: $this->paidOrder(), table: 'refund_requests'));
    }

    public function test_the_account_is_the_one_the_payment_was_stamped_with_and_not_the_one_in_force_now(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);
        $this->aPaidPaymentStampedWith(accountId: 42, companyId: 43, reference: 'A-LATER-PAYMENT');

        $this->assertSame(7, app(RefundOrderMoney::class)->of(order: $this->paidOrder(), table: 'refund_requests')['payment_gateway_account_id']);
    }

    public function test_nothing_is_named_while_per_company_payment_accounts_are_off(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);
        config(['gcc.storefronts.payment_accounts.enabled' => false]);

        $this->assertSame(
            ['storefront_country' => 'AE', 'currency_code' => 'AED', 'currency_rate' => '1.00000000', 'legal_entity_id' => 5],
            app(RefundOrderMoney::class)->of(order: $this->paidOrder(), table: 'refund_requests'),
        );
    }

    public function test_nothing_is_named_and_the_payments_table_is_never_asked_while_the_columns_are_not_there(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);
        $this->migration()->down();
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $values = app(RefundOrderMoney::class)->of(order: $this->paidOrder(), table: 'refund_requests');

        $this->assertArrayNotHasKey('payment_gateway_account_id', $values);
        $this->assertSame([], array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'payment_requests')), 'a database without the columns must not even look for the payment');
    }

    // ---- the lookup of the order's payment -----------------------------------------------------------------------------------------------

    public function test_the_lookup_reads_the_stamp_of_the_orders_own_payment(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);

        $stamp = app(OrderPaymentAccount::class)->of(order: $this->paidOrder());

        $this->assertSame([7, 9, 'AE', 'paymob_accept'], [$stamp->accountId, $stamp->companyId, $stamp->market, $stamp->gateway]);
    }

    public function test_the_lookup_answers_nothing_for_no_order_and_for_a_database_with_no_payments_table(): void
    {
        $this->assertNull(app(OrderPaymentAccount::class)->of(order: null));

        Schema::dropIfExists('payment_requests');

        $this->assertNull(app(OrderPaymentAccount::class)->of(order: $this->paidOrder()));
    }

    // ---- the transaction the administrator makes -----------------------------------------------------------------------------------------

    private function transactionData(array $order): array
    {
        return (new RefundTransactionService())->getData(
            request: new Fluent(['payment_method' => 'cash']),
            refund: new Fluent(['order_id' => 12, 'customer_id' => 3, 'amount' => 30, 'order_details_id' => 9, 'id' => 4]),
            order: new Fluent($order + ['seller_id' => 1, 'seller_is' => 'admin']),
        );
    }

    public function test_the_refund_transaction_names_the_account_and_the_company_that_took_the_payment(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);

        $data = $this->transactionData($this->paidOrder());

        $this->assertSame([7, 9], [$data['payment_gateway_account_id'], $data['payment_legal_entity_id']]);
        $this->assertSame([12, 'Refund Request', 30, 'Refund', 9, 4], [$data['order_id'], $data['payment_for'], $data['amount'], $data['transaction_type'], $data['order_details_id'], $data['refund_id']]);
    }

    public function test_the_model_stores_the_account_when_the_repository_creates_the_transaction(): void
    {
        $this->aPaidPaymentStampedWith(accountId: 7, companyId: 9);

        $stored = RefundTransaction::create($this->transactionData($this->paidOrder()))->fresh();

        $this->assertSame([7, 9], [(int) $stored->payment_gateway_account_id, (int) $stored->payment_legal_entity_id]);
    }

    public function test_the_refund_transaction_of_an_order_with_no_stamped_payment_is_what_it_always_was(): void
    {
        $this->assertSame(
            ['order_id', 'payment_for', 'payer_id', 'payment_receiver_id', 'paid_by', 'paid_to', 'payment_method', 'payment_status', 'amount', 'transaction_type', 'order_details_id', 'refund_id'],
            array_keys($this->transactionData(['id' => 12])),
        );
    }

    // ---- the migration -------------------------------------------------------------------------------------------------------------------

    public function test_the_migration_adds_two_nullable_columns_to_both_tables_and_is_idempotent(): void
    {
        $this->migration()->up();

        foreach (['refund_requests', 'refund_transactions'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column}");
            }
            DB::table($table)->insert(['order_id' => 1, 'amount' => 5]);
            $this->assertNull(DB::table($table)->value('payment_gateway_account_id'), 'a refund made before the record keeps both empty');
        }
    }

    public function test_the_migration_skips_a_table_that_does_not_exist(): void
    {
        Schema::dropIfExists('refund_transactions');

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('refund_transactions'));
    }

    public function test_the_rollback_is_refused_while_a_refund_names_an_account_and_drops_the_columns_otherwise(): void
    {
        DB::table('refund_transactions')->insert(['order_id' => 1, 'amount' => 5, 'payment_gateway_account_id' => 7]);

        try {
            $this->migration()->down();
            $this->fail('the rollback has to be refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rolling this migration back would forget it', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('refund_transactions', 'payment_gateway_account_id'));

        DB::table('refund_transactions')->delete();
        $this->migration()->down();

        foreach (['refund_requests', 'refund_transactions'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column}");
            }
        }
    }

    public function test_the_rollback_is_refused_while_only_the_company_of_the_payment_is_recorded(): void
    {
        DB::table('refund_requests')->insert(['order_id' => 1, 'amount' => 5, 'payment_legal_entity_id' => 9]);

        $this->expectException(RuntimeException::class);

        $this->migration()->down();
    }
}
