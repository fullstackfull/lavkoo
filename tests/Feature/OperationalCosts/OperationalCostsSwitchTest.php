<?php

namespace Tests\Feature\OperationalCosts;

use App\Console\ScheduleDefinition;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelEvent;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\CostRefresher;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\StorageCostService;
use App\Services\OperationalCosts\Support\Money;
use App\Services\OperationalCosts\Support\OperationalCostsSwitch;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use App\Services\SalesChannels\Finance\ManualStatementImporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\CreatesOperationalCostData;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * The cost module ships off, and the migrations switch nothing on. Nothing here is read from the environment: every test sets the two switches itself (the phpunit
 * configuration turns the master switch on for the other tests of the module).
 */
class OperationalCostsSwitchTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;
    use CreatesOperationalCostData;

    private const ENVIRONMENT_VALUES = ['OPERATIONAL_COSTS_ENABLED', 'OPERATIONAL_COSTS_SCHEDULE_ENABLED'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR', 'operational_costs.enabled' => false, 'operational_costs.schedule_enabled' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
    }

    /**
     * What config/operational_costs.php says with the environment as given: a value of null removes the variable altogether.
     *
     * @param array<string, string|null> $environment
     * @return array<string, mixed>
     */
    private function costConfigWith(array $environment): array
    {
        $kept = [];
        foreach (self::ENVIRONMENT_VALUES as $name) {
            $kept[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
        foreach ($environment as $name => $value) {
            if ($value !== null) {
                $_SERVER[$name] = $value;
            }
        }

        try {
            return require base_path('config/operational_costs.php');
        } finally {
            foreach ($kept as $name => [$server, $env, $process]) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
                $server === null ?: $_SERVER[$name] = $server;
                $env === null ?: $_ENV[$name] = $env;
                $process === false ?: putenv($name . '=' . $process);
            }
        }
    }

    public function test_with_no_environment_value_both_switches_are_off(): void
    {
        $config = $this->costConfigWith([]);

        $this->assertFalse($config['enabled']);
        $this->assertFalse($config['schedule_enabled']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function environmentSpellings(): iterable
    {
        foreach (['true', '1', 'on', 'yes', 'TRUE'] as $on) {
            yield "'{$on}' switches it on" => [$on, true];
        }
        foreach (['false', '0', 'off', 'no', 'disabled', 'n', '', 'maybe'] as $off) {
            yield "'{$off}' leaves it off" => [$off, false];
        }
    }

    #[DataProvider('environmentSpellings')]
    public function test_the_environment_values_are_read_as_booleans_not_cast(string $written, bool $expected): void
    {
        $config = $this->costConfigWith(['OPERATIONAL_COSTS_ENABLED' => $written, 'OPERATIONAL_COSTS_SCHEDULE_ENABLED' => $written]);

        $this->assertSame($expected, $config['enabled'], "OPERATIONAL_COSTS_ENABLED={$written}");
        $this->assertSame($expected, $config['schedule_enabled'], "OPERATIONAL_COSTS_SCHEDULE_ENABLED={$written}");
    }

    public function test_each_switch_is_read_on_its_own(): void
    {
        $this->assertTrue($this->costConfigWith(['OPERATIONAL_COSTS_ENABLED' => 'true'])['enabled']);
        $this->assertFalse($this->costConfigWith(['OPERATIONAL_COSTS_ENABLED' => 'true'])['schedule_enabled']);
        $this->assertTrue($this->costConfigWith(['OPERATIONAL_COSTS_SCHEDULE_ENABLED' => 'true'])['schedule_enabled']);
        $this->assertFalse($this->costConfigWith(['OPERATIONAL_COSTS_SCHEDULE_ENABLED' => 'true'])['enabled']);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function switchCombinations(): iterable
    {
        yield 'both off' => [false, false, false];
        yield 'schedule on but the module off' => [false, true, false];
        yield 'module on, schedule off' => [true, false, false];
        yield 'both on' => [true, true, true];
    }

    /** @return array{0: int, 1: string} the exit code and everything the command printed */
    private function runCommand(string $command, array $arguments = []): array
    {
        $code = Artisan::call($command, $arguments);

        return [$code, Artisan::output()];
    }

    /** @return list<string> */
    private function scheduledCommands(): array
    {
        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);

        return array_map(static fn ($event): string => (string) preg_replace("/^'[^']*' 'artisan' /", '', (string) $event->command), $schedule->events());
    }

    private function scheduled(string $needle): bool
    {
        return array_filter($this->scheduledCommands(), static fn (string $command): bool => str_contains($command, $needle)) !== [];
    }

    #[DataProvider('switchCombinations')]
    public function test_the_scheduler_runs_the_cost_commands_only_when_both_switches_are_on(bool $enabled, bool $schedule, bool $costCommandsScheduled): void
    {
        config(['operational_costs.enabled' => $enabled, 'operational_costs.schedule_enabled' => $schedule]);

        $this->assertSame($costCommandsScheduled, $this->scheduled('costs:refresh'));
        $this->assertSame($costCommandsScheduled, $this->scheduled('costs:accrue-storage'));
        $this->assertSame($enabled && $schedule, OperationalCostsSwitch::scheduled());
        $this->assertFalse($this->scheduled('costs:post-pending-fees'), 'The catch-up is run by the next import and by hand, not by the clock.');
    }

    #[DataProvider('switchCombinations')]
    public function test_channel_housekeeping_does_not_depend_on_the_cost_switches(bool $enabled, bool $schedule): void
    {
        config(['operational_costs.enabled' => $enabled, 'operational_costs.schedule_enabled' => $schedule]);

        foreach (['channels:prune', 'channels:sync --due', 'channels:publish --only=stock,price,shipments', 'channels:publish --only=settle'] as $command) {
            $this->assertTrue($this->scheduled($command), "{$command} is always on the schedule.");
        }
    }

    public function test_the_other_commands_of_the_platform_stay_on_the_schedule_whatever_the_cost_switches_say(): void
    {
        $withoutCosts = $this->scheduledCommands();
        config(['operational_costs.enabled' => true, 'operational_costs.schedule_enabled' => true]);
        $withCosts = $this->scheduledCommands();

        $this->assertSame(['costs:refresh --days=3', 'costs:accrue-storage'], array_values(array_diff($withCosts, $withoutCosts)));
        $this->assertSame([], array_diff($withoutCosts, $withCosts));
    }

    private function openStorefrontSaleWithStock(int $units = 5): InventoryLocation
    {
        $own = $this->stockLocation('Own warehouse', type: InventoryLocation::TYPE_OWN, provider: null);
        app(InventoryCostLayerService::class)->receive(new LayerReceipt(
            locationId: $own->id, productId: 50, quantity: $units, unitCost: Money::of('10'), currency: 'SAR', receivedAt: CarbonImmutable::parse('2026-01-01'),
            sourceType: 'purchase_invoice', identity: 'opening',
        ));
        $this->storefrontSale(orderId: 900, storedPrice: 40);

        return $own;
    }

    public function test_the_refresh_command_run_by_hand_says_it_is_switched_off_and_writes_nothing(): void
    {
        $own = $this->openStorefrontSaleWithStock();

        $this->artisan('costs:refresh', ['--days' => 30])->expectsOutput(OperationalCostsSwitch::OFF_MESSAGE)->assertSuccessful();

        $this->assertSame(0, OperationalCostEntry::count());
        $this->assertSame(0, InventoryCostConsumption::count(), 'No unit is taken from the layers by itself.');
        $this->assertSame(5, (int) InventoryCostLayer::where('inventory_location_id', $own->id)->sum('quantity_remaining'));
    }

    public function test_the_off_message_names_the_switch_and_is_one_line(): void
    {
        $this->assertStringContainsString('OPERATIONAL_COSTS_ENABLED', OperationalCostsSwitch::OFF_MESSAGE);
        $this->assertStringContainsString('switched off', OperationalCostsSwitch::OFF_MESSAGE);
        $this->assertStringNotContainsString("\n", OperationalCostsSwitch::OFF_MESSAGE);
    }

    public function test_the_refresh_command_works_once_the_module_is_switched_on_with_the_schedule_still_off(): void
    {
        $own = $this->openStorefrontSaleWithStock();
        config(['operational_costs.enabled' => true, 'operational_costs.schedule_enabled' => false]);

        $this->artisan('costs:refresh', ['--days' => 30])->expectsOutputToContain('sales checked')->assertSuccessful();

        $this->assertGreaterThan(0, OperationalCostEntry::count());
        $this->assertSame(1, InventoryCostConsumption::count(), 'The delivered order took its unit from the oldest layer.');
        $this->assertSame(4, (int) InventoryCostLayer::where('inventory_location_id', $own->id)->sum('quantity_remaining'));
    }

    public function test_the_storage_command_run_by_hand_says_it_is_switched_off_writes_nothing_and_creates_no_default_location(): void
    {
        $this->artisan('costs:accrue-storage')->expectsOutput(OperationalCostsSwitch::OFF_MESSAGE)->assertSuccessful();

        $this->assertSame(0, OperationalCostEntry::count());
        $this->assertSame(0, InventoryLocation::count(), 'Even the housekeeping of the module waits for the switch.');

        config(['operational_costs.enabled' => true]);
        $this->artisan('costs:accrue-storage')->expectsOutputToContain('storage cost entries')->assertSuccessful();
        $this->assertSame(3, InventoryLocation::count());
    }

    public function test_the_catch_up_command_also_says_it_is_switched_off_and_posts_nothing(): void
    {
        [$account] = $this->heldOrder();
        app(ChannelFeeImporter::class)->import($account, [$this->fee('a', '25')]);

        $this->artisan('costs:post-pending-fees')->expectsOutput(OperationalCostsSwitch::OFF_MESSAGE)->assertSuccessful();

        $this->assertSame(0, OperationalCostEntry::count());
        $this->assertSame(1, app(ChannelFeeImporter::class)->pendingPostings());
    }

    public function test_no_service_posts_a_cost_while_the_module_is_off(): void
    {
        $own = $this->openStorefrontSaleWithStock();
        $document = app(SaleDocumentReader::class)->forOrder(900);
        $torod = $this->stockLocation('Torod warehouse');
        $contracts = app(ContractService::class);
        $contract = $contracts->createDraft(['party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'currency' => 'SAR', 'effective_from' => '2026-01-01'], 1);
        $contracts->addRule($contract, ['service_type' => 'storage_daily', 'calc_method' => 'per_day', 'params' => ['amount' => '0.1', 'multiply_by' => 'none']], 1);
        $contracts->activate($contract, 1, 'test');

        $this->assertSame([], app(SaleCostService::class)->refresh($document));
        $this->assertSame([], app(CostRefresher::class)->refresh($document));
        $this->assertSame(['documents' => 0, 'written' => 0, 'failed' => 0], app(CostRefresher::class)->refreshChangedSince(CarbonImmutable::parse('2026-01-01')));
        $this->assertSame(['documents' => 0, 'written' => 0, 'failed' => 0], app(CostRefresher::class)->refreshPeriod(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')));
        $this->assertSame([], app(StorageCostService::class)->accrue($torod, CarbonImmutable::parse('2026-11-10')));

        $this->assertSame(0, OperationalCostEntry::count());
        $this->assertSame(0, InventoryCostConsumption::count());
        $this->assertNotNull($own);
    }

    public function test_what_a_person_enters_stays_available_while_the_module_is_off(): void
    {
        $warehouse = $this->stockLocation();
        $contracts = app(ContractService::class);
        $contract = $contracts->createDraft(['party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'currency' => 'SAR', 'effective_from' => '2026-01-01'], 1);
        $contracts->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => '12']], 1);
        $contracts->activate($contract, 1, 'a person decided');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->create([
            'kind' => 'purchase', 'party_type' => 'supplier', 'provider_code' => 'supplier-a', 'party_name' => 'Supplier A', 'invoice_number' => 'PI-1', 'invoice_date' => '2026-11-01',
            'currency' => 'SAR', 'total' => '100', 'inventory_location_id' => $warehouse->id,
        ], [['cost_component' => 'cogs', 'amount' => '100', 'quantity' => '10', 'product_id' => 50]], adminId: 1)->invoice;
        $entry = $this->recordCost(CostState::Actual, '25', CostComponent::Shipping);

        $invoices->approve($invoice, 1);
        app(CostLedger::class)->approve($entry, 1, 'checked by a person');
        app(InventoryCostLayerService::class)->receive(new LayerReceipt(
            locationId: $warehouse->id, productId: 51, quantity: 3, unitCost: Money::of('7'), currency: 'SAR', receivedAt: CarbonImmutable::parse('2026-11-02'),
            sourceType: 'opening_balance', identity: 'opening-51',
        ));

        $this->assertSame(['active'], [$contract->fresh()->status->value]);
        $this->assertSame(2, InventoryCostLayer::count(), 'A purchase invoice and an opening balance are stock a person recorded.');
        $this->assertNotNull($entry->fresh()->approved_at);
    }

    // ---- the channel finance importer ---------------------------------------------------------------------------------------------

    private function fee(string $identity, string $amount, string $orderId = '111', string $component = 'marketplace_commission', string $currency = 'SAR'): RemoteFinancialEntry
    {
        return new RemoteFinancialEntry($identity, RemoteFinancialEntry::FEE, CostComponent::from($component), Money::of($amount), $currency, $orderId, '900', null, null, CarbonImmutable::parse('2026-11-05 10:00:00 UTC'), 'Test');
    }

    private function heldOrder(): array
    {
        $account = $this->trendyolAccount();
        $mapping = ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '111', 'external_package_id' => '900', 'status' => 'delivered', 'currency' => 'SAR']);

        return [$account, $mapping];
    }

    /** @return array<string, string> component => amount of the effective actual entries */
    private function actuals(): array
    {
        return OperationalCostEntry::where('is_effective', true)->where('state', CostState::Actual->value)->pluck('amount', 'cost_component')->all();
    }

    public function test_the_importer_keeps_the_events_and_posts_nothing_while_the_module_is_off(): void
    {
        [$account] = $this->heldOrder();

        $stats = app(ChannelFeeImporter::class)->import($account, [$this->fee('a', '25'), $this->fee('b', '-10')]);

        $this->assertSame(['new' => 2, 'duplicates' => 0, 'costs_recorded' => 0, 'needs_review' => 0], $stats);
        $this->assertSame(0, OperationalCostEntry::count());
        $this->assertSame(['received', 'received'], SalesChannelEvent::orderBy('id')->pluck('outcome')->all(), 'Stored, waiting for the cost module.');
        $this->assertSame(2, app(ChannelFeeImporter::class)->pendingPostings($account));
    }

    public function test_the_postings_that_waited_are_made_exactly_once_by_the_next_import_run_after_the_switch_is_turned_on(): void
    {
        [$account] = $this->heldOrder();
        $importer = app(ChannelFeeImporter::class);
        $importer->import($account, [$this->fee('a', '25'), $this->fee('b', '-10')]);

        config(['operational_costs.enabled' => true]);
        $nextRun = $importer->import($account, [$this->fee('c', '4', component: 'marketplace_service_fee')]);
        $again = $importer->import($account, []);

        $this->assertSame(['marketplace_commission' => '15.000000', 'marketplace_service_fee' => '4.000000'], $this->actuals(), 'The held 25 and -10 are one net commission, and the new fee is posted with them.');
        $this->assertSame(2, $nextRun['costs_recorded']);
        $this->assertSame(0, $again['costs_recorded'], 'Nothing is posted twice.');
        $this->assertSame(['applied', 'applied', 'applied'], SalesChannelEvent::orderBy('id')->pluck('outcome')->all());
        $this->assertSame(2, OperationalCostEntry::count());
    }

    public function test_the_catch_up_does_not_need_the_channel_to_send_the_statement_again(): void
    {
        [$account] = $this->heldOrder();
        app(ChannelFeeImporter::class)->import($account, [$this->fee('old', '30')]);
        $this->travel(90)->days();

        config(['operational_costs.enabled' => true]);
        $this->artisan('costs:post-pending-fees')->expectsOutputToContain('1 cost entries posted')->assertSuccessful();

        $this->assertSame(['marketplace_commission' => '30.000000'], $this->actuals());
    }

    public function test_the_catch_up_command_posts_once_and_a_second_catch_up_posts_nothing(): void
    {
        [$account] = $this->heldOrder();
        $other = $this->amazonAccount();
        app(ChannelFeeImporter::class)->import($account, [$this->fee('a', '25')]);
        app(ChannelFeeImporter::class)->import($other, [$this->fee('amazon-subscription', '39', orderId: 'none', component: 'marketplace_service_fee')]);
        config(['operational_costs.enabled' => true]);

        [$code, $first] = $this->runCommand('costs:post-pending-fees');
        [, $second] = $this->runCommand('costs:post-pending-fees');
        [, $third] = $this->runCommand('costs:post-pending-fees', ['account' => $account->id]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2 cost entries posted from the stored statements; 0 postings still waiting.', $first);
        $this->assertStringContainsString('0 cost entries posted', $second);
        $this->assertStringContainsString('0 cost entries posted', $third);
        $this->assertSame(2, OperationalCostEntry::count());
    }

    public function test_the_catch_up_command_can_be_limited_to_one_account_and_refuses_one_that_does_not_exist(): void
    {
        [$account] = $this->heldOrder();
        $other = $this->amazonAccount();
        app(ChannelFeeImporter::class)->import($account, [$this->fee('a', '25')]);
        app(ChannelFeeImporter::class)->import($other, [$this->fee('b', '39', orderId: 'x', component: 'marketplace_service_fee')]);
        config(['operational_costs.enabled' => true]);

        [$code, $output] = $this->runCommand('costs:post-pending-fees', ['account' => $other->id]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 cost entries posted from the stored statements; 0 postings still waiting.', $output, 'Of the account that was asked for.');
        $this->artisan('costs:post-pending-fees', ['account' => 999])->assertFailed();

        $this->assertSame(1, OperationalCostEntry::count());
        $this->assertSame(['received'], SalesChannelEvent::where('account_id', $account->id)->pluck('outcome')->all());
    }

    public function test_a_statement_a_person_imports_while_the_module_is_off_is_stored_and_says_so(): void
    {
        [$account] = $this->heldOrder();
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($path, "order_id,package_id,posted_at,category,component,amount,currency,description\n111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission\n");

        [$code, $output] = $this->runCommand('channels:import-statement', ['account' => $account->id, 'file' => $path]);
        unlink($path);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 rows: 1 new', $output);
        $this->assertStringContainsString('OPERATIONAL_COSTS_ENABLED', $output);
        $this->assertStringContainsString('1 postings wait', $output);

        $this->assertSame(0, OperationalCostEntry::count());
        config(['operational_costs.enabled' => true]);
        $this->artisan('costs:post-pending-fees')->assertSuccessful();
        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
    }

    public function test_a_manual_statement_read_while_the_module_is_off_goes_through_the_same_importer(): void
    {
        [$account] = $this->heldOrder();

        $result = app(ManualStatementImporter::class)->import($account, "order_id,package_id,posted_at,category,component,amount,currency,description\n111,900,2026-11-05,fee,marketplace_commission,25.00,SAR,Commission\n");

        $this->assertSame([1, 1, 0, 0], [$result['rows'], $result['new'], $result['duplicates'], $result['costs_recorded']]);
        $this->assertSame(0, OperationalCostEntry::count());
    }

    public function test_a_fact_that_arrives_again_after_the_switch_is_turned_on_is_posted_by_that_run_and_is_neither_new_nor_a_duplicate(): void
    {
        [$account] = $this->heldOrder();
        $importer = app(ChannelFeeImporter::class);
        $stored = $this->fee('a', '25');
        $importer->import($account, [$stored]);
        config(['operational_costs.enabled' => true]);

        $stats = $importer->import($account, [$stored]);

        $this->assertSame(['new' => 0, 'duplicates' => 0, 'costs_recorded' => 1, 'needs_review' => 0], $stats);
        $this->assertSame(['marketplace_commission' => '25.000000'], $this->actuals());
    }

    public function test_a_fact_read_again_while_the_module_is_still_off_is_an_ordinary_duplicate(): void
    {
        [$account] = $this->heldOrder();
        $importer = app(ChannelFeeImporter::class);
        $importer->import($account, [$this->fee('a', '25')]);

        $stats = $importer->import($account, [$this->fee('a', '25')]);

        $this->assertSame(['new' => 0, 'duplicates' => 1, 'costs_recorded' => 0, 'needs_review' => 0], $stats);
        $this->assertSame(1, SalesChannelEvent::count());
    }

    public function test_events_of_a_fact_that_belongs_to_no_order_are_held_and_posted_the_same_way(): void
    {
        $account = $this->amazonAccount();
        $importer = app(ChannelFeeImporter::class);
        $importer->import($account, [$this->fee('sub-1', '39', orderId: 'NOT-HELD', component: 'marketplace_service_fee')]);
        $this->assertSame(0, OperationalCostEntry::count());

        config(['operational_costs.enabled' => true]);
        $importer->postPending($account);
        $importer->postPending($account);

        $entry = OperationalCostEntry::firstOrFail();
        $this->assertSame(['channel_account', 'unallocated', '39.000000'], [$entry->subject_type, $entry->allocation_status, $entry->amount]);
    }
}
