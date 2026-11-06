<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderMappingService;
use App\Services\Fulfillment\ProviderSettingsSaver;
use App\Services\Fulfillment\Providers\M5azn\M5aznFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodInvoiceRoundTrip;
use App\Services\Fulfillment\Readiness\GoLiveItem;
use App\Services\Fulfillment\Readiness\GoLiveOverride;
use App\Services\Fulfillment\Readiness\GoLiveReadiness;
use App\Services\Fulfillment\Readiness\GoLiveReport;
use App\Services\Fulfillment\ShipmentTracker;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic sending is a decision, not a default: it may only become live when what has really happened on this server says the
 * provider is ready, or when the master admin goes ahead knowingly, with a reason, on the record.
 */
class GoLiveReadinessTest extends AdminPagesTestCase
{
    private const REASON = 'The warehouse confirmed by phone that the first parcel left.';

    protected function setUp(): void
    {
        parent::setUp();

        // The controlled phase: sending is on, but nothing goes out without a person approving it.
        foreach (ProviderCode::cases() as $provider) {
            $this->enableProvider($provider, ['auto_dispatch' => false] + ($provider === ProviderCode::TorodPoint ? ['company_id' => '714'] : []));
        }
        $this->enableTheScheduler();
        config(['queue.default' => 'sync']);
    }

    private function readiness(): GoLiveReadiness
    {
        return app(GoLiveReadiness::class);
    }

    private function saver(): ProviderSettingsSaver
    {
        return app(ProviderSettingsSaver::class);
    }

    private function config(ProviderCode $provider): \App\Services\Fulfillment\Support\ProviderConfig
    {
        return app(ProviderConnectionManager::class)->config($provider);
    }

    /** @return array<string, string> item key => status */
    private function statuses(ProviderCode $provider): array
    {
        return collect($this->readiness()->report($provider)->items)->mapWithKeys(static fn (GoLiveItem $item): array => [$item->key => $item->status])->all();
    }

    /** @return list<string> */
    private function unmet(ProviderCode $provider): array
    {
        return array_map(static fn (GoLiveItem $item): string => $item->key, $this->readiness()->report($provider)->unmet());
    }

    private function enableTheScheduler(): void
    {
        app(ProviderSyncRecorder::class)->attempted(ProviderSyncRecorder::SYSTEM, ProviderSyncRecorder::SWEEP);
    }

    private function verifyMap(ProviderCode $provider, string $capability, array $paths, array $sample, array $required): void
    {
        app(FieldMapStore::class)->save($provider, $capability, $paths, null);
        $this->assertSame([], app(FieldMapStore::class)->verify($provider, $capability, $sample, $required));
    }

    /** A shipment the provider accepted, the way the dispatcher records it. */
    private function acceptedShipment(ProviderCode $provider, int $orderId, string $reference): ProviderFulfillment
    {
        $this->makeOrder($orderId);
        $shipment = $this->shipmentFor($orderId, $provider, FulfillmentStatus::Dispatching);
        app(ShipmentTracker::class)->recordAcceptance($shipment->id, SubmissionResult::accepted(providerReference: $reference));

        return $shipment->refresh();
    }

