<?php

namespace App\Services\Fulfillment\Providers\TorodPoint;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\CatalogStagingService;
use App\Services\Fulfillment\Contracts\HandlesWebhooks;
use App\Services\Fulfillment\Contracts\ProviderAdapter;
use App\Services\Fulfillment\Contracts\ProvidesShippingLabel;
use App\Services\Fulfillment\Contracts\ReconcilesSubmissions;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\Data\ConnectionTestResult;
use App\Services\Fulfillment\Data\NormalizedEvent;
use App\Services\Fulfillment\Data\PreflightResult;
use App\Services\Fulfillment\Data\ReadinessReport;
use App\Services\Fulfillment\Data\StatusSnapshot;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\ProviderMappingService;
use App\Services\Fulfillment\Support\AppTime;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\HttpResult;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use App\Services\Fulfillment\Support\SaudiPhone;
use App\Services\Fulfillment\Support\SecretRedactor;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Torod Point (LogesTechs): our own stock sits in their warehouse, and THEY pick, pack and ship.
 *
 * Orders go through the **Fulfillment API v5** (`POST /public/fulfillment/order`), whose lines name
 * warehouse products. The **Lastmile API v2** is a helper only — cities, package status, package cancel,
 * air waybill — and its create-shipment call is never made: the warehouse creates the last-mile package
 * itself, so creating one here too would ship the parcel twice.
 *
 * Inbound: two webhooks. The Lastmile one carries the delivery lifecycle and OUR invoice number; the
 * Fulfillment one reports warehouse progress (created/picked/packed), which is display-only. Neither is
 * signed, so the endpoint is guarded by a secret and terminal claims are confirmed with the provider.
 *
 * Not documented, so not claimed: any inventory/stock-level endpoint, whether the product list returns
 * stock, the Add Order response shape, a warehouse-order cancel, a warehouse-order status lookup, the
 * `codCollectionMethod` values. Each is gated, verified, or left to a human.
 */
class TorodPointAdapter implements ProviderAdapter, ReconcilesSubmissions, HandlesWebhooks, ProvidesShippingLabel
{
    private const STOCK_FRESH_HOURS = 6;
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 50;

