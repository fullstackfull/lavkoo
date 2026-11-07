<?php

namespace Tests\Feature\SalesChannels;

use App\Services\SalesChannels\Support\SalesChannelSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/** The code can be deployed before its migrations have run: until all of them have, the module leaves the platform exactly as it was. */
class UnmigratedModuleTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const SCHEDULED = [
        ['channels:sync', ['--due' => true]],
        ['channels:publish', ['--only' => 'stock,price,settle,shipments']],
        ['costs:refresh', ['--days' => 3]],
        ['costs:accrue-storage', []],
        ['channels:prune', []],
        ['channels:dispatch', []],
        ['channels:dispatch-sync', []],
        ['channels:purge-buyer-data', []],
        ['channels:find-shipments', []],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
    }

    public function test_with_no_table_the_module_is_not_ready_and_every_scheduled_command_does_nothing_and_succeeds(): void
    {
        $this->buildCoreSchema();
        $this->assertFalse(SalesChannelSchema::ready());

        foreach (self::SCHEDULED as [$command, $arguments]) {
            $this->artisan($command, $arguments)->expectsOutputToContain('migrations have not been run')->assertExitCode(0);
        }
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function fulfilmentTables(): array
    {
        return ['shipments' => ['provider_fulfillments'], 'their items' => ['provider_fulfillment_items'], 'provider stock figures' => ['provider_product_mappings']];
    }

    #[DataProvider('fulfilmentTables')]
    public function test_the_modules_wait_for_the_fulfilment_tables_they_read_too(string $table): void
    {
        $this->buildSalesChannelSchema();
        $this->assertTrue(SalesChannelSchema::ready());

        Schema::drop($table);

        $this->assertFalse(SalesChannelSchema::ready(), "Without {$table} the cost and stock code would fail on every run.");
        foreach (self::SCHEDULED as [$command, $arguments]) {
            $this->assertSame(0, Artisan::call($command, $arguments), $command);
            $this->assertStringContainsString('migrations have not been run', Artisan::output(), $command);
        }
    }

    public function test_a_migration_still_to_run_keeps_the_module_off_even_when_the_first_ones_have_run(): void
    {
        $this->buildSalesChannelSchema();
        $this->assertTrue(SalesChannelSchema::ready());

        Schema::table('operational_cost_entries', static fn ($table) => $table->dropColumn('state_before_dispute'));

        $this->assertFalse(SalesChannelSchema::ready(), 'The ledger writes that column on every entry: without it every write would fail.');
        foreach (self::SCHEDULED as [$command, $arguments]) {
            $this->assertSame(0, Artisan::call($command, $arguments), $command);
            $this->assertStringContainsString('migrations have not been run', Artisan::output(), $command);
        }
    }

    /** @return array<string, array{string}> the migrations added after the first four, by what each one adds */
    public static function laterMigrations(): array
    {
        return [
            'the refund columns of the order lines' => ['2026_11_09_100000_add_refund_columns_to_external_order_lines.php'],
            'the charge-matching columns of the statement events' => ['2026_11_09_100100_add_charge_matching_to_sales_channel_events.php'],
            'the return decisions table' => ['2026_11_09_100200_create_operational_cost_return_decisions_table.php'],
            'the basis of a reconciliation' => ['2026_11_09_100300_add_basis_to_operational_cost_reconciliations.php'],
            'the dispatch claim table' => ['2026_11_09_200000_create_channel_order_dispatches_table.php'],
            'the stock allocation pools' => ['2026_11_10_100000_add_stock_allocation.php'],
        ];
    }

    #[DataProvider('laterMigrations')]
    public function test_each_later_migration_still_to_run_keeps_the_module_off_with_the_command_saying_so(string $migration): void
    {
        $this->buildSalesChannelSchema();
        (require database_path('migrations/' . $migration))->down();

        $this->assertFalse(SalesChannelSchema::ready());
        foreach (self::SCHEDULED as [$command, $arguments]) {
            $this->assertSame(0, Artisan::call($command, $arguments), $command);
            $this->assertStringContainsString('migrations have not been run', Artisan::output(), $command);
        }

        (require database_path('migrations/' . $migration))->up();
        $this->assertTrue(SalesChannelSchema::ready());
    }
}
