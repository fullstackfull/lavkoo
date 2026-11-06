<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Contracts\ConfirmsShipments;
use App\Services\SalesChannels\Contracts\PublishesStockAndPrices;
use App\Services\SalesChannels\Contracts\ReadsFinances;
use App\Services\SalesChannels\Contracts\ReadsListings;
use App\Services\SalesChannels\Contracts\ReadsOrders;
use App\Services\SalesChannels\Contracts\ReportsPublishResults;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\PublishResult;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Support\ChannelResponseGuard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Trendyol Marketplace Integration API (international / Gulf documentation set, `storeFrontCode` header): connection test, products (Product V2), orders (Order V2 package
 * model), price and stock publishing, picking and tracking, and the current account statement. See TrendyolOperations for the calls and the documentation they follow.
 *
 * Product V1 and Order V1 are not used anywhere: Trendyol retired them in 2026.
 */
class TrendyolConnector implements ChannelConnector, ReadsListings, ReadsOrders, ReadsFinances, PublishesStockAndPrices, ReportsPublishResults, ConfirmsShipments
{
    private const MILLISECONDS_PER_DAY = 86_400_000;
    private const LIST_PAGE_SIZE = 100;
    private const LIST_WINDOW_LIMIT = 10_000;
    private const STREAM_PAGE_SIZE = 200;
    private const STREAM_HISTORY_DAYS = 89;
    private const FINANCE_PAGE_SIZE = 1000;
    private const PUBLISH_CHUNK = 1000;
    private const MAX_UNITS_PER_PRODUCT = 20_000;

    public function __construct(
        private readonly TrendyolClient $client,
        private readonly TrendyolOrderMapper $orders,
        private readonly TrendyolListingMapper $listings,
        private readonly TrendyolFinanceMapper $finances,
    ) {
    }

    public function channel(): SalesChannel
    {
        return SalesChannel::Trendyol;
    }

    public function capabilityMatrix(): array
    {
        return [
            'basic_authentication' => ['documented' => true, 'built' => true, 'note' => 'API key and secret as HTTP Basic, the mandatory "{sellerId} - {integration name}" User-Agent and the storeFrontCode header. Stage and production use different keys and hosts.'],
            'product_v2' => ['documented' => true, 'built' => true, 'note' => 'Approved and unapproved product lists, price-and-inventory with batch results. Product V1 was retired in 2026 and is not used.'],
            'product_create_update' => ['documented' => true, 'built' => false, 'note' => 'Creating or editing product content (brand, category, attributes, images) needs the lookups and an approval review at Trendyol; it is designed (operations are registered) but not built. Products are created in the seller panel and then linked here.'],
            'order_v2' => ['documented' => true, 'built' => true, 'note' => 'The cursor stream (lastModified windows of at most 14 days) for sync and /v2/orders for single-order lookups. Order V1 is not used.'],
            'shipment_packages' => ['documented' => true, 'built' => true, 'note' => 'A package is the unit (shipmentPackageId); a split or partial cancellation yields new package ids and the replaced package is terminal.'],
            'picking_and_tracking' => ['documented' => true, 'built' => true, 'note' => 'Picking, then the tracking number for packages on the seller-pays agreement only. Trendyol-pays packages use the carrier label Trendyol issues; the app never sets Shipped or Delivered (the carrier feed does).'],
            'cancel_unsupplied' => ['documented' => true, 'built' => false, 'note' => 'Cancelling lines cancels a customer order and locks the product at Trendyol: registered but deliberately not built.'],
            'split_packages' => ['documented' => true, 'built' => false, 'note' => 'Four split endpoints exist; none is built.'],
            'invoice_upload' => ['documented' => true, 'built' => false, 'note' => 'Invoice link/file upload is registered; invoice generation is outside this module.'],
            'webhooks' => ['documented' => true, 'built' => true, 'note' => 'Order-package webhooks (API-key authentication); polling stays the source of truth because Trendyol says delivery is not always possible. No webhook exists for claims, products or finance.'],
            'claims_returns' => ['documented' => true, 'built' => false, 'note' => 'Returned-package statuses reach the order through the feed; reading claim items to record partially returned quantities is designed but not built.'],
            'current_account_statement' => ['documented' => true, 'built' => true, 'note' => 'settlements (Sale, Return) and otherfinancials, windows of at most 15 days. Rows carry no currency and their ids changed in Aug 2026, so identity is built from the row\'s content.'],
            'fulfilment_by_trendyol' => ['documented' => false, 'built' => false, 'note' => 'The Gulf documentation describes no fulfilment-by-marketplace stock; every package is prepared by the seller.'],
        ];
    }

