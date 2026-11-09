<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\ExternalOrderMapping;
use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Contracts\ConfirmsShipments;
use App\Services\SalesChannels\Contracts\CreatesListings;
use App\Services\SalesChannels\Contracts\DescribesListingRequirements;
use App\Services\SalesChannels\Contracts\PublishesStockAndPrices;
use App\Services\SalesChannels\Contracts\ReadsFinances;
use App\Services\SalesChannels\Contracts\ReadsListings;
use App\Services\SalesChannels\Contracts\ReadsOrders;
use App\Services\SalesChannels\Contracts\ReadsRecipient;
use App\Services\SalesChannels\Contracts\ReadsReturns;
use App\Services\SalesChannels\Contracts\ResolvesChannelBrands;
use App\Services\SalesChannels\Contracts\ReportsPublishResults;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Data\ListingRequirements;
use App\Services\SalesChannels\Data\ListingSubmission;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\PublishResult;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Dispatch\ChannelRecipient;
use App\Services\SalesChannels\Shipments\PackageSplitPlan;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;
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
class TrendyolConnector implements ChannelConnector, ReadsListings, ReadsOrders, ReadsRecipient, ReadsReturns, ReadsFinances, PublishesStockAndPrices, ReportsPublishResults, ConfirmsShipments, DescribesListingRequirements, CreatesListings, ResolvesChannelBrands
{
    private const MILLISECONDS_PER_DAY = 86_400_000;

