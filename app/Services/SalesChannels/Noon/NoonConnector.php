<?php

namespace App\Services\SalesChannels\Noon;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\PublishResult;
use App\Services\SalesChannels\Data\ListingRequirements;
use App\Services\SalesChannels\Contracts\PublishesStockAndPrices;
use App\Services\SalesChannels\Contracts\DescribesListingRequirements;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use RuntimeException;

/**
 * noon's side of the integration.
 *
 * WHAT NOON PUBLISHES, corrected. An earlier version of this class said the request bodies were unpublished because the API Reference renders its Request and Responses
 * sections in the browser. That was wrong twice over: noon serves a Markdown copy of every reference page, and its quickstarts and guides carry worked examples. The field
 * names below are quoted from those, and the paths carry their service segment -- `/stock/v1/stock-update`, not `/v1/stock-update`, which is what this connector tried first
 * and would have 404ed on.
 *
 * THREE THINGS THAT CHANGE HOW CALLS MUST BE MADE, each documented and each easy to get wrong:
 *
 *  - **A 200 is not a success.** Every batch call answers one `items[]` entry per item submitted, each with its own `status`. The pricing guide says it outright: "a 200
 *    response does not mean every price was set." Success is `status.status_code == "OK"` with `status_id == 0`; anything else means THAT item failed and its value did not
 *    change. A caller that checked only the HTTP status would report a price nobody set.
 *  - **Stock `qty` is ABSOLUTE.** It becomes the new available quantity; it is not added to what noon holds. So a figure sent twice is harmless and a delta sent by mistake
 *    is not -- which is the same shape as this application's own storefront write, and for the same reason.
 *  - **A null price does not clear a price.** Omitting a field, or sending null, leaves noon's stored value as it is. There is no documented way to unset one.
 *
 * WHAT IS STILL OPEN, and not decided here:
 *
 *  - **Whether an FBPI seller may create products through the Content API.** `what-is-fbpi` says products must be listed in Seller Lab and cannot be created via API; the
 *    Content API's own overview says it creates and updates listings and names no fulfilment model. Neither page states a role or permission model. Unresolved, and a real
 *    account's granted roles are what would settle it.
 *  - **Who handles an FBPI return.** noon's own comparison table says the seller does; its prose on the same page says noon handles returns after pickup. Both are recorded;
 *    neither is picked.
 *  - **FBP.** It is not defined on any reference or guide page read here. It is NOT claimed to be nonexistent -- noon's help centre is said to describe it, and that is where
 *    it has to be read. FBN, FBPI and FBPO are described in the pages read.
 *
 * FBN stock is noon's own, held in a noon fulfilment centre, fulfilled and returned by noon. It must never enter the shared pool, exactly as Amazon FBA does not.
 */
class NoonConnector implements ChannelConnector, DescribesListingRequirements, PublishesStockAndPrices
{
    public function __construct(private readonly NoonClient $client)
    {
    }

    public function channel(): SalesChannel
    {
        return SalesChannel::Noon;
    }

    /**
     * Signs in and asks noon who the session belongs to. It reads, creates nothing, and changes nothing.
     *
     * A successful answer proves four things at once: the key file is one noon accepts, the project code is the key's own, this server's address is allowed to use it, and
     * the session cookie works on an ordinary call. That is as much as can be proved without a documented second call.
     *
     * An account set to REHEARSE still runs this for real, which is deliberate and not an oversight: a rehearsal withholds writes, and signing in and asking who you are
     * change nothing at noon. A test that refused to run would leave a person unable to check their credentials until they had already turned the writes on.
     */
    public function testConnection(SalesChannelAccount $account): ConnectionTestResult
    {
        try {
            $response = $this->client->call($account, 'noon.whoami', 'GET', NoonOperations::paths()['noon.whoami'], capability: IntegrationCapability::ConnectionTest);
        } catch (ChannelUnavailableException $exception) {
            return ConnectionTestResult::failed(
                $exception->getMessage(),
                $exception->reason === ChannelUnavailableException::AUTH_NEEDED ? ConnectionStatus::AuthExpired : ConnectionStatus::Failed,
            );
        } catch (ChannelPermissionDenied $exception) {
            return ConnectionTestResult::failed($exception->getMessage(), ConnectionStatus::Failed);
        }

        if (!$response->ok()) {
            return ConnectionTestResult::failed('noon answered HTTP ' . $response->status . ' to the identity call (' . $response->message() . ').', ConnectionStatus::Failed);
        }

        // The response's own shape is not documented, so nothing is read out of it by name: that it answered at all is the result. Only the project code we sent is shown
        // back, because that is ours and not a guess at noon's.
        return new ConnectionTestResult(
            status: ConnectionStatus::Ok,
            message: 'noon accepted the key file and the session works.',
            granted: ['identity'],
            missing: $this->whatIsNotBuiltYetFor($account),
            details: ['host' => $this->client->baseUrl($account), 'signed_in_at' => $this->client->loginUrl()],
        );
    }