    // ---- connection test ----------------------------------------------------------------------------------------

    public function testConnection(SalesChannelAccount $account): ConnectionTestResult
    {
        $capability = IntegrationCapability::ConnectionTest;
        try {
            $seller = $this->client->sellerId($account);
        } catch (ChannelUnavailableException $exception) {
            return ConnectionTestResult::failed($exception->getMessage());
        }

        $nowMs = $this->milliseconds(now());
        $probes = [
            'products:read' => ['trendyol.products_approved', "/product/sellers/{$seller}/products/approved", ['page' => 0, 'size' => 1]],
            'orders:read' => ['trendyol.probe_orders', "/order/sellers/{$seller}/orders/stream", ['size' => 1, 'lastModifiedStartDate' => $nowMs - self::MILLISECONDS_PER_DAY, 'lastModifiedEndDate' => $nowMs]],
            'finance:read' => ['trendyol.probe_finance', "/finance/che/sellers/{$seller}/settlements", ['transactionType' => 'Sale', 'startDate' => $nowMs - 3 * self::MILLISECONDS_PER_DAY, 'endDate' => $nowMs, 'page' => 0, 'size' => 500]],
        ];

        $granted = [];
        $missing = [];
        $hints = [];
        foreach ($probes as $label => [$operation, $path, $query]) {
            try {
                $response = $this->client->call($account, $operation, 'GET', $path, $query, capability: $capability);
            } catch (ChannelPermissionDenied) {
                $missing[] = $label;
                continue;
            } catch (ChannelUnavailableException $exception) {
                $status = $exception->reason === ChannelUnavailableException::AUTH_NEEDED ? ConnectionStatus::AuthExpired : ConnectionStatus::Failed;

                return ConnectionTestResult::failed($exception->getMessage(), $status);
            }

            if ($response->ok()) {
                $granted[] = $label;
                continue;
            }
            $missing[] = $label;
            if ($response->status === 503 && $account->isSandbox()) {
                $hints['sandbox_ip'] = 'The stage environment answers 503 for a server whose IP address is not allow-listed: ask Trendyol to allow this server\'s egress IP (or use the IP whitelist service with the production key).';
            }
        }

        if ($granted === []) {
            return ConnectionTestResult::failed('Trendyol did not accept any probe call. ' . implode(' ', $hints) . ' Missing: ' . implode(', ', $missing) . '.');
        }

        return new ConnectionTestResult(
            status: ConnectionStatus::Ok,
            message: $missing === [] ? 'Connected. Every probed service answered.' : 'Connected, but some services did not answer: ' . implode(', ', $missing) . '.',
            granted: $granted,
            missing: $missing,
            details: ['storefront' => $account->setting('storefront_code'), 'environment' => $account->isSandbox() ? 'stage' : 'production'] + $hints,
            externalAccountId: $seller,
        );
    }

    // ---- listings -----------------------------------------------------------------------------------------------

