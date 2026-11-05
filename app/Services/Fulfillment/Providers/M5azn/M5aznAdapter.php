<?php

namespace App\Services\Fulfillment\Providers\M5azn;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\HoldReason;
use App\Enums\Fulfillment\PaymentMode;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\Order;
use App\Models\ProviderFulfillment;
use App\Models\ProviderProductMapping;
use App\Models\ProviderSyncState;
use App\Services\Fulfillment\CatalogStagingService;
use App\Services\Fulfillment\Contracts\ProviderAdapter;
use App\Services\Fulfillment\Contracts\ReconcilesSubmissions;
use App\Services\Fulfillment\Data\CancellationResult;
use App\Services\Fulfillment\Data\CapabilityState;
use App\Services\Fulfillment\Data\ConnectionTestResult;
use App\Services\Fulfillment\Data\PreflightResult;
use App\Services\Fulfillment\Data\ReadinessReport;
use App\Services\Fulfillment\Data\StatusSnapshot;
use App\Services\Fulfillment\Data\SubmissionResult;
use App\Services\Fulfillment\Exceptions\ProviderUnavailableException;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\HttpResult;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\ProviderSyncRecorder;
use App\Services\Fulfillment\Support\SaudiPhone;
use App\Services\Fulfillment\Support\PiiRedactor;
use App\Services\Fulfillment\Support\SecretRedactor;
use App\Services\Fulfillment\Support\StoredFieldMap;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * M5AZN, a dropshipping supplier. Our store acts as a SELLER account on M5AZN: it logs in, reads the
 * products that seller has selected, follows their stock, and submits customer orders.
 *
 * What the official documentation does NOT provide is reflected in the capability report rather than
 * papered over: there is no product-push webhook (so products are PULLED from `seller/myproductlist`),
 * no order-status webhook, no cancel or return endpoint, no margin endpoint, and no response schemas.
 * Anything that depends on a response schema runs only through an administrator-verified field map.
 */
class M5aznAdapter implements ProviderAdapter, ReconcilesSubmissions
{
    private const STOCK_FRESH_HOURS = 6;

    /** Product types M5AZN's "my products" call accepts. The call itself defaults to `local`. */
    private const PRODUCT_TYPES = ['local', 'global', 'ali_express', 'digital'];

