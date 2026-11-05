<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\AuditLog;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderMappingService;
use App\Services\Fulfillment\Providers\M5azn\M5aznFieldMaps;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\Fulfillment\Readiness\GoLiveItem;
use App\Services\Fulfillment\Readiness\GoLiveReadiness;
use App\Services\Fulfillment\Readiness\GoLiveReport;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic sending is a decision, not a default: it may only become live when what has really happened on this server says the
 * provider is ready, or when an administrator goes ahead knowingly, on the audit trail.
 */
class GoLiveReadinessTest extends AdminPagesTestCase
{
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

    /** @return array<string, string> item key => status */
    private function statuses(ProviderCode $provider): array
    {
        return collect($this->readiness()->report($provider)->items)->mapWithKeys(static fn (GoLiveItem $item): array => [$item->key => $item->status])->all();
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
        }

        $productId = $this->makeProduct(900 + (int) ($provider === ProviderCode::TorodPoint), $provider);
        app(ProviderMappingService::class)->approve($provider, $productId, '', 'EXT-' . $productId);

        $order = $this->makeOrder(500 + $productId);
        $this->shipmentFor($order->id, $provider, FulfillmentStatus::Submitted, ['provider_reference' => 'REF-' . $productId, 'submitted_at' => now()]);
        $this->receiveStatus($provider, 'webhook', EventOutcome::APPLIED);
    }

    private function shipmentFor(int $orderId, ProviderCode $provider, FulfillmentStatus $status, array $overrides = []): ProviderFulfillment
    {
        return ProviderFulfillment::create($overrides + [
            'reference' => $orderId . '-' . $provider->value, 'order_id' => $orderId, 'provider' => $provider->value, 'generation' => 1,
            'idempotency_key' => hash('sha256', $orderId . $provider->value . $status->value), 'status' => $status,
            'payment_mode' => 'prepaid', 'currency' => 'SAR',
        ]);
    }

    private function receiveStatus(ProviderCode $provider, string $source, string $outcome): void
    {
        ProviderFulfillmentEvent::create([
            'provider' => $provider->value, 'source' => $source, 'event_type' => 'status', 'provider_fulfillment_id' => ProviderFulfillment::where('provider', $provider->value)->value('id'),
            'outcome' => $outcome, 'received_at' => now(), 'dedupe_key' => hash('sha256', uniqid('', true)),
        ]);
    }

    /** @return list<array{0: ProviderCode}> */
    public static function providers(): array
    {
        return [[ProviderCode::M5azn], [ProviderCode::TorodPoint]];
    }

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
            'nothing accepted yet' => [static fn (self $test) => ProviderFulfillment::query()->update(['provider_reference' => null]), 'first_order_accepted'],
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

        $this->assertContains($expectedUnmet, array_map(static fn (GoLiveItem $item): string => $item->key, $this->readiness()->report(ProviderCode::M5azn)->unmet()));
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

    public function test_admit_leaves_a_change_that_does_not_start_automatic_sending_alone(): void
    {
        $current = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn);

        $admission = $this->readiness()->admit(ProviderCode::M5azn, $current, true, ['dispatch_enabled' => true, 'auto_dispatch' => false, 'dry_run' => false], override: false);

        $this->assertFalse($admission->wasBlocked());
        $this->assertFalse($admission->settings['auto_dispatch']);

        $dryRun = $this->readiness()->admit(ProviderCode::M5azn, $current, true, ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => true], override: false);
        $this->assertFalse($dryRun->wasBlocked(), 'a dry run sends nothing, so there is nothing to hold back');
    }

    public function test_admit_takes_automatic_sending_back_when_items_are_unmet(): void
    {
        $current = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn);
        $submitted = ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false, 'cod_verified' => true];

        $admission = $this->readiness()->admit(ProviderCode::M5azn, $current, true, $submitted, override: false);

        $this->assertTrue($admission->wasBlocked());
        $this->assertFalse($admission->settings['auto_dispatch']);
        $this->assertTrue($admission->settings['cod_verified'], 'every other setting is kept');
        $this->assertSame([], $admission->overridden);
        $this->assertContains('first_order_accepted', array_map(static fn (GoLiveItem $item): string => $item->key, $admission->blocked));
    }

    public function test_turning_dry_run_off_cannot_slip_past_the_checklist(): void
    {
        $manager = app(ProviderConnectionManager::class);
        $manager->save(ProviderCode::M5azn, true, null, ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => true]);
        $current = $manager->config(ProviderCode::M5azn);

        $admission = $this->readiness()->admit(ProviderCode::M5azn, $current, true, ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false], override: false);

        $this->assertTrue($admission->wasBlocked());
        $this->assertFalse($admission->settings['auto_dispatch']);
        $this->assertFalse($admission->settings['dry_run'], 'the administrator did turn dry run off; only the approval switch is taken back');
    }

    public function test_admit_lets_a_ready_provider_go_live(): void
    {
        $this->reachGoLiveConditions(ProviderCode::TorodPoint);
        $current = app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint);

        $admission = $this->readiness()->admit(ProviderCode::TorodPoint, $current, true, ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false], override: false);

        $this->assertFalse($admission->wasBlocked());
        $this->assertTrue($admission->settings['auto_dispatch']);
        $this->assertSame([], $admission->overridden);
    }

    public function test_an_override_goes_ahead_and_names_what_it_went_ahead_without(): void
    {
        $current = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn);

        $admission = $this->readiness()->admit(ProviderCode::M5azn, $current, true, ['dispatch_enabled' => true, 'auto_dispatch' => true, 'dry_run' => false], override: true);

        $this->assertFalse($admission->wasBlocked());
        $this->assertTrue($admission->settings['auto_dispatch']);
        $this->assertContains('first_order_accepted', $admission->overridden);
        $this->assertSame(['go_live_gate_overridden' => $admission->overridden], $admission->auditContext());
    }

    public function test_the_form_saves_everything_but_keeps_automatic_sending_off_until_the_checklist_is_met(): void
    {
        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'cod_verified' => 1])
            ->assertRedirect(route('admin.fulfillment-providers.show', 'm5azn'));

        $config = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn);
        $this->assertFalse($config->bool('auto_dispatch'), 'held back: nothing proves the provider is ready');
        $this->assertTrue($config->bool('cod_verified'), 'the rest of the form was saved');
        $this->assertNull($config->get('go_live_at'));
    }

    public function test_the_form_goes_live_when_the_checklist_is_met(): void
    {
        $this->reachGoLiveConditions(ProviderCode::TorodPoint);

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'company_id' => '714', 'webhooks_enabled' => 1]);

        $config = app(ProviderConnectionManager::class)->config(ProviderCode::TorodPoint);
        $this->assertTrue($config->bool('auto_dispatch'));
        $this->assertNotNull($config->get('go_live_at'), 'the moment automatic sending went live is recorded');
    }

    public function test_the_form_override_goes_live_and_leaves_a_record_of_what_was_unmet(): void
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

        $this->post(route('admin.fulfillment-providers.update', 'm5azn'), ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'go_live_override' => 1]);

        $config = app(ProviderConnectionManager::class)->config(ProviderCode::M5azn);
        $this->assertTrue($config->bool('auto_dispatch'));
        $this->assertNotNull($config->get('go_live_at'));

        $entry = AuditLog::where('action', 'integration.provider_settings_updated')->latest('id')->firstOrFail();
        $this->assertContains('first_order_accepted', $entry->context['go_live_gate_overridden']);
        $this->assertTrue($entry->after['settings']['auto_dispatch']);
    }

    public function test_an_override_that_was_not_needed_is_not_recorded(): void
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
        $this->reachGoLiveConditions(ProviderCode::TorodPoint);

        $this->post(route('admin.fulfillment-providers.update', 'torod-point'), ['enabled' => 1, 'dispatch_enabled' => 1, 'auto_dispatch' => 1, 'dry_run' => 0, 'company_id' => '714', 'go_live_override' => 1]);

        $entry = AuditLog::where('action', 'integration.provider_settings_updated')->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('go_live_gate_overridden', (array) $entry->context);
    }

    public function test_the_provider_page_shows_the_checklist_and_offers_the_override_only_while_something_is_unmet(): void
    {
        $html = $this->get(route('admin.fulfillment-providers.show', 'm5azn'))->assertOk()->getContent();

        $this->assertStringContainsString('id="go-live-checklist"', $html);
        $this->assertStringContainsString('data-go-live-item="credentials_saved" data-status="met"', $html);
        $this->assertStringContainsString('data-go-live-item="first_order_accepted" data-status="unmet"', $html);
        $this->assertStringContainsString('name="go_live_override"', $html);
        $this->assertStringContainsString(e(translate('go_live_item_first_order_accepted_help')), $html);

        $this->reachGoLiveConditions(ProviderCode::M5azn);
        $ready = $this->get(route('admin.fulfillment-providers.show', 'm5azn'))->assertOk()->getContent();

        $this->assertStringContainsString('data-go-live-item="first_order_accepted" data-status="met"', $ready);
        $this->assertStringNotContainsString('name="go_live_override"', $ready, 'nothing to go ahead without');
    }

    public function test_every_checklist_text_exists_in_every_language(): void
    {
        $keys = ['go_live_checklist', 'go_live_checklist_help', 'go_live_ready', 'go_live_not_ready', 'go_live_override', 'go_live_override_help', 'settings_saved_automatic_sending_not_turned_on'];
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

    public function test_the_report_counts_what_is_met(): void
    {
        $report = new GoLiveReport(ProviderCode::M5azn, [new GoLiveItem('a', GoLiveItem::MET), new GoLiveItem('b', GoLiveItem::UNMET), new GoLiveItem('c', GoLiveItem::ADVISORY)]);

        $this->assertSame(1, $report->metCount());
        $this->assertFalse($report->ready());
        $this->assertSame(['b'], array_map(static fn (GoLiveItem $item): string => $item->key, $report->unmet()));
    }
}
