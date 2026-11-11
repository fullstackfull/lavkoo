<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelPackageSplit;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Shipments\ChannelPackageSplits;
use App\Services\SalesChannels\Shipments\PackageSplitPlan;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Cutting one shipment package into several, at most once, and then finding out whether it worked.
 *
 * Trendyol documents **no idempotency** for any of its four split endpoints, says **nothing** about a repeated identical
 * request, and creates the new packages **asynchronously** — the original turns `UnPacked`, its id becomes invalid, and the
 * new packages appear some time later carrying `createdBy: "split"` and `originPackageIds`.
 *
 * The three things this file is really about:
 *
 *  1. **A 200 is not a confirmation.** Every international split endpoint documents 200 with no content schema, so nothing may
 *     be read out of the answer, and the happy path still has to go and look.
 *  2. **The fence goes up before the request** and nothing is ever sent from behind it — but, unlike a return decision, a
 *     fenced split IS asked about, because the package's own status is an answer Trendyol does publish.
 *  3. **A plan is checked against the package it is for**, including Trendyol's own rule that a multi-split must not name
 *     every line, so a person reads a sentence instead of deciphering a 400.
 */
class TrendyolPackageSplitsTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const SELLER = '123456';

    private const BASE = 'https://apigw.trendyol.com/integration';

    private const ORDERS = self::BASE . '/order/sellers/' . self::SELLER . '/v2/orders*';

    private const SPLIT = self::BASE . '/order/sellers/' . self::SELLER . '/shipment-packages/*';

    private SalesChannelAccount $account;

    /** @var list<array<string, mixed>> the order's packages as the channel would describe them right now */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        $this->fakeOrderReads();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = app(ChannelAccountService::class)->create(
            ['channel' => 'trendyol', 'name' => 'Trendyol KSA', 'external_account_id' => self::SELLER, 'currency' => 'SAR', 'settings' => ['storefront_code' => 'SA', 'integration_name' => 'PharmacyHub']],
            ['api_key' => 'key-abc-123', 'api_secret' => 'secret-xyz-789'],
            adminId: 1,
        );
        $this->account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'fulfillment_dispatch'],
            'capability_approvals' => ['fulfillment_dispatch' => ['by' => 1, 'at' => '2026-11-18T00:00:00Z']],
        ])->save();
        $this->account = $this->account->fresh();
    }

    // ---- the shapes are Trendyol's own, and the four differ ------------------------------------------------------

    public function test_each_of_the_four_splits_uses_its_own_endpoint_and_its_own_body(): void
    {
        $cases = [
            [PackageSplitPlan::ofLines([11, 12]), '/split', ['orderLineIds' => [11, 12], 'shouldKeepPreviousStatus' => false]],
            [PackageSplitPlan::ofGroups([[11], [12]]), '/multi-split', ['splitGroups' => [['orderLineIds' => [11]], ['orderLineIds' => [12]]], 'shouldKeepPreviousStatus' => false]],
            [PackageSplitPlan::ofLineQuantities([11 => [2, 1]]), '/quantity-split', ['quantitySplit' => [['orderLineId' => 11, 'quantities' => [2, 1]]], 'shouldKeepPreviousStatus' => false]],
            [PackageSplitPlan::ofPackageDetails([[11 => 2], [11 => 1]]), '/split-packages', ['splitPackages' => [['packageDetails' => [['orderLineId' => 11, 'quantities' => 2]]], ['packageDetails' => [['orderLineId' => 11, 'quantities' => 1]]]], 'shouldKeepPreviousStatus' => false]],
        ];

        foreach ($cases as [$plan, $suffix, $body]) {
            $this->assertSame($suffix, $plan->path(), 'the endpoint is chosen by the kind of cut');
            $this->assertSame($body, $plan->body());
        }
    }

    public function test_quantities_is_a_list_for_one_endpoint_and_a_single_number_for_the_other(): void
    {
        // Trendyol's two schemas really do differ in this one field, and a confusion between them is a 400 nobody can read.
        $byQuantity = PackageSplitPlan::ofLineQuantities([11 => [2, 1]])->body();
        $byPackage = PackageSplitPlan::ofPackageDetails([[11 => 2]])->body();

        $this->assertSame([2, 1], $byQuantity['quantitySplit'][0]['quantities'], 'quantity-split takes an ARRAY');
        $this->assertSame(2, $byPackage['splitPackages'][0]['packageDetails'][0]['quantities'], 'split-packages takes a single INTEGER');
    }

    public function test_a_stored_plan_is_sent_exactly_as_it_was_recorded(): void
    {
        foreach ([
            PackageSplitPlan::ofLines([11, 12]),
            PackageSplitPlan::ofGroups([[11], [12]]),
            PackageSplitPlan::ofLineQuantities([11 => [2, 1]]),
            PackageSplitPlan::ofPackageDetails([[11 => 2, 12 => 1]]),
        ] as $plan) {
            $replayed = PackageSplitPlan::fromStored($plan->kind, $plan->body(), $plan->keepPreviousStatus);
            $this->assertSame($plan->body(), $replayed->body(), 'what a person asked for is what gets sent');
        }
    }

    // ---- a plan is checked against the package it is for ---------------------------------------------------------

    public function test_a_plan_naming_a_line_the_package_does_not_hold_is_refused_here(): void
    {
        $this->fakeOrder('Created');

        $result = app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([99]));

        $this->assertSame('package_split_names_a_line_the_package_does_not_hold', $result['refusal']);
        $this->assertNull($result['row']);
        $this->assertSame([], $this->sentSplits(), 'nothing was sent');
    }

    public function test_a_multi_split_may_not_name_every_line_because_trendyol_forbids_it(): void
    {
        $this->fakeOrder('Created');

        $result = app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofGroups([[11], [12]]));

        // "All orderLines in a package should not be sent to this service." A sentence beats a 400.
        $this->assertSame('package_multi_split_may_not_name_every_line', $result['refusal']);
        $this->assertSame([], $this->sentSplits());
    }

    public function test_a_split_that_moves_every_line_splits_nothing_and_is_refused(): void
    {
        $this->fakeOrder('Created');

        $result = app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11, 12]));

        $this->assertSame('package_split_of_every_line_splits_nothing', $result['refusal']);
    }

    public function test_a_package_that_cannot_be_read_is_never_split(): void
    {
        // No stub of its own: `preventStrayRequests` would raise, so the read has to fail through the registered one.
        $this->packages = [];
        Http::fake();
        Http::fake([self::ORDERS => Http::response(status: 500)]);

        $result = app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));

        // An unreadable package is not a package whose lines we checked.
        $this->assertSame('package_could_not_be_read_before_splitting', $result['refusal']);
        $this->assertSame([], $this->sentSplits());
    }

    public function test_an_already_unpacked_package_is_not_split_again(): void
    {
        $this->fakeOrder('UnPacked');

        $result = app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));

        $this->assertSame('package_is_already_unpacked_and_its_id_is_no_longer_valid', $result['refusal']);
    }

    public function test_a_package_with_a_live_split_is_not_split_twice(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $first = $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        $this->assertNull($first['refusal']);

        $second = $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([12]));

        $this->assertSame('package_already_has_a_split_pending', $second['refusal']);
        $this->assertSame(1, ChannelPackageSplit::count());
    }

    public function test_a_failed_split_is_asked_for_again_on_the_same_row_and_never_a_second_one(): void
    {
        // The unique key on `(account_id, package_id)` is what stops four processes cutting one package, and it would also
        // stop a person legitimately asking again after a clean failure. So a FAILED row -- the one state that proves the
        // package untouched -- is REVIVED, which keeps both the guarantee and the second chance, and keeps the attempts the
        // row already carries instead of starting a fresh history beside it.
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        Http::fake([self::SPLIT => Http::response(['errors' => [['message' => 'no']]], 400)]);
        $splits->run($this->account);
        $failed = ChannelPackageSplit::firstOrFail();
        $this->assertSame(ChannelPackageSplit::FAILED, $failed->state);
        $this->assertSame(1, (int) $failed->attempts);

        $again = app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([12]));

        $this->assertNull($again['refusal']);
        $this->assertSame(1, ChannelPackageSplit::count(), 'the same row, not a second one beside it');
        $revived = ChannelPackageSplit::firstOrFail();
        $this->assertSame($failed->id, $revived->id);
        $this->assertSame(ChannelPackageSplit::PENDING, $revived->state);
        $this->assertSame([12], $revived->plan['orderLineIds'], 'and it carries the new plan');
        $this->assertNull($revived->failure_code);
        $this->assertSame(1, (int) $revived->attempts, 'the history of what was already tried is kept');
    }

    public function test_a_picking_package_is_split_with_the_previous_status_kept(): void
    {
        // Trendyol's rule, not a preference: "If the old package status was picking, You should send
        // "shouldKeepPreviousStatus" as true". Left false, the new packages come back Created and the preparation is lost.
        $this->fakeOrder('Picking');

        $result = app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));

        $this->assertNull($result['refusal']);
        $this->assertTrue((bool) $result['row']->keep_previous_status, 'the rule is applied even though the caller asked for false');
        $this->assertTrue($result['row']->plan['shouldKeepPreviousStatus']);
    }

    // ---- a 200 is not a confirmation ----------------------------------------------------------------------------

    public function test_an_empty_two_hundred_leaves_the_row_waiting_for_the_packages_not_confirmed(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));

        // The documented success: 200 with no body at all. And the package has not changed yet, because the split is
        // asynchronous -- which is exactly the state a naive integration would read as failure and send again.
        Http::fake([self::SPLIT => Http::response(status: 200)]);
        $stats = $splits->run($this->account);

        $row = ChannelPackageSplit::firstOrFail();
        $this->assertSame(ChannelPackageSplit::MAY_HAVE_SENT, $row->state, 'a 200 is not proof: the packages are made asynchronously');
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(0, $stats['confirmed']);
        $this->assertCount(1, $this->sentSplits());
    }

    public function test_the_split_is_confirmed_by_reading_the_order_not_by_the_answer(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        Http::fake([self::SPLIT => Http::response(status: 200)]);
        $splits->run($this->account);

        // Now Trendyol has caught up: the original is UnPacked and two new packages name it as their origin.
        $this->fakeSplitOrder();
        $this->travelTo(now()->addMinutes(5));
        $stats = app(ChannelPackageSplits::class)->run($this->account);

        $row = ChannelPackageSplit::firstOrFail();
        $this->assertSame(ChannelPackageSplit::CONFIRMED, $row->state);
        $this->assertSame(['PKG-2', 'PKG-3'], $row->new_package_ids, 'read off createdBy=split and originPackageIds, never off the split response');
        $this->assertSame(1, $stats['confirmed']);
        $this->assertCount(1, $this->sentSplits(), 'and nothing was sent a second time');
    }

    public function test_a_fenced_row_is_never_sent_again_however_many_times_it_is_run(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        Http::fake([self::SPLIT => Http::response(status: 200)]);
        $splits->run($this->account);

        for ($pass = 0; $pass < 4; $pass++) {
            $this->travelTo(now()->addMinutes(5));
            app(ChannelPackageSplits::class)->run($this->account);
        }

        $this->assertCount(1, $this->sentSplits(), 'the package is cut once, whatever the status reads');
        $this->assertSame(1, (int) ChannelPackageSplit::firstOrFail()->attempts);
    }

    public function test_a_row_whose_proof_never_arrives_waits_for_a_person_and_is_not_called_failed(): void
    {
        config(['sales_channels.splits.max_checks' => 2]);
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        Http::fake([self::SPLIT => Http::response(status: 200)]);
        $splits->run($this->account);

        for ($pass = 0; $pass < 3; $pass++) {
            $this->travelTo(now()->addMinutes(5));
            app(ChannelPackageSplits::class)->run($this->account);
        }

        $row = ChannelPackageSplit::firstOrFail();
        // NOT failed: the request was answered, so the package may well be cut. Calling it failed invites a second cut.
        $this->assertSame(ChannelPackageSplit::NEEDS_REVIEW, $row->state);
        $this->assertStringContainsString('package_still_reads_as_it_did', (string) $row->failure_code);
        $this->assertCount(1, $this->sentSplits());
    }

    public function test_a_timeout_is_treated_exactly_like_a_two_hundred(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));

        // What has to be learned is the package's state, not the request's -- so an unclear answer and a clear one lead to the
        // same place. The only difference is the reason recorded.
        Http::fake([self::SPLIT => Http::response(status: 504)]);
        $splits->run($this->account);

        $row = ChannelPackageSplit::firstOrFail();
        $this->assertSame(ChannelPackageSplit::MAY_HAVE_SENT, $row->state);
        $this->assertSame('answer_unclear_awaiting_the_packages', $row->failure_code);
    }

    public function test_a_refusal_proves_the_package_was_not_cut_so_the_fence_comes_down(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        Http::fake([self::SPLIT => Http::response(['errors' => [['message' => 'orderLineId is not valid']]], 400)]);

        $stats = $splits->run($this->account);

        $row = ChannelPackageSplit::firstOrFail();
        $this->assertSame(ChannelPackageSplit::FAILED, $row->state);
        $this->assertSame('trendyol_refused_400', $row->failure_code);
        $this->assertSame(1, $stats['failed']);
        // And because it failed before anything happened, a person may ask again.
        $this->assertFalse($row->blocksAnotherSplit());
    }

    public function test_a_rehearsal_sends_nothing_and_leaves_the_row_able_to_be_sent(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        $this->account->forceFill(['dry_run' => true])->save();

        $splits->run($this->account->fresh());

        $row = ChannelPackageSplit::firstOrFail();
        $this->assertSame(ChannelPackageSplit::PENDING, $row->state);
        $this->assertSame('rehearsal_sent_nothing', $row->failure_code);
        $this->assertSame([], $this->sentSplits());
    }

    public function test_with_external_writes_off_nothing_is_sent_and_the_row_keeps_its_plan(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        config(['sales_channels.external_writes_enabled' => false]);

        $stats = $splits->run($this->account);

        $row = ChannelPackageSplit::firstOrFail();
        $this->assertSame(ChannelPackageSplit::PENDING, $row->state);
        $this->assertNotNull($stats['stopped']);
        $this->assertSame(0, (int) $row->attempts, 'a switch somebody turned off does not spend a row\'s tries');
        $this->assertSame([], $this->sentSplits());
    }

    public function test_the_whole_pipeline_says_so_when_its_table_has_not_been_migrated(): void
    {
        $this->assertTrue(ChannelPackageSplits::ready());
        \Illuminate\Support\Facades\Schema::drop('channel_package_splits');

        $this->assertFalse(ChannelPackageSplits::ready());
        $this->assertSame('splits_table_not_migrated', app(ChannelPackageSplits::class)->run($this->account)['stopped']);
        $this->assertSame('splits_table_not_migrated', app(ChannelPackageSplits::class)->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]))['refusal']);
    }

    public function test_a_successor_of_a_cancellation_is_not_mistaken_for_a_split(): void
    {
        $this->fakeOrder('Created');
        $splits = app(ChannelPackageSplits::class);
        $splits->request($this->account, 'ORDER-1', 'PKG-1', PackageSplitPlan::ofLines([11]));
        Http::fake([self::SPLIT => Http::response(status: 200)]);
        $splits->run($this->account);

        // The original is still Created, and the only other package of the order came from a TRANSFER. Counting packages would
        // have called this a split; reading Trendyol's own markers does not.
        $this->packages = [
            $this->package('PKG-1', 'Created'),
            ['createdBy' => 'transfer', 'originPackageIds' => ['PKG-1']] + $this->package('PKG-9', 'Created'),
        ];
        $this->travelTo(now()->addMinutes(5));
        app(ChannelPackageSplits::class)->run($this->account);

        $this->assertSame(ChannelPackageSplit::MAY_HAVE_SENT, ChannelPackageSplit::firstOrFail()->state, 'a transfer is not a cut');
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /**
     * The order, as it stands now.
     *
     * **One stub, registered once, answering from mutable state.** `Http::fake()` APPENDS and the first matching stub wins, so
     * a second `Http::fake([self::ORDERS => ...])` would never answer -- and a test about a package that changes over time
     * would silently keep reading the first answer for ever.
     */
    private function fakeOrderReads(): void
    {
        Http::fake([self::ORDERS => fn () => Http::response(['content' => $this->packages])]);
    }

    private function fakeOrder(string $status): void
    {
        $this->packages = [$this->package('PKG-1', $status)];
    }

    private function fakeSplitOrder(): void
    {
        $this->packages = [
            // Trendyol's own words: "The current shipmentPackageIds gets invalid and its status is updated to UnPacked".
            $this->package('PKG-1', 'UnPacked'),
            ['createdBy' => 'split', 'originPackageIds' => ['PKG-1']] + $this->package('PKG-2', 'Created'),
            ['createdBy' => 'split', 'originPackageIds' => ['PKG-1']] + $this->package('PKG-3', 'Created'),
        ];
    }

    /** @return array<string, mixed> */
    private function package(string $packageId, string $status): array
    {
        return [
            'shipmentPackageId' => $packageId,
            'orderNumber' => 'ORDER-1',
            'shipmentPackageStatus' => $status,
            'status' => $status,
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
    private function sentSplits(): array
    {
        return Http::recorded(static fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/shipment-packages/'))
            ->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