    /**
     * The capabilities an account has switched on that this version cannot actually serve, so the account page says so instead of looking ready.
     *
     * It is not a list of missing permissions at noon: it is a list of promises this module would otherwise be making.
     *
     * @return list<string>
     */
    private function whatIsNotBuiltYetFor(SalesChannelAccount $account): array
    {
        $unservable = [
            IntegrationCapability::ProductSync->value => 'product_sync_not_built_for_noon',
            IntegrationCapability::InventoryPublishing->value => 'inventory_publishing_not_built_for_noon',
            IntegrationCapability::PricePublishing->value => 'price_publishing_not_built_for_noon',
            IntegrationCapability::OrderImport->value => 'order_import_not_built_for_noon',
            IntegrationCapability::FulfillmentDispatch->value => 'fulfillment_dispatch_not_built_for_noon',
            IntegrationCapability::FinancialReconciliation->value => 'financial_reconciliation_not_built_for_noon',
        ];

        $missing = [];
        foreach ((array) ($account->capabilities ?? []) as $held) {
            if (isset($unservable[(string) $held])) {
                $missing[] = $unservable[(string) $held];
            }
        }

        return $missing;
    }

    // ---- the seller's warehouses ----------------------------------------------------------------------------------

    /**
     * The warehouses noon holds for this partner. Everything about stock needs them first: noon addresses a quantity by a (warehouse_code, partner_sku) PAIR, so "the stock
     * of a product" is not a number at noon until a warehouse is named.
     *
     * Paged with `next_token`, and its rate limit is 30 per 60 seconds -- far tighter than the 1 500 of everything else -- so every page is taken in one pass and the answer
     * is meant to be kept, not asked for per product.
     *
     * @return list<array{code: string, name: string|null, fulfillment_system: string|null, active: bool}>
     */
    public function warehouses(SalesChannelAccount $account): array
    {
        $warehouses = [];
        $token = null;

        do {
            $body = array_filter(['next_token' => $token], static fn (?string $value): bool => $value !== null && $value !== '');
            $response = $this->client->call($account, 'noon.warehouses_list', 'POST', NoonOperations::paths()['noon.warehouses_list'], json: $body ?: new \stdClass(), capability: IntegrationCapability::ReadOnlySync);
            if (!$response->ok()) {
                throw new RuntimeException('noon could not list the warehouses (HTTP ' . $response->status . ').');
            }
            foreach ((array) $response->get('warehouses', []) as $warehouse) {
                $code = trim((string) ($warehouse['warehouse_code'] ?? ''));
                if ($code !== '') {
                    $warehouses[$code] = [
                        'code' => $code,
                        'name' => isset($warehouse['display_name']) ? (string) $warehouse['display_name'] : null,
                        'fulfillment_system' => isset($warehouse['fulfillment_system_code']) ? (string) $warehouse['fulfillment_system_code'] : null,
                        'active' => (bool) ($warehouse['is_active'] ?? false),
                    ];
                }
            }
            $token = (string) $response->get('next_token', '');
        } while ($token !== '');

        return array_values($warehouses);
    }

    // ---- what noon requires of a product in one of its categories --------------------------------------------------

