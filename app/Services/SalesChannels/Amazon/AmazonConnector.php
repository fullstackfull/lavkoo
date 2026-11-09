<?php

namespace App\Services\SalesChannels\Amazon;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\ChannelListingDraft;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Contracts\ConfirmsShipments;
use App\Services\SalesChannels\Contracts\CreatesListings;
use App\Services\SalesChannels\Contracts\DescribesListingRequirements;
use App\Services\SalesChannels\Contracts\ReadsCatalog;
use App\Services\SalesChannels\Contracts\PublishesStockAndPrices;
use App\Services\SalesChannels\Contracts\ReadsChannelInventory;
use App\Services\SalesChannels\Contracts\ReadsFinances;
use App\Services\SalesChannels\Contracts\ReadsListings;
use App\Services\SalesChannels\Contracts\ReadsOrders;
use App\Services\SalesChannels\Contracts\ReadsRecipient;
use App\Services\SalesChannels\Data\CatalogMatch;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Data\ListingRequirements;
use App\Services\SalesChannels\Data\ListingSubmission;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\PublishResult;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\RemoteInventoryItem;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Dispatch\ChannelRecipient;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;
use App\Services\SalesChannels\Support\ChannelResponseGuard;
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
class AmazonConnector implements ChannelConnector, ReadsListings, ReadsOrders, ReadsRecipient, ReadsChannelInventory, ReadsFinances, PublishesStockAndPrices, ConfirmsShipments, CreatesListings, DescribesListingRequirements, ReadsCatalog
{
    private const LISTINGS_CAP = 1000;