    public function listings(SalesChannelAccount $account, ?array $cursor): Pages
    {
        $seller = $this->client->sellerId($account);
        $phase = $cursor['phase'] ?? 'approved';
        $page = (int) ($cursor['page'] ?? 0);
        $token = $cursor['token'] ?? null;

        $query = ['size' => self::LIST_PAGE_SIZE];
        if ($token !== null) {
            $query['nextPageToken'] = $token;
        } else {
            $query['page'] = $page;
        }
        if ($phase === 'approved' && isset($cursor['from'])) {
            $query += ['startDate' => (int) $cursor['from'], 'dateQueryType' => 'VARIANT_MODIFIED_DATE'];
        }

        $response = $this->client->call($account, $phase === 'approved' ? 'trendyol.products_approved' : 'trendyol.products_unapproved', 'GET', "/product/sellers/{$seller}/products/{$phase}", $query, capability: IntegrationCapability::ProductSync);
        $this->assertOk($response, "Trendyol {$phase} products");

        $items = [];
        $newest = null;
        foreach ((array) $response->get('content', []) as $record) {
            $found = $phase === 'approved' ? $this->listings->approved((array) $record, $account) : array_filter([$this->listings->unapproved((array) $record, $account)]);
            foreach ($found as $listing) {
                $items[] = $listing;
                $newest = $listing->updatedAt !== null && ($newest === null || $listing->updatedAt->gt($newest)) ? $listing->updatedAt : $newest;
            }
        }

        return new Pages($items, $this->nextListingCursor($response, $phase, $page, $cursor['from'] ?? null), $newest);
    }

    /**
     * Pages are followed by number while that stays inside Trendyol's 10,000-record window, then by the token the response carries; when a phase ends the unapproved
     * products are read after the approved ones.
     *
     * @return array<string, mixed>|null
     */
    private function nextListingCursor(ChannelResponse $response, string $phase, int $page, mixed $from): ?array
    {
        $totalPages = (int) $response->get('totalPages', 1);
        $token = $response->get('nextPageToken');
        $withinWindow = ($page + 2) * self::LIST_PAGE_SIZE <= self::LIST_WINDOW_LIMIT;

        if ($withinWindow && $page + 1 < $totalPages) {
            return ['phase' => $phase, 'page' => $page + 1, 'from' => $from];
        }
        if (!$withinWindow && $page + 1 < $totalPages && is_string($token) && $token !== '') {
            return ['phase' => $phase, 'token' => $token, 'from' => $from];
        }

        return $phase === 'approved' ? ['phase' => 'unapproved', 'page' => 0, 'from' => $from] : null;
    }

    // ---- orders -------------------------------------------------------------------------------------------------

    public function orders(SalesChannelAccount $account, CarbonInterface $since, ?array $cursor): Pages
    {
        $seller = $this->client->sellerId($account);
        $nowMs = $this->milliseconds(now());
        $earliest = $nowMs - self::STREAM_HISTORY_DAYS * self::MILLISECONDS_PER_DAY;
        $windowMs = (int) config('sales_channels.trendyol.orders_window_days', 14) * self::MILLISECONDS_PER_DAY;

        $start = (int) ($cursor['start'] ?? max($this->milliseconds($since), $earliest));
        $end = (int) ($cursor['end'] ?? min($start + $windowMs, $nowMs));
        $query = ['size' => self::STREAM_PAGE_SIZE, 'lastModifiedStartDate' => $start, 'lastModifiedEndDate' => $end];
        // Changing a filter while reusing a cursor is a 400: the window stays exactly as it was when the cursor was issued.
        if (isset($cursor['cursor'])) {
            $query['nextCursor'] = $cursor['cursor'];
        }

        $response = $this->client->call($account, 'trendyol.orders_stream', 'GET', "/order/sellers/{$seller}/orders/stream", $query, capability: IntegrationCapability::OrderImport);
        $this->assertOk($response, 'Trendyol order stream');

        $items = [];
        $newest = null;
        $skipped = 0;
        foreach ((array) $response->get('content', []) as $package) {
            if (!is_array($package) || !isset($package['shipmentPackageId'], $package['orderNumber'])) {
                $skipped++;
                continue;
            }
            $mapped = $this->orders->map($package, $account);
            $items[] = $mapped;
            $newest = $mapped->updatedAt !== null && ($newest === null || $mapped->updatedAt->gt($newest)) ? $mapped->updatedAt : $newest;
        }

        $next = match (true) {
            (bool) $response->get('hasMore', false) && is_string($response->get('nextCursor')) => ['start' => $start, 'end' => $end, 'cursor' => $response->get('nextCursor')],
            $end < $nowMs => ['start' => $end, 'end' => min($end + $windowMs, $nowMs), 'cursor' => null],
            default => null,
        };

        return new Pages($items, $next, $newest, $skipped);
    }

