<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Order;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilitySpec;
use App\Utils\OrderManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\Support\BuildsPilotServerSchema;

/**
 * The platform's own writes of an order status into order_details.delivery_status, on the pilot server's varchar(15) column and once the
 * compatibility migration has widened it. The length consequences exist only on MySQL and MariaDB (SQLite does not enforce a declared
 * length), so those tests are skipped elsewhere; CI runs this file on MySQL 8.
 */
class PilotServerOrderStatusTest extends FulfillmentTestCase
{
    use BuildsPilotServerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePilotServerSchema();
    }

    /** @return iterable<string, array{string}> */
    public static function platformOrderStatuses(): iterable
    {
        foreach (CompatibilitySpec::ORDER_STATUSES as $status) {
            yield $status => [$status];
        }
    }

    /** One two-unit line of a placed order, whose stock flag says whether its stock is currently taken (1) or given back (0). */
    private function placedOrder(int $stockFlag = 1): Order
    {
        $this->makeProduct(1);
        $this->makeOrder(100);
        $this->addLine(100, 1, 2, 20);
        DB::table('order_details')->where('order_id', 100)->update(['is_stock_decreased' => $stockFlag, 'delivery_status' => 'pending']);

        return Order::findOrFail(100);
    }

    private function changeStatus(string $status): void
    {
        OrderManager::getStockUpdateOnOrderStatusChange(Order::findOrFail(100), $status);
    }

    private function deliveryStatus(): string
    {
        return (string) DB::table('order_details')->where('order_id', 100)->value('delivery_status');
    }

    private function stock(): int
    {
        return (int) DB::table('products')->where('id', 1)->value('current_stock');
    }

    private function requireEnforcedLengths(): void
    {
        if (!$this->engineEnforcesColumnLengths()) {
            $this->markTestSkipped('SQLite does not enforce a declared column length: the consequences are proved on MySQL and MariaDB.');
        }
    }

    #[DataProvider('platformOrderStatuses')]
    public function test_every_platform_order_status_is_written_to_the_line_in_full_once_the_column_is_wide(string $status): void
    {
        $this->runCompatibilityMigrations();
        $restoring = in_array($status, ['returned', 'failed', 'canceled'], true);
        $this->placedOrder(stockFlag: $restoring ? 1 : 0);

        $this->changeStatus($status);

        $this->assertSame($status, $this->deliveryStatus());
    }

    public function test_re_opening_a_cancelled_order_into_out_for_delivery_loses_the_end_of_the_word_on_the_narrow_column(): void
    {
        $this->requireEnforcedLengths();
        $this->placedOrder();

        $this->inSqlMode(self::LENIENT_SQL_MODE, function (): void {
            $this->changeStatus('canceled');
            $this->assertSame('canceled', $this->deliveryStatus());
            $this->assertSame(52, $this->stock(), 'the two units went back on the shelf');

            $this->changeStatus('out_for_delivery');

            $this->assertSame('out_for_deliver', $this->deliveryStatus(), 'cut short without any error, as the platform\'s connection is not strict');
            $this->assertSame(50, $this->stock(), 'the stock accounting itself is not affected');
            $this->assertSame(1, (int) DB::table('order_details')->where('order_id', 100)->value('is_stock_decreased'));
        });
    }

    public function test_the_same_re_opening_keeps_the_whole_word_once_the_migration_has_run(): void
    {
        $this->requireEnforcedLengths();
        $this->runCompatibilityMigrations();
        $this->placedOrder();

        $this->inSqlMode(self::LENIENT_SQL_MODE, function (): void {
            $this->changeStatus('canceled');
            $this->changeStatus('out_for_delivery');

            $this->assertSame('out_for_delivery', $this->deliveryStatus());
            $this->assertSame(50, $this->stock());
        });
    }

    public function test_a_strict_session_refuses_the_re_opening_on_the_narrow_column_and_leaves_the_order_as_it_was(): void
    {
        $this->requireEnforcedLengths();
        $this->placedOrder();
        $this->changeStatus('canceled');

        $this->inSqlMode(self::STRICT_SQL_MODE, function (): void {
            try {
                $this->changeStatus('out_for_delivery');
                $this->fail('a value that does not fit the column must be refused in a strict session');
            } catch (QueryException $refusal) {
                $this->assertStringContainsString('Data too long', $refusal->getMessage());
            }
        });

        $this->assertSame('canceled', $this->deliveryStatus());
        $this->assertSame(0, (int) DB::table('order_details')->where('order_id', 100)->value('is_stock_decreased'), 'the change was rolled back as a whole');
        $this->assertSame(52, $this->stock());
    }

    public function test_a_strict_session_accepts_the_re_opening_once_the_migration_has_run(): void
    {
        $this->requireEnforcedLengths();
        $this->runCompatibilityMigrations();
        $this->placedOrder();
        $this->changeStatus('canceled');

        $this->inSqlMode(self::STRICT_SQL_MODE, fn () => $this->changeStatus('out_for_delivery'));

        $this->assertSame('out_for_delivery', $this->deliveryStatus());
        $this->assertSame(50, $this->stock());
    }

    public function test_a_status_change_that_does_not_flip_the_stock_flag_writes_no_status_at_all(): void
    {
        $this->placedOrder();

        $this->changeStatus('out_for_delivery');

        $this->assertSame('pending', $this->deliveryStatus(), 'the platform copies a status into the line only when the stock is given back or taken again');
        $this->assertSame(50, $this->stock());
    }

    public function test_the_integration_neither_reads_nor_writes_the_status_column(): void
    {
        $finder = (new Finder())->files()->name('*.php')->in(array_filter([
            app_path('Services/Fulfillment'),
            app_path('Jobs/Fulfillment'),
            app_path('Http/Controllers/Admin/Fulfillment'),
        ], 'is_dir'))->notPath('Readiness')->notName('LegacyColumnWidener.php');

        $offenders = [];
        foreach ($finder as $file) {
            if (str_contains($file->getContents(), 'delivery_status')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'the claim that widening order_details.delivery_status cannot affect the integration rests on this');
    }
}
