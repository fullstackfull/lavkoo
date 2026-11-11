<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelPackageSplit;
use App\Models\ChannelReturnDecision;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Returns\ChannelReturnDecisions;
use App\Services\SalesChannels\Shipments\ChannelPackageSplits;
use App\Services\SalesChannels\Shipments\PackageSplitPlan;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * The run that carries what a person decided.
 *
 * **Both pipelines are useless without it**, and that was the hole this file closes: `ChannelReturnDecisions` and
 * `ChannelPackageSplits` were written, tested and scheduled by nothing, so a return a pharmacist approved would have sat in a
 * table for ever and the customer would never have been refunded. A service with no caller is not delivered.
 */
class DecisionsCommandTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const SELLER = '123456';

    private const BASE = 'https://apigw.trendyol.com/integration';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::fake([
            self::BASE . '/order/claim-issue-reasons' => Http::response([['id' => 51, 'name' => 'Ürün kullanılmış']]),
            self::BASE . '/order/sellers/*/claims/*' => Http::response('', 200),
            self::BASE . '/order/sellers/*/v2/orders*' => fn () => Http::response(['content' => [$this->package()]]),
            self::BASE . '/order/sellers/*/shipment-packages/*' => Http::response('', 200),
        ]);
        $this->account = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => self::SELLER, 'currency' => 'SAR', 'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub']],
            ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789'],
            adminId: 1,
        );
        $this->account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'fulfillment_dispatch'],
            'capability_approvals' => [
                ChannelOperations::SCOPE_RETURN_DECISIONS => ['by' => 1, 'at' => '2026-11-18T00:00:00Z'],
                'fulfillment_dispatch' => ['by' => 1, 'at' => '2026-11-18T00:00:00Z'],
            ],
        ])->save();
        $this->account = $this->account->fresh();
    }

    public function test_a_decision_a_person_made_is_actually_sent_by_the_run(): void
    {
        app(ChannelReturnDecisions::class)->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        $this->assertSame(ChannelReturnDecision::PENDING, ChannelReturnDecision::firstOrFail()->state);

        $this->artisan('channels:decisions')->assertSuccessful();

        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state);
        $this->assertCount(1, $this->sent('/claims/'));
    }

    public function test_a_split_a_person_asked_for_is_actually_sent_by_the_run(): void
    {
        app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]), adminId: 1);

        $this->artisan('channels:decisions')->assertSuccessful();

        // Fenced and sent, and waiting for the proof -- never confirmed off a 200.
        $this->assertSame(ChannelPackageSplit::MAY_HAVE_SENT, ChannelPackageSplit::firstOrFail()->state);
        $this->assertCount(1, $this->sent('/shipment-packages/'));
    }

    public function test_each_switch_works_on_its_own(): void
    {
        app(ChannelReturnDecisions::class)->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]), adminId: 1);

        $this->artisan('channels:decisions --returns')->assertSuccessful();

        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state);
        $this->assertSame(ChannelPackageSplit::PENDING, ChannelPackageSplit::firstOrFail()->state, 'the splits were left alone');

        $this->artisan('channels:decisions --splits')->assertSuccessful();
        $this->assertSame(ChannelPackageSplit::MAY_HAVE_SENT, ChannelPackageSplit::firstOrFail()->state);
    }

    public function test_status_sends_nothing_and_counts_what_is_waiting(): void
    {
        app(ChannelReturnDecisions::class)->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);

        $this->artisan('channels:decisions --status')->expectsOutputToContain('returns_pending')->assertSuccessful();

        $this->assertSame(ChannelReturnDecision::PENDING, ChannelReturnDecision::firstOrFail()->state);
        $this->assertSame([], $this->sent('/claims/'));
    }

    public function test_what_nothing_will_retry_is_said_out_loud(): void
    {
        // A fenced return decision is somebody's job now: Trendyol publishes no way to ask what became of it, so a run that
        // printed only its own totals would let it sit unnoticed between one scheduled pass and the next.
        app(ChannelReturnDecisions::class)->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        ChannelReturnDecision::query()->update(['state' => ChannelReturnDecision::NEEDS_REVIEW]);

        $this->artisan('channels:decisions')->expectsOutputToContain('a person has to check them')->assertSuccessful();
    }

    public function test_with_the_integrations_switched_off_nothing_is_sent(): void
    {
        app(ChannelReturnDecisions::class)->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        config(['sales_channels.enabled' => false]);

        $this->artisan('channels:decisions')->expectsOutputToContain('switched off')->assertSuccessful();

        $this->assertSame(ChannelReturnDecision::PENDING, ChannelReturnDecision::firstOrFail()->state);
        $this->assertSame([], $this->sent('/claims/'));
    }

    public function test_it_runs_on_a_host_where_only_one_of_the_two_tables_exists(): void
    {
        // The tables ship before their migrations have to have run, and they are separate migrations: a host part-way through
        // must not have the whole run fail because one of them is not there yet.
        app(ChannelReturnDecisions::class)->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        Schema::drop('channel_package_splits');

        $this->artisan('channels:decisions')->assertSuccessful();

        $this->assertSame(ChannelReturnDecision::DONE, ChannelReturnDecision::firstOrFail()->state);
    }

    public function test_an_account_that_does_not_exist_is_an_error_and_not_a_silent_pass(): void
    {
        $this->artisan('channels:decisions 999999')->expectsOutputToContain('No such sales channel account')->assertFailed();
    }

    public function test_a_disabled_account_is_not_worked_by_the_schedule(): void
    {
        app(ChannelReturnDecisions::class)->decide($this->account, 'CLAIM-1', ['ITEM-A'], ChannelReturnDecision::APPROVE, adminId: 1);
        $this->account->forceFill(['enabled' => false])->save();

        $this->artisan('channels:decisions')->expectsOutputToContain('No enabled account')->assertSuccessful();

        $this->assertSame(ChannelReturnDecision::PENDING, ChannelReturnDecision::firstOrFail()->state);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function package(): array
    {
        return [
            'shipmentPackageId' => 'PKG-1',
            'orderNumber' => 'ORDER-1',
            'shipmentPackageStatus' => 'Created',
            'status' => 'Created',
            'orderDate' => 1_763_000_000_000,
            'lastModifiedDate' => 1_763_000_000_000,
            'grossAmount' => 100.0,
            'totalDiscount' => 0.0,
            'totalPrice' => 100.0,
            'currencyCode' => 'SAR',
            'lines' => [
                ['lineId' => 11, 'id' => 11, 'barcode' => 'BAR-11', 'quantity' => 3, 'price' => 50.0, 'amount' => 50.0, 'discount' => 0.0],
                ['lineId' => 12, 'id' => 12, 'barcode' => 'BAR-12', 'quantity' => 1, 'price' => 50.0, 'amount' => 50.0, 'discount' => 0.0],
            ],
        ];
    }

    /** @return list<Request> */
    private function sent(string $fragment): array
    {
        return Http::recorded(static fn (Request $request): bool => $request->method() !== 'GET' && str_contains($request->url(), $fragment))
            ->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
