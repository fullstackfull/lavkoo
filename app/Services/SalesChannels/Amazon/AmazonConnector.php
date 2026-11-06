<?php

namespace App\Services\SalesChannels\Amazon;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Contracts\ConfirmsShipments;
use App\Services\SalesChannels\Contracts\PublishesStockAndPrices;
use App\Services\SalesChannels\Contracts\ReadsChannelInventory;
use App\Services\SalesChannels\Contracts\ReadsFinances;
use App\Services\SalesChannels\Contracts\ReadsListings;
use App\Services\SalesChannels\Contracts\ReadsOrders;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\PublishResult;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\RemoteInventoryItem;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Amazon Selling Partner API: connection test, listings, orders (v2026-01-01), FBA stock, financial transactions (v2024-06-19), price and quantity publishing,
 * and shipment confirmation. See AmazonOperations for the calls and the documentation they follow.
 *
 * What it will not do: guess a field, ask for personal data it does not need, treat an order Amazon fulfils (FBA) or one that is not an Amazon channel's own
 * as ours to ship, or write anything unless the transport (ChannelHttp) lets the call out.
 */
class AmazonConnector implements ChannelConnector, ReadsListings, ReadsOrders, ReadsChannelInventory, ReadsFinances, PublishesStockAndPrices, ConfirmsShipments
{
    private const LISTINGS_CAP = 1000;

    public function __construct(
        private readonly AmazonClient $client,
        private readonly AmazonOrderMapper $orders,
        private readonly AmazonListingMapper $listings,
        private readonly AmazonFinanceMapper $finances,
    ) {
    }

    public function channel(): SalesChannel
    {
        return SalesChannel::Amazon;
    }

    public function capabilityMatrix(): array
    {
        return [
            'lwa_authentication' => ['documented' => true, 'built' => true, 'note' => 'Login with Amazon refresh-token flow; no AWS signing is required any more.'],
            'orders_v2026_01_01' => ['documented' => true, 'built' => true, 'note' => 'searchOrders / getOrder. Orders v0 reads are deprecated and are not used.'],
            'fba_vs_fbm' => ['documented' => true, 'built' => true, 'note' => 'fulfillment.fulfilledBy with salesChannel.channelName; an unclear order is never treated as FBM.'],
            'listings_items' => ['documented' => true, 'built' => true, 'note' => 'searchListingsItems (capped at 1000 per query: windows are split), getListingsItem, patchListingsItem with validation preview.'],
            'catalog_items' => ['documented' => true, 'built' => false, 'note' => 'Read-only enrichment (EAN/UPC for matching) is designed; not built in this version.'],
            'product_type_definitions' => ['documented' => true, 'built' => true, 'note' => 'Read before publishing a price, to confirm the attribute exists for the marketplace.'],
            'fba_inventory' => ['documented' => true, 'built' => true, 'note' => 'getInventorySummaries v1 with inventoryDetails; kept apart from Torod Point stock.'],
            'finances_v2024_06_19' => ['documented' => true, 'built' => true, 'note' => 'listTransactions; fee types are an open set classified by configurable patterns, anything unknown is kept for review.'],
            'feeds_json_listings' => ['documented' => true, 'built' => true, 'note' => 'JSON_LISTINGS_FEED for bulk price and quantity. The legacy flat-file and XML listing feeds are removed by Amazon.'],
            'confirm_shipment' => ['documented' => true, 'built' => true, 'note' => 'Orders v0 confirmShipment (still current; no equivalent in v2026-01-01). Carrier codes for Saudi carriers are not published: "Other" with a carrier name is used.'],
            'notifications' => ['documented' => true, 'built' => false, 'note' => 'Delivered only to the seller\'s own AWS SQS/EventBridge, never to a URL. Polling stays the source of truth; an SQS poller is designed, not built.'],
            'reports' => ['documented' => true, 'built' => false, 'note' => 'Settlement and full-listing reports (GET_MERCHANT_LISTINGS_ALL_DATA) are registered but the report reader is not built; listing windows are used instead.'],
            'sandbox' => ['documented' => true, 'built' => true, 'note' => 'The EU sandbox list omits Saudi Arabia; use another EU marketplace id for sandbox runs.'],
        ];
    }