    /** Everything the controlled phase is meant to produce, for one provider. */
    private function reachGoLiveConditions(ProviderCode $provider): void
    {
        app(ProviderSyncRecorder::class)->succeeded($provider->value, ProviderSyncRecorder::LIVE_VERIFICATION, ['pass' => 8]);

        if ($provider === ProviderCode::M5azn) {
            $this->verifyMap($provider, M5aznFieldMaps::CATALOG, ['id' => 'id', 'name' => 'name'], [['id' => 1, 'name' => 'Vitamin C']], ['id', 'name']);
            $this->verifyMap($provider, M5aznFieldMaps::INVENTORY, ['id' => 'id', 'stock' => 'quantity'], [['id' => 1, 'quantity' => 4]], ['id', 'stock']);
            $this->verifyMap($provider, M5aznFieldMaps::ORDERS, ['reference' => 'id', 'status' => 'status'], [['id' => 9, 'status' => 'processing']], ['reference', 'status']);
            app(ProviderConnectionManager::class)->save($provider, true, null, ['status_map' => ['processing' => 'processing']]);
        } else {
            $this->verifyMap($provider, TorodFieldMaps::PRODUCTS, ['id' => 'id', 'name' => 'name'], [['id' => 1, 'name' => 'Panadol']], ['id', 'name']);
            $this->verifyMap($provider, TorodFieldMaps::ORDERS, ['invoice_number' => 'invoiceNumber', 'reference' => 'barcode'], [['invoiceNumber' => '1-TP1', 'barcode' => 'B1']], ['invoice_number', 'reference']);
            app(TorodInvoiceRoundTrip::class)->confirm(adminId: 1, adminName: 'Admin', evidence: 'Provider confirmed in writing that the invoice number comes back.');
        }

        $productId = $this->makeProduct(900 + (int) ($provider === ProviderCode::TorodPoint), $provider);
        app(ProviderMappingService::class)->approve($provider, $productId, '', 'EXT-' . $productId);

        $shipment = $this->acceptedShipment($provider, 500 + $productId, 'REF-' . $productId);
        app(ShipmentTracker::class)->applyProviderStatus($shipment->id, FulfillmentStatus::Processing, null, 'poll', 'processing');
    }

    private function shipmentFor(int $orderId, ProviderCode $provider, FulfillmentStatus $status, array $overrides = []): ProviderFulfillment
    {
        return ProviderFulfillment::create($overrides + [
            'reference' => $orderId . '-' . $provider->value, 'order_id' => $orderId, 'provider' => $provider->value, 'generation' => 1,
            'idempotency_key' => hash('sha256', $orderId . $provider->value . $status->value), 'status' => $status,
            'payment_mode' => 'prepaid', 'currency' => 'SAR',
        ]);
    }

