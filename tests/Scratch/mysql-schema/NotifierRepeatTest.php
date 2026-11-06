<?php

namespace Tests\Scratch\MysqlSchema;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderMapping;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use App\Services\SalesChannels\Shipments\ExternalShipmentLinker;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

class NotifierRepeatTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const TY = 'https://apigw.trendyol.com/integration';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
    }

    public function test_the_channel_is_told_again_after_the_order_changes_at_the_channel(): void
    {
        $account = $this->trendyolAccount();
        Http::fake([
            self::TY . '/order/sellers/1234567/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package(['whoPays' => 1])])),
            self::TY . '/order/sellers/*' => Http::response('', 200),
        ]);
        $ingestor = app(ChannelOrderIngestor::class);
        $overrides = ['fulfillmentMode' => FulfillmentMode::MerchantManaged, 'fulfillmentChannelRaw' => 'seller_pays'];
        $mapping = $ingestor->ingest($account, $this->remoteOrder('10234567890', '3012345678', ChannelOrderStatus::New, null, overrides: $overrides))->mapping;
        $mapping->forceFill(['shipped_at' => now()])->save();
        $linked = app(ExternalShipmentLinker::class)->link($mapping->refresh(), 'torod_point', 'TOR-1', 1, trackingNumber: 'TRK-9', carrier: 'SMSA')->load('lines', 'account');
        $notifier = app(ChannelShipmentNotifier::class);

        $first = $notifier->notify($linked);
        // Trendyol now shows the package as Shipped (the order changes at the channel; the poller ingests it)
        $ingestor->ingest($account, $this->remoteOrder('10234567890', '3012345678', ChannelOrderStatus::Shipped, null, '2026-11-10 12:00:00', overrides: $overrides));
        $second = $notifier->notify(ExternalOrderMapping::first()->load('lines', 'account'));

        $puts = array_values(array_filter(Http::recorded()->map(fn (array $pair): Request => $pair[0])->all(), fn (Request $request): bool => $request->method() === 'PUT'));
        fwrite(STDERR, "\nFIRST=" . json_encode($first) . ' SECOND=' . json_encode($second) . ' PUT calls=' . count($puts) . "\n");
        $this->assertSame('already_notified', $second['reason']);
    }
}
