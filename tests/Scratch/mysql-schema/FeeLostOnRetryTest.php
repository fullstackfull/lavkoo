<?php

namespace Tests\Scratch\MysqlSchema;

use App\Enums\OperationalCosts\CostComponent;
use App\Models\ExternalOrderMapping;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelEvent;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\Data\RemoteFinancialEntry;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

class FeeLostOnRetryTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    public function test_a_fee_whose_recording_failed_once_is_never_recorded_by_the_next_run(): void
    {
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR']);
        $account = $this->trendyolAccount();
        ExternalOrderMapping::create(['account_id' => $account->id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '111', 'external_package_id' => '900', 'status' => 'delivered', 'currency' => 'SAR']);
        $fact = new RemoteFinancialEntry('fact-a', RemoteFinancialEntry::FEE, CostComponent::MarketplaceCommission, Money::of('25'), 'SAR', '111', '900', null, null, CarbonImmutable::parse('2026-11-06 10:00:00 UTC'), 'Test');

        // The first run: the database refuses once (a deadlock victim, a lock wait timeout, a dropped connection).
        $real = app(CostLedger::class);
        $this->app->instance(CostLedger::class, new class($real) extends CostLedger {
            public function __construct(private CostLedger $real)
            {
            }

            public function record(CostDraft $draft): RecordOutcome
            {
                throw new QueryException('mysql', 'insert into operational_cost_entries ...', [], new \Exception('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });
        $first = null;
        try {
            app(ChannelFeeImporter::class)->import($account, [$fact]);
        } catch (\Throwable $e) {
            $first = get_class($e);
        }

        // The next run (the sync re-reads the window, the channel returns the same fact again) with a healthy database.
        $this->app->instance(CostLedger::class, $real);
        $this->app->forgetInstance(ChannelFeeImporter::class);
        $second = app(ChannelFeeImporter::class)->import($account, [$fact]);

        fwrite(STDERR, "\nFIRST threw=" . var_export($first, true) . ' SECOND=' . json_encode($second) . ' events=' . SalesChannelEvent::count() . ' entries=' . OperationalCostEntry::count() . "\n");
        $this->assertSame(1, OperationalCostEntry::count(), 'the commission must reach the ledger');
    }
}