    /** What the administrator's form submits for the switches that make sending automatic. */
    private function armed(array $overrides = []): array
    {
        return ['enabled' => true, 'baseUrl' => null, 'settings' => $overrides + ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false], 'secrets' => [], 'clearSecrets' => []];
    }

    private function master(): GoLiveOverride
    {
        return new GoLiveOverride(adminId: 1, adminName: 'Admin', reason: self::REASON);
    }

    private function createAuditTable(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    /** @return list<array{0: ProviderCode}> */
    public static function providers(): array
    {
        return [[ProviderCode::M5azn], [ProviderCode::TorodPoint]];
    }

    // ── the checklist ────────────────────────────────────────────────────────────────────────────────────────

    /** @dataProvider providers */
    public function test_a_provider_that_has_only_been_configured_is_not_ready_and_says_why(ProviderCode $provider): void
    {
        $report = $this->readiness()->report($provider);
        $statuses = $this->statuses($provider);

        $this->assertFalse($report->ready());
        foreach (['credentials_saved', 'integration_on', 'external_writes_allowed', 'scheduler_running', 'queue_working', 'no_unresolved_shipments'] as $met) {
            $this->assertSame(GoLiveItem::MET, $statuses[$met], $met);
        }
        foreach (['live_verification_passed', 'field_maps_verified', 'products_ready', 'first_order_accepted', 'tracking_received'] as $unmet) {
            $this->assertSame(GoLiveItem::UNMET, $statuses[$unmet], $unmet);
        }
    }

    /** @dataProvider providers */
    public function test_every_blocking_item_is_met_once_the_controlled_phase_has_produced_its_proof(ProviderCode $provider): void
    {
        $this->reachGoLiveConditions($provider);

        $report = $this->readiness()->report($provider);

        $this->assertSame([], array_map(static fn (GoLiveItem $item): string => $item->key, $report->unmet()), 'nothing may still hold automatic sending back');
        $this->assertTrue($report->ready());
    }

    public function test_the_advisory_items_are_shown_but_never_hold_anything_back(): void
    {
        $this->reachGoLiveConditions(ProviderCode::M5azn);

        $report = $this->readiness()->report(ProviderCode::M5azn);
        $statuses = $this->statuses(ProviderCode::M5azn);

        $this->assertTrue($report->ready());
        $this->assertSame(GoLiveItem::ADVISORY, $statuses['stock_sync_running'], 'stock sync is not switched on');
        $this->assertSame(GoLiveItem::ADVISORY, $statuses['order_reference_named']);
        $this->assertSame(GoLiveItem::ADVISORY, $statuses['cod_settlement']);
        $this->assertSame(GoLiveItem::MET, $statuses['credentials_saved']);
    }

    /** @return array<string, array{0: callable(self): void, 1: string}> */
    public static function breakages(): array
    {
        return [
            'credentials removed' => [static fn (self $test) => app(ProviderConnectionManager::class)->save(ProviderCode::M5azn, true, null, [], [], ['email']), 'credentials_saved'],
            'provider switched off' => [static fn (self $test) => app(ProviderConnectionManager::class)->save(ProviderCode::M5azn, false, null, []), 'integration_on'],
            'server kill switch off' => [static fn (self $test) => config(['fulfillment.enabled' => false]), 'integration_on'],
            'external writes off' => [static fn (self $test) => config(['fulfillment.external_writes_enabled' => false]), 'external_writes_allowed'],
            'verification too old' => [static fn (self $test) => ProviderSyncState::forCapability('m5azn', 'live_verification')->forceFill(['last_success_at' => now()->subDays(15)])->save(), 'live_verification_passed'],
            'latest verification failed' => [static fn (self $test) => app(ProviderSyncRecorder::class)->failed('m5azn', 'live_verification', '1 check(s) failed: M-6'), 'live_verification_passed'],
            'a field map changed' => [static fn (self $test) => app(FieldMapStore::class)->save(ProviderCode::M5azn, M5aznFieldMaps::ORDERS, ['reference' => 'orderId', 'status' => 'status'], null), 'field_maps_verified'],
            'no status map' => [static fn (self $test) => app(ProviderConnectionManager::class)->save(ProviderCode::M5azn, true, null, ['status_map' => []]), 'status_tracking_ready'],
            'no product assigned' => [static fn (self $test) => DB::table('products')->update(['fulfillment_provider' => null]), 'products_ready'],
            'scheduler stopped' => [static fn (self $test) => ProviderSyncState::forCapability('system', 'sweep')->forceFill(['last_attempt_at' => now()->subMinutes(30)])->save(), 'scheduler_running'],
            'nothing accepted yet' => [static fn (self $test) => ProviderFulfillmentEvent::where('event_type', 'dispatch')->delete(), 'first_order_accepted'],
            'a send outcome unknown' => [static fn (self $test) => ProviderFulfillment::query()->update(['status' => FulfillmentStatus::ReconcilePending->value]), 'no_unresolved_shipments'],
            'tracking only ever failed to match' => [static fn (self $test) => ProviderFulfillmentEvent::query()->update(['outcome' => EventOutcome::IGNORED_UNMAPPED]), 'tracking_received'],
            'tracking only from our own system' => [static fn (self $test) => ProviderFulfillmentEvent::query()->update(['source' => 'system']), 'tracking_received'],
        ];
    }

    /** @dataProvider breakages */
    public function test_each_condition_alone_holds_automatic_sending_back(callable $break, string $expectedUnmet): void
    {
        $this->reachGoLiveConditions(ProviderCode::M5azn);
        $this->assertTrue($this->readiness()->report(ProviderCode::M5azn)->ready(), 'the arrangement itself is ready');

        $break($this);

        $this->assertContains($expectedUnmet, $this->unmet(ProviderCode::M5azn));
    }

    public function test_a_status_update_found_by_the_poll_counts_even_though_m5azn_has_no_webhook(): void
    {
        $shipment = $this->acceptedShipment(ProviderCode::M5azn, 700, 'MZ-700');
        $this->assertSame(GoLiveItem::UNMET, $this->statuses(ProviderCode::M5azn)['tracking_received'], 'acceptance is not tracking');

        app(ShipmentTracker::class)->applyProviderStatus($shipment->id, FulfillmentStatus::Processing, null, 'poll', 'processing');

        $this->assertSame(GoLiveItem::MET, $this->statuses(ProviderCode::M5azn)['tracking_received']);
        $this->assertSame('m5azn', ProviderFulfillmentEvent::where('source', 'poll')->value('provider'), 'an update seen by the poll belongs to the provider of its shipment');
        $this->assertSame(GoLiveItem::UNMET, $this->statuses(ProviderCode::TorodPoint)['tracking_received'], 'and to no other provider');
    }

    public function test_an_order_linked_by_hand_is_not_an_order_the_provider_accepted(): void
    {
        $this->makeOrder(701);
        $held = $this->shipmentFor(701, ProviderCode::M5azn, FulfillmentStatus::Held);

        $this->assertTrue(app(ShipmentTracker::class)->linkProviderReference($held->id, 'ANYTHING-I-TYPE', null, 'typed by hand'));

        $this->assertSame(GoLiveItem::UNMET, $this->statuses(ProviderCode::M5azn)['first_order_accepted'], 'nothing was ever asked of the provider');

        $this->acceptedShipment(ProviderCode::M5azn, 702, 'MZ-702');

        $this->assertSame(GoLiveItem::MET, $this->statuses(ProviderCode::M5azn)['first_order_accepted']);
    }

    public function test_an_order_found_at_the_provider_by_a_lookup_counts_as_accepted(): void
    {
        $this->makeOrder(703);
        $unknown = $this->shipmentFor(703, ProviderCode::M5azn, FulfillmentStatus::ReconcilePending);

        app(ShipmentTracker::class)->recordAcceptance($unknown->id, SubmissionResult::accepted(providerReference: 'MZ-703'));

        $this->assertSame(GoLiveItem::MET, $this->statuses(ProviderCode::M5azn)['first_order_accepted']);
    }

    public function test_a_mapping_only_counts_for_a_product_that_is_assigned_to_the_provider(): void
    {
        $assigned = $this->makeProduct(910, ProviderCode::M5azn);
        $unassigned = $this->makeProduct(911);
        app(ProviderMappingService::class)->approve(ProviderCode::M5azn, $unassigned, '', 'EXT-911');

        $this->assertSame(GoLiveItem::UNMET, $this->statuses(ProviderCode::M5azn)['products_ready'], 'one product is assigned and one is mapped, but no product is both');

        app(ProviderMappingService::class)->approve(ProviderCode::M5azn, $assigned, '', 'EXT-910');

        $this->assertSame(GoLiveItem::MET, $this->statuses(ProviderCode::M5azn)['products_ready']);
    }

    public function test_a_run_in_which_nothing_was_asked_does_not_count_as_a_verification(): void
    {
        app(ProviderSyncRecorder::class)->failed('m5azn', 'live_verification', 'nothing was verified: no check passed');

        $this->assertSame(GoLiveItem::UNMET, $this->statuses(ProviderCode::M5azn)['live_verification_passed']);
    }

    public function test_a_database_queue_with_jobs_nobody_picked_up_holds_automatic_sending_back(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $this->assertSame(GoLiveItem::MET, $this->statuses(ProviderCode::M5azn)['queue_working'], 'an empty queue is not a backlog');

        DB::table('jobs')->insert(['queue' => 'fulfillment', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(30)->getTimestamp(), 'created_at' => now()->subMinutes(30)->getTimestamp()]);
        $this->assertSame(GoLiveItem::UNMET, $this->statuses(ProviderCode::M5azn)['queue_working']);

        DB::table('jobs')->update(['reserved_at' => now()->getTimestamp()]);
        $this->assertSame(GoLiveItem::MET, $this->statuses(ProviderCode::M5azn)['queue_working'], 'a job a worker has taken is not waiting');

        DB::table('jobs')->update(['queue' => 'default', 'reserved_at' => null]);
        $this->assertSame(GoLiveItem::MET, $this->statuses(ProviderCode::M5azn)['queue_working'], 'only the fulfillment queue matters here');
    }

    public function test_a_queue_that_cannot_be_measured_is_said_so_without_blocking(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.driver' => 'redis']);

        $this->assertSame(GoLiveItem::ADVISORY, $this->statuses(ProviderCode::M5azn)['queue_working']);
    }

    public function test_the_checklist_reads_state_it_never_asks_a_provider(): void
    {
        Http::fake();

        $this->readiness()->report(ProviderCode::M5azn);
        $this->readiness()->report(ProviderCode::TorodPoint);

        Http::assertNothingSent();
    }

    public function test_the_report_counts_what_is_met(): void
    {
        $report = new GoLiveReport(ProviderCode::M5azn, [new GoLiveItem('a', GoLiveItem::MET), new GoLiveItem('b', GoLiveItem::UNMET), new GoLiveItem('c', GoLiveItem::ADVISORY)]);

        $this->assertSame(1, $report->metCount());
        $this->assertFalse($report->ready());
        $this->assertSame(['b'], array_map(static fn (GoLiveItem $item): string => $item->key, $report->unmet()));
    }

    // ── the gate ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_save_that_does_not_start_automatic_sending_is_stored_as_submitted(): void
    {
        $admission = $this->saver()->save(ProviderCode::M5azn, $this->armed(['auto_dispatch' => false, 'cod_verified' => true]));

        $this->assertFalse($admission->wasBlocked());
        $config = $this->config(ProviderCode::M5azn);
        $this->assertFalse($config->bool('auto_dispatch'));
        $this->assertTrue($config->bool('cod_verified'));
    }

    public function test_a_dry_run_sends_nothing_so_there_is_nothing_to_hold_back(): void
    {
        $admission = $this->saver()->save(ProviderCode::M5azn, $this->armed(['dry_run' => true]));

        $this->assertFalse($admission->wasBlocked());
        $this->assertTrue($this->config(ProviderCode::M5azn)->bool('auto_dispatch'));
        $this->assertNull($this->config(ProviderCode::M5azn)->get('go_live_at'), 'a dry run is not going live');
    }

    public function test_unmet_items_keep_automatic_sending_off_and_everything_else_is_saved(): void
    {
        $admission = $this->saver()->save(ProviderCode::M5azn, $this->armed(['cod_verified' => true]));

        $this->assertTrue($admission->wasBlocked());
        $this->assertContains('first_order_accepted', array_map(static fn (GoLiveItem $item): string => $item->key, $admission->blocked));
        $config = $this->config(ProviderCode::M5azn);
        $this->assertFalse($config->bool('auto_dispatch'));
        $this->assertTrue($config->bool('cod_verified'), 'every other setting is kept');
        $this->assertFalse($config->bool('dry_run'), 'the administrator did turn dry run off; only the approval switch is held back');
        $this->assertNull($config->get('go_live_at'));
    }

    public function test_turning_dry_run_off_later_cannot_slip_past_the_checklist(): void
    {
        $this->saver()->save(ProviderCode::M5azn, $this->armed(['dry_run' => true]));
        $this->assertTrue($this->config(ProviderCode::M5azn)->bool('auto_dispatch'));

        $admission = $this->saver()->save(ProviderCode::M5azn, $this->armed(['dry_run' => false]));

        $this->assertTrue($admission->wasBlocked());
        $this->assertFalse($this->config(ProviderCode::M5azn)->bool('auto_dispatch'));
    }

    public function test_a_ready_provider_goes_live_and_the_moment_is_recorded(): void
    {
        $this->reachGoLiveConditions(ProviderCode::TorodPoint);

        $admission = $this->saver()->save(ProviderCode::TorodPoint, $this->armed());

        $this->assertFalse($admission->wasBlocked());
        $this->assertSame([], $admission->overridden);
        $config = $this->config(ProviderCode::TorodPoint);
        $this->assertTrue($config->bool('auto_dispatch'));
        $this->assertNotNull($config->get('go_live_at'));
        $this->assertNull($config->get('go_live_override'));
    }

    public function test_the_checklist_is_judged_on_the_state_the_same_save_leaves_behind_not_the_one_before_it(): void
    {
        $this->reachGoLiveConditions(ProviderCode::M5azn);

        $emptiedStatusMap = $this->saver()->save(ProviderCode::M5azn, $this->armed(['status_map' => []]));

        $this->assertContains('status_tracking_ready', array_map(static fn (GoLiveItem $item): string => $item->key, $emptiedStatusMap->blocked), 'a prerequisite this very save removes counts against it');
        $this->assertFalse($this->config(ProviderCode::M5azn)->bool('auto_dispatch'));

        app(ProviderConnectionManager::class)->save(ProviderCode::M5azn, true, null, ['status_map' => ['processing' => 'processing']]);
        app(ProviderConnectionManager::class)->save(ProviderCode::M5azn, false, null, ['auto_dispatch' => false]);

        $resumed = $this->saver()->save(ProviderCode::M5azn, $this->armed());

        $this->assertFalse($resumed->wasBlocked(), 'and one it restores counts for it: switching the provider back on and sending on in one save is one step');
        $this->assertTrue($this->config(ProviderCode::M5azn)->enabled);
        $this->assertTrue($this->config(ProviderCode::M5azn)->bool('auto_dispatch'));
    }

    public function test_the_master_admin_may_go_ahead_and_the_decision_is_kept_on_the_provider_page_data_whether_or_not_an_audit_table_exists(): void
    {
        $admission = $this->saver()->save(ProviderCode::M5azn, $this->armed(), $this->master());

        $this->assertFalse($admission->wasBlocked());
        $this->assertContains('first_order_accepted', $admission->overridden);
        $config = $this->config(ProviderCode::M5azn);
        $this->assertTrue($config->bool('auto_dispatch'));
        $this->assertNotNull($config->get('go_live_at'));
        $record = $config->get('go_live_override');
        $this->assertSame('Admin', $record['by']);
        $this->assertSame(self::REASON, $record['reason']);
        $this->assertSame($admission->overridden, $record['unmet']);
        $this->assertFalse(Schema::hasTable('audit_logs'), 'this installation has no audit table, and the decision is still on record');
    }

    public function test_an_override_is_also_written_to_the_audit_log_with_its_reason(): void
    {
        $this->createAuditTable();

        $this->saver()->save(ProviderCode::M5azn, $this->armed(), $this->master());

        $entry = AuditLog::where('action', 'integration.provider_settings_updated')->latest('id')->firstOrFail();
        $this->assertContains('first_order_accepted', $entry->context['go_live_gate_overridden']);
        $this->assertSame(self::REASON, $entry->context['go_live_override_reason']);
        $this->assertTrue($entry->after['settings']['auto_dispatch']);
    }

    public function test_an_override_that_was_not_needed_is_not_recorded_and_clears_an_older_one(): void
    {
        $this->saver()->save(ProviderCode::M5azn, $this->armed(), $this->master());
        $this->assertNotNull($this->config(ProviderCode::M5azn)->get('go_live_override'));
        app(ProviderConnectionManager::class)->save(ProviderCode::M5azn, true, null, ['auto_dispatch' => false]);
        $this->reachGoLiveConditions(ProviderCode::M5azn);

        $admission = $this->saver()->save(ProviderCode::M5azn, $this->armed(), $this->master());

        $this->assertSame([], $admission->overridden);
        $this->assertTrue($this->config(ProviderCode::M5azn)->bool('auto_dispatch'));
        $this->assertNull($this->config(ProviderCode::M5azn)->get('go_live_override'), 'automatic sending now rests on the checklist, not on the old decision');
    }

    public function test_a_save_that_only_changes_a_map_or_a_secret_cannot_rewrite_the_provider_switch(): void
    {
        $manager = app(ProviderConnectionManager::class);
        $manager->save(ProviderCode::M5azn, false, null, []);

        app(FieldMapStore::class)->save(ProviderCode::M5azn, M5aznFieldMaps::CATALOG, ['id' => 'id'], null);
        $manager->rotateWebhookSecret(ProviderCode::M5azn);
        $manager->save(ProviderCode::M5azn, null, null, ['cod_verified' => true]);

        $this->assertFalse($this->config(ProviderCode::M5azn)->enabled, 'a provider an administrator stopped stays stopped');
        $this->assertTrue($this->config(ProviderCode::M5azn)->bool('cod_verified'));
    }

    // ── the form ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_form_saves_everything_but_keeps_automatic_sending_off_until_the_checklist_is_met(): void
    {
        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'cod_verified' => 1])
            ->assertRedirect(route('admin.fulfillment-providers.show', 'm5azn'));

        $config = $this->config(ProviderCode::M5azn);
        $this->assertFalse($config->bool('auto_dispatch'), 'held back: nothing proves the provider is ready');
        $this->assertTrue($config->bool('cod_verified'), 'the rest of the form was saved');
        $this->assertNull($config->get('go_live_at'));
    }

    public function test_the_form_goes_live_when_the_checklist_is_met(): void
    {
        $this->reachGoLiveConditions(ProviderCode::TorodPoint);

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'company_id' => '714', 'webhooks_enabled' => 1]);

        $config = $this->config(ProviderCode::TorodPoint);
        $this->assertTrue($config->bool('auto_dispatch'));
        $this->assertNotNull($config->get('go_live_at'), 'the moment automatic sending went live is recorded');
    }

    public function test_the_form_override_needs_a_reason_and_then_goes_live_and_keeps_what_was_unmet(): void
    {
        $armed = ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'go_live_override' => 1];

        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), $armed)->assertSessionHasErrors('go_live_reason');
        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), $armed + ['go_live_reason' => 'too short'])->assertSessionHasErrors('go_live_reason');
        $this->assertFalse($this->config(ProviderCode::M5azn)->bool('auto_dispatch'), 'a refused override changes nothing');

        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), $armed + ['go_live_reason' => self::REASON])->assertSessionHasNoErrors();

        $config = $this->config(ProviderCode::M5azn);
        $this->assertTrue($config->bool('auto_dispatch'));
        $this->assertNotNull($config->get('go_live_at'));
        $this->assertSame(self::REASON, $config->get('go_live_override')['reason']);
        $this->assertContains('first_order_accepted', $config->get('go_live_override')['unmet']);
    }

    public function test_only_the_master_admin_may_override(): void
    {
        $this->actingAs(Admin::findOrFail(3), 'admin');

        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'go_live_override' => 1, 'go_live_reason' => self::REASON])
            ->assertSessionHasErrors('go_live_override');

        $this->assertFalse($this->config(ProviderCode::M5azn)->bool('auto_dispatch'));
        $this->assertNull($this->config(ProviderCode::M5azn)->get('go_live_override'));
    }

    public function test_a_page_opened_before_an_emergency_stop_cannot_switch_sending_back_on(): void
    {
        $this->reachGoLiveConditions(ProviderCode::TorodPoint);
        $this->saver()->save(ProviderCode::TorodPoint, $this->armed());
        $this->assertTrue($this->config(ProviderCode::TorodPoint)->bool('auto_dispatch'));
        $stale = ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'company_id' => '714'];
        $loaded = ['loaded_enabled' => 1, 'loaded_dispatch_enabled' => 1, 'loaded_auto_dispatch' => 1, 'loaded_dry_run' => 0];

        // Admin A stops automatic sending.
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), array_replace($stale, ['auto_dispatch' => 0]) + $loaded)->assertSessionHasNoErrors();
        $this->assertFalse($this->config(ProviderCode::TorodPoint)->bool('auto_dispatch'));

        // Admin B saves something else from a page that was opened while it was still on.
        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), $stale + $loaded + ['cod_verified' => 1])->assertSessionHasNoErrors();

        $config = $this->config(ProviderCode::TorodPoint);
        $this->assertFalse($config->bool('auto_dispatch'), 'a switch B did not touch keeps what A set');
        $this->assertTrue($config->bool('cod_verified'), 'what B did change is saved');
        $this->assertTrue($config->enabled);
    }

    public function test_a_switch_the_administrator_really_changed_is_applied_and_still_goes_through_the_gate(): void
    {
        $this->reachGoLiveConditions(ProviderCode::TorodPoint);
        $loaded = ['loaded_enabled' => 1, 'loaded_dispatch_enabled' => 1, 'loaded_auto_dispatch' => 0, 'loaded_dry_run' => 0];

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'company_id' => '714'] + $loaded);

        $this->assertTrue($this->config(ProviderCode::TorodPoint)->bool('auto_dispatch'));
    }

    // ── the page ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_provider_page_shows_the_checklist_and_offers_the_override_to_the_master_admin_only_while_something_is_unmet(): void
    {
        $html = $this->get(route('admin.fulfillment-providers.show', 'm5azn'))->assertOk()->getContent();

        $this->assertStringContainsString('id="go-live-checklist"', $html);
        $this->assertStringContainsString('data-go-live-item="credentials_saved" data-status="met"', $html);
        $this->assertStringContainsString('data-go-live-item="first_order_accepted" data-status="unmet"', $html);
        $this->assertStringContainsString('name="go_live_override"', $html);
        $this->assertStringContainsString('name="go_live_reason"', $html);
        $this->assertStringContainsString('name="loaded_auto_dispatch" value="0"', $html);
        $this->assertStringContainsString(e(translate('go_live_item_first_order_accepted_help')), $html);

        $this->actingAs(Admin::findOrFail(3), 'admin');
        $other = $this->get(route('admin.fulfillment-providers.show', 'm5azn'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="go_live_override"', $other);
        $this->assertStringContainsString('data-go-live-master-only', $other);

        $this->actingAs(Admin::findOrFail(1), 'admin');
        $this->reachGoLiveConditions(ProviderCode::M5azn);
        $ready = $this->get(route('admin.fulfillment-providers.show', 'm5azn'))->assertOk()->getContent();

        $this->assertStringContainsString('data-go-live-item="first_order_accepted" data-status="met"', $ready);
        $this->assertStringNotContainsString('name="go_live_override"', $ready, 'nothing to go ahead without');
    }

    public function test_the_page_says_when_automatic_sending_is_running_on_an_override(): void
    {
        $this->saver()->save(ProviderCode::M5azn, $this->armed(), $this->master());

        $html = $this->get(route('admin.fulfillment-providers.show', 'm5azn'))->assertOk()->getContent();

        $this->assertStringContainsString('data-go-live-override-record', $html);
        $this->assertStringContainsString(e(self::REASON), $html);
        $this->assertStringContainsString(e(translate('go_live_item_first_order_accepted')), $html);
    }

    public function test_every_checklist_text_exists_in_every_language(): void
    {
        $keys = [
            'go_live_checklist', 'go_live_checklist_help', 'go_live_ready', 'go_live_not_ready', 'go_live_override', 'go_live_override_help', 'go_live_override_reason',
            'go_live_override_master_admin_only', 'go_live_override_active', 'settings_saved_automatic_sending_not_turned_on',
        ];
        foreach ([GoLiveItem::MET, GoLiveItem::UNMET, GoLiveItem::ADVISORY] as $status) {
            $keys[] = 'go_live_status_' . $status;
        }
        foreach (ProviderCode::cases() as $provider) {
            foreach ($this->readiness()->report($provider)->items as $item) {
                array_push($keys, $item->labelKey(), $item->helpKey());
            }
        }

        foreach (['en', 'sa', 'ae'] as $locale) {
            $catalogue = include base_path("resources/lang/{$locale}/new-messages.php");
            $this->assertSame([], array_values(array_filter(array_unique($keys), static fn (string $key): bool => !array_key_exists($key, $catalogue))), "missing from {$locale}");
        }
    }
}