    public function __construct(
        private readonly AmazonClient $client,
        private readonly AmazonOrderMapper $orders,
        private readonly AmazonListingMapper $listings,
        private readonly AmazonFinanceMapper $finances,
        private readonly AmazonCatalogMapper $catalog,
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
            'listings_items' => ['documented' => true, 'built' => true, 'note' => 'searchListingsItems (capped at 1000 per query: windows are split); patchListingsItem for a price or a quantity, with a validation preview, and for the CONTENT of a listing that exists (only the fields that differ, nothing removed); putListingsItem ONLY to create the listing of a SKU Amazon holds nothing for, with the product-only requirements mode and never an offer attribute; and getListingsItem with summaries and issues, which is the only thing that says a listing exists — `ACCEPTED` on a write means the submission was taken, not that anything is listed.'],
            'catalog_items' => ['documented' => true, 'built' => true, 'note' => 'searchCatalogItems by EAN/UPC (one identifier kind per call, tried most trustworthy first), read-only. It brings back the ASIN and Amazon\'s own productType, which is what makes the requirements call possible without a person guessing the type. Four answers are kept apart and a catalogue that could not be reached is UNKNOWN, never "holds nothing": one ASIN is a match, several wait for a person, and only an answered zero licenses anything.'],
            'product_type_definitions' => ['documented' => true, 'built' => true, 'note' => 'getDefinitionsProductType is read before a listing is created, and ONLY a `required` list the response itself carries is used. The full rules live in a JSON Schema behind a presigned link this version does not fetch (it is not on the allowed list), so where the response carries no such list the answer is UNKNOWN and the owner\'s own declared list on the account is used instead, recorded as the owner\'s and not Amazon\'s.'],
            'fba_inventory' => ['documented' => true, 'built' => true, 'note' => 'getInventorySummaries v1 with inventoryDetails; kept apart from Torod Point stock.'],
            'finances_v2024_06_19' => ['documented' => true, 'built' => true, 'note' => 'listTransactions; fee types are an open set classified by configurable patterns, anything unknown is kept for review.'],
            'feeds_json_listings' => ['documented' => true, 'built' => false, 'note' => 'JSON_LISTINGS_FEED for bulk price and quantity is registered on the allowed list but not called: stock and price go one SKU at a time through Listings Items. The legacy flat-file and XML listing feeds are removed by Amazon.'],
            'confirm_shipment' => ['documented' => true, 'built' => true, 'note' => 'Orders v0 confirmShipment (still current; no equivalent in v2026-01-01). Carrier codes for Saudi carriers are not published: "Other" with a carrier name is used.'],
            'notifications' => ['documented' => true, 'built' => false, 'note' => 'Delivered only to the seller\'s own AWS SQS/EventBridge, never to a URL. Polling stays the source of truth; an SQS poller is designed, not built.'],
            'reports' => ['documented' => true, 'built' => true, 'note' => 'Two flows, because Amazon allows only two: a requestable report (GET_MERCHANT_LISTINGS_ALL_DATA, GET_AFN_INVENTORY_DATA) is createReport, poll, getReportDocument, download; a SETTLEMENT report cannot be requested at all -- Amazon schedules it and its page says so outright -- so it is found with getReports. The request is fenced before it leaves, because a lost reply to createReport means a report exists under a number we never saw, and the row is then found with getReports rather than requested again. A settlement lands as an UNAPPROVED marketplace statement, never as cost entries: the Finances API already records the fees, and writing both would book the same commission twice. Known limit: the client buffers a whole body, so `report_max_bytes` bounds what is parsed and refuses by name above it -- the streaming download is not built.'],
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
                } else {
                    $missing[] = $label . ':unverified(' . ($response->status ?? $response->kind) . ')';
                }
            } catch (ChannelPermissionDenied) {
                $missing[] = $label;
            }
        }

        return new ConnectionTestResult(
            status: ConnectionStatus::Ok,
            message: $missing === [] ? 'Connected. Every probed permission is present.' : 'Connected, but some permissions or marketplaces are missing or could not be confirmed: ' . implode(', ', $missing) . '.',
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
        [$position, $marketplace] = $this->marketplaceAt($account, $cursor);
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

        $response = $this->client->call($account, 'amazon.listings_search', 'GET', "/listings/2021-08-01/items/{$seller}", $query, capability: IntegrationCapability::ProductSync);
        $this->assertOk($response, 'Amazon listings search');

        // A query returns at most 1000 items, however many exist: a window that holds that many is split in half and each half read on its own, down to a couple of
        // seconds. A window that small which still holds that many cannot be read completely, and is reported rather than read short.
        if ($token === null && (int) $response->get('numberOfResults', 0) >= self::LISTINGS_CAP) {
            $split = $this->split($after, $before);
            if ($split === null) {
                throw new RuntimeException('Amazon holds ' . self::LISTINGS_CAP . ' or more listings changed within the same couple of seconds, more than one search can return: the listings of this window cannot be read completely.');
            }

            return new Pages([], ['windows' => [...$split, ...array_slice($windows, 1)], 'token' => null, 'm' => $position]);
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
            is_string($nextToken) && $nextToken !== '' => ['windows' => $windows, 'token' => $nextToken, 'm' => $position],
            count($windows) > 1 => ['windows' => array_slice($windows, 1), 'token' => null, 'm' => $position],
            default => $this->nextMarketplace($account, $position, ['windows' => [[null, null]], 'token' => null]),
        };

        return new Pages($items, $next, $newest);
    }

    /**
     * An account may hold several marketplaces; each of the reads that Amazon takes one marketplace at a time (listings, FBA stock, statements) goes through them one after
     * the other, the marketplace it is at being part of the saved place.
     *
     * @param array<string, mixed>|null $cursor
     * @return array{0: int, 1: string} the position in the account's list and the marketplace id there
     */
    private function marketplaceAt(SalesChannelAccount $account, ?array $cursor): array
    {
        $ids = $this->marketplaceIds($account);
        $position = min((int) ($cursor['m'] ?? 0), count($ids) - 1);

        return [$position, $ids[$position]];
    }

    /**
     * @param array<string, mixed> $start the place a read of the next marketplace starts from
     * @return array<string, mixed>|null
     */
    private function nextMarketplace(SalesChannelAccount $account, int $position, array $start): ?array
    {
        return $position + 1 < count($this->marketplaceIds($account)) ? $start + ['m' => $position + 1] : null;
    }

    /** @return list<array{0: string|null, 1: string|null}>|null the two halves of a window, or null when it is too narrow to split */
    private function split(?string $after, ?string $before): ?array
    {
        $from = $after === null ? CarbonImmutable::parse('2012-01-01') : CarbonImmutable::parse($after);
        $to = $before === null ? now()->subMinutes(3)->toImmutable() : CarbonImmutable::parse($before);
        if ($to->diffInSeconds($from, true) < 2) {
            return null;
        }
        $middle = $from->addSeconds((int) ($from->diffInSeconds($to, true) / 2));

        return [[$from->toIso8601ZuluString(), $middle->toIso8601ZuluString()], [$middle->toIso8601ZuluString(), $to->toIso8601ZuluString()]];
    }

    // ---- the channel's own catalogue ------------------------------------------------------------------------------

    /**
     * Asks Amazon's catalogue whether it already holds a product with our barcode, and brings back the ASIN and — the reason this call exists — Amazon's own `productType`,
     * without which the requirements of a listing cannot be asked for at all.
     *
     * `searchCatalogItems` takes ONE kind of identifier per call (`identifiersType`), so the kinds are tried in order of how much they can be trusted and the FIRST kind that
     * produces an answer wins. A kind that finds nothing is not a failure: the next is tried. Amazon knows EAN, UPC, GTIN, JAN, ISBN and MINSAN; a kind it does not know is
     * never sent, because the call would be refused for the whole batch.
     *
     * The four answers are kept apart deliberately, and the distinction is the point of the method:
     *
     *  - every kind asked, every one answered, none held anything  → `NONE`, a definite zero;
     *  - exactly one ASIN                                          → `MATCHED`;
     *  - several                                                   → `SEVERAL`, for a person; never a guess, because the wrong ASIN is another seller's product;
     *  - a switch off, a rehearsal, a refusal, a rate limit        → `UNKNOWN`, with the reason.
     *
     * The last one is why `saysNothingIsThere()` exists. A rate-limited search that answered nothing looks exactly like an empty catalogue if you only count the results, and
     * acting on that is how a product that is already listed gets listed a second time.
     *
     * @param array<string, string> $identifiers
     */
    public function catalogMatch(SalesChannelAccount $account, array $identifiers): CatalogMatch
    {
        $marketplace = $this->primaryMarketplace($account);
        $usable = $this->catalogIdentifiers($identifiers);
        if ($usable === []) {
            return CatalogMatch::unknown('no_identifier_the_catalogue_knows', $identifiers);
        }

        $asked = [];
        foreach ($usable as $kind => $value) {
            $asked[$kind] = $value;
            $response = $this->client->call(
                $account,
                'amazon.catalog_search',
                'GET',
                '/catalog/2022-04-01/items',
                ['identifiers' => $value, 'identifiersType' => $kind, 'marketplaceIds' => $marketplace, 'includedData' => 'identifiers,images,productTypes,summaries', 'pageSize' => 10],
                capability: IntegrationCapability::ProductSync,
            );

            // Not asked, or asked and not answered. Either way we do not know, and we say so instead of moving on to the next kind as though this one had come back empty.
            if ($response->isDryRun()) {
                return CatalogMatch::unknown('rehearsal', $asked);
            }
            if ($response->isTransient()) {
                return CatalogMatch::unknown('channel_unreachable', $asked);
            }
            if (!$response->ok()) {
                // Amazon refuses an identifier it considers malformed for the kind. That is about this identifier, not about the catalogue, so the next kind is still worth asking.
                continue;
            }

            $candidates = [];
            foreach ((array) $response->get('items', []) as $item) {
                $candidate = $this->catalog->map((array) $item, $marketplace);
                if ($candidate->externalId !== '') {
                    $candidates[$candidate->externalId] = $candidate;
                }
            }
            if ($candidates !== []) {
                return CatalogMatch::of(array_values($candidates), $asked);
            }
        }

        // Every kind we could ask was asked and answered, and Amazon holds nothing. This is the one path that may read as a definite zero.
        return CatalogMatch::none($asked);
    }

    /**
     * Our identifiers reduced to the kinds Amazon's catalogue accepts, most trustworthy first. A GTIN-shaped barcode is offered as EAN or UPC by its length, which is what
     * those kinds are; anything else is left out rather than sent under a kind it is not.
     *
     * @param array<string, string> $identifiers
     * @return array<string, string>
     */
    private function catalogIdentifiers(array $identifiers): array
    {
        $known = ['ASIN', 'EAN', 'UPC', 'GTIN', 'JAN', 'ISBN', 'MINSAN'];
        $usable = [];
        foreach ($identifiers as $kind => $value) {
            $kind = mb_strtoupper(trim((string) $kind));
            $value = trim((string) $value);
            if ($value === '' || !in_array($kind, $known, true)) {
                continue;
            }
            $usable[$kind] = $value;
        }

        // A bare barcode arrives under no kind at all: its length is the only honest thing to go on. 13 digits is an EAN, 12 a UPC; anything else is not offered as either.
        $barcode = trim((string) ($identifiers['barcode'] ?? ''));
        if ($barcode !== '' && ctype_digit($barcode)) {
            $kind = match (mb_strlen($barcode)) {
                13 => 'EAN',
                12 => 'UPC',
                default => null,
            };
            if ($kind !== null) {
                $usable[$kind] ??= $barcode;
            }
        }

        $order = array_flip($known);
        uksort($usable, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return $usable;
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

    public function recipient(SalesChannelAccount $account, ExternalOrderMapping $mapping): ChannelRecipient
    {
        try {
            $response = $this->client->call($account, 'amazon.order_recipient', 'GET', '/orders/2026-01-01/orders/' . rawurlencode($mapping->external_order_id), ['includedData' => 'RECIPIENT,FULFILLMENT'], capability: IntegrationCapability::FulfillmentDispatch);
        } catch (ChannelPermissionDenied) {
            throw new RecipientUnavailable(RecipientUnavailable::NOT_PERMITTED);
        }
        if ($response->status === 404) {
            throw new RecipientUnavailable(RecipientUnavailable::NOT_FOUND);
        }
        if (!$response->ok()) {
            throw new RecipientUnavailable(RecipientUnavailable::CHANNEL_ERROR);
        }

        $recipient = AmazonRecipientMapper::map((array) $response->get('order', []));
        $problems = $recipient->problems();

        return $problems === [] ? $recipient : throw new RecipientUnavailable(RecipientUnavailable::INCOMPLETE, $problems);
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
        [$position, $marketplace] = $this->marketplaceAt($account, $cursor);
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

        // The page token lives 30 seconds: the next page is fetched at once, in the same run (the sync never keeps this place for a later one).
        return new Pages($items, is_string($nextToken) && $nextToken !== '' ? ['token' => $nextToken, 'm' => $position] : $this->nextMarketplace($account, $position, []));
    }

    // ---- finances -----------------------------------------------------------------------------------------------

    public function finances(SalesChannelAccount $account, CarbonInterface $from, CarbonInterface $to, ?array $cursor): Pages
    {
        [$position, $marketplace] = $this->marketplaceAt($account, $cursor);
        $query = [
            'postedAfter' => $from->toIso8601ZuluString(),
            // listTransactions refuses a range ending less than two minutes ago and returns nothing for a span over 180 days. The sync asks for a window inside both, and
            // asks for the very same one on every page: a next-page token is only good for the arguments it was issued for.
            'postedBefore' => min($to, now()->subMinutes(3))->toIso8601ZuluString(),
            'marketplaceId' => $marketplace,
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

        return new Pages($entries, is_string($nextToken) && $nextToken !== '' ? ['token' => $nextToken, 'm' => $position] : $this->nextMarketplace($account, $position, []));
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
     * patch Amazon would reject is never sent; an update that previews as invalid is reported with Amazon's reason. The preview is a write for every gate: an account in
     * dry run logs both requests and sends neither.
     *
     * @param list<PublishUpdate> $updates
     */
    private function patchEach(SalesChannelAccount $account, array $updates, string $field): PublishResult
    {
        $accepted = [];
        $failed = [];
        $transient = [];
        $dryRun = false;
        $marketplace = $this->primaryMarketplace($account);
        $seller = rawurlencode((string) $account->external_account_id);
        $capability = $field === 'price' ? IntegrationCapability::PricePublishing : IntegrationCapability::InventoryPublishing;
        $scope = $field === 'price' ? 'price_publishing' : 'inventory_publishing';
        $operation = $field === 'price' ? 'amazon.listings_patch_price' : 'amazon.listings_patch_quantity';

        foreach ($updates as $update) {
            $sku = $update->sku ?? $update->listingKey;
            if ($field === 'price' && $update->price !== null && !$update->price->round(scale: 2)->isPositive()) {
                $failed[$update->mappingId] = 'A price of zero or less (or one that rounds to zero) is refused.';
                continue;
            }
            $patch = $field === 'price' ? $this->pricePatch($account, $update, $marketplace) : $this->quantityPatch($update);
            if ($patch === null) {
                $failed[$update->mappingId] = $field === 'price' ? 'No price to publish.' : 'No quantity to publish.';
                continue;
            }
            $body = ['productType' => $this->productType($update), 'patches' => [$patch]];
            $path = "/listings/2021-08-01/items/{$seller}/" . rawurlencode($sku);

            if ($account->setting('validate_before_publish', true)) {
                $preview = $this->client->call($account, 'amazon.listings_validate', 'PATCH', $path, ['marketplaceIds' => $marketplace, 'mode' => 'VALIDATION_PREVIEW', 'includedData' => 'issues'], $body, $capability, $scope);
                if (!$preview->isDryRun() && $preview->ok() && $preview->get('status') === 'INVALID') {
                    $failed[$update->mappingId] = $this->firstIssue($preview) ?? 'Amazon\'s validation preview rejected this update.';
                    continue;
                }
                if (!$preview->isDryRun() && !$preview->ok()) {
                    $failed[$update->mappingId] = 'Validation preview failed (' . $preview->status . '): ' . $preview->message();
                    if ($preview->isTransient()) {
                        $transient[] = $update->mappingId;
                    }
                    continue;
                }
            }

            $response = $this->client->call($account, $operation, 'PATCH', $path, ['marketplaceIds' => $marketplace, 'includedData' => 'issues'], $body, $capability, $scope);
            if ($response->isDryRun()) {
                $dryRun = true;
                continue;
            }
            if ($response->ok() && $response->get('status') === 'ACCEPTED') {
                $accepted[$update->mappingId] = (string) $response->get('submissionId', '');
                continue;
            }
            $failed[$update->mappingId] = $response->ok() ? ($this->firstIssue($response) ?? 'Amazon did not accept the update (' . $response->get('status') . ').') : 'HTTP ' . $response->status . ': ' . $response->message();
            if (!$response->ok() && $response->isTransient()) {
                $transient[] = $update->mappingId;
            }
        }

        return new PublishResult($accepted, $failed, null, $dryRun, $transient);
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

    // ---- creating and changing the listing of a product of ours --------------------------------------------------

    /**
     * The attributes that would give the listing an offer. They are never sent by a create or an update: a price and a quantity go out only through price and stock publishing,
     * which are separate capabilities with approvals of their own.
     *
     * @return list<string>
     */
    public function offerAttributes(): array
    {
        return array_values(array_filter(array_map('strval', (array) config('sales_channels.listing.offer_attributes.amazon', []))));
    }

    /**
     * putListingsItem: it SETS the whole listing of our own SKU, so the publisher calls it only when Amazon holds nothing for that SKU. `requirements` decides how much of a
     * listing is being given; the default is the product without an offer, so an accepted submission cannot make the item sellable.
     *
     * Amazon's `ACCEPTED` here means it TOOK the submission. It is not a finished listing: that is what `listingFor()` is for.
     */
    public function createListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $attributes): ListingSubmission
    {
        $sku = (string) (($draft->payload ?? [])['sku'] ?? '');
        if ($sku === '' || (string) $draft->product_type === '') {
            return ListingSubmission::rejected(['A listing needs our own SKU and Amazon\'s product type.']);
        }

        $response = $this->client->call(
            $account,
            'amazon.listings_put',
            'PUT',
            $this->listingPath($account, $sku),
            ['marketplaceIds' => $this->primaryMarketplace($account), 'issueLocale' => $this->issueLocale($account)],
            [
                'productType' => (string) $draft->product_type,
                // UNVERIFIED for the KSA product types: that LISTING_PRODUCT_ONLY is accepted for a product with no offer. It is an account setting so that it can be changed
                // without a deployment; whatever it is, no offer attribute is in the body.
                'requirements' => (string) $account->setting('listing_requirements_mode', 'LISTING_PRODUCT_ONLY'),
                'attributes' => $attributes,
            ],
            IntegrationCapability::ProductSync,
            'product_writes',
        );

        return $this->submissionFrom($response, 'created');
    }

    /** patchListingsItem: only the fields given, each replaced. Nothing else of the listing is touched, and nothing is removed. */
    public function updateListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $changedAttributes): ListingSubmission
    {
        $sku = (string) (($draft->payload ?? [])['sku'] ?? '');
        if ($sku === '' || (string) $draft->product_type === '') {
            return ListingSubmission::rejected(['A listing needs our own SKU and Amazon\'s product type.']);
        }
        if ($changedAttributes === []) {
            return ListingSubmission::working(null, 'nothing_changed');
        }

        $patches = [];
        foreach ($changedAttributes as $field => $value) {
            $patches[] = ['op' => 'replace', 'path' => '/attributes/' . $field, 'value' => $value];
        }

        $response = $this->client->call(
            $account,
            'amazon.listings_patch_content',
            'PATCH',
            $this->listingPath($account, $sku),
            ['marketplaceIds' => $this->primaryMarketplace($account), 'issueLocale' => $this->issueLocale($account)],
            ['productType' => (string) $draft->product_type, 'patches' => $patches],
            IntegrationCapability::ProductSync,
            'product_writes',
        );

        return $this->submissionFrom($response, 'updated');
    }

    /**
     * getListingsItem with its summaries and issues: what Amazon HOLDS for one of our SKUs. It reads and changes nothing.
     *
     * A listing Amazon knows nothing about is WORKING (nothing to report), not a refusal. A listing with an ERROR issue is REJECTED with that issue. Otherwise it is LIVE, and
     * the summary's own statuses come back with it, so that "the listing exists" is never read as "the listing is sellable".
     */
    public function listingFor(SalesChannelAccount $account, string $sku): ListingSubmission
    {
        if ($sku === '') {
            return ListingSubmission::rejected(['A listing needs our own SKU.']);
        }

        $response = $this->client->call(
            $account,
            'amazon.listing_get',
            'GET',
            $this->listingPath($account, $sku),
            ['marketplaceIds' => $this->primaryMarketplace($account), 'includedData' => 'issues,summaries', 'issueLocale' => $this->issueLocale($account)],
            capability: IntegrationCapability::ProductSync,
        );

        if ($response->isDryRun()) {
            // A rehearsal cannot tell us whether the SKU is taken, and a guess here would either replace a listing or create a second one.
            return ListingSubmission::unknown('dry_run');
        }
        if ($response->status === 404) {
            // Amazon knows no listing of ours by that SKU, and says so. This is the only answer a create may be made on.
            return ListingSubmission::absent();
        }
        if (!$response->ok()) {
            return ListingSubmission::unknown((string) $response->status);
        }
        if (($errors = $this->issueMessages($response, ['ERROR'])) !== []) {
            return ListingSubmission::rejected($errors, channelStatus: 'has_issues');
        }

        $summaries = array_values(array_filter((array) $response->get('summaries', []), 'is_array'));
        if ($summaries === []) {
            // Amazon answered 200 for this SKU but gave no summary for the marketplace. That is NOT an absence: the item IS in the seller's catalogue, and a PUT would set it.
            // It is an answer that settles nothing, which is what WORKING means, and the publisher refuses to write on it.
            return ListingSubmission::working(null, 'no_summary');
        }

        return ListingSubmission::live($sku, $this->summaryStatuses($summaries));
    }

    private function listingPath(SalesChannelAccount $account, string $sku): string
    {
        return '/listings/2021-08-01/items/' . rawurlencode((string) $account->external_account_id) . '/' . rawurlencode($sku);
    }

    private function issueLocale(SalesChannelAccount $account): string
    {
        return (string) $account->setting('issue_locale', 'en_US');
    }

    /**
     * Amazon's own words about the listing: the summary's `status` list (BUYABLE, DISCOVERABLE), plus the two booleans older payloads use instead.
     *
     * @param list<array<string, mixed>> $summaries
     * @return list<string>
     */
    private function summaryStatuses(array $summaries): array
    {
        $statuses = [];
        foreach ($summaries as $summary) {
            foreach ((array) ($summary['status'] ?? $summary['statuses'] ?? []) as $status) {
                $statuses[] = strtoupper((string) $status);
            }
            foreach (['buyable' => ListingSubmission::BUYABLE, 'discoverable' => ListingSubmission::DISCOVERABLE] as $field => $status) {
                if (($summary[$field] ?? null) === true) {
                    $statuses[] = $status;
                }
            }
        }

        return array_values(array_unique(array_filter($statuses)));
    }

    /**
     * What Amazon made of a write. INVALID, or any ERROR issue, is a refusal; anything else it answered is a submission it TOOK, never a finished listing.
     */
    private function submissionFrom(ChannelResponse $response, string $what): ListingSubmission
    {
        if ($response->isDryRun()) {
            return ListingSubmission::working(null, 'dry_run');
        }
        if (!$response->ok()) {
            return ListingSubmission::rejected([$response->message()], channelStatus: (string) $response->status);
        }

        $status = strtoupper((string) ($response->get('status') ?? ''));
        $submissionId = ($id = $response->get('submissionId')) === null ? null : (string) $id;
        $errors = $this->issueMessages($response, ['ERROR']);
        if ($status === 'INVALID' || $errors !== []) {
            return ListingSubmission::rejected($errors === [] ? ['Amazon refused the listing.'] : $errors, $submissionId, $status ?: null);
        }

        return ListingSubmission::acceptedForProcessing($submissionId, $status ?: $what);
    }

    /**
     * What Amazon says a product type requires. The definition's full rules live in a JSON Schema behind a presigned link that this version does not fetch (it is not on the
     * allowed list), so only a `required` list the response itself carries is used; otherwise the answer is UNKNOWN and nothing is guessed from it.
     */
    public function listingRequirements(SalesChannelAccount $account, string $productType): ListingRequirements
    {
        if ($productType === '') {
            return ListingRequirements::unknown();
        }

        $response = $this->client->call(
            $account,
            'amazon.product_type_definition',
            'GET',
            '/definitions/2020-09-01/productTypes/' . rawurlencode($productType),
            ['marketplaceIds' => $this->primaryMarketplace($account), 'requirements' => 'LISTING', 'locale' => (string) $account->setting('definition_locale', 'DEFAULT')],
            capability: IntegrationCapability::ProductSync,
        );

        if ($response->isDryRun() || !$response->ok()) {
            return ListingRequirements::unknown($productType);
        }

        $required = array_values(array_filter(array_map('strval', (array) ($response->get('required', []) ?: $response->get('requiredAttributes', [])))));
        if ($required === []) {
            return ListingRequirements::unknown($productType);
        }

        return new ListingRequirements(
            productType: (string) ($response->get('productType') ?? $productType),
            required: $required,
            optional: array_values(array_filter(array_map('strval', (array) $response->get('optionalAttributes', [])))),
            readAt: now(),
        );
    }

    /**
     * @param list<string> $severities
     * @return list<string>
     */
    private function issueMessages(ChannelResponse $response, array $severities): array
    {
        $messages = [];
        foreach ((array) $response->get('issues', []) as $issue) {
            if (in_array($issue['severity'] ?? '', $severities, true)) {
                $messages[] = trim(((string) ($issue['code'] ?? '')) . ': ' . ((string) ($issue['message'] ?? '')), ': ');
            }
        }

        return array_values(array_filter($messages));
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
        ChannelResponseGuard::assertOk(response: $response, what: $what, channelName: 'Amazon');
    }
}