    /** Safety cap on pages read per product type in one sync. */
    private const CATALOG_MAX_PAGES = 50;

    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly M5aznClient $client,
        private readonly M5aznPayloadBuilder $payloads,
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
            'import_enabled' => false,
            'dispatch_order_statuses' => [],
            'platform_name' => '',
            'country_code' => 'SA',
            'country_name' => 'Saudi Arabia',
            'language' => 'en',
            'device' => 'Android',
            'api_version' => '1.0',
            'app_version' => '1.0',
            'catalog_product_type' => 'local',
            'catalog_category_id' => '',
            'catalog_brand_id' => '',
            'default_category_id' => null,
            'publish_on_import' => false,
            'update_content_on_sync' => false,
            'price_policy' => 'store_controlled',
            'min_margin_percent' => 0,
            'cod_supported' => true,
            'cod_verified' => false,
            'order_response_reference_path' => '',
            'status_map' => [],
            'field_maps' => [],
            'tracking_url_template' => '',
        ];
    }

    public function code(): ProviderCode
    {
        return ProviderCode::M5azn;
    }

    public function readiness(): ReadinessReport
    {
        $config = $this->config();
        $missing = array_values(array_filter([
            $config->hasSecret('email') ? null : 'email',
            $config->hasSecret('password') ? null : 'password',
        ]));
        $credentials = $missing === [];
        $global = $this->connections->isGloballyEnabled() && $config->enabled;

        $catalogMap = $this->fieldMaps->get($this->code(), M5aznFieldMaps::CATALOG);
        $inventoryMap = $this->fieldMaps->get($this->code(), M5aznFieldMaps::INVENTORY);
        $ordersMap = $this->fieldMaps->get($this->code(), M5aznFieldMaps::ORDERS);

        $capabilities = [
            'connection' => $this->state('connection', $credentials, $global, true),
            'catalog_sync' => $this->state('catalog_sync', $credentials, $global, $config->bool('product_sync_enabled')),
            'catalog_import' => $this->gated('catalog_import', $credentials && $global && $config->bool('import_enabled') && $config->get('default_category_id') !== null, $catalogMap->verified, 'field_map_unverified', $credentials),
            'inventory_sync' => $this->gated('inventory_sync', $credentials && $global && $config->bool('inventory_sync_enabled'), $inventoryMap->verified, 'field_map_unverified', $credentials),
            'dispatch' => $this->state('dispatch', $credentials, $global, $config->bool('dispatch_enabled'), $this->connections->externalWritesEnabled()),
            'status_poll' => $this->gated('status_poll', $credentials && $global && (array) $config->get('status_map', []) !== [], $ordersMap->verified, 'field_map_unverified', $credentials),
            'cancel' => new CapabilityState('cancel', CapabilityState::UNDOCUMENTED, 'm5azn_no_cancel_endpoint'),
            'webhooks' => new CapabilityState('webhooks', CapabilityState::UNDOCUMENTED, 'm5azn_no_webhooks'),
            'margin_management' => new CapabilityState('margin_management', CapabilityState::UNDOCUMENTED, 'm5azn_margin_managed_on_platform'),
        ];

        return new ReadinessReport($credentials, $missing, $capabilities);
    }

    public function testConnection(): ConnectionTestResult
    {
        if (!$this->connections->canCall($this->code())) {
            return new ConnectionTestResult(false, 'Integrations are switched off or this provider is disabled; nothing was sent.');
        }

        $this->sync->attempted($this->code()->value, ProviderSyncRecorder::CONNECTION);
        $login = $this->client->login();
        $token = $login->get('data.accessToken');

        if ($login->ok() && is_string($token) && $token !== '' && $login->get('success', true) !== false) {
            $this->client->refreshToken();
            $this->sync->succeeded($this->code()->value, ProviderSyncRecorder::CONNECTION);

            return new ConnectionTestResult(true, 'Logged in to M5AZN.', $login->status);
        }

        $message = $login->message();
        $this->sync->failed($this->code()->value, ProviderSyncRecorder::CONNECTION, $message);

        return new ConnectionTestResult(false, $message, $login->status);
    }

    public function preflight(ProviderFulfillment $fulfillment): PreflightResult
    {
        $order = Order::find($fulfillment->order_id);
        $address = $order === null ? [] : $this->payloads->address($order);

        if ($fulfillment->payment_mode === PaymentMode::CashOnDelivery && !$this->config()->bool('cod_supported', true)) {
            return PreflightResult::hold(HoldReason::PROVIDER_NOT_READY, 'cod_supported');
        }

        foreach ($fulfillment->items as $item) {
            if ($item->mapping_id === null || ($item->external_product_id ?? '') === '' || ($item->external_sku ?? '') === '') {
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

        if (trim((string) ($address['address'] ?? '')) === '' || trim((string) ($address['city'] ?? '')) === '') {
            return PreflightResult::hold(HoldReason::ADDRESS_INCOMPLETE);
        }
        if (SaudiPhone::normalize((string) ($address['phone'] ?? '')) === null) {
            return PreflightResult::hold(HoldReason::PHONE_INVALID);
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

        try {
            $payload = $this->payloads->build($fulfillment->loadMissing('items'), $order, $config);
        } catch (Throwable $exception) {
            return SubmissionResult::rejected('payload_error', $exception::class . ': ' . $exception->getMessage());
        }

        if ($config->bool('dry_run', true)) {
            return SubmissionResult::dryRun([
                'payload_hash' => hash('sha256', (string) json_encode($payload)),
                'payload_preview' => SecretRedactor::redact($payload),
            ]);
        }

        return $this->interpretCreateResponse($this->client->createOrder($payload, $fulfillment->id), $config);
    }

    public function cancel(ProviderFulfillment $fulfillment): CancellationResult
    {
        return CancellationResult::unsupported('M5AZN documents no cancel endpoint. Cancel the order in the M5AZN dashboard, then mark it cancelled here.');
    }

    public function fetchStatus(ProviderFulfillment $fulfillment): ?StatusSnapshot
    {
        $stored = $this->fieldMaps->get($this->code(), M5aznFieldMaps::ORDERS);
        if (!$stored->verified || $fulfillment->provider_reference === null) {
            return null;
        }

        $result = $this->client->orderDetails($fulfillment->provider_reference);
        if (!$result->ok() || $result->json === null) {
            return null;
        }

        $map = $stored->map();
        $status = $map->string($result->json, 'status') ?? $map->string((array) Arr::get($result->json, 'data', []), 'status');

        return $status === null ? null : new StatusSnapshot($status, raw: SecretRedactor::redact($result->json));
    }

    public function mapStatus(string $providerStatus): ?FulfillmentStatus
    {
        $map = array_change_key_case((array) $this->config()->get('status_map', []), CASE_LOWER);
        $internal = FulfillmentStatus::tryFrom((string) ($map[strtolower(trim($providerStatus))] ?? ''));

        return $internal === null || $internal->isPreSubmission() ? null : $internal;
    }

    /**
     * An ambiguous create-order is resolved by finding OUR order id in M5AZN's order list. That needs a
     * verified orders map that says which field echoes our id; without it, and for a re-dispatched
     * generation whose id is shared with an earlier one, the answer stays "unknown".
     */
    public function reconcileSubmission(ProviderFulfillment $fulfillment): SubmissionResult
    {
        $stored = $this->fieldMaps->get($this->code(), M5aznFieldMaps::ORDERS);
        $map = $stored->map();
        if (!$stored->verified || !$map->has('external_order_id') || $fulfillment->generation > 1) {
            return SubmissionResult::ambiguous('cannot_reconcile', 'No verified way to find this order in the M5AZN order list.');
        }

        $result = $this->client->orders();
        $items = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json, $stored->itemsPath) : null;
        if ($items === null) {
            return SubmissionResult::ambiguous('order_list_unreadable', 'The M5AZN order list could not be read.');
        }

        foreach ($items as $item) {
            if ($map->string($item, 'external_order_id') === (string) $fulfillment->order_id) {
                $reference = $map->string($item, 'reference');
                if ($reference !== null) {
                    return SubmissionResult::accepted(providerReference: $reference, providerStatus: $map->string($item, 'status'));
                }
            }
        }

        return SubmissionResult::ambiguous('not_found_in_order_list', 'Our order id is not in the M5AZN order list (it may be paged or late).');
    }

    /**
     * Pull the seller's selected products into the staging area. Staging is a raw, de-duplicated copy
     * and is safe without a verified map; IMPORTING a staged item into the store needs one.
     *
     * @return array{ok: bool, error?: string, stats?: array<string, int>, verified?: bool}
     */
    public function syncCatalog(): array
    {
        $this->ensureCallable();
        $this->sync->attempted($this->code()->value, ProviderSyncRecorder::PRODUCTS);

        $config = $this->config();
        $stored = $this->fieldMaps->get($this->code(), M5aznFieldMaps::CATALOG);
        $filters = array_filter([
            'category_id' => $config->string('catalog_category_id'),
            'brand_id' => $config->string('catalog_brand_id'),
        ], static fn (string $value): bool => $value !== '');

        $items = [];
        $pages = 0;
        $truncated = false;
        $partial = false;
        foreach ($this->catalogProductTypes($config) as $type) {
            $fetched = $this->fetchSelectedProducts($filters + ['product_type' => $type], $stored->itemsPath, $this->identityPaths($stored));
            if ($fetched['error'] !== null) {
                return $this->failed(ProviderSyncRecorder::PRODUCTS, $fetched['error']);
            }

            $items = array_merge($items, $fetched['items']);
            $pages += $fetched['pages'];
            $truncated = $truncated || $fetched['truncated'];
            $partial = $partial || $fetched['partial'];
        }

        $paths = $stored->paths !== [] ? $stored->paths : $this->fieldMaps->propose($items, M5aznFieldMaps::CANDIDATES[M5aznFieldMaps::CATALOG]);
        $map = new FieldMap($paths);

        $records = [];
        $unidentified = 0;
        foreach ($items as $item) {
            $externalId = $map->string($item, 'id');
            if ($externalId === null) {
                $unidentified++;
                continue;
            }

            $records[$externalId] = [
                'external_id' => $externalId,
                'name' => $map->string($item, 'name'),
                'sku' => $map->string($item, 'sku'),
                'barcode' => $map->string($item, 'barcode'),
                'stock' => ($stock = $map->number($item, 'stock')) === null ? null : (int) $stock,
                'cost' => $map->number($item, 'cost'),
                'retail_price' => $map->number($item, 'retail_price'),
                'payload' => $item,
            ];
        }

        $stats = $this->staging->stage($this->code(), 'api', array_values($records)) + ['unidentified' => $unidentified, 'pages' => $pages, 'truncated' => $truncated, 'partial' => $partial];
        $this->sync->succeeded($this->code()->value, ProviderSyncRecorder::PRODUCTS, $stats);

        return ['ok' => true, 'stats' => $stats, 'verified' => $stored->verified];
    }

    /**
     * The product types the seller can have selected. M5AZN's "my products" call defaults to `local`, so a
     * seller who supplied a `global` or `ali_express` product would never see it appear unless that type is
     * asked for as well. The setting is a comma-separated list; unknown words are ignored.
     *
     * @return list<string>
     */
    private function catalogProductTypes(ProviderConfig $config): array
    {
        $types = array_values(array_unique(array_filter(
            array_map('trim', explode(',', strtolower($config->string('catalog_product_type', 'local')))),
            static fn (string $type): bool => in_array($type, self::PRODUCT_TYPES, true),
        )));

        return $types === [] ? ['local'] : $types;
    }

    /**
     * Every product on the seller's list for one product type.
     *
     * The documentation lists no pagination parameter and no response schema, so this asks for page 2, 3, …
     * and stops as soon as a page adds nothing new (an API that ignores `page` repeats page 1) or the safety
     * cap is reached. Silently reading only the first page of a paginated list would drop products without
     * any error, which is the one outcome this must not allow.
     *
     * @param array<string, string> $query
     * @param array<string, string> $identityPaths where an item's id lives (stored map or the proposed one)
     * @return array{items: list<array<array-key, mixed>>, pages: int, truncated: bool, partial: bool, error: ?string} partial = a later page failed, so the list may be incomplete this time
     */
    private function fetchSelectedProducts(array $query, ?string $itemsPath, array $identityPaths): array
    {
        $items = [];
        $seen = [];
        $pages = 0;

        for ($page = 1; $page <= self::CATALOG_MAX_PAGES; $page++) {
            $result = $this->client->myProducts($page === 1 ? $query : $query + ['page' => $page]);
            $pages++;

            if (!$result->ok() || $result->json === null) {
                return $page === 1
                    ? ['items' => [], 'pages' => $pages, 'truncated' => false, 'partial' => false, 'error' => $result->message()]
                    : ['items' => $items, 'pages' => $pages, 'truncated' => false, 'partial' => true, 'error' => null];
            }

            $pageItems = FieldMap::extractItems($result->json, $itemsPath);
            if ($pageItems === null) {
                return $page === 1
                    ? ['items' => [], 'pages' => $pages, 'truncated' => false, 'partial' => false, 'error' => 'The response does not contain a list of products; set the items path in the field map.']
                    : ['items' => $items, 'pages' => $pages, 'truncated' => false, 'partial' => false, 'error' => null];
            }

            $added = 0;
            foreach ($pageItems as $item) {
                $fingerprint = $this->fingerprint($item, $identityPaths);
                if (!isset($seen[$fingerprint])) {
                    $seen[$fingerprint] = true;
                    $items[] = $item;
                    $added++;
                }
            }

            if ($added === 0) {
                return ['items' => $items, 'pages' => $pages, 'truncated' => false, 'partial' => false, 'error' => null];
            }
        }

        return ['items' => $items, 'pages' => $pages, 'truncated' => true, 'partial' => false, 'error' => null];
    }

    /** @param array<array-key, mixed> $item @param array<string, string> $identityPaths */
    private function fingerprint(array $item, array $identityPaths): string
    {
        $id = $identityPaths === [] ? null : (new FieldMap($identityPaths))->string($item, 'id');

        return $id !== null ? 'id:' . $id : 'hash:' . hash('sha256', (string) json_encode($item));
    }

    /** @return array<string, string> */
    private function identityPaths(StoredFieldMap $stored): array
    {
        return $stored->paths !== [] ? $stored->paths : ['id' => M5aznFieldMaps::CANDIDATES[M5aznFieldMaps::CATALOG]['id'][0]];
    }

    /**
     * Follow M5AZN's stock changes through its documented change feed. Runs only against a verified
     * inventory map; the store's stock is then overwritten with M5AZN's number, minus what we have sold
     * and not yet handed to them.
     *
     * @return array{ok: bool, error?: string, stats?: array<string, int>}
     */
    public function syncInventory(): array
    {
        $this->ensureCallable();
        $stored = $this->fieldMaps->get($this->code(), M5aznFieldMaps::INVENTORY);
        if (!$stored->verified) {
            return $this->failed(ProviderSyncRecorder::INVENTORY, 'The inventory field map is not verified yet.');
        }
        if (!$this->config()->bool('inventory_sync_enabled')) {
            return $this->failed(ProviderSyncRecorder::INVENTORY, 'Inventory sync is switched off.');
        }

        $this->sync->attempted($this->code()->value, ProviderSyncRecorder::INVENTORY);
        [$days, $hours] = $this->lookbackWindow();
        $result = $this->client->quantityChanges($days, $hours);
        $items = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json, $stored->itemsPath) : null;
        if ($items === null) {
            return $this->failed(ProviderSyncRecorder::INVENTORY, $result->ok() ? 'The stock-change response contains no list.' : $result->message());
        }

        $map = $stored->map();
        $stock = [];
        foreach ($items as $item) {
            $id = $map->string($item, 'id');
            $quantity = $map->number($item, 'stock');
            if ($id === null || $quantity === null) {
                continue;
            }
            $variant = $map->string($item, 'variant_sku');
            $stock[$variant !== null ? $id . '|' . $variant : $id] = (int) $quantity;
        }

        $stats = $this->inventory->apply($this->code(), $stock);
        $this->sync->succeeded($this->code()->value, ProviderSyncRecorder::INVENTORY, $stats);

        return ['ok' => true, 'stats' => $stats];
    }

    /**
     * A read-only look at what M5AZN really returns, for the administrator verifying a field map:
     * the first records raw, plus the mapping we would propose.
     *
     * @return array{ok: bool, error?: string, items: list<array<array-key, mixed>>, proposal: array<string, string>, items_path: ?string}
     */
    public function sample(string $capability): array
    {
        $this->ensureCallable();

        $result = match ($capability) {
            M5aznFieldMaps::INVENTORY => $this->client->quantityChanges(days: 1),
            M5aznFieldMaps::ORDERS => $this->client->orders(),
            default => $this->client->myProducts(['product_type' => $this->config()->string('catalog_product_type', 'local')]),
        };

        $stored = $this->fieldMaps->get($this->code(), $capability);
        $items = $result->ok() && $result->json !== null ? FieldMap::extractItems($result->json, $stored->itemsPath) : null;
        if ($items === null) {
            return ['ok' => false, 'error' => $result->ok() ? 'No list of records was found in the response.' : $result->message(), 'items' => [], 'proposal' => [], 'items_path' => null];
        }

        $items = array_slice($items, 0, 5);

        return [
            'ok' => true,
            'items' => PiiRedactor::redact(SecretRedactor::redact($items), strict: $capability === M5aznFieldMaps::ORDERS),
            'proposal' => $this->fieldMaps->propose($items, M5aznFieldMaps::CANDIDATES[$capability] ?? []),
            'items_path' => $stored->itemsPath,
        ];
    }

    private function interpretCreateResponse(HttpResult $result, ProviderConfig $config): SubmissionResult
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
        foreach ($configured !== '' ? [$configured] : M5aznFieldMaps::CREATE_RESPONSE_REFERENCE_PATHS as $path) {
            $reference = Arr::get($json, $path);
            if (is_scalar($reference) && (string) $reference !== '') {
                return SubmissionResult::accepted(providerReference: (string) $reference);
            }
        }

        // A 2xx with nothing to hold on to is not verified acceptance, and must not be re-sent either.
        return SubmissionResult::ambiguous('no_reference_in_response', 'M5AZN answered successfully but returned no order reference; confirm in the M5AZN dashboard and link it manually.');
    }

    private function knownToBeOutOfStock(ProviderProductMapping $mapping, int $quantity): bool
    {
        return $mapping->stock_source === StockSource::ProviderSync
            && $mapping->provider_stock !== null
            && $mapping->provider_stock_synced_at?->gt(now()->subHours(self::STOCK_FRESH_HOURS))
            && $mapping->provider_stock < $quantity;
    }

    /** @return array{0: ?int, 1: ?int} [days, hours] covering the time since the last successful stock sync */
    private function lookbackWindow(): array
    {
        $last = ProviderSyncState::where('provider', $this->code()->value)->where('capability', ProviderSyncRecorder::INVENTORY)->value('last_success_at');
        // One hour of overlap: the feed is absolute quantities, so re-reading a change is harmless.
        $hours = $last === null ? 24 : (int) ceil(Carbon::parse($last)->diffInMinutes(now(), true) / 60) + 1;

        return $hours <= 24 ? [null, max(1, $hours)] : [min(7, (int) ceil($hours / 24)), null];
    }

    private function ensureCallable(): void
    {
        if (!$this->connections->canCall($this->code())) {
            throw new ProviderUnavailableException('M5AZN is disabled or integrations are switched off; nothing was sent.');
        }
    }

    /** @return array{ok: false, error: string} */
    private function failed(string $capability, string $message): array
    {
        $this->sync->failed($this->code()->value, $capability, $message);

        return ['ok' => false, 'error' => $message];
    }

    /** @param bool $writes false for a capability that writes to the provider while this host may not write */
    private function state(string $key, bool $credentials, bool $global, bool $switchedOn, bool $writes = true): CapabilityState
    {
        return match (true) {
            !$credentials => new CapabilityState($key, CapabilityState::NEEDS_CONFIGURATION, 'credentials_missing'),
            !$writes => new CapabilityState($key, CapabilityState::READY_DISABLED, 'external_writes_off'),
            $global && $switchedOn => new CapabilityState($key, CapabilityState::ENABLED),
            default => new CapabilityState($key, CapabilityState::READY_DISABLED),
        };
    }

    private function gated(string $key, bool $on, bool $mapVerified, string $unverifiedReason, bool $credentials): CapabilityState
    {
        return match (true) {
            !$credentials => new CapabilityState($key, CapabilityState::NEEDS_CONFIGURATION, 'credentials_missing'),
            !$mapVerified => new CapabilityState($key, CapabilityState::UNVERIFIED, $unverifiedReason),
            $on => new CapabilityState($key, CapabilityState::ENABLED),
            default => new CapabilityState($key, CapabilityState::READY_DISABLED),
        };
    }

    private function config(): ProviderConfig
    {
        return $this->connections->config($this->code());
    }
}