    public function order(SalesChannelAccount $account, string $orderId, ?string $packageId = null): ?RemoteOrder
    {
        $seller = $this->client->sellerId($account);
        $query = $packageId !== null && $packageId !== '' ? ['shipmentPackageIds' => $packageId] : ['orderNumber' => $orderId];
        $response = $this->client->call($account, 'trendyol.orders_list', 'GET', "/order/sellers/{$seller}/v2/orders", $query + ['size' => 50], capability: IntegrationCapability::OrderImport);
        $this->assertOk($response, 'Trendyol order lookup');

        foreach ((array) $response->get('content', []) as $package) {
            if (is_array($package) && (string) ($package['orderNumber'] ?? '') === $orderId && ($packageId === null || (string) ($package['shipmentPackageId'] ?? '') === $packageId)) {
                return $this->orders->map($package, $account);
            }
        }

        return null;
    }

    // ---- finances -----------------------------------------------------------------------------------------------

    public function finances(SalesChannelAccount $account, CarbonInterface $from, CarbonInterface $to, ?array $cursor): Pages
    {
        $seller = $this->client->sellerId($account);
        $windowMs = (int) config('sales_channels.trendyol.finance_window_days', 15) * self::MILLISECONDS_PER_DAY;
        $toMs = min($this->milliseconds($to), $this->milliseconds(now()));

        $state = $cursor ?? ['start' => $this->milliseconds($from), 'stage' => 'settlements', 'type' => 0, 'page' => 0];
        $start = (int) $state['start'];
        $end = (int) ($state['end'] ?? min($start + $windowMs, $toMs));
        $stage = (string) $state['stage'];
        $types = array_values((array) config($stage === 'settlements' ? 'sales_channels.trendyol.finance_settlement_types' : 'sales_channels.trendyol.finance_other_types', []));
        $type = (string) ($types[(int) $state['type']] ?? '');

        $entries = [];
        $totalPages = 1;
        if ($type !== '' && $start < $end) {
            $path = $stage === 'settlements' ? 'settlements' : 'otherfinancials';
            $response = $this->client->call(
                $account, $stage === 'settlements' ? 'trendyol.finance_settlements' : 'trendyol.finance_other', 'GET', "/finance/che/sellers/{$seller}/{$path}",
                ['transactionType' => $type, 'startDate' => $start, 'endDate' => $end, 'page' => (int) $state['page'], 'size' => self::FINANCE_PAGE_SIZE],
                capability: IntegrationCapability::FinancialReconciliation,
            );
            $this->assertOk($response, "Trendyol {$path} ({$type})");
            $totalPages = (int) $response->get('totalPages', 1);
            foreach ((array) $response->get('content', []) as $row) {
                $entries = [...$entries, ...($stage === 'settlements' ? $this->finances->settlement((array) $row, $type, $account) : $this->finances->other((array) $row, $type, $account))];
            }
        }

        return new Pages($entries, $this->nextFinanceCursor($state, $start, $end, $toMs, $windowMs, count($types), $totalPages));
    }