    /**
     * What noon requires of a product in one of its CATEGORIES. `$productType` is a noon category code: a complete `family-product_type-product_subtype` path, which is
     * what a category is to this pipeline.
     *
     * The attributes come back with `is_mandatory` on each, so required and optional are noon's own judgement and not ours. `attribute_options` gives the permitted values
     * where noon limits them; a multi-valued or free-text attribute has none and is recorded with no value list rather than an empty one -- an empty list would read as "no
     * value is acceptable" and refuse every product.
     *
     * noon also gives each attribute a type (`ATTRIBUTE_TYPE_SELECT`, `ATTRIBUTE_TYPE_TEXT`), and whether it is multi-valued or localisable. Those are not carried on
     * `ListingRequirements` yet: the field that would hold them (`channelFields`) is introduced by the Trendyol branch, and adding it here too would put the same change on
     * two branches. They are read again when a product is built, where the type is what actually decides how a value is shaped.
     *
     * A category that cannot be read is UNKNOWN, never "nothing is required". noon's own quickstart warns that a code outside its list is refused by both endpoints, so a
     * code we cannot confirm is worth nothing to send.
     */
    public function listingRequirements(SalesChannelAccount $account, string $productType): ListingRequirements
    {
        $category = trim($productType);
        if ($category === '') {
            return ListingRequirements::unknown($productType);
        }

        $response = $this->client->call(
            $account,
            'noon.category_attributes_list',
            'POST',
            NoonOperations::paths()['noon.category_attributes_list'],
            json: ['category_code' => $category],
            capability: IntegrationCapability::ProductSync,
        );
        if (!$response->ok()) {
            return ListingRequirements::unknown($category);
        }

        $required = [];
        $optional = [];
        $allowedValues = [];

        foreach ((array) $response->get('attributes', []) as $attribute) {
            $code = trim((string) ($attribute['attribute_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if ((bool) ($attribute['is_mandatory'] ?? false)) {
                $required[] = $code;
            } else {
                $optional[] = $code;
            }

            $options = [];
            foreach ((array) ($attribute['attribute_options'] ?? []) as $option) {
                $value = is_array($option) ? trim((string) ($option['code'] ?? $option['value'] ?? $option['name'] ?? '')) : trim((string) $option);
                if ($value !== '') {
                    $options[] = $value;
                }
            }
            if ($options !== []) {
                $allowedValues[$code] = array_values(array_unique($options));
            }
        }

        if ($required === [] && $optional === []) {
            // A category that named no attribute at all tells us nothing, and silence is never permission.
            return ListingRequirements::unknown($category);
        }

        return new ListingRequirements(
            productType: $category,
            required: array_values(array_unique($required)),
            optional: array_values(array_unique($optional)),
            allowedValues: $allowedValues,
            readAt: now(),
        );
    }

    /**
     * noon's category codes, each a complete `family-product_type-product_subtype` path. A code outside this list is refused by the attribute and upsert calls alike, so this
     * is what a person chooses from rather than typing a category by hand.
     *
     * @return list<string>
     */
    public function categories(SalesChannelAccount $account): array
    {
        $response = $this->client->call($account, 'noon.categories_list', 'POST', NoonOperations::paths()['noon.categories_list'], json: new \stdClass(), capability: IntegrationCapability::ProductSync);
        if (!$response->ok()) {
            throw new RuntimeException('noon could not list its categories (HTTP ' . $response->status . ').');
        }

        return array_values(array_filter(array_map(
            static fn ($code): string => trim((string) (is_array($code) ? ($code['category_code'] ?? $code['code'] ?? '') : $code)),
            (array) $response->get('categories', []),
        ), static fn (string $code): bool => $code !== ''));
    }

    // ---- stock and price -------------------------------------------------------------------------------------------

    /**
     * Sets the available quantity of each listing, per warehouse.
     *
     * `qty` is ABSOLUTE at noon: it becomes the new available quantity and is never added to what noon holds. So sending the same figure twice changes nothing, and sending
     * a delta by mistake would be a silent corruption -- the same property, and the same reason for it, as this application's own storefront write.
     *
     * The warehouse comes from the account's setting, because noon has no notion of a product's quantity without one. An account with no warehouse chosen cannot publish
     * stock at all, and says so rather than sending a call noon would refuse.
     *
     * @param list<PublishUpdate> $updates
     */
    public function publishStock(SalesChannelAccount $account, array $updates): PublishResult
    {
        $warehouse = trim((string) $account->setting('warehouse_code', ''));
        if ($warehouse === '') {
            return new PublishResult([], array_fill_keys(array_map(static fn (PublishUpdate $u): int => $u->mappingId, $updates), 'No noon warehouse is chosen for this account, and noon has no quantity for a product without one.'), null, false, []);
        }

        $items = [];
        $byKey = [];
        $failed = [];
        foreach ($updates as $update) {
            $sku = trim((string) ($update->sku ?? $update->listingKey));
            if ($sku === '' || $update->quantity === null) {
                $failed[$update->mappingId] = $update->quantity === null ? 'No quantity to publish.' : 'No partner SKU to publish it against.';
                continue;
            }
            $items[] = ['warehouse_code' => $warehouse, 'partner_sku' => $sku, 'qty' => max(0, $update->quantity)];
            $byKey[$warehouse . '|' . $sku] = $update->mappingId;
        }

        return $this->batch($account, 'noon.stock_update', $items, $byKey, $failed, IntegrationCapability::InventoryPublishing, 'inventory_publishing', static fn (array $item): string => trim((string) ($item['warehouse_code'] ?? '')) . '|' . trim((string) ($item['partner_sku'] ?? '')));
    }

    /**
     * Sets the price of each listing, per country.
     *
     * A null leaves noon's stored value alone and does NOT clear it: noon documents no way to unset a price, so a caller cannot remove one through this. `msrp` is sent only
     * when there is a list price, for the same reason.
     *
     * @param list<PublishUpdate> $updates
     */
    public function publishPrices(SalesChannelAccount $account, array $updates): PublishResult
    {
        $country = mb_strtolower(trim((string) $account->setting('country_code', '')));
        if ($country === '') {
            return new PublishResult([], array_fill_keys(array_map(static fn (PublishUpdate $u): int => $u->mappingId, $updates), 'No noon country is chosen for this account, and a noon price belongs to a country.'), null, false, []);
        }

        $items = [];
        $byKey = [];
        $failed = [];
        foreach ($updates as $update) {
            $sku = trim((string) ($update->sku ?? $update->listingKey));
            if ($sku === '' || $update->price === null) {
                $failed[$update->mappingId] = $update->price === null ? 'No price to publish.' : 'No partner SKU to publish it against.';
                continue;
            }
            if (!$update->price->round(scale: 2)->isPositive()) {
                $failed[$update->mappingId] = 'A price of zero or less (or one that rounds to zero) is refused.';
                continue;
            }
            // Only the fields there is something to say about: a null would be read as "leave it", which is right, but sending one says nothing and invites the reader to
            // think it clears something.
            $items[] = array_filter([
                'partner_sku' => $sku,
                'country_code' => $country,
                'price' => $update->price->round(scale: 2)->toFloat(),
                'msrp' => $update->listPrice?->round(scale: 2)->toFloat(),
            ], static fn ($value): bool => $value !== null);
            $byKey[$sku . '|' . $country] = $update->mappingId;
        }

        return $this->batch($account, 'noon.pricing_upsert', $items, $byKey, $failed, IntegrationCapability::PricePublishing, 'price_publishing', static fn (array $item): string => trim((string) ($item['partner_sku'] ?? '')) . '|' . mb_strtolower(trim((string) ($item['country_code'] ?? ''))));
    }

    /**
     * What noon holds as the available quantity of each SKU, in one warehouse.
     *
     * This is how a published figure is checked rather than assumed: `stock_updated_at` says when noon last changed it, so a quantity that never moved can be told from one
     * that moved to the same number. Per-item status applies here too — an item noon answers NOT_FOUND about has no quantity, which is not the same as a quantity of zero.
     *
     * @param list<string> $partnerSkus
     * @return array<string, array{qty: int|null, updated_at: string|null, ok: bool, reason: string|null}> by partner SKU
     */
    public function stockAt(SalesChannelAccount $account, string $warehouseCode, array $partnerSkus): array
    {
        $warehouse = trim($warehouseCode);
        $skus = array_values(array_unique(array_filter(array_map('trim', $partnerSkus), static fn (string $sku): bool => $sku !== '')));
        if ($warehouse === '' || $skus === []) {
            return [];
        }

        $response = $this->client->call(
            $account,
            'noon.stock_list',
            'POST',
            NoonOperations::paths()['noon.stock_list'],
            json: ['items' => array_map(static fn (string $sku): array => ['warehouse_code' => $warehouse, 'partner_sku' => $sku], $skus)],
            capability: IntegrationCapability::ReadOnlySync,
        );
        if (!$response->ok()) {
            throw new RuntimeException('noon could not be asked what it holds (HTTP ' . $response->status . ').');
        }

        $held = [];
        foreach ((array) $response->get('items', []) as $item) {
            $sku = trim((string) ($item['partner_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $status = NoonItemStatus::of((array) $item, $sku);
            $held[$sku] = [
                // A figure is only a figure when noon said OK about it: an item it refused has no quantity, which is not a quantity of zero.
                'qty' => $status->ok && isset($item['qty']) ? (int) $item['qty'] : null,
                'updated_at' => isset($item['stock_updated_at']) ? (string) $item['stock_updated_at'] : null,
                'ok' => $status->ok,
                'reason' => $status->ok ? null : $status->reason(),
            ];
        }

        return $held;
    }

    /**
     * What noon holds as the price of each SKU in one country, and whether the offer is active.
     *
     * Same rule as the stock read: a price is a price only when noon said OK about it. Its own quickstart example shows a NOT_FOUND item with price, msrp and is_active all
     * null, which is "noon has no price for this" and not "noon prices it at nothing".
     *
     * @param list<string> $partnerSkus
     * @return array<string, array{price: float|null, msrp: float|null, active: bool|null, ok: bool, reason: string|null}> by partner SKU
     */
    public function pricesOf(SalesChannelAccount $account, string $countryCode, array $partnerSkus): array
    {
        $country = mb_strtolower(trim($countryCode));
        $skus = array_values(array_unique(array_filter(array_map('trim', $partnerSkus), static fn (string $sku): bool => $sku !== '')));
        if ($country === '' || $skus === []) {
            return [];
        }

        $response = $this->client->call(
            $account,
            'noon.pricing_get',
            'POST',
            NoonOperations::paths()['noon.pricing_get'],
            json: ['items' => array_map(static fn (string $sku): array => ['partner_sku' => $sku, 'country_code' => $country], $skus)],
            capability: IntegrationCapability::ReadOnlySync,
        );
        if (!$response->ok()) {
            throw new RuntimeException('noon could not be asked what it prices (HTTP ' . $response->status . ').');
        }

        $prices = [];
        foreach ((array) $response->get('items', []) as $item) {
            $sku = trim((string) ($item['partner_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $status = NoonItemStatus::of((array) $item, $sku);
            $prices[$sku] = [
                'price' => $status->ok && isset($item['price']) ? (float) $item['price'] : null,
                'msrp' => $status->ok && isset($item['msrp']) ? (float) $item['msrp'] : null,
                'active' => $status->ok && array_key_exists('is_active', (array) $item) ? (bool) $item['is_active'] : null,
                'ok' => $status->ok,
                'reason' => $status->ok ? null : $status->reason(),
            ];
        }

        return $prices;
    }

    /**
     * One batch call, and the reading of its per-item answers.
     *
     * THE POINT OF THIS METHOD is that a 200 is not a success. noon answers one `items[]` entry per item submitted, each with its own `status`, and the pricing guide says
     * plainly that "a 200 response does not mean every price was set". So each answer is matched back to its mapping BY ITS OWN IDENTIFIERS -- noon echoes the fields it was
     * given, never a position -- and only an item whose status is OK is reported as accepted.
     *
     * An item we sent and noon did not answer about is a failure too, not a success: silence is not confirmation.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, int> $byKey the key noon will echo => our mapping id
     * @param array<int, string> $failed already-refused mappings, by id
     * @param callable(array<string, mixed>): string $keyOf
     */
    private function batch(SalesChannelAccount $account, string $operation, array $items, array $byKey, array $failed, IntegrationCapability $capability, string $scope, callable $keyOf): PublishResult
    {
        if ($items === []) {
            return new PublishResult([], $failed, null, false, []);
        }

        $response = $this->client->call($account, $operation, 'POST', NoonOperations::paths()[$operation], json: ['items' => $items], capability: $capability, scope: $scope);
        if ($response->isDryRun()) {
            return new PublishResult([], $failed, null, true, []);
        }
        if (!$response->ok()) {
            // The whole request failed, so nothing was set. A transient refusal is worth repeating; a rejection is not.
            $reason = 'HTTP ' . $response->status . ': ' . $response->message();

            return new PublishResult([], $failed + array_fill_keys(array_values($byKey), $reason), null, false, $response->isTransient() ? array_values($byKey) : []);
        }

        $accepted = [];
        $answered = [];
        foreach ((array) $response->get('items', []) as $item) {
            $key = $keyOf((array) $item);
            $mappingId = $byKey[$key] ?? null;
            if ($mappingId === null) {
                continue;
            }
            $answered[$key] = true;
            $status = NoonItemStatus::of((array) $item, $key);
            if ($status->ok) {
                $accepted[$mappingId] = '';
            } else {
                $failed[$mappingId] = $status->reason();
            }
        }

        foreach ($byKey as $key => $mappingId) {
            if (!isset($answered[$key]) && !isset($accepted[$mappingId])) {
                $failed[$mappingId] = 'noon did not answer about this item, so nothing about it is confirmed.';
            }
        }

        return new PublishResult($accepted, $failed, null, false, []);
    }

    /** @return array<string, array{documented: bool, built: bool, note: string}> */
    public function capabilityMatrix(): array
    {
        return [
            'service_account_login' => ['documented' => true, 'built' => true, 'note' => 'A JWT signed RS256 with the key file\'s private key (claims sub, iat, jti) is POSTed with the project code to /identity/public/v1/api/login, and noon answers with session cookies. A token more than five minutes from noon\'s clock is refused, so one is made per attempt. The sign-in always goes to the production host, even for a sandbox account. The cookie is not named in the documentation, so the whole cookie string is kept and replayed.'],
            'identity_whoami' => ['documented' => true, 'built' => true, 'note' => 'GET /identity/v1/whoami answers user_code and username. The connection test: it proves the key file, the project code, the address allow-list and the session in one read. Note the prefix differs from the sign-in\'s and neither is a typo.'],
            'static_sandbox' => ['documented' => true, 'built' => true, 'note' => 'noon-sandbox-api-gateway.noon.partners is a drop-in replacement for every call EXCEPT the sign-in. It is a mock: it persists nothing, emits no events, never answers 5xx, and builds its answers out of the request, so it cannot be used to test stock or pricing logic. Its traffic still counts against the project quota.'],
            'warehouses' => ['documented' => true, 'built' => true, 'note' => 'POST /warehouse-platform/v1/warehouses/list, paged with next_token and filterable by fulfillment_system_code, answering warehouse_code, display_name, fulfillment_system_code and is_active. It comes first because noon addresses stock by a (warehouse_code, partner_sku) PAIR. Its rate limit is 30 per 60 seconds, far tighter than everything else.'],
            'stock' => ['documented' => true, 'built' => true, 'note' => 'POST /stock/v1/stock-list reads and POST /stock/v1/stock-update writes, both by (warehouse_code, partner_sku). `qty` is ABSOLUTE: it becomes the new available quantity and is never added to what noon holds. A 200 carries a per-item status that must be read. FBN stock stays noon\'s own and never joins the shared pool.'],
            'pricing' => ['documented' => true, 'built' => true, 'note' => 'POST /pricing/v1/pricing/get and POST /pricing/v1/pricing/upsert, per (partner_sku, country_code) with price, msrp and is_active. Omitting a field or sending null leaves the stored value unchanged and does NOT clear it. A 200 carries a per-item status: "a 200 response does not mean every price was set".'],
            'category_discovery' => ['documented' => true, 'built' => true, 'note' => 'POST /content/v1/categories/list gives the category codes, each a complete family-product_type-product_subtype path; POST /content/v1/categories/attributes/list gives one category\'s attributes with attribute_code, attribute_type, is_mandatory, is_multivalued and the permitted options. A code that is not in the list is refused by both endpoints.'],
            'catalog_product_upsert' => ['documented' => true, 'built' => false, 'note' => 'POST /content/v1/product/upsert takes skus[] (partner_sku, size), brand, category, images[] (url, sort) and an attributes map, and answers sku_parent with a variant per size. noon decides create from amend by our own partner_sku. UNRESOLVED: what-is-fbpi says an item must be listed in Seller Lab and cannot be created via API, while the Content API overview names no fulfilment model and neither page states a role model -- so whether an FBPI seller may call this is not settled, and a real account\'s roles are what would settle it. NOT BUILT YET: the path and the body are known, nothing calls it.'],
            'catalog_content_state' => ['documented' => true, 'built' => false, 'note' => 'POST /content/v1/product/content/get answers what noon holds for a sku_parent: its completeness, the attributes it is missing or rejects, its quality-review status and its errors. This is how acceptance and refusal are followed, rather than assuming an upsert that was taken became a listing. NOT BUILT YET: the path and the body are known, nothing calls it.'],
            'barcode_map' => ['documented' => true, 'built' => false, 'note' => 'POST /catplat/v1/barcode/map ties our partner_sku to a barcode, creating the barcode if noon does not hold it. Per-item status in a 200. NOT BUILT YET: the path and the body are known, nothing calls it.'],
            'product_groups' => ['documented' => true, 'built' => false, 'note' => 'POST /content/v1/product/group/upsert groups sku_parents under shared attribute_codes, and its lists are full replacements whenever it is amended. NOT BUILT YET: nothing here groups catalogue items.'],
            'fbpi_orders' => ['documented' => true, 'built' => false, 'note' => 'POST /fbpi/v1/fbpi-orders/list for one warehouse, pages of at most 50, continued with next_token as a QUERY parameter and filtered by created_after / created_before in UTC. The single-order read, the buyer\'s details and the order update are separate operations whose pages have not been read, so no path is registered for them. NOT BUILT YET: the path and the body are known, and an order mapper is what it still needs.'],
            'shipments' => ['documented' => true, 'built' => false, 'note' => 'POST /fbpi/v1/shipment/create with warehouse_code, a unique integration_shipment_nr, the fbpi_order_nr, awbs[] (courier, awb_nr) and items[] (mp_item_nr). The reference gives no 200 body, so success is judged by the absence of an error rather than by a field that may not exist. Cancelling and reading a shipment are unread operations. NOT BUILT YET: the path and the body are known, nothing calls it.'],
            'event_destinations' => ['documented' => true, 'built' => false, 'note' => 'GET /event-notifications/v1/destination/list shows where noon sends events, with credentials redacted on read. CREATING a destination is a separate operation whose page has not been read, so it is not registered: a webhook is configured in noon\'s own portal until it is. NOT BUILT YET: the path and the body are known, nothing calls it.'],
            'oauth_for_integrators' => ['documented' => true, 'built' => false, 'note' => 'An OAuth flow exists for integrators acting on many sellers\' behalf. Its endpoint paths were not read, so nothing is registered for it, and this application holds one seller\'s own key file in any case.'],
            'returns' => ['documented' => true, 'built' => false, 'note' => 'A Returns service exists with one operation (listing return references). Its method and path were not readable, so nothing is registered. Who handles an FBPI return is itself unsettled in noon\'s own documentation: its comparison table says the seller, its prose on the same page says noon after pickup.'],
            'settlements_and_fees' => ['documented' => false, 'built' => false, 'note' => 'NO settlement, payout, fee or commission endpoint appears anywhere in noon\'s index. So noon\'s commissions cannot be read automatically, OperationalCosts has nothing to ingest for this channel, and no financial figure for noon is invented to fill the gap. A Reports/export service exists whose pages were not read and which may or may not cover financial data.'],
            'fbp' => ['documented' => false, 'built' => false, 'note' => 'FBP is not defined on any reference or guide page read here, and nothing is concluded from that silence: noon\'s help centre describes FBP, and that is where it has to be read before anything is built for it. What the pages read do describe: FBPI (our warehouse, we fulfil, we create shipments), FBN (a noon fulfilment centre, noon fulfils and handles returns, inbound by ASN) and FBPO (purchase-order driven, for B2B2C).'],
        ];
    }
}
