<?php

namespace Tests\Feature\Storefront;

use App\Models\RefundTransaction;
use App\Services\RefundTransactionService;
use App\Services\Storefront\RefundOrderMoney;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use RuntimeException;
use Tests\TestCase;

/**
 * D1-10: a refund row names the money and the company of the order it gives back, copied from the order's own record when the row is made, so that a refund read after the
 * rates or the settings changed still says what was sold in and by whom. An order with no record, and a database that has not run the migration, give nothing and the row is
 * made exactly as it always was. The amount of a refund is not touched.
 */
class RefundOrderMoneyTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_12_03_100000_add_order_money_to_refund_tables.php';

    private const COLUMNS = ['storefront_country', 'currency_code', 'currency_rate', 'legal_entity_id'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropRefundTables();
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
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        $this->dropRefundTables();

        parent::tearDown();
    }

    private function dropRefundTables(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('refund_transactions');
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    /** @return array<string, mixed> an order of Kuwait with the record its sale wrote */
    private function kuwaitiOrder(): array
    {
        return ['id' => 12, 'storefront_country' => 'KW', 'currency_code' => 'KWD', 'currency_rate' => '1.00000000', 'legal_entity_id' => 5];
    }

    // ---- what the service copies ---------------------------------------------------------------------------------------------------------

    public function test_a_refund_row_gets_the_four_columns_of_the_orders_record(): void
    {
        foreach (['refund_requests', 'refund_transactions'] as $table) {
            $this->assertSame(
                ['storefront_country' => 'KW', 'currency_code' => 'KWD', 'currency_rate' => '1.00000000', 'legal_entity_id' => 5],
                app(RefundOrderMoney::class)->of(order: $this->kuwaitiOrder(), table: $table),
            );
        }
    }

    public function test_an_order_object_is_read_as_an_array_is(): void
    {
        $this->assertSame(['storefront_country' => 'KW', 'currency_code' => 'KWD', 'currency_rate' => '1.00000000', 'legal_entity_id' => 5], app(RefundOrderMoney::class)->of(order: (object) $this->kuwaitiOrder(), table: 'refund_requests'));
    }

    public function test_an_order_with_no_record_gives_nothing(): void
    {
        $this->assertSame([], app(RefundOrderMoney::class)->of(order: ['id' => 12, 'storefront_country' => null, 'currency_code' => null, 'currency_rate' => null, 'legal_entity_id' => null], table: 'refund_requests'));
        $this->assertSame([], app(RefundOrderMoney::class)->of(order: ['id' => 12], table: 'refund_requests'));
        $this->assertSame([], app(RefundOrderMoney::class)->of(order: null, table: 'refund_requests'));
    }

    public function test_only_what_the_order_recorded_is_copied(): void
    {
        $this->assertSame(['storefront_country' => 'SA', 'currency_code' => 'SAR'], app(RefundOrderMoney::class)->of(order: ['storefront_country' => 'SA', 'currency_code' => 'SAR', 'currency_rate' => null, 'legal_entity_id' => '', 'id' => 1], table: 'refund_requests'));
    }

    public function test_a_table_that_has_not_run_the_migration_or_does_not_exist_gives_nothing(): void
    {
        Schema::table('refund_requests', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
        Schema::dropIfExists('refund_transactions');

        $this->assertSame([], app(RefundOrderMoney::class)->of(order: $this->kuwaitiOrder(), table: 'refund_requests'));
        $this->assertSame([], app(RefundOrderMoney::class)->of(order: $this->kuwaitiOrder(), table: 'refund_transactions'));
    }

    // ---- the transaction the administrator makes -------------------------------------------------------------------------------------------

    private function transactionData(array $order): array
    {
        return (new RefundTransactionService())->getData(
            request: new Fluent(['payment_method' => 'cash']),
            refund: new Fluent(['order_id' => 12, 'customer_id' => 3, 'amount' => 30, 'order_details_id' => 9, 'id' => 4]),
            order: new Fluent($order + ['seller_id' => 1, 'seller_is' => 'admin']),
        );
    }

    public function test_the_refund_transaction_carries_the_record_of_the_order_and_keeps_its_own_fields(): void
    {
        $data = $this->transactionData($this->kuwaitiOrder());

        $this->assertSame(['KW', 'KWD', 5], [$data['storefront_country'], $data['currency_code'], $data['legal_entity_id']]);
        $this->assertSame([12, 'Refund Request', 1, 3, 'admin', 'customer', 'cash', 'paid', 30, 'Refund', 9, 4], [
            $data['order_id'], $data['payment_for'], $data['payer_id'], $data['payment_receiver_id'], $data['paid_by'], $data['paid_to'], $data['payment_method'],
            $data['payment_status'], $data['amount'], $data['transaction_type'], $data['order_details_id'], $data['refund_id'],
        ]);
    }

    public function test_the_model_stores_the_record_when_the_repository_creates_the_transaction(): void
    {
        $stored = RefundTransaction::create($this->transactionData($this->kuwaitiOrder()))->fresh();

        $this->assertSame(['KW', 'KWD', 5], [$stored->storefront_country, $stored->currency_code, (int) $stored->legal_entity_id]);
        $this->assertEquals(1, (float) $stored->currency_rate);
    }

    public function test_the_refund_transaction_of_an_order_with_no_record_is_what_it_always_was(): void
    {
        $this->assertSame(
            ['order_id', 'payment_for', 'payer_id', 'payment_receiver_id', 'paid_by', 'paid_to', 'payment_method', 'payment_status', 'amount', 'transaction_type', 'order_details_id', 'refund_id'],
            array_keys($this->transactionData(['id' => 12])),
        );
    }

    public function test_every_place_that_makes_a_refund_row_asks_for_the_record_of_the_order(): void
    {
        $missing = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $makesARow = preg_match('/new RefundRequest\b|refundTransactionService->getData|class RefundTransactionService/', $source) === 1;

            if ($makesARow && !str_contains($source, 'RefundOrderMoney') && !str_contains($source, 'refundTransactionService->getData')) {
                $missing[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $missing, 'a place that makes a refund request or a refund transaction has to copy the record of its order (RefundOrderMoney)');
    }

    // ---- the migration -----------------------------------------------------------------------------------------------------------------------

    public function test_the_migration_adds_four_nullable_columns_to_both_tables_and_is_idempotent(): void
    {
        $this->migration()->up();

        foreach (['refund_requests', 'refund_transactions'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column}");
            }
            DB::table($table)->insert(['order_id' => 1, 'amount' => 5]);
            $this->assertNull(DB::table($table)->value('storefront_country'), 'a refund made before the record keeps all four empty');
        }
    }

    public function test_the_migration_skips_a_table_that_does_not_exist(): void
    {
        Schema::dropIfExists('refund_transactions');

        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('refund_transactions'));
    }

    public function test_the_rollback_is_refused_while_a_refund_carries_a_record_and_drops_the_columns_otherwise(): void
    {
        DB::table('refund_transactions')->insert(['order_id' => 1, 'amount' => 5, 'storefront_country' => 'KW']);

        try {
            $this->migration()->down();
            $this->fail('the rollback has to be refused');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rolling this migration back would forget it', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('refund_transactions', 'storefront_country'));

        DB::table('refund_transactions')->delete();
        $this->migration()->down();

        foreach (['refund_requests', 'refund_transactions'] as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column}");
            }
        }
    }
}