    /**
     * The statement is walked page by page, then type by type (each type is its own request), then settlements before other financials, then window by window.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function nextFinanceCursor(array $state, int $start, int $end, int $toMs, int $windowMs, int $typeCount, int $totalPages): ?array
    {
        $base = ['start' => $start, 'end' => $end];
        if ((int) $state['page'] + 1 < $totalPages) {
            return $base + ['stage' => $state['stage'], 'type' => $state['type'], 'page' => (int) $state['page'] + 1];
        }
        if ((int) $state['type'] + 1 < $typeCount) {
            return $base + ['stage' => $state['stage'], 'type' => (int) $state['type'] + 1, 'page' => 0];
        }
        if ($state['stage'] === 'settlements') {
            return $base + ['stage' => 'other', 'type' => 0, 'page' => 0];
        }
        if ($end < $toMs) {
            return ['start' => $end, 'end' => min($end + $windowMs, $toMs), 'stage' => 'settlements', 'type' => 0, 'page' => 0];
        }

        return null;
    }

    // ---- publishing ---------------------------------------------------------------------------------------------

    public function publishStock(SalesChannelAccount $account, array $updates): PublishResult
    {
        return $this->publish($account, $updates, IntegrationCapability::InventoryPublishing, 'inventory_publishing');
    }

    public function publishPrices(SalesChannelAccount $account, array $updates): PublishResult
    {
        return $this->publish($account, $updates, IntegrationCapability::PricePublishing, 'price_publishing');
    }

    /**
     * Quantity and price are one Trendyol call (price-and-inventory, up to 1,000 items), but they are two approvals here, so each publish sends only its own field.
     * A zero price is refused: Trendyol takes a price of 0 as "remove the listing", which is never the intent of a computed price. The call returns a batch id, not a result:
     * an item is only known to be applied once its batch has been read (batchResult).
     *
     * @param list<PublishUpdate> $updates
     */
    private function publish(SalesChannelAccount $account, array $updates, IntegrationCapability $capability, string $scope): PublishResult
    {
        $seller = $this->client->sellerId($account);
        $isPrice = $capability === IntegrationCapability::PricePublishing;
        $accepted = [];
        $failed = [];
        $items = [];

        foreach ($updates as $update) {
            $item = $this->publishItem($update, $isPrice);
            if (is_string($item)) {
                $failed[$update->mappingId] = $item;
                continue;
            }
            $items[$update->mappingId] = $item;
        }

        $dryRun = false;
        foreach (array_chunk($items, self::PUBLISH_CHUNK, preserve_keys: true) as $chunk) {
            $response = $this->client->call($account, 'trendyol.price_inventory_update', 'POST', "/inventory/sellers/{$seller}/products/price-and-inventory", json: ['items' => array_values($chunk)], capability: $capability, scope: $scope);
            if ($response->isDryRun()) {
                $dryRun = true;
                continue;
            }
            $batchId = $response->ok() ? (string) $response->get('batchRequestId', '') : '';
            foreach (array_keys($chunk) as $mappingId) {
                if ($batchId !== '') {
                    $accepted[$mappingId] = $batchId;
                } else {
                    $failed[$mappingId] = $response->ok() ? 'Trendyol returned no batch id.' : 'HTTP ' . $response->status . ': ' . $response->message();
                }
            }
        }

        return new PublishResult($accepted, $failed, $accepted === [] ? null : (string) reset($accepted), $dryRun);
    }

    /** @return array<string, mixed>|string the item to send, or the reason it cannot be sent */
    private function publishItem(PublishUpdate $update, bool $isPrice): array|string
    {
        if ($update->barcode === null || $update->barcode === '') {
            return 'The listing has no barcode; Trendyol identifies stock and price by barcode.';
        }
        if (!$isPrice) {
            if ($update->quantity === null) {
                return 'No quantity to publish.';
            }
            if ($update->quantity < 0 || $update->quantity > self::MAX_UNITS_PER_PRODUCT) {
                return 'Trendyol accepts between 0 and ' . self::MAX_UNITS_PER_PRODUCT . ' units per product.';
            }

            return ['barcode' => $update->barcode, 'quantity' => $update->quantity];
        }
        if ($update->price === null) {
            return 'No price to publish.';
        }
        if (!$update->price->isPositive()) {
            return 'A price of zero or less is refused: Trendyol treats a zero price as removing the listing.';
        }
        if ($update->listPrice !== null && $update->listPrice->compareTo($update->price) < 0) {
            return 'The list price may not be lower than the sale price.';
        }

        return array_filter(['barcode' => $update->barcode, 'salePrice' => round($update->price->toFloat(), 2), 'listPrice' => $update->listPrice === null ? null : round($update->listPrice->toFloat(), 2)], static fn ($value): bool => $value !== null);
    }