    /**
     * What Trendyol will not let an APPROVED product change. Refused here by name rather than sent and refused as a whole batch, because the batch's reason is one a person
     * then has to decipher. Changing any of these makes it a different product, which is a decision and not an edit.
     */
    private const IMMUTABLE_ONCE_APPROVED = ['barcode', 'productMainId', 'brandId', 'categoryId'];
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
        private readonly TrendyolReturnMapper $returns,
        private readonly TrendyolCategoryMapper $categories,
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
            'category_requirements' => ['documented' => true, 'built' => true, 'note' => 'Product V2 `/product/categories/{id}/attributes`: which attributes a category requires, the values it accepts where Trendyol embeds them, and whether a value of our own is allowed. The attribute and value IDS are kept beside the names because a write sends ids. A category that cannot be read, or that names no attribute, is UNKNOWN and never "nothing is required". The very long value lists have an endpoint of their own which this version does not page through.'],
            'product_create_update' => ['documented' => true, 'built' => true, 'note' => 'Product V2, both as BATCHES. Create: `POST .../v2/products`. Content change: `POST .../products/content-bulk-update` (V2 has no PUT at that path -- the PUT belonged to V1, retired). HTTP 200 answers a batchRequestId and means the batch was taken, never that anything is listed, so a submission is ACCEPTED_FOR_PROCESSING and `batchResult()` says what became of each item. The barcode is the identity. A barcode in the UNAPPROVED list is WORKING, never absent: it exists and awaits Trendyol\'s review, and creating it again would leave two of the same item and a locked barcode. Trendyol will not change an approved item\'s barcode, productMainId, brandId or categoryId, so those are refused here by name before anything is sent. Brand lookup is built. Images ARE built, and were a hole rather than a nicety: `images` is REQUIRED on the create, and `GET /product/categories/{id}/attributes` -- the only thing this module asked -- answers with CATEGORY attributes and never mentions a product-level field, so nothing could discover the requirement. Trendyol documents a maximum of 8 per barcode and that the URLs must be https with a valid certificate. The shop\'s own pictures become those URLs through `ChannelProductImages`, which REFUSES rather than falling back: `getStorageImages()` returns a placeholder when a file is missing, and on a channel payload that would publish our grey placeholder PNG as somebody\'s product photograph.'],
            'order_v2' => ['documented' => true, 'built' => true, 'note' => 'The cursor stream (lastModified windows of at most 14 days) for sync and /v2/orders for single-order lookups. Order V1 is not used.'],
            'shipment_packages' => ['documented' => true, 'built' => true, 'note' => 'A package is the unit (shipmentPackageId); a split or partial cancellation yields new package ids and the replaced package is terminal.'],
            'picking_and_tracking' => ['documented' => true, 'built' => true, 'note' => 'Picking, then the tracking number for packages on the seller-pays agreement only. Trendyol-pays packages use the carrier label Trendyol issues; the app never sets Shipped or Delivered (the carrier feed does).'],
            'cancel_unsupplied' => ['documented' => true, 'built' => false, 'note' => 'Cancelling lines cancels a customer order and locks the product at Trendyol: registered but deliberately not built.'],
            'split_packages' => ['documented' => true, 'built' => true, 'note' => 'All four international split endpoints are built, through `ChannelPackageSplits`: `/split` (whole lines), `/multi-split` (several groups, and Trendyol forbids naming every line), `/quantity-split` (a line\'s units across packages, `quantities` an ARRAY) and `/split-packages` (named packages of named quantities, `quantities` a single INTEGER -- the two schemas really do differ in that one field). All four take `shouldKeepPreviousStatus`, which is forced true for a package already in Picking because Trendyol requires it there. **No split endpoint documents a response body** in the international set (200, no content schema), so nothing is read from the answer and a 200 is not a confirmation: the packages are created asynchronously, the original turns UnPacked and its id becomes invalid, and the new ones are learned by reading the order back, where `createdBy: "split"` and `originPackageIds` identify them. No idempotency and nothing about a repeated request is documented anywhere, so the intent is fenced before it is sent and a fenced row is never sent again -- but it IS asked about, which is where this differs from a return decision: the package\'s own status is the answer. A row whose proof never arrives is needs_review, never failed.'],
            'invoice_upload' => ['documented' => true, 'built' => false, 'note' => 'DECIDED, NOT MISSING. The contract is verified: `POST /integration/sellers/{sellerId}/seller-invoice-links` takes only `invoiceLink` and `shipmentPackageId` for the Gulf markets (no invoice number or date, unlike Turkey), answers 201, and answers 409 for either of two reasons it does not distinguish -- this package already has an invoice, OR this link was already fed for a different package. `POST .../seller-invoice-links/delete` takes serviceSourceId, channelId and customerId and answers 202; `POST .../seller-invoice-file` is multipart with a `file` part, at most 10 MB, pdf/jpeg/png. What is missing is not the integration but the LINK: Trendyol requires a URL that stays reachable without a login for ten years in Saudi Arabia, and this application has no such URL. `generate-invoice/{id}` is scoped to the order\'s own customer by session, so Trendyol fetching it would get a 404, and publishing an unguessable long-lived URL instead would put a customer\'s name, address, telephone and medicines behind a link anybody holding it could read. **THE OWNER HAS NOW DECIDED: invoices stay with the Trendyol seller panel.** Nothing is sent from here -- no link and no file -- and no public invoice URL is built, so no customer\'s name, address, telephone or medicines are put behind a link. The contract above is kept written down because it is verified and because the decision could be revisited; the code to act on it is deliberately absent rather than present and switched off. See docs/sales-channels/gap-matrix.md.'],
            'webhooks' => ['documented' => true, 'built' => true, 'note' => 'Order-package webhooks (API-key authentication); polling stays the source of truth because Trendyol says delivery is not always possible. No webhook exists for claims, products or finance.'],
            'claims_returns' => ['documented' => true, 'built' => true, 'note' => 'Claims are read (read-only) and the units the channel ACCEPTED back are recorded on the order lines. Approving and rejecting ARE built, through `ChannelReturnDecisions`. They are the only writes in this module that move money belonging to a customer with no order behind them, so they carry their own approval scope (`return_decisions`) and their own record: one decision per claim item EVER, written before it is sent. Trendyol documents no idempotency for either, and -- unlike every other outbox here -- publishes no way to ask what became of one claim-item decision, so an unknown outcome waits for a person and is never sent again. A rejection carries a reason id read from `GET /order/claim-issue-reasons`, whose example list Trendyol does not describe as complete, so an id is never typed from memory. Attachments on a rejection are a declared gap: the schema does not mark `files` required while the operation description says they must be added, and nothing is attached to satisfy a rule nobody can state. The Gulf documentation prints the endpoint both with and without a -gulf suffix: UNVERIFIED which the gateway serves.'],
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

