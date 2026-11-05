<?php

namespace App\Services\Fulfillment\Readiness;

use App\Enums\Fulfillment\EventOutcome;
use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentEvent;
use App\Models\ProviderProductMapping;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\FieldMapDefinitions;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderRegistry;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is this provider ready to receive orders without a person approving each one?
 *
 * Read-only. Every item is derived from what really exists (settings, saved verification runs, shipments the provider
 * accepted, events it sent back), never from a flag somebody ticked, so the checklist cannot be satisfied by saying so.
 * The controlled phase is what produces the proof: orders approved one at a time, one accepted by the provider, one status
 * update received and understood.
 *
 * Items that hold automatic sending back are UNMET; items that are only worth knowing are ADVISORY. An administrator may go
 * ahead with unmet items, but only knowingly and on the audit trail (see admit()).
 */
class GoLiveReadiness
{
    /** A verification older than this describes a provider that may since have changed. */
    public const VERIFICATION_MAX_AGE_DAYS = 14;

    /** The sweep runs every minute; this many minutes of silence means the scheduler has stopped. */
    public const SWEEP_MAX_AGE_MINUTES = 5;

    /** A queued fulfillment job that has waited this long found no worker. */
    public const QUEUE_BACKLOG_MINUTES = 10;

    /** An inbound status counts as understood when it matched a shipment and its status was mapped. */
    private const UNDERSTOOD_OUTCOMES = [EventOutcome::APPLIED, EventOutcome::IGNORED_DUPLICATE, EventOutcome::IGNORED_STALE, EventOutcome::IGNORED_TERMINAL];

    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly ProviderRegistry $registry,
        private readonly FieldMapStore $fieldMaps,
        private readonly FieldMapDefinitions $definitions,
    ) {
    }

    public function report(ProviderCode $provider): GoLiveReport
    {
        $config = $this->connections->config($provider);
        $readiness = $this->registry->adapter($provider)->readiness();

        return new GoLiveReport($provider, [
            $this->item('credentials_saved', $readiness->missing === [], implode(', ', $readiness->missing)),
            $this->integrationOn($provider),
            $this->item('external_writes_allowed', $this->connections->externalWritesEnabled(), 'FULFILLMENT_EXTERNAL_WRITES_ENABLED'),
            $this->verificationPassed($provider),
            $this->fieldMapsVerified($provider),
            $this->productsReady($provider),
            $this->capabilityItem('status_tracking_ready', $readiness->capability('status_poll'), advisory: false),
            $this->schedulerRunning(),
            $this->queueWorking(),
            $this->firstOrderAccepted($provider),
            $this->trackingReceived($provider),
            $this->noUnresolvedShipments($provider),
            $this->capabilityItem('stock_sync_running', $readiness->capability('inventory_sync'), advisory: true),
            $this->item('order_reference_named', $config->string('order_response_reference_path') !== '', advisory: true),
            $this->cashOnDelivery($config),
        ]);
    }

    /**
     * Automatic sending may only become live when its checklist is met, or when an administrator says, on the record, that
     * it is going ahead anyway. Everything else they changed is saved either way: only `auto_dispatch` is taken back.
     *
     * @param array<string, mixed> $settings the settings about to be saved
     */
    public function admit(ProviderCode $provider, ProviderConfig $current, bool $enabled, array $settings, bool $override): GoLiveAdmission
    {
        $becomesLive = !ProviderConnectionManager::isLive($current->enabled, $current->settings())
            && ProviderConnectionManager::isLive($enabled, $settings);
        $unmet = $becomesLive ? $this->report($provider)->unmet() : [];

        return match (true) {
            $unmet === [] => new GoLiveAdmission(settings: $settings),
            $override => new GoLiveAdmission(settings: $settings, overridden: array_map(static fn (GoLiveItem $item): string => $item->key, $unmet)),
            default => new GoLiveAdmission(settings: array_replace($settings, ['auto_dispatch' => false]), blocked: $unmet),
        };
    }

    private function integrationOn(ProviderCode $provider): GoLiveItem
    {
        $evidence = match (true) {
            !$this->connections->isGloballyEnabled() => 'FULFILLMENT_INTEGRATIONS_ENABLED',
            !$this->connections->config($provider)->enabled => 'Integration enabled',
            default => '',
        };

        return $this->item('integration_on', $this->connections->canCall($provider), $evidence);
    }

    private function verificationPassed(ProviderCode $provider): GoLiveItem
    {
        $state = ProviderSyncState::forCapability($provider->value, ProviderSyncRecorder::LIVE_VERIFICATION);
        $passedAt = $state?->last_success_at;
        $failedSince = $passedAt !== null && $state->last_failure_at?->gte($passedAt) === true;
        $fresh = $passedAt !== null && $passedAt->gt(now()->subDays(self::VERIFICATION_MAX_AGE_DAYS));

        return $this->item('live_verification_passed', $fresh && !$failedSince, match (true) {
            $passedAt === null => $state?->last_failure_at === null ? 'never run' : 'never passed',
            $failedSince => 'the latest run failed',
            default => ($fresh ? 'passed ' : 'last passed ') . $passedAt->diffForHumans(),
        });
    }

    private function fieldMapsVerified(ProviderCode $provider): GoLiveItem
    {
        $unverified = array_values(array_filter(
            array_keys($this->definitions->for($provider)),
            fn (string $capability): bool => !$this->fieldMaps->get($provider, $capability)->verified,
        ));

        return $this->item('field_maps_verified', $unverified === [], implode(', ', $unverified));
    }

    private function productsReady(ProviderCode $provider): GoLiveItem
    {
        $assigned = FulfillmentSchema::productColumnReady() ? DB::table('products')->where('fulfillment_provider', $provider->value)->count() : 0;
        $approved = ProviderProductMapping::where('provider', $provider->value)->approved()->count();

        return $this->item('products_ready', $assigned > 0 && $approved > 0, "{$assigned} product(s) assigned, {$approved} approved mapping(s)");
    }

    private function schedulerRunning(): GoLiveItem
    {
        $ranAt = ProviderSyncState::forCapability(ProviderSyncRecorder::SYSTEM, ProviderSyncRecorder::SWEEP)?->last_attempt_at;
        $minutes = $ranAt === null ? null : (int) $ranAt->diffInMinutes(now(), true);

        return $this->item(
            'scheduler_running',
            $minutes !== null && $minutes <= self::SWEEP_MAX_AGE_MINUTES,
            $minutes === null ? 'the sweep has never run' : "last sweep {$minutes} minute(s) ago",
        );
    }

    private function queueWorking(): GoLiveItem
    {
        $connection = (string) (config('fulfillment.queue.connection') ?: config('queue.default'));
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        return match ($driver) {
            'sync' => $this->item('queue_working', true, 'sync driver: the per-minute sweep does the work'),
            'database' => $this->databaseQueueWorking(),
            default => $this->item('queue_working', false, "cannot be measured on the {$driver} driver", advisory: true),
        };
    }

    private function databaseQueueWorking(): GoLiveItem
    {
        if (!Schema::hasTable('jobs')) {
            return $this->item('queue_working', false, 'the jobs table is missing');
        }

        $waiting = DB::table('jobs')
            ->where('queue', (string) config('fulfillment.queue.name', 'fulfillment'))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->subMinutes(self::QUEUE_BACKLOG_MINUTES)->getTimestamp())
            ->count();

        return $this->item('queue_working', $waiting === 0, $waiting === 0 ? '' : "{$waiting} job(s) waiting for over " . self::QUEUE_BACKLOG_MINUTES . ' minutes');
    }

    private function firstOrderAccepted(ProviderCode $provider): GoLiveItem
    {
        $accepted = ProviderFulfillment::where('provider', $provider->value)->whereNotNull('provider_reference')->whereNotNull('submitted_at')->count();

        return $this->item('first_order_accepted', $accepted > 0, "{$accepted} shipment(s) accepted by the provider");
    }

    private function trackingReceived(ProviderCode $provider): GoLiveItem
    {
        $latest = ProviderFulfillmentEvent::where('provider', $provider->value)
            ->whereIn('source', ['webhook', 'poll'])
            ->whereIn('outcome', self::UNDERSTOOD_OUTCOMES)
            ->whereNotNull('provider_fulfillment_id')
            ->max('received_at');

        return $this->item('tracking_received', $latest !== null, $latest === null ? 'none yet' : 'latest ' . Carbon::parse($latest)->diffForHumans());
    }

    private function noUnresolvedShipments(ProviderCode $provider): GoLiveItem
    {
        $unresolved = ProviderFulfillment::where('provider', $provider->value)
            ->whereIn('status', [FulfillmentStatus::ReconcilePending->value, FulfillmentStatus::NeedsAttention->value])
            ->count();

        return $this->item('no_unresolved_shipments', $unresolved === 0, $unresolved === 0 ? '' : "{$unresolved} shipment(s) wait for a decision");
    }

    private function cashOnDelivery(ProviderConfig $config): GoLiveItem
    {
        $settled = !$config->bool('cod_supported', true) || $config->bool('cod_verified');

        return $this->item('cod_settlement', $settled, $settled ? '' : 'COD settlement not verified', advisory: true);
    }

    private function capabilityItem(string $key, ?CapabilityState $capability, bool $advisory): GoLiveItem
    {
        return $this->item($key, $capability?->isUsable() ?? false, $capability?->isUsable() === false ? (string) $capability->state : '', $advisory, $capability?->reason);
    }

    private function item(string $key, bool $met, string $evidence = '', bool $advisory = false, ?string $reasonKey = null): GoLiveItem
    {
        $status = match (true) {
            $met => GoLiveItem::MET,
            $advisory => GoLiveItem::ADVISORY,
            default => GoLiveItem::UNMET,
        };

        return new GoLiveItem($key, $status, $evidence, $met ? null : $reasonKey);
    }
}
