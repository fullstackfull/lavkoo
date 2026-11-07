<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelHttp;
use App\Services\SalesChannels\Support\ChannelOperations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/** Reading a buyer's name, phone and address needs its own switch AND a recorded approval, on top of everything a read needs. */
class BuyerDataGateTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const RECIPIENT = 'trendyol.test_recipient';

    private ChannelHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        ChannelOperations::register([self::RECIPIENT => ChannelOperations::read(capabilities: [IntegrationCapability::FulfillmentDispatch], buyerData: true)]);
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true, 'sales_channels.buyer_data_transfer_enabled' => true]);
        $this->http = app(ChannelHttp::class);
    }

    private function account(array $overrides = []): SalesChannelAccount
    {
        return SalesChannelAccount::create($overrides + [
            'channel' => 'trendyol', 'name' => 'Test', 'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'fulfillment_dispatch'],
            'capability_approvals' => ['fulfillment_dispatch' => ['by' => 1], 'buyer_data_transfer' => ['by' => 1, 'at' => '2026-11-07T00:00:00Z', 'reason' => 'approved for shipping']],
        ]);
    }

    private function read(SalesChannelAccount $account): void
    {
        $this->http->send($account, self::RECIPIENT, 'GET', 'https://channel.example/packages/1');
    }

    private function assertRefused(string $reason, SalesChannelAccount $account): void
    {
        try {
            $this->read($account);
            $this->fail("Expected the read to be refused with '{$reason}'.");
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
        Http::assertNothingSent();
    }

    public function test_the_buyer_data_switch_off_refuses_the_read_before_anything_is_sent(): void
    {
        config(['sales_channels.buyer_data_transfer_enabled' => false]);

        $this->assertRefused(ChannelUnavailableException::BUYER_DATA_OFF, $this->account());
    }

    public function test_the_switch_alone_is_not_enough_without_the_recorded_approval(): void
    {
        $this->assertRefused(ChannelUnavailableException::NOT_APPROVED, $this->account(['capability_approvals' => ['fulfillment_dispatch' => ['by' => 1]]]));
    }

    public function test_a_read_that_needs_buyer_data_also_needs_the_dispatch_capability(): void
    {
        $this->assertRefused(ChannelUnavailableException::CAPABILITY_OFF, $this->account(['capabilities' => ['connection_test', 'read_only_sync', 'order_import']]));
    }

    public function test_with_the_switch_the_approval_and_the_capability_the_read_goes_out_and_its_log_hides_the_person(): void
    {
        Http::fake(['channel.example/*' => Http::response(['shipmentAddress' => ['fullName' => 'Hind Al-Otaibi', 'phone' => '0555123456', 'address1' => 'Prince Sultan Rd 12', 'city' => 'Jeddah']])]);

        $this->read($this->account());

        Http::assertSentCount(1);
        $stored = json_encode(SalesChannelApiLog::firstOrFail()->toArray());
        $this->assertStringNotContainsString('Hind', $stored);
        $this->assertStringNotContainsString('0555123456', $stored);
        $this->assertStringNotContainsString('Prince Sultan', $stored);
    }

    public function test_an_ordinary_read_is_not_held_by_the_buyer_data_gate(): void
    {
        config(['sales_channels.buyer_data_transfer_enabled' => false]);
        ChannelOperations::register(['trendyol.test_plain' => ChannelOperations::read(capabilities: [IntegrationCapability::OrderImport], people: true)]);
        Http::fake(['channel.example/*' => Http::response(['content' => []])]);

        $this->http->send($this->account(['capability_approvals' => []]), 'trendyol.test_plain', 'GET', 'https://channel.example/orders');

        Http::assertSentCount(1);
    }

    public function test_switching_dispatch_off_withdraws_the_buyer_data_approval_with_it(): void
    {
        $account = $this->account();

        app(ChannelAccountService::class)->disableCapability($account, IntegrationCapability::FulfillmentDispatch, 1);

        $this->assertArrayNotHasKey('buyer_data_transfer', (array) $account->fresh()->capability_approvals);
    }

    public function test_the_approval_is_a_kind_of_approval_a_person_can_record_with_a_reason(): void
    {
        $account = $this->account(['capability_approvals' => [], 'connection_status' => 'ok', 'connection_checked_at' => now()]);

        app(ChannelAccountService::class)->approveScope($account, ChannelOperations::SCOPE_BUYER_DATA_TRANSFER, 7, 'Owner approved: buyer details are sent to Torod Point only to ship the order');

        $this->assertSame(7, $account->fresh()->capability_approvals['buyer_data_transfer']['by']);
    }
}