    public function testConnection(SalesChannelAccount $account): ConnectionTestResult
    {
        $capability = IntegrationCapability::ConnectionTest;
        $sellerId = (string) $account->external_account_id;
        if ($sellerId === '') {
            return ConnectionTestResult::failed('Enter the Seller ID (Seller Central, Settings, Account Info, Merchant Token) first.');
        }

        try {
            $participations = $this->client->call($account, 'amazon.probe_participations', 'GET', '/sellers/v1/marketplaceParticipations', capability: $capability);
        } catch (ChannelPermissionDenied $exception) {
            return ConnectionTestResult::failed($exception->getMessage(), ConnectionStatus::AuthExpired);
        } catch (ChannelUnavailableException $exception) {
            return ConnectionTestResult::failed($exception->getMessage(), $exception->reason === ChannelUnavailableException::AUTH_NEEDED ? $account->connectionStatus() : ConnectionStatus::Failed);
        }
        if (!$participations->ok()) {
            return ConnectionTestResult::failed('Amazon answered ' . $participations->status . ': ' . $participations->message());
        }

        $participating = [];
        foreach ((array) $participations->get('payload', []) as $row) {
            if (($row['participation']['isParticipating'] ?? false) === true) {
                $participating[(string) $row['marketplace']['id']] = $row['marketplace']['countryCode'] ?? '';
            }
        }
        $missing = [];
        foreach ($this->marketplaceIds($account) as $marketplaceId) {
            if (!isset($participating[$marketplaceId])) {
                $missing[] = 'marketplace:' . $marketplaceId;
            }
        }

        $granted = ['sellers:read'];
        foreach ($this->probes($account) as $label => [$operation, $path, $query]) {
            try {
                $response = $this->client->call($account, $operation, 'GET', $path, $query, capability: $capability);
                if ($response->ok()) {
                    $granted[] = $label;
                } elseif ($response->kind === ChannelResponse::REJECTED) {
                    $missing[] = $label . ':rejected(' . ($response->get('errors.0.code') ?? $response->status) . ')';
                }
            } catch (ChannelPermissionDenied) {
                $missing[] = $label;
            }
        }

        return new ConnectionTestResult(
            status: ConnectionStatus::Ok,
            message: $missing === [] ? 'Connected. Every probed permission is present.' : 'Connected, but some permissions or marketplaces are missing: ' . implode(', ', $missing) . '.',
            granted: $granted,
            missing: $missing,
            details: ['marketplaces' => $participating],
            externalAccountId: $sellerId,
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    private function probes(SalesChannelAccount $account): array
    {
        $marketplace = $this->primaryMarketplace($account);
        $seller = rawurlencode((string) $account->external_account_id);

        return [
            'orders:read' => ['amazon.probe_orders', '/orders/2026-01-01/orders', ['lastUpdatedAfter' => now()->subDay()->toIso8601ZuluString(), 'marketplaceIds' => $marketplace, 'maxResultsPerPage' => 1]],
            'listings:read' => ['amazon.probe_listings', "/listings/2021-08-01/items/{$seller}", ['marketplaceIds' => $marketplace, 'pageSize' => 1, 'includedData' => 'summaries']],
            'fba_inventory:read' => ['amazon.probe_fba_inventory', '/fba/inventory/v1/summaries', ['granularityType' => 'Marketplace', 'granularityId' => $marketplace, 'marketplaceIds' => $marketplace]],
            'finances:read' => ['amazon.probe_finances', '/finances/2024-06-19/transactions', ['postedAfter' => now()->subDays(3)->toIso8601ZuluString(), 'postedBefore' => now()->subDay()->toIso8601ZuluString(), 'marketplaceId' => $marketplace]],
        ];
    }

    // ---- listings -----------------------------------------------------------------------------------------------

    public function listings(SalesChannelAccount $account, ?array $cursor): Pages
    {
        $marketplace = $this->primaryMarketplace($account);
        $seller = rawurlencode((string) $account->external_account_id);
        $windows = $cursor['windows'] ?? [[$cursor['from'] ?? null, null]];
        $token = $cursor['token'] ?? null;
        [$after, $before] = $windows[0];

        $query = ['marketplaceIds' => $marketplace, 'includedData' => 'summaries,offers,fulfillmentAvailability,relationships', 'pageSize' => 20, 'sortBy' => 'lastUpdatedDate', 'sortOrder' => 'ASC'];
        if ($after !== null) {
            $query['lastUpdatedAfter'] = $after;
        }
        if ($before !== null) {
            $query['lastUpdatedBefore'] = $before;
        }
        if ($token !== null) {
            $query['pageToken'] = $token;
        }

        $response = $this->client->call($account, 'amazon.listings_search', 'GET', "/listings/2021-08-01/items/{$seller}", $query, capability: IntegrationCapability::ReadOnlySync);
        $this->assertOk($response, 'Amazon listings search');

        // A query returns at most 1000 items, however many exist: a window that holds that many is split in half and each half read on its own.
        if ($token === null && (int) $response->get('numberOfResults', 0) >= self::LISTINGS_CAP) {
            $split = $this->split($after, $before);
            if ($split !== null) {
                return new Pages([], ['windows' => [...$split, ...array_slice($windows, 1)], 'token' => null]);
            }
        }

        $items = [];
        $newest = null;
        foreach ((array) $response->get('items', []) as $item) {
            $listing = $this->listings->map((array) $item, $marketplace);
            $items[] = $listing;
            $newest = $listing->updatedAt !== null && ($newest === null || $listing->updatedAt->gt($newest)) ? $listing->updatedAt : $newest;
        }

        $nextToken = $response->get('pagination.nextToken');
        $next = match (true) {
            is_string($nextToken) && $nextToken !== '' => ['windows' => $windows, 'token' => $nextToken],
            count($windows) > 1 => ['windows' => array_slice($windows, 1), 'token' => null],
            default => null,
        };

        return new Pages($items, $next, $newest);
    }

    /** @return list<array{0: string|null, 1: string|null}>|null the two halves of a window, or null when it is too narrow to split */
    private function split(?string $after, ?string $before): ?array
    {
        $from = $after === null ? CarbonImmutable::parse('2012-01-01') : CarbonImmutable::parse($after);
        $to = $before === null ? now()->subMinutes(3)->toImmutable() : CarbonImmutable::parse($before);
        if ($to->diffInMinutes($from, true) < 120) {
            return null;
        }
        $middle = $from->addSeconds((int) ($from->diffInSeconds($to, true) / 2));

        return [[$from->toIso8601ZuluString(), $middle->toIso8601ZuluString()], [$middle->toIso8601ZuluString(), $to->toIso8601ZuluString()]];
    }

    // ---- orders -------------------------------------------------------------------------------------------------

    public function orders(SalesChannelAccount $account, CarbonInterface $since, ?array $cursor): Pages
    {
        $after = $cursor['lastUpdatedAfter'] ?? $since->toIso8601ZuluString();
        $query = [
            'lastUpdatedAfter' => $after,
            'marketplaceIds' => implode(',', $this->marketplaceIds($account)),
            'maxResultsPerPage' => 100,
            'includedData' => implode(',', $this->includedData($account)),
        ];
        // The pagination token belongs to the exact query that produced it: every other value stays as it was.
        if (isset($cursor['token'])) {
            $query['paginationToken'] = $cursor['token'];
        }

        $response = $this->client->call($account, 'amazon.orders_search', 'GET', '/orders/2026-01-01/orders', $query, capability: IntegrationCapability::OrderImport);
        $this->assertOk($response, 'Amazon order search');

        $items = [];
        $newest = null;
        foreach ((array) $response->get('orders', []) as $order) {
            $mapped = $this->orders->map((array) $order, $account);
            $items[] = $mapped;
            $newest = $mapped->updatedAt !== null && ($newest === null || $mapped->updatedAt->gt($newest)) ? $mapped->updatedAt : $newest;
        }
        $nextToken = $response->get('pagination.nextToken');

        return new Pages($items, is_string($nextToken) && $nextToken !== '' ? ['token' => $nextToken, 'lastUpdatedAfter' => $after] : null, $newest);
    }

    public function order(SalesChannelAccount $account, string $orderId, ?string $packageId = null): ?RemoteOrder
    {
        $response = $this->client->call($account, 'amazon.order_get', 'GET', '/orders/2026-01-01/orders/' . rawurlencode($orderId), ['includedData' => implode(',', $this->includedData($account))], capability: IntegrationCapability::OrderImport);
        if ($response->status === 404) {
            return null;
        }
        $this->assertOk($response, 'Amazon order lookup');

        return $this->orders->map((array) $response->get('order', []), $account);
    }

    /** @return list<string> */
    private function includedData(SalesChannelAccount $account): array
    {
        $included = (array) config('sales_channels.amazon.orders_included_data', []);
        if ($account->setting('request_buyer_data', false)) {
            $included[] = 'BUYER';
        }

        return array_values(array_unique($included));
    }

    // ---- FBA stock ----------------------------------------------------------------------------------------------

    public function inventory(SalesChannelAccount $account, ?array $cursor): Pages
    {
        $marketplace = $this->primaryMarketplace($account);
        $query = ['granularityType' => 'Marketplace', 'granularityId' => $marketplace, 'marketplaceIds' => $marketplace, 'details' => 'true'];
        if (isset($cursor['token'])) {
            $query['nextToken'] = $cursor['token'];
        }

        $response = $this->client->call($account, 'amazon.fba_inventory', 'GET', '/fba/inventory/v1/summaries', $query, capability: IntegrationCapability::ReadOnlySync);
        $this->assertOk($response, 'Amazon FBA inventory');

        $items = [];
        foreach ((array) $response->get('payload.inventorySummaries', []) as $summary) {
            $details = (array) ($summary['inventoryDetails'] ?? []);
            $items[] = new RemoteInventoryItem(
                sku: (string) $summary['sellerSku'],
                asin: $summary['asin'] ?? null,
                fnSku: $summary['fnSku'] ?? null,
                marketplaceId: $marketplace,
                available: isset($details['fulfillableQuantity']) ? (int) $details['fulfillableQuantity'] : null,
                reserved: isset($details['reservedQuantity']['totalReservedQuantity']) ? (int) $details['reservedQuantity']['totalReservedQuantity'] : null,
                unfulfillable: isset($details['unfulfillableQuantity']['totalUnfulfillableQuantity']) ? (int) $details['unfulfillableQuantity']['totalUnfulfillableQuantity'] : null,
                inbound: $details === [] ? null : (int) ($details['inboundWorkingQuantity'] ?? 0) + (int) ($details['inboundShippedQuantity'] ?? 0) + (int) ($details['inboundReceivingQuantity'] ?? 0),
                total: isset($summary['totalQuantity']) ? (int) $summary['totalQuantity'] : null,
                lastUpdated: isset($summary['lastUpdatedTime']) ? CarbonImmutable::parse((string) $summary['lastUpdatedTime']) : null,
                condition: $summary['condition'] ?? null,
            );
        }
        $nextToken = $response->get('pagination.nextToken');

        // The page token lives 30 seconds: the next page is fetched at once, in the same run.
        return new Pages($items, is_string($nextToken) && $nextToken !== '' ? ['token' => $nextToken] : null);
    }

    // ---- finances -----------------------------------------------------------------------------------------------

    public function finances(SalesChannelAccount $account, CarbonInterface $from, CarbonInterface $to, ?array $cursor): Pages
    {
        $query = [
            'postedAfter' => $from->toIso8601ZuluString(),
            // listTransactions refuses a range ending less than two minutes ago and returns nothing for a span over 180 days.
            'postedBefore' => min($to, now()->subMinutes(3))->toIso8601ZuluString(),
            'marketplaceId' => $this->primaryMarketplace($account),
        ];
        if (isset($cursor['token'])) {
            $query['nextToken'] = $cursor['token'];
        }

        $response = $this->client->call($account, 'amazon.finances_transactions', 'GET', '/finances/2024-06-19/transactions', $query, capability: IntegrationCapability::FinancialReconciliation);
        $this->assertOk($response, 'Amazon financial transactions');

        $entries = [];
        foreach ((array) $response->get('payload.transactions', []) as $transaction) {
            $entries = [...$entries, ...$this->finances->map((array) $transaction, $account)];
        }
        $nextToken = $response->get('payload.nextToken');

        return new Pages($entries, is_string($nextToken) && $nextToken !== '' ? ['token' => $nextToken] : null);
    }

    // ---- publishing ---------------------------------------------------------------------------------------------

    public function publishPrices(SalesChannelAccount $account, array $updates): PublishResult
    {
        return $this->patchEach($account, $updates, 'price');
    }

    public function publishStock(SalesChannelAccount $account, array $updates): PublishResult
    {
        return $this->patchEach($account, $updates, 'quantity');
    }

    /**
     * One PATCH per SKU (the Listings API takes one SKU per call). Each is first checked with Amazon's validation preview, which submits nothing, so a
     * patch Amazon would reject is never sent; an update that previews as invalid is reported with Amazon's reason.
     *
     * @param list<PublishUpdate> $updates
     */
    private function patchEach(SalesChannelAccount $account, array $updates, string $field): PublishResult
    {
        $accepted = [];
        $failed = [];
        $dryRun = false;
        $marketplace = $this->primaryMarketplace($account);
        $seller = rawurlencode((string) $account->external_account_id);
        $capability = $field === 'price' ? IntegrationCapability::PricePublishing : IntegrationCapability::InventoryPublishing;
        $operation = $field === 'price' ? 'amazon.listings_patch_price' : 'amazon.listings_patch_quantity';

        foreach ($updates as $update) {
            $sku = $update->sku ?? $update->listingKey;
            $patch = $field === 'price' ? $this->pricePatch($account, $update, $marketplace) : $this->quantityPatch($update);
            if ($patch === null) {
                $failed[$update->mappingId] = $field === 'price' ? 'No price to publish.' : 'No quantity to publish.';
                continue;
            }
            $body = ['productType' => $this->productType($update), 'patches' => [$patch]];
            $path = "/listings/2021-08-01/items/{$seller}/" . rawurlencode($sku);

            if ($account->setting('validate_before_publish', true)) {
                $preview = $this->client->call($account, 'amazon.listings_validate', 'PATCH', $path, ['marketplaceIds' => $marketplace, 'mode' => 'VALIDATION_PREVIEW', 'includedData' => 'issues'], $body, $capability);
                if ($preview->ok() && $preview->get('status') === 'INVALID') {
                    $failed[$update->mappingId] = $this->firstIssue($preview) ?? 'Amazon\'s validation preview rejected this update.';
                    continue;
                }
                if (!$preview->ok()) {
                    $failed[$update->mappingId] = 'Validation preview failed (' . $preview->status . '): ' . $preview->message();
                    continue;
                }
            }

            $response = $this->client->call($account, $operation, 'PATCH', $path, ['marketplaceIds' => $marketplace, 'includedData' => 'issues'], $body, $capability);
            if ($response->isDryRun()) {
                $dryRun = true;
                continue;
            }
            if ($response->ok() && $response->get('status') === 'ACCEPTED') {
                $accepted[$update->mappingId] = (string) $response->get('submissionId', '');
            } else {
                $failed[$update->mappingId] = $response->ok() ? ($this->firstIssue($response) ?? 'Amazon did not accept the update (' . $response->get('status') . ').') : 'HTTP ' . $response->status . ': ' . $response->message();
            }
        }

        return new PublishResult($accepted, $failed, null, $dryRun);
    }

    /** @return array<string, mixed>|null */
    private function pricePatch(SalesChannelAccount $account, PublishUpdate $update, string $marketplace): ?array
    {
        if ($update->price === null) {
            return null;
        }

        return [
            'op' => 'replace',
            'path' => '/attributes/purchasable_offer',
            'value' => [[
                'marketplace_id' => $marketplace,
                'currency' => $update->currency ?? $account->currency ?? 'SAR',
                'audience' => 'ALL',
                'our_price' => [['schedule' => [['value_with_tax' => round($update->price->toFloat(), 2)]]]],
            ]],
        ];
    }

    /** @return array<string, mixed>|null */
    private function quantityPatch(PublishUpdate $update): ?array
    {
        if ($update->quantity === null) {
            return null;
        }

        // `merge` changes only the quantity and leaves the handling time the seller set.
        return ['op' => 'merge', 'path' => '/attributes/fulfillment_availability', 'value' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => max(0, $update->quantity)]]];
    }