    /** Hosts the air waybill may be served from. */
    private const LABEL_HOST_SUFFIXES = ['amazonaws.com', 'logestechs.com'];
    private const RECONCILE_PAGES = 3;

    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly TorodFulfillmentClient $fulfillment,
        private readonly TorodLastmileClient $lastmile,
        private readonly TorodCityResolver $cities,
        private readonly TorodOrderPayloadBuilder $payloads,
        private readonly FieldMapStore $fieldMaps,
        private readonly CatalogStagingService $staging,
        private readonly InventorySyncService $inventory,
        private readonly ProviderSyncRecorder $sync,
    ) {
    }

    /** @return array<string, mixed> */
    public static function defaultSettings(): array
    {
        return [
            'dispatch_enabled' => false,
            'auto_dispatch' => false,
            'dry_run' => true,
            'go_live_at' => null,
            'product_sync_enabled' => false,
            'inventory_sync_enabled' => false,
            'product_sync_interval_minutes' => 30,
            'inventory_sync_interval_minutes' => 30,
            'push_products_enabled' => false,
            'webhooks_enabled' => false,
            'dispatch_order_statuses' => [],
            'company_id' => '',
            'lastmile_base_url' => (string) config('fulfillment.providers.torod_point.default_lastmile_base_url'),
            'phone_format' => 'international_00',
            'cod_supported' => true,
            'cod_verified' => false,
            'cod_collection_method' => '',
            'cost_source' => 'omit',
            'default_weight_kg' => 1,
            'order_response_reference_path' => '',
            'product_defaults' => [],
            'verify_terminal_webhooks' => true,
            'webhook_ip_allowlist' => '',
            'tracking_url_template' => '',
            'stock_field_confirmed_at' => null,
            'field_maps' => [],
        ];
    }

    public function code(): ProviderCode
    {
        return ProviderCode::TorodPoint;
    }

    public function readiness(): ReadinessReport
    {
        $config = $this->config();
        $missing = array_values(array_filter([
            $config->string('company_id') !== '' ? null : 'company_id',
            $config->hasSecret('fulfillment_username') ? null : 'fulfillment_username',
            $config->hasSecret('fulfillment_password') ? null : 'fulfillment_password',
        ]));
        $credentials = $missing === [];
        $global = $this->connections->isGloballyEnabled() && $config->enabled;
        $lastmileCredentials = $config->hasSecret('lastmile_email') && $config->hasSecret('lastmile_password');
        $companyId = $config->string('company_id') !== '';

        $products = $this->fieldMaps->get($this->code(), TorodFieldMaps::PRODUCTS);
        $stockMapped = $products->verified && $products->map()->has('stock') && $config->get('stock_field_confirmed_at') !== null;

        $capabilities = [
            'connection' => $this->state('connection', $credentials, $global, true),
            'dispatch' => $this->state('dispatch', $credentials, $global, $config->bool('dispatch_enabled'), $this->connections->externalWritesEnabled()),
            'product_catalog' => $this->state('product_catalog', $credentials, $global, $config->bool('product_sync_enabled')),
            'product_push' => $this->state('product_push', $credentials, $global, $config->bool('push_products_enabled'), $this->connections->externalWritesEnabled()),
            'inventory_sync' => match (true) {
                !$credentials => new CapabilityState('inventory_sync', CapabilityState::NEEDS_CONFIGURATION, 'credentials_missing'),
                !$stockMapped => new CapabilityState('inventory_sync', CapabilityState::UNVERIFIED, 'torod_stock_field_unverified'),
                $global && $config->bool('inventory_sync_enabled') => new CapabilityState('inventory_sync', CapabilityState::ENABLED),
                default => new CapabilityState('inventory_sync', CapabilityState::READY_DISABLED),
            },
            'cities' => $this->state('cities', $companyId, $global, true),
            'package_status' => $this->state('package_status', $companyId, $global, true),
            'air_waybill' => $this->state('air_waybill', $companyId, $global, true),
            'cancel_package' => $this->state('cancel_package', $companyId && $lastmileCredentials, $global, true, $this->connections->externalWritesEnabled()),
            'webhooks' => $this->state('webhooks', $config->hasSecret('webhook_secret'), $global, $config->bool('webhooks_enabled')),
            'status_poll' => $this->state('status_poll', $companyId, $global, true),
            'inventory_endpoint' => new CapabilityState('inventory_endpoint', CapabilityState::UNDOCUMENTED, 'torod_no_inventory_endpoint'),
            'cancel_warehouse_order' => new CapabilityState('cancel_warehouse_order', CapabilityState::UNDOCUMENTED, 'torod_no_warehouse_cancel'),
            'warehouse_order_status_lookup' => new CapabilityState('warehouse_order_status_lookup', CapabilityState::UNDOCUMENTED, 'torod_no_warehouse_status_lookup'),
            'lastmile_create_shipment' => new CapabilityState('lastmile_create_shipment', CapabilityState::NOT_USED, 'torod_would_duplicate_shipment'),
        ];

        return new ReadinessReport($credentials, $missing, $capabilities);
    }

    public function testConnection(): ConnectionTestResult
    {
        if (!$this->connections->canCall($this->code())) {
            return new ConnectionTestResult(false, 'Integrations are switched off or this provider is disabled; nothing was sent.');
        }
        if (!$this->readiness()->configured) {
            return new ConnectionTestResult(false, 'Credentials or company id are missing.');
        }

        $this->sync->attempted($this->code()->value, ProviderSyncRecorder::CONNECTION);
        // A one-row read of the product list: proves the headers authenticate, creates nothing.
        $result = $this->fulfillment->listProducts(1, 1);

        if ($result->ok()) {
            $this->sync->succeeded($this->code()->value, ProviderSyncRecorder::CONNECTION);

            return new ConnectionTestResult(true, 'Authenticated against the Fulfillment API.', $result->status);
        }

        $this->sync->failed($this->code()->value, ProviderSyncRecorder::CONNECTION, $result->message());

        return new ConnectionTestResult(false, $result->message(), $result->status);
    }

    public function preflight(ProviderFulfillment $fulfillment): PreflightResult
    {
        $config = $this->config();
        $order = Order::find($fulfillment->order_id);
        $address = $order === null ? [] : $this->payloads->address($order);

        foreach ($fulfillment->items as $item) {
            if ($item->mapping_id === null || ($item->external_product_id ?? '') === '') {
                return PreflightResult::hold(HoldReason::MAPPING_MISSING, '#' . $item->product_id);
            }

            $mapping = ProviderProductMapping::find($item->mapping_id);
            if ($mapping === null || !$mapping->isApproved()) {
                return PreflightResult::hold(HoldReason::MAPPING_NOT_APPROVED, '#' . $item->product_id);
            }
            if ($this->knownToBeOutOfStock($mapping, $item->quantity)) {
                return PreflightResult::hold(HoldReason::STOCK_UNAVAILABLE, '#' . $item->product_id);
            }
        }

        if (trim((string) ($address['address'] ?? '')) === '') {
            return PreflightResult::hold(HoldReason::ADDRESS_INCOMPLETE);
        }
        if (SaudiPhone::normalize((string) ($address['phone'] ?? '')) === null) {
            return PreflightResult::hold(HoldReason::PHONE_INVALID);
        }
        if ($this->cities->resolve($address['city'] ?? null, $address['state'] ?? null) === null) {
            return PreflightResult::hold(HoldReason::CITY_UNMAPPED, trim((string) ($address['city'] ?? '')));
        }
        if ($fulfillment->payment_mode === PaymentMode::CashOnDelivery
            && (!$config->bool('cod_supported', true) || $config->string('cod_collection_method') === '')) {
            return PreflightResult::hold(HoldReason::PROVIDER_NOT_READY, 'cod_collection_method');
        }

        return PreflightResult::passed();
    }

    public function submit(ProviderFulfillment $fulfillment): SubmissionResult
    {
        $order = Order::find($fulfillment->order_id);
        if ($order === null) {
            return SubmissionResult::rejected('order_missing', 'The parent order no longer exists.');
        }

        $config = $this->config();
        $address = $this->payloads->address($order);
        $city = $this->cities->resolve($address['city'] ?? null, $address['state'] ?? null);
        if ($city === null) {
            return SubmissionResult::rejected('city_unmapped', 'The delivery city cannot be mapped to a Torod Point city.');
        }

        try {
            $payload = $this->payloads->build($fulfillment->loadMissing('items'), $order, $config, $city);
        } catch (Throwable $exception) {
            return SubmissionResult::rejected('payload_error', $exception::class . ': ' . $exception->getMessage());
        }

        if ($config->bool('dry_run', true)) {
            return SubmissionResult::dryRun([
                'payload_hash' => hash('sha256', (string) json_encode($payload)),
                'payload_preview' => SecretRedactor::redact($payload),
            ]);
        }

        return $this->interpretCreate($this->fulfillment->createOrder($payload, $fulfillment->id), $config);
    }

    /**
     * Cancellation is only possible through the Lastmile API, on the package the warehouse created. Before
     * that package exists no cancel is documented, so the request is reported as unsupported and the
     * operator cancels in the Torod Point dashboard.
     */
    public function cancel(ProviderFulfillment $fulfillment): CancellationResult
    {
        $barcode = $fulfillment->provider_secondary_reference;
        if ($barcode === null) {
            return CancellationResult::unsupported('Torod Point documents no way to cancel a warehouse order before its last-mile package exists. Cancel it in the Torod Point dashboard, then mark it cancelled here.');
        }
        if (!$this->config()->hasSecret('lastmile_email') || !$this->config()->hasSecret('lastmile_password')) {
            return CancellationResult::failed('Lastmile credentials are not configured.');
        }

        $result = $this->lastmile->cancelPackage($barcode, $fulfillment->id);

        return match (true) {
            $result->ok() => CancellationResult::requested('Cancellation requested; it is final once Torod Point reports the package cancelled.'),
            $result->kind === HttpResult::REJECTED => CancellationResult::refused($result->message()),
            default => CancellationResult::failed($result->message()),
        };
    }

    public function fetchStatus(ProviderFulfillment $fulfillment): ?StatusSnapshot
    {
        $barcode = $fulfillment->provider_secondary_reference;
        if ($barcode === null || $this->config()->string('company_id') === '') {
            return null;
        }

        $result = $this->lastmile->packageStatus($barcode);
        $status = $result->ok() ? $result->get('status') : null;

        return is_string($status) && $status !== '' ? new StatusSnapshot($status, raw: SecretRedactor::redact($result->json ?? [])) : null;
    }

    public function mapStatus(string $providerStatus): ?FulfillmentStatus
    {
        return TorodStatusMap::map($providerStatus);
    }

    public function labelUrl(ProviderFulfillment $fulfillment): ?string
    {
        $barcode = $fulfillment->provider_secondary_reference;
        if ($barcode === null) {
            return null;
        }

        $result = $this->lastmile->airWaybill([$barcode], $fulfillment->id);
        $url = $result->ok() ? $result->get('url') : null;

        return is_string($url) && $this->isLabelHost($url) ? $url : null;
    }

    /** The air waybill is served from the provider's own storage (the docs show an S3 bucket); the administrator is only ever sent there. */
    private function isLabelHost(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || $host === '') {
            return false;
        }

        foreach (self::LABEL_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An ambiguous Add Order is resolved by finding OUR invoice number in the order list. That needs a
     * verified orders map saying which field carries it; otherwise the answer stays "unknown".
     */
    public function reconcileSubmission(ProviderFulfillment $fulfillment): SubmissionResult
    {
        $stored = $this->fieldMaps->get($this->code(), TorodFieldMaps::ORDERS);
        $map = $stored->map();
        if (!$stored->verified) {
            return SubmissionResult::ambiguous('cannot_reconcile', 'No verified way to find this order in the Torod Point order list.');
        }

        for ($page = 1; $page <= self::RECONCILE_PAGES; $page++) {
            $result = $this->fulfillment->listOrders($page, 50);
            $items = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json, $stored->itemsPath) : null;
            if ($items === null) {
                return SubmissionResult::ambiguous('order_list_unreadable', 'The Torod Point order list could not be read.');
            }

            foreach ($items as $item) {
                if ($map->string($item, 'invoice_number') === $fulfillment->reference) {
                    $reference = $map->string($item, 'reference');
                    if ($reference !== null) {
                        return SubmissionResult::accepted(providerReference: $reference, providerStatus: $map->string($item, 'status'));
                    }
                }
            }

            if (count($items) < 50) {
                break;
            }
        }

        return SubmissionResult::ambiguous('not_found_in_order_list', 'Our invoice number was not found in the recent Torod Point orders (the list may be paged or late).');
    }

    // ---- webhooks -----------------------------------------------------------------------------------

    public function normalizeWebhook(string $type, array $payload): ?NormalizedEvent
    {
        return match ($type) {
            'lastmile' => $this->normalizeLastmile($payload),
            'fulfillment' => $this->normalizeFulfillment($payload),
            default => null,
        };
    }

    /**
     * The shipment an event is about, found by the references it carries. When two references point at DIFFERENT shipments
     * the event is not about either of them: it is left unmatched instead of being given to whichever reference is tried first.
     */
    public function locateFulfillment(NormalizedEvent $event): ?ProviderFulfillment
    {
        $query = ProviderFulfillment::where('provider', $this->code()->value);

        [$first, $second] = $event->type === 'lastmile'
            ? [
                $event->reference('barcode') !== null ? (clone $query)->where('provider_secondary_reference', $event->reference('barcode'))->first() : null,
                $event->reference('invoice') !== null ? (clone $query)->where('reference', $event->reference('invoice'))->first() : null,
            ]
            : [
                $event->reference('barcode') !== null ? (clone $query)->where('provider_reference', $event->reference('barcode'))->first() : null,
                $event->reference('package_barcode') !== null ? (clone $query)->where('provider_secondary_reference', $event->reference('package_barcode'))->first() : null,
            ];

        return $first !== null && $second !== null && $first->id !== $second->id ? null : ($first ?? $second);
    }

    public function learnReferences(ProviderFulfillment $fulfillment, NormalizedEvent $event): void
    {
        $template = $this->config()->string('tracking_url_template');
        $update = [];

        if ($event->type === 'lastmile') {
            $barcode = $event->reference('barcode');
            if ($barcode !== null && $fulfillment->provider_secondary_reference === null && $this->describesThisShipment($fulfillment, $event)) {
                $update['provider_secondary_reference'] = $barcode;
                $update['tracking_number'] = $fulfillment->tracking_number ?? $barcode;
                if ($template !== '' && $fulfillment->tracking_url === null) {
                    $update['tracking_url'] = str_replace('{tracking}', rawurlencode($barcode), $template);
                }
            }
            if ($event->reference('package_id') !== null && $fulfillment->provider_internal_id === null) {
                $update['provider_internal_id'] = $event->reference('package_id');
            }
        } else {
            $barcode = $event->reference('barcode');
            $package = $event->reference('package_barcode');
            if ($barcode !== null && $fulfillment->provider_reference === null) {
                $update['provider_reference'] = $barcode;
            }
            if ($package !== null && $fulfillment->provider_secondary_reference === null) {
                $update['provider_secondary_reference'] = $package;
                $update['tracking_number'] = $fulfillment->tracking_number ?? $package;
                if ($template !== '' && $fulfillment->tracking_url === null) {
                    $update['tracking_url'] = str_replace('{tracking}', rawurlencode($package), $template);
                }
            }
        }

        if ($update !== []) {
            DB::table('provider_fulfillments')->where('id', $fulfillment->id)->update($update + ['updated_at' => now()]);
        }
    }

    /**
     * The webhooks carry no signature, so a terminal claim ("delivered", "returned", "cancelled"…) is
     * confirmed against the Lastmile status API before it is applied: true = confirmed or not applicable,
     * false = the provider does not know that package, null = could not be checked (or the provider has not
     * caught up yet), in which case the claim waits and is never applied on trust.
     */
    public function confirmStatus(ProviderFulfillment $fulfillment, NormalizedEvent $event): ?bool
    {
        $config = $this->config();
        $claimed = strtoupper((string) $event->providerStatus);

        if ($event->type !== 'lastmile' || !in_array($claimed, TorodStatusMap::VERIFIED_BEFORE_APPLYING, true)
            || !$config->bool('verify_terminal_webhooks', true) || $config->string('company_id') === '') {
            return true;
        }

        // The package asked about is the one WE hold for this shipment, never the one the payload names: a payload that
        // pairs our invoice number with someone else's delivered package must not confirm our shipment as delivered.
        $barcode = $fulfillment->provider_secondary_reference;
        if ($barcode === null) {
            return null;
        }

        try {
            $result = $this->lastmile->packageStatus($barcode);
        } catch (ProviderUnavailableException) {
            return null;
        }

        if (!$result->ok()) {
            return $result->kind === HttpResult::REJECTED ? false : null;
        }

        if (!$this->cashMatches($fulfillment, $result->get('cod'))) {
            return false;
        }

        $actual = strtoupper((string) $result->get('status'));
        if ($actual === '') {
            return null;
        }
        if ($actual === $claimed) {
            return true;
        }

        $actualStatus = TorodStatusMap::map($actual);
        $claimedStatus = TorodStatusMap::map($claimed);

        return $actualStatus !== null && $claimedStatus !== null && $actualStatus->rank() >= $claimedStatus->rank() ? true : null;
    }

    /**
     * Is this Lastmile event plausibly about OUR shipment? It must name our invoice number when it names one at all, and the
     * cash it says is collected must be the cash we asked for. Learning a package barcode from an event is trust on first use;
     * this is the check that stops one shipment adopting another's barcode.
     */
    private function describesThisShipment(ProviderFulfillment $fulfillment, NormalizedEvent $event): bool
    {
        $invoice = $event->reference('invoice');

        return ($invoice === null || $invoice === $fulfillment->reference) && $this->cashMatches($fulfillment, $event->reference('cod'));
    }

    /** A package that reports a different cash-on-delivery amount than the one we sent is not our package. An absent amount proves nothing. */
    private function cashMatches(ProviderFulfillment $fulfillment, mixed $reported): bool
    {
        if (!is_numeric($reported)) {
            return true;
        }

        return abs((float) $reported - (float) $fulfillment->cod_amount) <= 0.01;
    }

    // ---- catalog, stock, cities, product push --------------------------------------------------------

    /**
     * Read the warehouse product list into staging for matching. Raw and de-duplicated; safe without a
     * verified map, but suggestions and stock are only trustworthy once the map is verified.
     *
     * @return array{ok: bool, error?: string, stats?: array<string, int>, verified?: bool}
     */
    public function syncCatalog(): array
    {
        $this->ensureCallable();
        $this->sync->attempted($this->code()->value, ProviderSyncRecorder::PRODUCTS);

        $stored = $this->fieldMaps->get($this->code(), TorodFieldMaps::PRODUCTS);
        $items = $this->readProductPages($stored->itemsPath, $error);
        if ($items === null) {
            return $this->failed(ProviderSyncRecorder::PRODUCTS, (string) $error);
        }

        $paths = $stored->paths !== [] ? $stored->paths : $this->fieldMaps->propose($items, TorodFieldMaps::CANDIDATES[TorodFieldMaps::PRODUCTS]);
        $map = new FieldMap($paths);
        $records = [];
        $unidentified = 0;
        foreach ($items as $item) {
            $id = $map->string($item, 'id');
            if ($id === null) {
                $unidentified++;
                continue;
            }
            $records[] = [
                'external_id' => $id,
                'name' => $map->string($item, 'name'),
                'sku' => $map->string($item, 'sku'),
                'barcode' => $map->string($item, 'barcode'),
                'stock' => ($stock = $map->number($item, 'stock')) === null ? null : (int) $stock,
                'retail_price' => $map->number($item, 'price'),
                'payload' => $item,
            ];
        }

        $stats = $this->staging->stage($this->code(), 'api', $records) + ['unidentified' => $unidentified];
        $this->sync->succeeded($this->code()->value, ProviderSyncRecorder::PRODUCTS, $stats);

        return ['ok' => true, 'stats' => $stats, 'verified' => $stored->verified];
    }

    /**
     * Warehouse stock, from the product list — and ONLY if the document's silence on whether it carries
     * stock has been resolved by an administrator: the products map is verified, a `stock` path is set,
     * and the administrator confirmed that path is the available quantity.
     *
     * @return array{ok: bool, error?: string, stats?: array<string, int>}
     */
    public function syncInventory(): array
    {
        $this->ensureCallable();
        $config = $this->config();
        $stored = $this->fieldMaps->get($this->code(), TorodFieldMaps::PRODUCTS);

        if (!$stored->verified || !$stored->map()->has('stock') || $config->get('stock_field_confirmed_at') === null) {
            return $this->failed(ProviderSyncRecorder::INVENTORY, 'Warehouse stock is not verified: no inventory endpoint is documented, and the product list has not been confirmed to carry available stock.');
        }
        if (!$config->bool('inventory_sync_enabled')) {
            return $this->failed(ProviderSyncRecorder::INVENTORY, 'Inventory sync is switched off.');
        }

        $this->sync->attempted($this->code()->value, ProviderSyncRecorder::INVENTORY);
        $items = $this->readProductPages($stored->itemsPath, $error);
        if ($items === null) {
            return $this->failed(ProviderSyncRecorder::INVENTORY, (string) $error);
        }

        $map = $stored->map();
        $stock = [];
        foreach ($items as $item) {
            $id = $map->string($item, 'id');
            $quantity = $map->number($item, 'stock');
            if ($id !== null && $quantity !== null) {
                $stock[$id] = (int) $quantity;
            }
        }

        $stats = $this->inventory->apply($this->code(), $stock);
        $this->sync->succeeded($this->code()->value, ProviderSyncRecorder::INVENTORY, $stats);

        return ['ok' => true, 'stats' => $stats];
    }

    /** @return array{ok: bool, error?: string, count?: int} */
    public function syncCities(): array
    {
        $this->ensureCallable();

        return $this->cities->sync();
    }

    /**
     * A read-only look at what the warehouse API really returns, for the administrator verifying a map.
     *
     * @return array{ok: bool, error?: string, items: list<array<array-key, mixed>>, proposal: array<string, string>, items_path: ?string}
     */
    public function sample(string $capability): array
    {
        $this->ensureCallable();
        $result = $capability === TorodFieldMaps::ORDERS ? $this->fulfillment->listOrders(1, 5) : $this->fulfillment->listProducts(1, 5);
        $stored = $this->fieldMaps->get($this->code(), $capability);
        $items = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json, $stored->itemsPath) : null;

        if ($items === null) {
            return ['ok' => false, 'error' => $result->ok() ? 'No list of records was found in the response.' : $result->message(), 'items' => [], 'proposal' => [], 'items_path' => null];
        }

        $items = array_slice($items, 0, 5);

        return [
            'ok' => true,
            'items' => SecretRedactor::redact($items),
            'proposal' => $this->fieldMaps->propose($items, TorodFieldMaps::CANDIDATES[$capability] ?? []),
            'items_path' => $stored->itemsPath,
        ];
    }

    /**
     * Add or update warehouse products from selected store products — an explicit administrator action,
     * never automatic. Quantity is never sent: warehouse stock is established by receiving goods, not by
     * a store's number. On success each product gets an approved mapping to the id the warehouse returned.
     *
     * @param list<int> $productIds
     * @return array{ok: bool, error?: string, pushed?: int, skipped?: array<int, string>, dry_run?: bool, preview?: list<array<string, mixed>>}
     */
    public function pushProducts(array $productIds, ?int $adminId = null): array
    {
        $this->ensureCallable();
        $config = $this->config();
        if (!$config->bool('push_products_enabled')) {
            return ['ok' => false, 'error' => 'Pushing products to the warehouse is switched off.'];
        }

        $list = [];
        $skipped = [];
        foreach (DB::table('products')->whereIn('id', $productIds)->where('added_by', 'admin')->where('product_type', 'physical')->get() as $product) {
            $variations = json_decode((string) ($product->variation ?? '[]'), true) ?: [];
            if ($variations !== []) {
                $skipped[(int) $product->id] = 'variants_not_supported';
                continue;
            }
            $price = app(ProviderCurrency::class)->fromStored($product->unit_price);
            $list[] = array_replace(array_filter([
                'externalId' => (int) $product->id,
                'name' => $product->name,
                'sku' => $product->code,
                'barcode' => $product->barcode ?? null,
                'price' => $price === null ? null : (string) $price,
                'description' => mb_substr(trim(strip_tags((string) ($product->details ?? ''))), 0, 250) ?: '.',
            ], static fn ($value): bool => $value !== null && $value !== ''), (array) $config->get('product_defaults', []));
        }

        if ($config->bool('dry_run', true)) {
            return ['ok' => true, 'dry_run' => true, 'pushed' => 0, 'skipped' => $skipped, 'preview' => $list];
        }
        if ($list === []) {
            return ['ok' => true, 'pushed' => 0, 'skipped' => $skipped];
        }

        $result = $this->fulfillment->upsertProducts($list);
        $rows = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json) : null;
        if ($rows === null) {
            return ['ok' => false, 'error' => $result->ok() ? 'The warehouse answered without a result list.' : $result->message(), 'skipped' => $skipped];
        }

        $pushed = 0;
        foreach ($rows as $row) {
            $productId = (int) ($row['externalId'] ?? 0);
            $warehouseId = $row['id'] ?? null;
            if ($productId === 0 || $warehouseId === null || strtolower((string) ($row['status'] ?? '')) !== 'synced') {
                $skipped[$productId] = (string) ($row['feedBack'] ?? 'not_synced');
                continue;
            }

            $source = collect($list)->firstWhere('externalId', $productId);
            app(ProviderMappingService::class)->approve(
                provider: $this->code(),
                productId: $productId,
                variantKey: '',
                externalId: (string) $warehouseId,
                externalSku: $source['sku'] ?? null,
                externalBarcode: $source['barcode'] ?? null,
                externalName: $source['name'] ?? null,
                approvedBy: $adminId,
                source: 'warehouse_push',
            );
            $pushed++;
        }

        return ['ok' => true, 'pushed' => $pushed, 'skipped' => $skipped];
    }

    // ---- internals ----------------------------------------------------------------------------------

    /** @return list<array<array-key, mixed>>|null every record across pages; null (with $error) when a page cannot be read */
    private function readProductPages(?string $itemsPath, ?string &$error = null): ?array
    {
        $all = [];
        $previousFirst = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $this->fulfillment->listProducts($page, self::PAGE_SIZE);
            if (!$result->ok() || $result->json === null) {
                $error = $result->message();

                return null;
            }

            $items = FieldMap::extractItems($result->json, $itemsPath);
            if ($items === null) {
                $error = 'The response does not contain a list of products; set the items path in the field map.';

                return null;
            }

            // A server that ignores `page` would return the same rows forever.
            $first = $items[0] ?? null;
            if ($page > 1 && $first !== null && $first === $previousFirst) {
                break;
            }
            $previousFirst = $first;
            array_push($all, ...$items);

            if (count($items) < self::PAGE_SIZE) {
                break;
            }
        }

        return $all;
    }

    private function interpretCreate(HttpResult $result, ProviderConfig $config): SubmissionResult
    {
        $detail = $result->message();

        return match ($result->kind) {
            HttpResult::SUCCESS => $this->interpretAccepted($result, $config),
            HttpResult::AUTH_ERROR => SubmissionResult::retryable('auth_error', $detail),
            HttpResult::RATE_LIMITED, HttpResult::NOT_SENT => SubmissionResult::retryable($result->kind, $detail),
            HttpResult::REJECTED => SubmissionResult::rejected('http_' . $result->status, $detail),
            default => SubmissionResult::ambiguous($result->kind . ($result->status ? '_' . $result->status : ''), $detail),
        };
    }

    private function interpretAccepted(HttpResult $result, ProviderConfig $config): SubmissionResult
    {
        $json = $result->json ?? [];

        if (($json['success'] ?? true) === false || ($json['error'] ?? false) === true) {
            return SubmissionResult::rejected('provider_refused', $result->message());
        }

        $configured = $config->string('order_response_reference_path');
        $reference = null;
        foreach ($configured !== '' ? [$configured] : TorodFieldMaps::CREATE_RESPONSE_REFERENCE_PATHS as $path) {
            $value = Arr::get($json, $path);
            if (is_scalar($value) && (string) $value !== '') {
                $reference = (string) $value;
                break;
            }
        }

        if ($reference === null) {
            // A 2xx with nothing to hold on to is not verified acceptance, and must not be re-sent either.
            return SubmissionResult::ambiguous('no_reference_in_response', 'Torod Point answered successfully but returned no order reference; confirm in the Torod Point dashboard and link it manually.');
        }

        $internal = null;
        foreach (TorodFieldMaps::CREATE_RESPONSE_ID_PATHS as $path) {
            $value = Arr::get($json, $path);
            if (is_scalar($value) && (string) $value !== '' && (string) $value !== $reference) {
                $internal = (string) $value;
                break;
            }
        }

        return SubmissionResult::accepted(providerReference: $reference, providerInternalId: $internal);
    }

    /** @param array<array-key, mixed> $payload */
    private function normalizeLastmile(array $payload): ?NormalizedEvent
    {
        $payload = $this->withLowerCaseKeys($payload);
        $status = $payload['newstatus'] ?? null;
        $barcode = $payload['barcode'] ?? null;
        if (!is_string($status) || $status === '' || !is_scalar($barcode) || (string) $barcode === '') {
            return null;
        }

        $occurredAt = $this->occurredAt($payload['time'] ?? null);
        $postponed = $payload['postponeddeliverydate'] ?? $payload['postponeddate'] ?? null;
        $proof = array_slice(array_values(array_filter((array) ($payload['attachmenturls'] ?? []), static fn ($url): bool => is_string($url) && str_starts_with($url, 'https://'))), 0, 5);

        return new NormalizedEvent(
            type: 'lastmile',
            dedupeKey: hash('sha256', implode('|', ['torod_point', 'lastmile', (string) $barcode, $status, (string) ($payload['time'] ?? ''), (string) $postponed])),
            references: [
                'barcode' => (string) $barcode,
                'package_id' => isset($payload['packageid']) ? (string) $payload['packageid'] : null,
                'invoice' => isset($payload['invoicenumber']) ? (string) $payload['invoicenumber'] : null,
                'cod' => isset($payload['cod']) && is_numeric($payload['cod']) ? (string) $payload['cod'] : null,
            ],
            providerStatus: $status,
            occurredAt: $occurredAt,
            meta: array_filter([
                'postponed_until' => is_scalar($postponed) ? (string) $postponed : null,
                'last_note' => isset($payload['notes']) && is_scalar($payload['notes']) ? mb_substr((string) $payload['notes'], 0, 250) : null,
                'proof_urls' => $proof === [] ? null : $proof,
            ], static fn ($value): bool => $value !== null),
            note: isset($payload['notes']) && is_scalar($payload['notes']) ? mb_substr((string) $payload['notes'], 0, 250) : null,
        );
    }

    /** @param array<array-key, mixed> $payload */
    private function normalizeFulfillment(array $payload): ?NormalizedEvent
    {
        $payload = $this->withLowerCaseKeys($payload);
        $status = $payload['status'] ?? null;
        $barcode = $payload['barcode'] ?? null;
        if (!is_string($status) || $status === '' || !is_scalar($barcode) || (string) $barcode === '') {
            return null;
        }

        $package = isset($payload['packagebarcode']) && is_scalar($payload['packagebarcode']) && (string) $payload['packagebarcode'] !== '' ? (string) $payload['packagebarcode'] : null;

        return new NormalizedEvent(
            type: 'fulfillment',
            dedupeKey: hash('sha256', implode('|', ['torod_point', 'fulfillment', (string) $barcode, $status, (string) $package])),
            references: ['barcode' => (string) $barcode, 'package_barcode' => $package],
            providerStatus: $status,
            occurredAt: null,
            informational: true,
            warehouseStatus: $status,
        );
    }

    /**
     * The webhook documents give field names in one casing and the examples in another; reading them case-insensitively
     * means a provider that capitalises a key does not turn every delivery into "invalid payload".
     *
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private function withLowerCaseKeys(array $payload): array
    {
        return array_change_key_case($payload, CASE_LOWER);
    }

    /** Epoch milliseconds → time, clamped so a skewed provider clock cannot pin a shipment to the future. */
    private function occurredAt(mixed $time): ?Carbon
    {
        if (!is_numeric($time) || (float) $time <= 0) {
            return null;
        }

        $at = AppTime::fromMilliseconds($time);
        $ceiling = now()->addSeconds((int) config('fulfillment.webhooks.max_future_skew_seconds', 300));

        return $at->greaterThan($ceiling) ? now() : $at;
    }

    private function knownToBeOutOfStock(ProviderProductMapping $mapping, int $quantity): bool
    {
        return $mapping->stock_source === StockSource::ProviderSync
            && $mapping->provider_stock !== null
            && $mapping->provider_stock_synced_at?->gt(now()->subHours(self::STOCK_FRESH_HOURS))
            && $mapping->provider_stock < $quantity;
    }

    private function ensureCallable(): void
    {
        if (!$this->connections->canCall($this->code())) {
            throw new ProviderUnavailableException('Torod Point is disabled or integrations are switched off; nothing was sent.');
        }
    }

    /** @return array{ok: false, error: string} */
    private function failed(string $capability, string $message): array
    {
        $this->sync->failed($this->code()->value, $capability, $message);

        return ['ok' => false, 'error' => $message];
    }

    /** @param bool $writes false for a capability that writes to the provider while this host may not write */
    private function state(string $key, bool $configured, bool $global, bool $switchedOn, bool $writes = true): CapabilityState
    {
        return match (true) {
            !$configured => new CapabilityState($key, CapabilityState::NEEDS_CONFIGURATION, 'credentials_missing'),
            !$writes => new CapabilityState($key, CapabilityState::READY_DISABLED, 'external_writes_off'),
            $global && $switchedOn => new CapabilityState($key, CapabilityState::ENABLED),
            default => new CapabilityState($key, CapabilityState::READY_DISABLED),
        };
    }

    private function config(): ProviderConfig
    {
        return $this->connections->config($this->code());
    }
}
