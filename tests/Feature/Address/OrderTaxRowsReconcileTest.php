<?php

namespace Tests\Feature\Address;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Modules\TaxModule\app\Services\OrderTaxRowsReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsTaxModuleWorld;

/**
 * D1-07: orders:tax-rows-reconcile says, for every order in a period, whether the rows of `order_taxes` add up to the tax the order carries, and classes each difference
 * (short, over, without rows). It is how a report that adds the rows up is compared with the orders it reports on, and it never writes, restates or repairs a row: what a
 * finished order recorded is for the finance review to decide.
 */
class OrderTaxRowsReconcileTest extends ApiAddressTestCase
{
    use BuildsTaxModuleWorld;

    private const COD = '/api/v1/customer/order/place';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrderPipelineTables();
        $this->createTaxModuleTables();
        $this->seedTheTaxRates();
    }

    /** @param list<float> $rows the amount of each row the order recorded */
    private function recordOrder(int $id, float $carried, array $rows, string $createdAt = '2026-11-10 12:00:00', string $orderType = 'order', string $taxFor = 'tax_for_orders'): void
    {
        DB::table('orders')->insert(['id' => $id, 'customer_id' => 1, 'total_tax_amount' => $carried, 'created_at' => $createdAt, 'updated_at' => $createdAt]);

        foreach ($rows as $amount) {
            DB::table('order_taxes')->insert([
                'tax_name' => 'VAT', 'tax_type' => 'order_wise', 'tax_on' => 'basic', 'tax_rate' => 15, 'tax_amount' => $amount, 'order_id' => $id, 'order_type' => $orderType,
                'tax_id' => self::VAT, 'system_tax_setup_id' => 1, 'tax_for' => $taxFor, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }
    }

    /** One order of each class, as a database that had the defect would hold them. */
    private function recordOneOrderOfEachClass(): void
    {
        $this->recordOrder(id: 1, carried: 30, rows: [15, 5, 7.5, 2.5]);
        $this->recordOrder(id: 2, carried: 30, rows: [7.5, 2.5, 3.75, 1.25]);
        $this->recordOrder(id: 3, carried: 10, rows: [12]);
        $this->recordOrder(id: 4, carried: 20, rows: []);
        $this->recordOrder(id: 5, carried: 0, rows: []);
    }

    private function reconcile(array $options = []): PendingCommand
    {
        return $this->artisan('orders:tax-rows-reconcile', $options);
    }

    public function test_it_classes_each_order_by_what_its_rows_add_up_to(): void
    {
        $this->recordOneOrderOfEachClass();

        $report = app(OrderTaxRowsReconciler::class)->reconcile();

        $this->assertSame(5, $report['orders']);
        $this->assertSame(2, $report['reconciled'], 'the order whose rows add up, and the order with no tax and no row');
        $this->assertEquals(['count' => 1, 'carried' => 30.0, 'recorded' => 15.0, 'orders' => [['id' => 2, 'carried' => 30.0, 'recorded' => 15.0]]], $report['short']);
        $this->assertEquals(['count' => 1, 'carried' => 10.0, 'recorded' => 12.0, 'orders' => [['id' => 3, 'carried' => 10.0, 'recorded' => 12.0]]], $report['over']);
        $this->assertEquals(['count' => 1, 'carried' => 20.0, 'recorded' => 0.0, 'orders' => [['id' => 4, 'carried' => 20.0, 'recorded' => 0.0]]], $report['without_rows']);
    }

    public function test_it_prints_each_class_and_fails_while_any_order_differs(): void
    {
        $this->recordOneOrderOfEachClass();

        $this->reconcile()
            ->expectsOutputToContain('Orders examined: 5')
            ->expectsOutputToContain('Reconciled: 2.')
            ->expectsOutputToContain('Short: the rows add up to less than the tax the order carries, so a report that adds the rows up under-reports it: 1 orders, carried 30, recorded 15, difference 15.')
            ->expectsOutputToContain('  order 2: carried 30, recorded 15')
            ->expectsOutputToContain('Over: the rows add up to more than the tax the order carries: 1 orders, carried 10, recorded 12, difference -2.')
            ->expectsOutputToContain('Without rows: the order carries a tax and has no row recorded for it: 1 orders, carried 20, recorded 0, difference 20.')
            ->expectsOutputToContain('Nothing was written.')
            ->assertExitCode(1);
    }

    public function test_it_succeeds_when_every_order_reconciles(): void
    {
        $this->recordOrder(id: 1, carried: 30, rows: [15, 5, 7.5, 2.5]);
        $this->recordOrder(id: 2, carried: 0, rows: []);

        $this->reconcile()->expectsOutputToContain('Reconciled: 2.')->assertExitCode(0);
    }

    public function test_a_database_with_no_orders_succeeds_and_says_so(): void
    {
        $this->reconcile()->expectsOutputToContain('Orders examined: 0')->assertExitCode(0);
    }

    public function test_it_names_at_most_the_number_of_orders_it_was_asked_for_and_counts_all_of_them(): void
    {
        foreach (range(1, 5) as $id) {
            $this->recordOrder(id: $id, carried: 10, rows: [5]);
        }

        $this->reconcile(['--list' => 2])
            ->expectsOutputToContain('Short: the rows add up to less than the tax the order carries, so a report that adds the rows up under-reports it: 5 orders, carried 50, recorded 25, difference 25.')
            ->expectsOutputToContain('  order 1: carried 10, recorded 5')
            ->expectsOutputToContain('  order 2: carried 10, recorded 5')
            ->doesntExpectOutputToContain('  order 3:')
            ->expectsOutputToContain('... and 3 more (raise --list to name them).')
            ->assertExitCode(1);
    }

    public function test_a_difference_within_the_rounding_tolerance_is_not_a_difference(): void
    {
        $this->recordOrder(id: 1, carried: 10, rows: [9.997]);

        $this->reconcile()->assertExitCode(0);
        $this->reconcile(['--tolerance' => '0.001'])->expectsOutputToContain('Short:')->assertExitCode(1);
    }

    public function test_rows_that_are_not_the_orders_own_tax_are_left_out(): void
    {
        $this->recordOrder(id: 1, carried: 30, rows: [15, 5, 7.5, 2.5]);
        DB::table('order_taxes')->insert([
            ['tax_name' => 'Fee', 'tax_type' => 'order_wise', 'tax_on' => 'basic', 'tax_rate' => 5, 'tax_amount' => 99, 'order_id' => 1, 'order_type' => 'store', 'tax_id' => 1, 'system_tax_setup_id' => 1, 'tax_for' => 'tax_for_orders', 'created_at' => now(), 'updated_at' => now()],
            ['tax_name' => 'Fee', 'tax_type' => 'order_wise', 'tax_on' => 'basic', 'tax_rate' => 5, 'tax_amount' => 77, 'order_id' => 1, 'order_type' => 'order', 'tax_id' => 1, 'system_tax_setup_id' => 1, 'tax_for' => 'tax_for_stores', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->reconcile()->expectsOutputToContain('Reconciled: 1.')->assertExitCode(0);
    }

    public function test_a_period_narrows_the_orders_that_are_compared(): void
    {
        $this->recordOrder(id: 1, carried: 30, rows: [15], createdAt: '2026-10-31 23:59:59');
        $this->recordOrder(id: 2, carried: 30, rows: [15], createdAt: '2026-11-01 00:00:00');
        $this->recordOrder(id: 3, carried: 30, rows: [15], createdAt: '2026-11-30 23:59:59');
        $this->recordOrder(id: 4, carried: 30, rows: [15], createdAt: '2026-12-01 00:00:00');

        $this->reconcile(['--from' => '2026-11-01', '--to' => '2026-11-30'])
            ->expectsOutputToContain('Orders examined: 2, created from 2026-11-01 to 2026-11-30')
            ->expectsOutputToContain('  order 2:')
            ->expectsOutputToContain('  order 3:')
            ->doesntExpectOutputToContain('  order 1:')
            ->doesntExpectOutputToContain('  order 4:')
            ->assertExitCode(1);
    }

    public function test_the_report_can_be_printed_as_json_with_the_number_of_orders_that_differ(): void
    {
        $this->recordOneOrderOfEachClass();

        $exit = Artisan::call('orders:tax-rows-reconcile', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame([5, 2, 3], [$report['orders'], $report['reconciled'], $report['differing']]);
        $this->assertSame([2], array_column($report['short']['orders'], 'id'));
        $this->assertSame([3], array_column($report['over']['orders'], 'id'));
        $this->assertSame([4], array_column($report['without_rows']['orders'], 'id'));
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function refusedOptions(): array
    {
        return [
            'a tolerance that is not a number' => [['--tolerance' => 'a little'], '--tolerance must be a plain number'],
            'a negative tolerance' => [['--tolerance' => '-1'], '--tolerance must be a plain number'],
            'a list that is not a number' => [['--list' => 'all'], '--list must be a whole number'],
            'a date that is not a date' => [['--from' => 'yesterday'], '--from and --to must be dates'],
            'an impossible date' => [['--to' => '2026-13-45'], '--from and --to must be dates'],
        ];
    }

    /** @param array<string, string> $options */
    #[DataProvider('refusedOptions')]
    public function test_a_refused_option_says_why_and_reads_nothing(array $options, string $message): void
    {
        $this->recordOneOrderOfEachClass();

        $this->reconcile($options)->expectsOutputToContain($message)->assertExitCode(1);
    }

    public function test_a_database_without_the_rows_table_is_refused(): void
    {
        Schema::dropIfExists('order_taxes');

        $this->reconcile()->expectsOutputToContain('there is nothing to compare')->assertExitCode(1);
    }

    public function test_it_only_reads_every_statement_it_runs_is_a_select_and_no_row_changes(): void
    {
        $this->recordOneOrderOfEachClass();
        $before = [DB::table('orders')->orderBy('id')->get()->all(), DB::table('order_taxes')->orderBy('id')->get()->all()];
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->reconcile()->assertExitCode(1);

        $this->assertNotSame([], $statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql, 'the command wrote: ' . $sql);
        }
        $this->assertEquals($before, [DB::table('orders')->orderBy('id')->get()->all(), DB::table('order_taxes')->orderBy('id')->get()->all()]);
    }

    public function test_an_order_placed_through_the_checkout_reconciles_and_one_that_kept_the_old_rows_is_listed_and_left_as_it_is(): void
    {
        $this->openTheTaxSetup();
        $customer = $this->makeCustomer();
        $this->putOneProductInTheCart($customer->id);
        DB::table('products')->insert(['id' => 2, 'name' => 'Ibuprofen', 'added_by' => 'admin', 'user_id' => 1, 'unit_price' => 50, 'current_stock' => 10]);
        DB::table('carts')->insert([
            'customer_id' => $customer->id, 'is_guest' => false, 'cart_group_id' => 'group-' . $customer->id . 'c', 'product_id' => 2, 'quantity' => 1,
            'price' => 50, 'seller_id' => 1, 'seller_is' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->asCustomer($customer)->getJson(self::COD)->assertOk();
        $orderId = (int) DB::table('orders')->value('id');

        $this->reconcile()->expectsOutputToContain('Reconciled: 1.')->assertExitCode(0);

        DB::table('order_taxes')->where('order_id', $orderId)->update(['tax_amount' => DB::raw('tax_amount / 2')]);
        $held = DB::table('order_taxes')->orderBy('id')->pluck('tax_amount')->map(fn ($amount): float => round((float) $amount, 4))->all();

        $this->reconcile()
            ->expectsOutputToContain("  order {$orderId}: carried 30, recorded 15")
            ->assertExitCode(1);

        $this->assertSame([7.5, 2.5, 3.75, 1.25], $held, 'the rows an order was placed with before the fix, as they were');
        $this->assertSame($held, DB::table('order_taxes')->orderBy('id')->pluck('tax_amount')->map(fn ($amount): float => round((float) $amount, 4))->all(), 'and the command left them alone');
    }
}