        return new Pages($items, $this->nextListingCursor($response, $phase, $page, $cursor['from'] ?? null, byToken: $token !== null), $newest);
    }

    /**
     * Pages are followed by number while that stays inside Trendyol's 10,000-record window, then by the token the response carries for as long as it carries one (once
     * a read is by token it stays by token: a token cursor has no page number to count on); when a phase ends the unapproved products are read after the approved ones.
     *
     * @return array<string, mixed>|null
     */
    private function nextListingCursor(ChannelResponse $response, string $phase, int $page, mixed $from, bool $byToken): ?array
    {
        $token = $response->get('nextPageToken');
        $hasToken = is_string($token) && $token !== '';

        if ($byToken) {
            return $hasToken ? ['phase' => $phase, 'token' => $token, 'from' => $from] : $this->afterListingPhase($phase, $from);
        }

        $totalPages = (int) $response->get('totalPages', 1);
        $withinWindow = ($page + 2) * self::LIST_PAGE_SIZE <= self::LIST_WINDOW_LIMIT;
        if ($withinWindow && $page + 1 < $totalPages) {
            return ['phase' => $phase, 'page' => $page + 1, 'from' => $from];
        }
        if (!$withinWindow && $page + 1 < $totalPages && $hasToken) {
            return ['phase' => $phase, 'token' => $token, 'from' => $from];
        }

        return $this->afterListingPhase($phase, $from);
    }

    /** @return array<string, mixed>|null */
    private function afterListingPhase(string $phase, mixed $from): ?array
    {
        return $phase === 'approved' ? ['phase' => 'unapproved', 'page' => 0, 'from' => $from] : null;
    }

    // ---- orders -------------------------------------------------------------------------------------------------

    public function orders(SalesChannelAccount $account, CarbonInterface $since, ?array $cursor): Pages
    {
        $seller = $this->client->sellerId($account);
        $nowMs = $this->milliseconds(now());
        $earliest = $nowMs - self::STREAM_HISTORY_DAYS * self::MILLISECONDS_PER_DAY;
        $windowMs = (int) config('sales_channels.trendyol.orders_window_days', 14) * self::MILLISECONDS_PER_DAY;

        // The sweep reads up to the moment it began and no further: asking "up to now" afresh on every call would always leave one more tiny window to read, and the sweep
        // would chase the clock for ever. Whatever changes after that moment is the next run's (it starts a little before the watermark).
        $until = (int) ($cursor['until'] ?? $nowMs);
        $start = (int) ($cursor['start'] ?? max($this->milliseconds($since), $earliest));
        $end = (int) ($cursor['end'] ?? min($start + $windowMs, $until));
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
            (bool) $response->get('hasMore', false) && is_string($response->get('nextCursor')) => ['start' => $start, 'end' => $end, 'cursor' => $response->get('nextCursor'), 'until' => $until],
            $end < $until => ['start' => $end, 'end' => min($end + $windowMs, $until), 'cursor' => null, 'until' => $until],
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

    /**
     * Every package of one order, as the channel describes them now.
     *
     * `order()` answers about one package; this answers about the order, which is what a split needs: the packages a cut
     * produced are new rows of the same `orderNumber`, carrying `createdBy: "split"` and `originPackageIds` pointing back at
     * the package they came from (which the mapper turns into `replacesPackageId`). Reading the order is how Trendyol's own
     * pages say to learn them -- "you have to fetch the new generated packages from the Get Shipment Packages again".
     *
     * @return list<RemoteOrder>
     * @throws ChannelUnavailableException|ChannelPermissionDenied
     */
    public function orderPackages(SalesChannelAccount $account, string $orderId): array
    {
        $seller = $this->client->sellerId($account);
        $response = $this->client->call($account, 'trendyol.orders_list', 'GET', "/order/sellers/{$seller}/v2/orders", ['orderNumber' => $orderId, 'size' => 50], capability: IntegrationCapability::OrderImport);
        $this->assertOk($response, 'Trendyol order lookup');

        $packages = [];
        foreach ((array) $response->get('content', []) as $package) {
            if (is_array($package) && (string) ($package['orderNumber'] ?? '') === $orderId && isset($package['shipmentPackageId'])) {
                $packages[] = $this->orders->map($package, $account);
            }
        }

        return $packages;
    }

    public function recipient(SalesChannelAccount $account, ExternalOrderMapping $mapping): ChannelRecipient
    {
        $seller = $this->client->sellerId($account);
        $packageId = (string) $mapping->external_package_id;
        $query = $packageId !== '' ? ['shipmentPackageIds' => $packageId] : ['orderNumber' => $mapping->external_order_id];

        try {
            $response = $this->client->call($account, 'trendyol.order_recipient', 'GET', "/order/sellers/{$seller}/v2/orders", $query + ['size' => 50], capability: IntegrationCapability::FulfillmentDispatch);
        } catch (ChannelPermissionDenied) {
            throw new RecipientUnavailable(RecipientUnavailable::NOT_PERMITTED);
        }
        if (!$response->ok()) {
            throw new RecipientUnavailable(RecipientUnavailable::CHANNEL_ERROR);
        }

        foreach ((array) $response->get('content', []) as $package) {
            if (is_array($package) && (string) ($package['orderNumber'] ?? '') === $mapping->external_order_id && ($packageId === '' || (string) ($package['shipmentPackageId'] ?? '') === $packageId)) {
                $recipient = TrendyolRecipientMapper::map($package);
                $problems = $recipient->problems();

                return $problems === [] ? $recipient : throw new RecipientUnavailable(RecipientUnavailable::INCOMPLETE, $problems);
            }
        }

        throw new RecipientUnavailable(RecipientUnavailable::NOT_FOUND);
    }

    // ---- returns ------------------------------------------------------------------------------------------------

    /**
     * Customer returns (claims) changed since `$since`. The claims endpoint filters on the claim's last-modified time; the Gulf documentation prints the path both plain and
     * with a `-gulf` suffix, and the plain path with the storefront header is used (UNVERIFIED which the gateway serves: a refusal is reported, not hidden).
     */
    public function returns(SalesChannelAccount $account, CarbonInterface $since, ?array $cursor): Pages
    {
        $seller = $this->client->sellerId($account);
        $page = (int) ($cursor['page'] ?? 0);
        $start = (int) ($cursor['start'] ?? $this->milliseconds($since));
        $end = (int) ($cursor['end'] ?? $this->milliseconds(now()));
        $response = $this->client->call($account, 'trendyol.claims_list', 'GET', "/order/sellers/{$seller}/claims", ['startDate' => $start, 'endDate' => $end, 'page' => $page, 'size' => 200], capability: IntegrationCapability::OrderImport);
        $this->assertOk($response, 'Trendyol claims');

        $items = [];
        $newest = null;
        foreach ((array) $response->get('content', []) as $claim) {
            $mapped = is_array($claim) ? $this->returns->map($claim) : null;
            if ($mapped === null) {
                continue;
            }
            $items[] = $mapped;
            $newest = $mapped->updatedAt !== null && ($newest === null || $mapped->updatedAt->gt($newest)) ? $mapped->updatedAt : $newest;
        }
        $more = $page + 1 < (int) $response->get('totalPages', 1);

        return new Pages($items, $more ? ['page' => $page + 1, 'start' => $start, 'end' => $end] : null, $newest);
    }

    // ---- deciding a customer's return ---------------------------------------------------------------------------

    /**
     * The reasons Trendyol accepts for REFUSING a return. `GET /order/claim-issue-reasons`.
     *
     * Three things about this call are worth knowing before anything is built on it, and all three are from Trendyol's own
     * pages:
     *
     *  - it has **no seller in its path**, unlike every other order call here, which is why it is its own operation;
     *  - its answer is a bare array of `{id, name}` with the names in Turkish;
     *  - **the example list is not described as complete.** So the list is read from the instance and a rejection carries an id
     *    that came back from it -- an id typed from memory would be refused by Trendyol, or worse, accepted as a different
     *    reason than the person meant.
     *
     * @return array<int, string> the reason id => its name, as Trendyol spells it
     * @throws RuntimeException when Trendyol could not be asked, so "we do not know the reasons" is never read as "there are none"
     */
    public function returnRejectReasons(SalesChannelAccount $account): array
    {
        $response = $this->client->call($account, 'trendyol.claim_issue_reasons', 'GET', '/order/claim-issue-reasons', capability: IntegrationCapability::OrderImport);
        $this->assertOk($response, 'Trendyol return rejection reasons');

        $reasons = [];
        foreach ((array) ($response->json ?? []) as $entry) {
            $id = is_array($entry) ? (int) ($entry['id'] ?? 0) : 0;
            $name = is_array($entry) ? trim((string) ($entry['name'] ?? '')) : '';
            if ($id > 0 && $name !== '') {
                $reasons[$id] = $name;
            }
        }

        return $reasons;
    }

    /**
     * Accepts the units of a return back. `PUT /order/sellers/{id}/claims/{claimId}/items/approve`.
     *
     * **This refunds a customer**, which is why the operation carries the return-decisions approval and is not retry-safe:
     * Trendyol documents no idempotency for it, and what a second approval does to a refund is not in its pages. Whether this
     * may be sent again is `ChannelReturnDecisions`' to answer from what it recorded before sending, never the transport's to
     * assume.
     *
     * The body names claim ITEM ids, each of which is one unit of one order line. `claimId` is a string, not a number.
     *
     * @param list<string> $claimItemIds
     */
    public function approveReturnItems(SalesChannelAccount $account, string $claimId, array $claimItemIds): ChannelResponse
    {
        $seller = $this->client->sellerId($account);

        return $this->client->call(
            $account,
            'trendyol.claims_approve',
            'PUT',
            "/order/sellers/{$seller}/claims/" . rawurlencode($claimId) . '/items/approve',
            json: ['claimLineItemIdList' => array_values($claimItemIds)],
            capability: IntegrationCapability::OrderImport,
            scope: ChannelOperations::SCOPE_RETURN_DECISIONS,
        );
    }

    /**
     * Refuses a return. `POST /order/sellers/{id}/claims/{claimId}/issue`, and it is **multipart/form-data**, not JSON.
     *
     * Two shapes here are Trendyol's and would be easy to get wrong:
     *
     *  - `claimItemIdList` is **one comma-separated string**, not an array, even though the approval above takes an array;
     *  - `description` is capped at 500 characters, so it is cut here rather than having the whole request refused for a field
     *    a person typed too much into.
     *
     * Attachments are a declared gap and not a refusal to build: the schema does not mark `files` required while the operation
     * description says they must be added, so Trendyol contradicts itself and this sends none. A rejection that Trendyol
     * refuses for a missing file comes back as a refusal with its own message, which is the honest outcome of an ambiguity --
     * better than attaching an empty file to satisfy a rule nobody can state.
     *
     * @param list<string> $claimItemIds
     */
    public function rejectReturnItems(
        SalesChannelAccount $account,
        string $claimId,
        array $claimItemIds,
        int $reasonId,
        string $description,
    ): ChannelResponse {
        $seller = $this->client->sellerId($account);

        return $this->client->call(
            $account,
            'trendyol.claims_reject',
            'POST',
            "/order/sellers/{$seller}/claims/" . rawurlencode($claimId) . '/issue',
            capability: IntegrationCapability::OrderImport,
            scope: ChannelOperations::SCOPE_RETURN_DECISIONS,
            multipart: [
                'claimIssueReasonId' => (string) $reasonId,
                'claimItemIdList' => implode(',', array_values($claimItemIds)),
                'description' => mb_substr(trim($description), 0, 500),
            ],
        );
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
        $transient = [];
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
                    continue;
                }
                $failed[$mappingId] = $response->ok() ? 'Trendyol returned no batch id.' : 'HTTP ' . $response->status . ': ' . $response->message();
                if (!$response->ok() && $response->isTransient()) {
                    $transient[] = $mappingId;
                }
            }
        }

        return new PublishResult($accepted, $failed, $accepted === [] ? null : (string) reset($accepted), $dryRun, $transient);
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
        if (!$update->price->round(scale: 2)->isPositive()) {
            return 'A price of zero or less (or one that rounds to zero) is refused: Trendyol treats a zero price as removing the listing.';
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
    /**
     * What Trendyol requires of a product in one of its CATEGORIES. `$productType` is a Trendyol category id, which is what a category is to this pipeline.
     *
     * Product **V2** paths are used (`/product/categories/{id}/attributes`): V1's `/product/product-categories/{id}/attributes` is retired, and the module does not use V1
     * anywhere. Trendyol embeds the acceptable values with the attributes where the list is short; a very long list has an endpoint of its own which this version does not
     * page through, and an attribute whose values were not embedded is recorded as required with NO value list rather than an empty one — an empty list would read as "no
     * value is acceptable" and refuse every product.
     *
     * A category that cannot be read, or that names no attribute at all, is UNKNOWN. It is never "nothing is required": the draft then stays a draft, which is the whole
     * point of `ListingRequirements::unknown()`.
     */
    /**
     * Fields Trendyol requires of EVERY product, whatever its category -- and the reason they are declared here rather than
     * read from the channel.
     *
     * `GET /product/categories/{id}/attributes` answers with `categoryAttributes`, which are the attributes of a CATEGORY:
     * colour, size, whatever that category asks about. It never mentions a product-level field, so nothing that asks it can
     * discover that `images` is required -- and that is how a documented requirement comes to be missing from every call this
     * module makes. The create guide's own table marks `images` required.
     *
     * **It holds only what was read in the documentation**, not a complete list of Trendyol's product-level requirements: the
     * other product fields were not verified one by one, so they are not claimed here. `ListingRequirements` keeps these apart
     * from the channel's own answer precisely so a reader can tell which is which.
     *
     * @var list<string>
     */
    private const REQUIRED_OF_EVERY_PRODUCT = ['images'];

    public function listingRequirements(SalesChannelAccount $account, string $productType): ListingRequirements
    {
        $categoryId = trim($productType);
        if ($categoryId === '' || !ctype_digit($categoryId)) {
            // Trendyol's categories are numeric ids. Anything else is not a category, and asking about it would spend a call to be told so.
            return ListingRequirements::unknown($productType);
        }

        $response = $this->client->call(
            $account,
            'trendyol.category_attributes',
            'GET',
            '/product/categories/' . rawurlencode($categoryId) . '/attributes',
            capability: IntegrationCapability::ProductSync,
        );

        if ($response->isDryRun() || !$response->ok()) {
            return ListingRequirements::unknown($categoryId);
        }

        return $this->categories->map((array) ($response->json ?? []), $categoryId)
            ->withAlwaysRequired(self::REQUIRED_OF_EVERY_PRODUCT);
    }

    /**
     * Trendyol's own brands whose name matches. `GET /product/brands/by-name?name=` — a bare JSON array of `{id, name, luxe}`, which is the shape the documentation shows.
     *
     * It is the by-name call and not the paged full list: the list endpoint's response envelope is NOT in the documentation (the pages give only its `page` and `size`
     * parameters and disagree on the size range), and this repository does not build a parser on an unverified shape.
     *
     * Trendyol's match is CASE SENSITIVE, which is worth knowing when nothing comes back for a brand that plainly exists. What comes back is sorted so that an exact match
     * leads, because that is the one a person is almost always after — but every candidate is returned and none is chosen.
     *
     * @return list<array{id: int, name: string}>
     * @throws RuntimeException when Trendyol could not be asked, so that "we do not know" is never read as "there is no such brand"
     */
    public function brandsNamed(SalesChannelAccount $account, string $name): array
    {
        $wanted = trim($name);
        if ($wanted === '') {
            return [];
        }

        $response = $this->client->call($account, 'trendyol.brands', 'GET', '/product/brands/by-name', ['name' => $wanted], capability: IntegrationCapability::ProductSync);
        $this->assertOk($response, 'Trendyol brand lookup');

        $brands = [];
        // Documented as a bare array. A wrapper is accepted too rather than asserted against: either way only rows carrying both an id and a name are used.
        $rows = $response->json;
        $rows = is_array($rows) ? ($rows['brands'] ?? $rows['content'] ?? $rows) : [];
        foreach ((array) $rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $brandName = trim((string) ($row['name'] ?? ''));
            if ($brandName !== '') {
                $brands[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => $brandName];
            }
        }

        $brands = array_values($brands);
        usort($brands, static function (array $a, array $b) use ($wanted): int {
            return [$a['name'] !== $wanted, mb_strlen($a['name']), $a['name']] <=> [$b['name'] !== $wanted, mb_strlen($b['name']), $b['name']];
        });

        return $brands;
    }

    // ---- creating and changing the listing of a product of ours --------------------------------------------------

    /**
     * Trendyol's own fields that would price a listing or put stock behind it. The publisher withholds every one of them from a create, because a price and a quantity may
     * only leave through the separately switched and separately approved price-and-stock path.
     *
     * A zero price is worse than withheld at Trendyol: it reads the price 0 as a delisting.
     *
     * @return list<string>
     */
    public function offerAttributes(): array
    {
        return ['listPrice', 'salePrice', 'quantity', 'stock', 'price'];
    }

    /**
     * Creates the product at Trendyol. Product **V2**: `POST /product/sellers/{id}/v2/products`.
     *
     * HTTP 200 is a BATCH TAKEN, never a product listed — the answer is a `batchRequestId` and nothing else, and what became of each item is `batchResult()`'s to say. So the
     * submission comes back as ACCEPTED_FOR_PROCESSING, which is what makes `ListingPublisher` ask again instead of reporting a success nobody confirmed.
     *
     * The barcode is the identity of a Trendyol product, so it is required here: without one the call would make a product nothing can ever be matched to, and a second
     * attempt would make another.
     *
     * @param array<string, mixed> $attributes
     */
    public function createListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $attributes): ListingSubmission
    {
        $seller = $this->client->sellerId($account);
        // The publisher keeps our own key for the listing in the draft's payload (`ListingPublisher::skuOf`). At Trendyol that key IS the barcode: it is the identity of a product there.
        $barcode = trim((string) ($attributes['barcode'] ?? (($draft->payload ?? [])['sku'] ?? '')));
        if ($barcode === '') {
            return ListingSubmission::rejected(['barcode_is_required_by_trendyol']);
        }

        $response = $this->client->call(
            $account,
            'trendyol.products_create',
            'POST',
            "/product/sellers/{$seller}/v2/products",
            json: ['items' => [$attributes + ['barcode' => $barcode]]],
            capability: IntegrationCapability::ProductSync,
            scope: ChannelOperations::SCOPE_PRODUCT_WRITES,
        );

        if ($response->isDryRun()) {
            return ListingSubmission::unknown('dry_run');
        }
        if (!$response->ok()) {
            return ListingSubmission::rejected([$response->message()], channelStatus: 'http_' . $response->status);
        }

        $batch = trim((string) $response->get('batchRequestId', ''));

        // A 200 with no batch id is not a success we can follow up, and claiming it was taken would leave a product nobody ever asks about again.
        return $batch === ''
            ? ListingSubmission::working(channelStatus: 'no_batch_id')
            : ListingSubmission::acceptedForProcessing($batch, 'batch_taken');
    }

    /**
     * Changes the CONTENT of a product Trendyol already holds. Product **V2** has no `PUT v2/products` — the PUT was V1, which is retired — so this is
     * `POST /product/sellers/{id}/products/content-bulk-update`, a batch like the create.
     *
     * Trendyol REFUSES to change some fields of an approved product: its barcode, `productMainId`, `brandId`, `categoryId`, and the values of slicer/varianter attributes.
     * Sending one would have the whole batch refused for a reason a person then has to decipher, so they are refused here, by name, before anything is sent. Changing any of
     * them is a different product, which is a person's decision and not an edit.
     *
     * @param array<string, mixed> $changedAttributes
     */
    public function updateListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $changedAttributes): ListingSubmission
    {
        $barcode = trim((string) (($draft->payload ?? [])['sku'] ?? ''));
        if ($barcode === '') {
            return ListingSubmission::rejected(['barcode_is_required_by_trendyol']);
        }

        $immutable = array_values(array_intersect(array_keys($changedAttributes), self::IMMUTABLE_ONCE_APPROVED));
        if ($immutable !== []) {
            return ListingSubmission::rejected(array_map(static fn (string $field): string => 'trendyol_will_not_change_' . $field, $immutable));
        }
        if ($changedAttributes === []) {
            return ListingSubmission::working(channelStatus: 'nothing_changed');
        }

        $seller = $this->client->sellerId($account);
        $response = $this->client->call(
            $account,
            'trendyol.products_update',
            'POST',
            "/product/sellers/{$seller}/products/content-bulk-update",
            json: ['items' => [$changedAttributes + ['barcode' => $barcode]]],
            capability: IntegrationCapability::ProductSync,
            scope: ChannelOperations::SCOPE_PRODUCT_WRITES,
        );

        if ($response->isDryRun()) {
            return ListingSubmission::unknown('dry_run');
        }
        if (!$response->ok()) {
            return ListingSubmission::rejected([$response->message()], channelStatus: 'http_' . $response->status);
        }

        $batch = trim((string) $response->get('batchRequestId', ''));

        return $batch === ''
            ? ListingSubmission::working(channelStatus: 'no_batch_id')
            : ListingSubmission::acceptedForProcessing($batch, 'batch_taken');
    }

    /**
     * What Trendyol holds for one of our barcodes, asked afresh. The four answers are kept apart, and the middle one is the trap:
     *
     *  - in the APPROVED list      → LIVE, with Trendyol's own words about it;
     *  - in the UNAPPROVED list    → **WORKING**, not absent. The product EXISTS and is waiting for Trendyol's review; creating it again because it is not approved yet is
     *                                how a seller ends up with two of the same product and a locked barcode;
     *  - in neither, both answered → ABSENT, the only answer a create may be made on;
     *  - a call that failed        → UNKNOWN. Never "it holds nothing".
     */
    public function listingFor(SalesChannelAccount $account, string $sku): ListingSubmission
    {
        $barcode = trim($sku);
        if ($barcode === '') {
            return ListingSubmission::unknown('no_barcode');
        }
        $seller = $this->client->sellerId($account);

        $approved = $this->client->call($account, 'trendyol.products_approved', 'GET', "/product/sellers/{$seller}/products/approved", ['barcode' => $barcode, 'size' => 1], capability: IntegrationCapability::ProductSync);
        if ($approved->isDryRun() || !$approved->ok()) {
            return ListingSubmission::unknown($approved->isDryRun() ? 'dry_run' : 'http_' . $approved->status);
        }
        if ($this->holdsTheBarcode((array) ($approved->json ?? []), $barcode)) {
            return ListingSubmission::live($barcode, ['APPROVED']);
        }

        $unapproved = $this->client->call($account, 'trendyol.products_unapproved', 'GET', "/product/sellers/{$seller}/products/unapproved", ['barcode' => $barcode, 'size' => 1], capability: IntegrationCapability::ProductSync);
        if ($unapproved->isDryRun() || !$unapproved->ok()) {
            return ListingSubmission::unknown($unapproved->isDryRun() ? 'dry_run' : 'http_' . $unapproved->status);
        }
        if ($this->holdsTheBarcode((array) ($unapproved->json ?? []), $barcode)) {
            // It exists. It is simply not approved yet, and a create here would be a duplicate.
            return ListingSubmission::working(channelStatus: 'awaiting_trendyol_approval');
        }

        return ListingSubmission::absent();
    }

    /**
     * Whether a product list really names this barcode. The filter is Trendyol's, but a channel that ignored it and answered with something else must not be read as holding
     * our product: the barcode is checked here rather than trusting a non-empty page.
     *
     * @param array<string, mixed> $body
     */
    private function holdsTheBarcode(array $body, string $barcode): bool
    {
        foreach ((array) ($body['content'] ?? []) as $record) {
            if (trim((string) ($record['barcode'] ?? '')) === $barcode) {
                return true;
            }
            foreach ((array) ($record['variants'] ?? []) as $variant) {
                if (trim((string) ($variant['barcode'] ?? '')) === $barcode) {
                    return true;
                }
            }
        }

        return false;
    }

    public function batchResult(SalesChannelAccount $account, string $batchId): array
    {
        $seller = $this->client->sellerId($account);
        $response = $this->client->call($account, 'trendyol.batch_result', 'GET', "/product/sellers/{$seller}/products/batch-requests/" . rawurlencode($batchId));
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

        // Picking is the first step; a package that is already Picking or Invoiced is further along and is never moved back.
        if ($package->externalStatus === 'Created') {
            $picking = $this->client->call($account, 'trendyol.orders_picking', 'PUT', "/order/sellers/{$seller}/shipment-packages/{$confirmation->packageId}", json: ['status' => 'Picking', 'lines' => $lines, 'params' => (object) []], capability: IntegrationCapability::FulfillmentDispatch);
            if (!$picking->ok()) {
                return false;
            }
        }
        if ($providerCode === null || $package->trackingNumber === $confirmation->trackingNumber) {
            return true;
        }

        $tracking = $this->client->call($account, 'trendyol.orders_tracking', 'PUT', "/order/sellers/{$seller}/shipment-packages/{$confirmation->packageId}/tracking-details", json: ['cargoSenderNumber' => $confirmation->trackingNumber, 'providerCode' => $providerCode], capability: IntegrationCapability::FulfillmentDispatch);

        return $tracking->ok();
    }

    /**
     * Asks Trendyol to cut one package into several, in whichever of its four shapes the plan is written in.
     *
     * **Nothing is read out of the answer.** All four endpoints document 200 "Package split successfully" with no content
     * schema in the international set, so the new `shipmentPackageId`s exist only in Trendyol and are learned by reading the
     * order again -- which is what Trendyol's own pages instruct ("you have to fetch the new generated packages from the Get
     * Shipment Packages again"). A caller that trusted a body here would be trusting a field no page promises.
     *
     * The caller decides whether sending is safe at all; this method only sends. See `ChannelPackageSplits`.
     *
     * @throws ChannelUnavailableException|ChannelPermissionDenied
     */
    public function splitShipmentPackage(SalesChannelAccount $account, string $packageId, PackageSplitPlan $plan): ChannelResponse
    {
        $seller = $this->client->sellerId($account);

        return $this->client->call(
            $account,
            $plan->operation(),
            'POST',
            "/order/sellers/{$seller}/shipment-packages/" . rawurlencode($packageId) . $plan->path(),
            json: $plan->body(),
            capability: IntegrationCapability::FulfillmentDispatch,
        );
    }

    /**
     * Whether `shouldKeepPreviousStatus` has to be true for this package, which is Trendyol's own rule and not a preference:
     * "If the old package status was picking, You should send "shouldKeepPreviousStatus" as true". Left false, the new packages
     * come back as `Created` and the preparation already recorded against the old package is lost.
     */
    public function splitKeepsPreviousStatus(?RemoteOrder $package): bool
    {
        return $package !== null && $package->externalStatus === 'Picking';
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
