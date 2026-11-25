<?php

namespace Tests\Feature\Fulfillment;

use App\Models\ReturnShipment;
use App\Models\StockMovement;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\Marketplace\InventoryService;
use App\Services\Marketplace\ReturnLogisticsService;
use App\Services\Returns\CrossBorderReturn;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsReturnReceiptSchema;
use Tests\TestCase;

/**
 * H1-13, criterion 4: a return of a cross-border order puts its units back on the shelf of the market that sold them, and records the money it owes back
 * in the currency the customer paid in.
 *
 * The first test is the one that matters most: with cross-border returns off, which is how they ship, a return behaves to the byte as it did before --
 * the units go back, the movement row is written, the status reaches `restocked`, and the three new columns stay empty even on a return whose markets
 * disagree. Everything after it switches the mechanism on.
 */
class CrossBorderReturnTest extends TestCase
{
    use BuildsReturnReceiptSchema;

    private const SAUDI = 'SA';

    private const KUWAIT = 'KW';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReturnReceiptSchema();
        $this->addTheMarketColumns();
        app(CrossBorderReturn::class)->forget();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');
        $this->dropReturnReceiptSchema();
        parent::tearDown();
    }

    public function test_a_return_restocks_exactly_as_before_while_cross_border_returns_are_off(): void
    {
        config([CrossBorderReturn::SETTING => false]);
        $sale = $this->saleOf(soldIn: self::SAUDI, productIn: self::KUWAIT, currency: 'KWD');

        $received = app(ReturnLogisticsService::class)->receive(rma: $sale['return'], by: 1);

        $this->assertTrue($received['restocked'], 'a return of a shop with the switch off must restock');
        $this->assertNull($received['refused']);
        $this->assertSame(12, $this->stockOf($sale['product_id']));
        $this->assertSame(ReturnShipment::STATUS_RESTOCKED, $sale['return']->fresh()->status);
        $this->assertSame(
            [null, null, null],
            $this->recordOn($sale['return']),
            'nothing about a market or its money is written while the switch is off',
        );
        $this->assertLanguageFilesUnchanged();
    }

    public function test_a_return_of_a_cross_border_order_puts_the_units_back_on_the_market_that_sold_them(): void
    {
        config([CrossBorderReturn::SETTING => true]);
        $sale = $this->saleOf(soldIn: self::SAUDI, productIn: self::SAUDI, currency: 'SAR');

        $received = app(ReturnLogisticsService::class)->receive(rma: $sale['return'], by: 1);

        $this->assertTrue($received['restocked']);
        $this->assertNull($received['refused']);
        $this->assertSame(12, $this->stockOf($sale['product_id']));
        $this->assertSame([self::SAUDI, 'SAR', null], $this->recordOn($sale['return']));
    }

    public function test_a_return_whose_product_belongs_to_another_market_is_received_and_not_restocked(): void
    {
        config([CrossBorderReturn::SETTING => true]);
        $sale = $this->saleOf(soldIn: self::SAUDI, productIn: self::KUWAIT, currency: 'SAR');

        $received = app(ReturnLogisticsService::class)->receive(rma: $sale['return'], by: 1);

        $this->assertTrue($received['ok'], 'the goods did arrive; the receipt is not a failure');
        $this->assertFalse($received['restocked']);
        $this->assertSame(CrossBorderReturn::REFUSAL_SHELF_BELONGS_TO_ANOTHER_MARKET, $received['refused']);
        $this->assertSame(10, $this->stockOf($sale['product_id']), "the other market's shelf must not grow");
        $this->assertSame(ReturnShipment::STATUS_RECEIVED, $sale['return']->fresh()->status);
        $this->assertSame(
            0,
            StockMovement::where('reference_id', $sale['return']->id)->count(),
            'a refused receipt writes no movement, because nothing moved',
        );
    }

    /**
     * A receipt for the WHOLE line, so that a receipt which restocked would flip the line's marker and claim the restore. A refused one must not: the
     * units are still off the shelf and the order status writer is the only thing left that can put them back.
     */
    public function test_the_units_of_a_refused_receipt_stay_claimable_by_the_order_status_writer(): void
    {
        config([CrossBorderReturn::SETTING => true]);
        $sale = $this->saleOf(soldIn: self::SAUDI, productIn: self::KUWAIT, currency: 'SAR', returning: 5);

        app(ReturnLogisticsService::class)->receive(rma: $sale['return'], by: 1);

        $this->assertSame(
            1,
            (int) DB::table('order_details')->where('id', $sale['line_id'])->value('is_stock_decreased'),
            'a receipt that restocked nothing must not take the line\'s claim: the units are still off the shelf',
        );
    }

    public function test_a_market_nobody_recorded_is_not_a_disagreement(): void
    {
        config([CrossBorderReturn::SETTING => true]);
        $sale = $this->saleOf(soldIn: null, productIn: self::KUWAIT, currency: 'KWD');

        $received = app(ReturnLogisticsService::class)->receive(rma: $sale['return'], by: 1);

        $this->assertTrue($received['restocked'], 'a shop that records no market must keep returning goods');
        $this->assertNull($received['refused']);
        $this->assertSame([self::KUWAIT, 'KWD', null], $this->recordOn($sale['return']));
    }

    /** Both columns are a two-letter code (char(2) on products and on orders), so the case is the only way one market can be written two ways here. */
    public function test_a_market_written_in_either_case_is_one_shelf(): void
    {
        config([CrossBorderReturn::SETTING => true]);

        foreach ([['sa', 'SA'], ['SA', 'sa'], ['kw', 'KW']] as [$soldIn, $productIn]) {
            $sale = $this->saleOf(soldIn: $soldIn, productIn: $productIn, currency: 'SAR');

            $received = app(ReturnLogisticsService::class)->receive(rma: $sale['return'], by: 1);

            $this->assertTrue($received['restocked'], "'{$soldIn}' and '{$productIn}' are the same shelf");
            $this->assertNull($received['refused']);
        }
    }

    public function test_the_return_records_the_currency_the_customer_paid_in(): void
    {
        config([CrossBorderReturn::SETTING => true]);
        $sale = $this->saleOf(soldIn: self::KUWAIT, productIn: self::KUWAIT, currency: 'KWD');

        $this->assertSame('KWD', $sale['return']->fresh()->refund_currency, 'what is owed back is owed in the money it was paid in');
    }

    public function test_a_shop_whose_table_has_not_run_the_migration_writes_the_row_as_before(): void
    {
        config([CrossBorderReturn::SETTING => true]);
        Schema::table('return_shipments', function (Blueprint $table): void {
            $table->dropColumn([CrossBorderReturn::SOURCE_COLUMN, CrossBorderReturn::CURRENCY_COLUMN, CrossBorderReturn::ACCOUNT_COLUMN]);
        });
        app(CrossBorderReturn::class)->forget();

        $sale = $this->saleOf(soldIn: self::SAUDI, productIn: self::SAUDI, currency: 'SAR');
        $received = app(ReturnLogisticsService::class)->receive(rma: $sale['return'], by: 1);

        $this->assertTrue($received['restocked']);
        $this->assertSame(12, $this->stockOf($sale['product_id']));
    }

    public function test_the_refusal_reaches_the_audit_trail_and_not_only_the_caller(): void
    {
        config([CrossBorderReturn::SETTING => true]);
        $sale = $this->saleOf(soldIn: self::SAUDI, productIn: self::KUWAIT, currency: 'SAR');
        $recorded = [];
        $audit = new class($recorded) extends AuditLogger {
            /** @param array<int, array<string, mixed>> $recorded */
            public function __construct(private array &$recorded)
            {
            }

            /**
             * The whole signature, `$country` included: PHP will not let a child DROP a parameter, so a stub that stops short of the
             * parent's is a fatal error on load -- exit 255 rather than a test failure, which takes the whole group with it. `AuditLogger`
             * gained `$country` with G1-08, and this stub was written before it.
             */
            public function record(string $action, array|object|null $subject = null, ?array $before = null, ?array $after = null, ?array $context = null, ?string $country = null): ?AuditLog
            {
                $this->recorded[] = ['action' => $action, 'after' => $after, 'country' => $country];

                return null;
            }
        };

        (new ReturnLogisticsService(inventory: app(InventoryService::class), audit: $audit, crossBorder: app(CrossBorderReturn::class)))
            ->receive(rma: $sale['return'], by: 1);

        $receipt = array_values(array_filter($recorded, static fn (array $row): bool => $row['action'] === 'returns.received'));

        $this->assertCount(1, $receipt);
        $this->assertSame(CrossBorderReturn::REFUSAL_SHELF_BELONGS_TO_ANOTHER_MARKET, $receipt[0]['after']['refused']);
        $this->assertFalse($receipt[0]['after']['restocked']);
    }

    public function test_the_migration_adds_the_three_columns_and_refuses_to_roll_back_once_a_return_carries_a_record(): void
    {
        $migration = require database_path('migrations/2026_12_21_100000_add_source_market_to_return_shipments.php');
        $columns = [CrossBorderReturn::SOURCE_COLUMN, CrossBorderReturn::CURRENCY_COLUMN, CrossBorderReturn::ACCOUNT_COLUMN];

        Schema::table('return_shipments', fn (Blueprint $table) => $table->dropColumn($columns));
        app(CrossBorderReturn::class)->forget();
        $migration->up();

        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn('return_shipments', $column), "the migration must add {$column}");
        }

        config([CrossBorderReturn::SETTING => true]);
        app(CrossBorderReturn::class)->forget();
        $this->saleOf(soldIn: self::SAUDI, productIn: self::SAUDI, currency: 'SAR');

        try {
            $migration->down();
            $this->fail('The rollback must be refused while a return carries a record.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('Rolling this migration back would forget', $refusal->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('return_shipments', CrossBorderReturn::SOURCE_COLUMN), 'and nothing was dropped on the way out');
    }

    /** A second run of the migration over a table that already has the columns must not fail: it is how every installation that imported the dump arrives. */
    public function test_the_migration_runs_twice_without_complaint(): void
    {
        $migration = require database_path('migrations/2026_12_21_100000_add_source_market_to_return_shipments.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('return_shipments', CrossBorderReturn::CURRENCY_COLUMN));
    }

    /**
     * One sale, returned: a product belonging to one market, an order sold in another, and an authorised return for part (or all) of the 5 units.
     *
     * @return array{product_id: int, line_id: int, order_id: int, return: ReturnShipment}
     */
    private function saleOf(?string $soldIn, string $productIn, string $currency, int $returning = 2): array
    {
        $productId = DB::table('products')->insertGetId(['current_stock' => 10, 'storefront_country' => $productIn]);
        $orderId = DB::table('orders')->insertGetId(['storefront_country' => $soldIn, 'currency_code' => $currency]);
        $lineId = DB::table('order_details')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $productId,
            'qty' => 5,
            'is_stock_decreased' => 1,
        ]);

        app(CrossBorderReturn::class)->forget();

        return [
            'product_id' => $productId,
            'line_id' => $lineId,
            'order_id' => $orderId,
            'return' => app(ReturnLogisticsService::class)->authorize(
                data: ['order_id' => $orderId, 'order_details_id' => $lineId, 'product_id' => $productId, 'seller_id' => 7, 'qty' => $returning, 'restock' => true],
                by: 1,
            ),
        ];
    }

    private function stockOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    /** @return array{0: ?string, 1: ?string, 2: ?int} */
    private function recordOn(ReturnShipment $rma): array
    {
        $row = DB::table('return_shipments')->where('id', $rma->id)->first();

        return [
            $row->{CrossBorderReturn::SOURCE_COLUMN} ?? null,
            $row->{CrossBorderReturn::CURRENCY_COLUMN} ?? null,
            $row->{CrossBorderReturn::ACCOUNT_COLUMN} ?? null,
        ];
    }

    /** The columns the GCC migrations add that this test's base does not build: a market on the product, and the order it belongs to. (`return_shipments` already carries its three, from BuildsReturnDumpTables.) */
    private function addTheMarketColumns(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->char('storefront_country', 2)->nullable());

        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->char('storefront_country', 2)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->timestamps();
        });

    }
}