    private function productType(PublishUpdate $update): string
    {
        $mapping = SalesChannelProductMapping::find($update->mappingId);

        return (string) ($mapping->snapshot['product_type'] ?? 'PRODUCT');
    }

    private function firstIssue(ChannelResponse $response): ?string
    {
        foreach ((array) $response->get('issues', []) as $issue) {
            if (in_array($issue['severity'] ?? '', ['ERROR', 'WARNING'], true) && !empty($issue['message'])) {
                return $issue['code'] . ': ' . $issue['message'];
            }
        }

        return null;
    }

    // ---- shipment confirmation ----------------------------------------------------------------------------------

    public function confirmShipment(SalesChannelAccount $account, ShipmentConfirmation $confirmation): bool
    {
        $items = [];
        foreach ($confirmation->lineQuantities as $orderItemId => $quantity) {
            $items[] = ['orderItemId' => (string) $orderItemId, 'quantity' => $quantity];
        }
        $body = [
            'marketplaceId' => $this->primaryMarketplace($account),
            'packageDetail' => array_filter([
                'packageReferenceId' => $confirmation->packageId !== '' ? $confirmation->packageId : '1',
                // The list of allowed carrier codes is not published; "Other" with the carrier's name is the documented way to name any other carrier.
                'carrierCode' => 'Other',
                'carrierName' => $confirmation->carrierName ?? $confirmation->carrierCode,
                'trackingNumber' => $confirmation->trackingNumber,
                'shipDate' => $confirmation->shippedAt->toIso8601ZuluString(),
                'orderItems' => $items,
            ], static fn ($value): bool => $value !== null && $value !== ''),
        ];

        $response = $this->client->call($account, 'amazon.order_confirm_shipment', 'POST', '/orders/v0/orders/' . rawurlencode($confirmation->orderId) . '/shipmentConfirmation', json: $body, capability: IntegrationCapability::FulfillmentDispatch);

        // Success is 204 No Content. A dry run sent nothing, and says so by not claiming success.
        return $response->ok() && $response->status === 204;
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function marketplaceIds(SalesChannelAccount $account): array
    {
        $ids = array_values(array_filter(array_map('strval', (array) ($account->marketplace_ids ?? []))));
        if ($ids === []) {
            throw new ChannelUnavailableException(ChannelUnavailableException::AUTH_NEEDED, 'Choose at least one Amazon marketplace for this account (Saudi Arabia is A17E79C6D8DWNP).');
        }

        return $ids;
    }

    private function primaryMarketplace(SalesChannelAccount $account): string
    {
        return $this->marketplaceIds($account)[0];
    }

    private function assertOk(ChannelResponse $response, string $what): void
    {
        if ($response->ok()) {
            return;
        }
        if ($response->kind === ChannelResponse::RATE_LIMITED) {
            throw new ChannelRateLimitedException($response->retryAfterSeconds() ?? 60, "{$what}: Amazon asked us to slow down.");
        }

        throw new RuntimeException("{$what} failed (HTTP {$response->status}): " . $response->message());
    }
}