    /**
     * What became of a price-and-inventory batch, per barcode (batches are kept for four hours).
     *
     * @return array{complete: bool, items: array<string, array{status: string, reasons: list<string>}>}
     */
    public function batchResult(SalesChannelAccount $account, string $batchId): array
    {
        $seller = $this->client->sellerId($account);
        $capability = $account->hasCapability(IntegrationCapability::InventoryPublishing) ? IntegrationCapability::InventoryPublishing : IntegrationCapability::PricePublishing;
        $response = $this->client->call($account, 'trendyol.batch_result', 'GET', "/product/sellers/{$seller}/products/batch-requests/" . rawurlencode($batchId), capability: $capability);
        $this->assertOk($response, 'Trendyol batch result');

        $items = [];
        foreach ((array) $response->get('items', []) as $item) {
            $barcode = (string) ($item['requestItem']['barcode'] ?? '');
            if ($barcode !== '') {
                $items[$barcode] = ['status' => (string) ($item['status'] ?? ''), 'reasons' => array_map('strval', (array) ($item['failureReasons'] ?? []))];
            }
        }
        // For a price-and-inventory batch the top-level status is not returned: the batch is settled when every item it holds has an outcome.
        $expected = (int) $response->get('itemCount', count($items));
        $settled = count(array_filter($items, static fn (array $item): bool => in_array($item['status'], ['SUCCESS', 'FAILED'], true)));

        return ['complete' => $response->get('status') === 'COMPLETED' || ($expected > 0 && $settled >= $expected), 'items' => $items];
    }

    // ---- shipping -----------------------------------------------------------------------------------------------

    /**
     * Tells Trendyol the package is being prepared and, for a package on the seller-pays agreement, which carrier has it and under what number. Nothing here marks a package
     * Shipped or Delivered: Trendyol sets those from the carrier. The package is read first, so a package that has since been split, cancelled or shipped is left alone.
     * A package Trendyol pays the carrier for is only moved to Picking, because Trendyol issues the tracking number and the label.
     *
     * @throws RuntimeException when the seller-pays carrier has no Trendyol provider code configured
     */
    public function confirmShipment(SalesChannelAccount $account, ShipmentConfirmation $confirmation): bool
    {
        $seller = $this->client->sellerId($account);
        $package = $this->order($account, $confirmation->orderId, $confirmation->packageId);
        if ($package === null || !in_array($package->externalStatus, ['Created', 'Picking', 'Invoiced'], true)) {
            return false;
        }

        $providerCode = $package->fulfillmentChannelRaw === 'seller_pays' ? $this->providerCode($account, $confirmation) : null;
        $lines = [];
        foreach ($confirmation->lineQuantities as $lineId => $quantity) {
            $lines[] = ['lineId' => (int) $lineId, 'quantity' => $quantity];
        }

        $picking = $this->client->call($account, 'trendyol.orders_picking', 'PUT', "/order/sellers/{$seller}/shipment-packages/{$confirmation->packageId}", json: ['status' => 'Picking', 'lines' => $lines, 'params' => (object) []], capability: IntegrationCapability::FulfillmentDispatch);
        if (!$picking->ok()) {
            return false;
        }
        if ($providerCode === null || $package->trackingNumber === $confirmation->trackingNumber) {
            return true;
        }

        $tracking = $this->client->call($account, 'trendyol.orders_tracking', 'PUT', "/order/sellers/{$seller}/shipment-packages/{$confirmation->packageId}/tracking-details", json: ['cargoSenderNumber' => $confirmation->trackingNumber, 'providerCode' => $providerCode], capability: IntegrationCapability::FulfillmentDispatch);

        return $tracking->ok();
    }

    private function providerCode(SalesChannelAccount $account, ShipmentConfirmation $confirmation): string
    {
        $mapped = (array) $account->setting('carrier_codes', []);
        $code = $mapped[$confirmation->carrierCode] ?? null;
        if (!is_string($code) || $code === '') {
            throw new RuntimeException("No Trendyol carrier code is configured for '{$confirmation->carrierCode}'. Trendyol accepts only its own list of carrier codes (for example ARAMEX, SMSA, SPL) and does not list Torod Point; map the carrier that really collects the parcel in the account's carrier codes.");
        }

        return $code;
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    private function milliseconds(CarbonInterface $instant): int
    {
        return (int) ($instant->getTimestamp() * 1000);
    }

    private function assertOk(ChannelResponse $response, string $what): void
    {
        ChannelResponseGuard::assertOk(response: $response, what: $what, channelName: 'Trendyol');
    }
}
