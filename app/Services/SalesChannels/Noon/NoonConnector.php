<?php

namespace App\Services\SalesChannels\Noon;

use App\Enums\SalesChannels\ConnectionStatus;
use App\Enums\SalesChannels\IntegrationCapability;
use App\Enums\SalesChannels\SalesChannel;
use App\Models\ChannelListingDraft;
use App\Models\SalesChannelAccount;
use App\Models\ExternalOrderMapping;
use App\Services\SalesChannels\Contracts\ChannelConnector;
use App\Services\SalesChannels\Contracts\ConfirmsShipments;
use App\Services\SalesChannels\Contracts\CreatesListings;
use App\Services\SalesChannels\Contracts\DescribesListingRequirements;
use App\Services\SalesChannels\Contracts\PublishesStockAndPrices;
use App\Services\SalesChannels\Contracts\ReadsOrders;
use App\Services\SalesChannels\Contracts\ReadsRecipient;
use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Data\ConnectionTestResult;
use App\Services\SalesChannels\Data\ListingRequirements;
use App\Services\SalesChannels\Data\ListingSubmission;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Data\PublishResult;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Data\RemoteOrder;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Shipments\ChannelShipmentSends;
use App\Services\SalesChannels\Dispatch\ChannelRecipient;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Exceptions\RecipientUnavailable;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
class NoonConnector implements ChannelConnector, ConfirmsShipments, CreatesListings, DescribesListingRequirements, PublishesStockAndPrices, ReadsOrders, ReadsRecipient
{
    public function __construct(
        private readonly NoonClient $client,
        private readonly NoonParentSku $parents,
        private readonly NoonOrderMapper $orders,
        private readonly ChannelShipmentSends $sends,
    )
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
     * It is not a list of missing permissions at noon, and not a list of settings left blank: it is a list of promises THIS MODULE would otherwise be making. A capability
     * noon has not granted shows up as a refusal from noon; a warehouse or country nobody chose shows up on the publish itself. Those are three different problems and the
     * screen is no use if it runs them together.
     *
     * @return list<string>
     */
    private function whatIsNotBuiltYetFor(SalesChannelAccount $account): array
    {
        // Only what this module still cannot serve. Stock, prices, the catalogue, order import and shipments ARE built now, so naming any of them here would tell a person
        // their account is less capable than it is. What is left is not a backlog of ours at all: noon publishes no settlement, payout or fee endpoint ANYWHERE in its
        // index, so no amount of work here can close it, and that is why it reads as noon publishing nothing rather than as something unbuilt.
        $unservable = [
            IntegrationCapability::FinancialReconciliation->value => 'noon_publishes_no_settlement_endpoint',
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


    // ---- the catalogue: putting a product on noon ---------------------------------------------------------------

    /**
     * The attribute names that would give a noon listing an offer. Withheld from every content call by the publisher.
     *
     * noon keeps price and quantity in services of their own — `pricing/v1/pricing/upsert` and `stock/v1/stock-update`, each separately switched and separately approved —
     * so the content upsert has no field for either. These names are listed all the same because `attributes{}` is a free-form map of a CATEGORY's attributes: if a noon
     * category ever carries one called `price`, it must not reach noon through the content call, where no price switch guards it.
     *
     * @return list<string>
     */
    public function offerAttributes(): array
    {
        return array_values(array_filter(array_map('strval', (array) config('sales_channels.listing.offer_attributes.noon', []))));
    }

    /**
     * Creates the catalogue item: `POST /content/v1/product/upsert`.
     *
     * noon decides create from amend BY OUR OWN `partner_sku` — one endpoint for both — so there is no call that can only create. The publisher still calls this one only
     * when the channel holds nothing, and that protection matters more here than at Amazon, not less: see `updateListing()`.
     *
     * `skus`, `brand`, `category`, `images` and `attributes` are each required by noon's schema, so a draft missing any of them is refused HERE rather than sent to be
     * refused there — the message a person then reads is ours and says what to fill in.
     *
     * TWO ANSWERS HAVE TO AGREE. The 200 carries noon's `status` object, the same shape as every per-item status in this module, so an upsert can be taken and refused in one
     * breath; and it carries `sku_parent`, which is the ONLY place noon ever tells us its own code for our product (`NoonParentSku` says why that matters). An upsert whose
     * status is good but which named no `sku_parent` is therefore not a success we can act on: nothing could follow it up.
     */
    public function createListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $attributes): ListingSubmission
    {
        return $this->upsertProduct($account, $draft, $attributes);
    }

    /**
     * Amends the catalogue item — the SAME call as a create, because noon publishes no other.
     *
     * WHETHER AN AMEND MERGES OR REPLACES IS NOT STATED. noon's page says only "Creates or updates a product in the catalog", and no sentence anywhere says what becomes of
     * an attribute the amend does not mention. This module does not build on an unstated behaviour, so it sends the WHOLE attribute set the draft holds rather than the
     * changed fields alone. If noon replaces, the product is left complete; if noon merges, re-sending a field its existing value is harmless. The opposite choice — sending
     * only what changed — is the one that cannot be made safe: against a replace it would empty every attribute a person had not touched.
     *
     * `$changedAttributes` is therefore read as the QUESTION "is there anything to send?" and not as the payload. Nothing changed means nothing is sent.
     */
    public function updateListing(SalesChannelAccount $account, ChannelListingDraft $draft, array $changedAttributes): ListingSubmission
    {
        if ($changedAttributes === []) {
            return ListingSubmission::working(null, 'nothing_changed');
        }

        return $this->upsertProduct($account, $draft, $this->attributesOf($draft));
    }

    /**
     * What noon HOLDS for our SKU: `POST /content/v1/product/content/get`, which reads and changes nothing.
     *
     * It takes noon's `sku_parent` and nothing else — our `partner_sku` is not a key it accepts — so a product we were never given a `sku_parent` for cannot be asked about
     * at all. That answer is UNKNOWN, never ABSENT: "we have no way to ask" read as "noon holds nothing" is precisely what would have the publisher create a second
     * catalogue item for a product noon already lists.
     *
     * What comes back is read as noon's own three judgements, in the order that decides the outcome:
     *
     *   1. `statuses[].errors` or `statuses[].qc.status == QC_STATUS_REJECTED` — refused, with noon's own codes and rejection reasons;
     *   2. `qc.status` PENDING or NOT_ELIGIBLE, or an incomplete `content` — still WORKING. noon's guide says an item cannot enter quality review below 100% completeness,
     *      so an incomplete item is not waiting for noon, it is waiting for us, and the missing attributes come back as the problems;
     *   3. `overall_status == OVERALL_STATUS_ACTIVE` with QC approved — LIVE, with noon's own words carried through.
     *
     * `statuses[]` is one entry PER LANGUAGE (EN, AR). A product is only as live as its worst language, so every entry is read and the first refusal wins; an English
     * listing whose Arabic content noon rejected is not a listing this store can leave alone.
     */
    public function listingFor(SalesChannelAccount $account, string $sku): ListingSubmission
    {
        $partnerSku = trim($sku);
        if ($partnerSku === '') {
            return ListingSubmission::rejected(['A noon listing needs our own partner_sku.']);
        }

        $parent = $this->parents->forPartnerSku($account, $partnerSku);
        if ($parent === null) {
            // noon has no partner_sku -> sku_parent lookup, so there is genuinely nothing to ask. Saying so is the whole point.
            return ListingSubmission::unknown('no_sku_parent_known_for_this_partner_sku');
        }

        $response = $this->client->call(
            $account,
            'noon.product_content_get',
            'POST',
            NoonOperations::paths()['noon.product_content_get'],
            json: ['sku_parent' => $parent],
            capability: IntegrationCapability::ProductSync,
        );
        if ($response->isDryRun()) {
            return ListingSubmission::unknown('dry_run');
        }
        if (!$response->ok()) {
            return ListingSubmission::unknown((string) $response->status);
        }

        return $this->contentState($response, $parent);
    }

    /**
     * Ties our own `partner_sku` values to their barcodes: `POST /catplat/v1/barcode/map`.
     *
     * noon's own words: "This API connects an existing partner_sku and a barcode ... The barcode is created if it doesn't exist in the system already." So this is what makes
     * a warehouse scan mean something at noon, and it is the one call that needs the barcode a product already carries in this store rather than anything a person types.
     *
     * Per-item status in a 200, read the way every batch call here is read: an item noon did not answer about is a failure, and an answer is matched back by the
     * `partner_sku` it echoes rather than by its position.
     *
     * `force_map` is NOT sent. Its own page documents the field as nullable and says nothing whatever about what it does; the Batch SKU map page describes a field of the
     * same name as overriding an existing mapping, but that is a different endpoint and borrowing its meaning would be a guess with a seller's barcodes at stake.
     *
     * @param array<string, string> $barcodesBySku our `partner_sku` => the barcode it carries
     * @return array{mapped: list<string>, failed: array<string, string>, sent: bool}
     */
    public function mapBarcodes(SalesChannelAccount $account, array $barcodesBySku): array
    {
        $items = [];
        $failed = [];
        foreach ($barcodesBySku as $sku => $barcode) {
            $partnerSku = trim((string) $sku);
            $code = trim((string) $barcode);
            if ($partnerSku === '') {
                continue;
            }
            if ($code === '') {
                // A product with no barcode is not an error at noon; it is simply nothing to map, and inventing one would tie a scan to the wrong goods.
                $failed[$partnerSku] = 'this product carries no barcode, so there is nothing to map at noon';
                continue;
            }
            $items[] = ['barcode' => $code, 'partner_sku' => $partnerSku];
        }

        if ($items === []) {
            return ['mapped' => [], 'failed' => $failed, 'sent' => false];
        }

        $response = $this->client->call(
            $account,
            'noon.barcode_map',
            'POST',
            NoonOperations::paths()['noon.barcode_map'],
            json: ['items' => $items],
            capability: IntegrationCapability::ProductSync,
            scope: ChannelOperations::SCOPE_PRODUCT_WRITES,
        );
        if ($response->isDryRun()) {
            return ['mapped' => [], 'failed' => $failed, 'sent' => false];
        }
        if (!$response->ok()) {
            $reason = 'noon refused the whole barcode request (HTTP ' . $response->status . ': ' . $response->message() . '), so no barcode was mapped.';
            foreach ($items as $item) {
                $failed[(string) $item['partner_sku']] = $reason;
            }

            return ['mapped' => [], 'failed' => $failed, 'sent' => true];
        }

        $mapped = [];
        $answered = [];
        foreach ((array) $response->get('items', []) as $item) {
            $partnerSku = trim((string) ((array) $item)['partner_sku'] ?? '');
            if ($partnerSku === '') {
                continue;
            }
            $answered[$partnerSku] = true;
            $status = NoonItemStatus::of((array) $item, $partnerSku);
            if ($status->ok) {
                $mapped[] = $partnerSku;
            } else {
                $failed[$partnerSku] = $status->reason();
            }
        }
        foreach ($items as $item) {
            $partnerSku = (string) $item['partner_sku'];
            if (!isset($answered[$partnerSku])) {
                $failed[$partnerSku] = 'noon did not answer about this barcode, so nothing about it is confirmed.';
            }
        }

        return ['mapped' => $mapped, 'failed' => $failed, 'sent' => true];
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

    // ---- orders fulfilled from OUR warehouse (FBPI) --------------------------------------------------------------

    /**
     * FBPI orders, page by page: `POST /fbpi/v1/fbpi-orders/list`.
     *
     * Three documented properties shape this, and each is a thing to get wrong:
     *
     *  - **ONE WAREHOUSE PER CALL.** `warehouse_code` is required and there is no "all warehouses", so the sweep reads the warehouse the account is set to. An account with
     *    none cannot read orders at all, and says so rather than sending a call noon would refuse.
     *  - **`next_token` IS A QUERY PARAMETER**, not a body field, and its page marks it required while its description says it is only needed after the first page — so an
     *    empty one is sent first, exactly as noon's own samples do.
     *  - **THE FILTERS ARE ON CREATION TIME**, `created_after` / `created_before` in UTC, because noon documents no "last modified" on an FBPI order. So `$since` narrows by
     *    when an order was CREATED. An order that changes long after it was placed is not found again by a narrowed sweep: the webhook and `order()` are what catch that,
     *    and this is written here because it is the limit of what noon's filters can do, not an oversight.
     *
     * WHERE A PAGE RUN ENDS IS NOT DOCUMENTED. noon does not say whether the last page's `next_token` is empty, absent or repeated. So all three end the run, and a token
     * identical to the one just sent ends it too — otherwise an unchanged token would have the sweep ask for the same page for ever.
     *
     * @param array<string, mixed>|null $cursor
     * @return Pages<RemoteOrder>
     */
    public function orders(SalesChannelAccount $account, CarbonInterface $since, ?array $cursor): Pages
    {
        $warehouse = trim((string) $account->setting('warehouse_code', ''));
        if ($warehouse === '') {
            throw new ChannelUnavailableException(
                ChannelUnavailableException::SETTING_MISSING,
                'No noon warehouse is chosen for this account, and noon lists FBPI orders for one named warehouse at a time. Choose the warehouse in the account\'s settings.',
            );
        }

        $sent = (string) ($cursor['next_token'] ?? '');
        $until = $this->instantOf($cursor['until'] ?? null) ?? CarbonImmutable::instance(now());
        $response = $this->client->call(
            $account,
            'noon.fbpi_orders_list',
            'POST',
            NoonOperations::paths()['noon.fbpi_orders_list'],
            // The token rides in the query. Sending it in the body would simply be ignored and every page would be the first one.
            query: ['next_token' => $sent],
            json: [
                'warehouse_code' => $warehouse,
                'created_after' => CarbonImmutable::instance($since)->utc()->format('Y-m-d\TH:i:s\Z'),
                'created_before' => $until->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            capability: IntegrationCapability::OrderImport,
        );
        if ($response->isDryRun()) {
            return new Pages([], null, null, 0);
        }
        if (!$response->ok()) {
            throw new RuntimeException('noon could not list its FBPI orders (HTTP ' . $response->status . ': ' . $response->message() . ').');
        }

        $orders = [];
        $newest = null;
        $skipped = 0;
        foreach ((array) $response->get('orders', []) as $order) {
            if (!is_array($order) || trim((string) ($order['fbpi_order_nr'] ?? '')) === '') {
                $skipped++;
                continue;
            }
            $mapped = $this->orders->map($order, $account);
            $orders[] = $mapped;
            $newest = $mapped->purchasedAt !== null && ($newest === null || $mapped->purchasedAt->gt($newest)) ? $mapped->purchasedAt : $newest;
        }

        $token = trim((string) $response->get('next_token', ''));
        // Empty, absent, or the very token we just sent: each ends the run, because noon documents none of them and a repeat would loop for ever.
        $next = ($token === '' || $token === $sent || $orders === []) ? null : ['next_token' => $token, 'until' => $until->toIso8601String()];

        return new Pages($orders, $next, $newest, $skipped);
    }

    /**
     * One FBPI order: `GET /fbpi/v1/fbpi-order/{fbpi_order_nr}/get`, the order number IN THE PATH.
     *
     * This is what a webhook is followed up with. noon's page says it sends one whenever the marketplace creates an order, and its payload contents are not documented — so
     * nothing is read out of a webhook body here; its order number is used to ask noon directly, and noon's own answer is what is believed.
     *
     * noon has no package or parcel of its own, so `$packageId` has nothing to address and is ignored.
     */
    public function order(SalesChannelAccount $account, string $orderId, ?string $packageId = null): ?RemoteOrder
    {
        $number = trim($orderId);
        if ($number === '') {
            return null;
        }

        $response = $this->client->call(
            $account,
            'noon.fbpi_order_get',
            'GET',
            str_replace('{fbpi_order_nr}', rawurlencode($number), NoonOperations::paths()['noon.fbpi_order_get']),
            capability: IntegrationCapability::OrderImport,
        );
        if ($response->isDryRun() || $response->status === 404) {
            return null;
        }
        if (!$response->ok()) {
            throw new RuntimeException('noon could not be asked about FBPI order ' . $number . ' (HTTP ' . $response->status . ').');
        }

        $body = (array) ($response->json ?? []);
        if (trim((string) ($body['fbpi_order_nr'] ?? '')) === '') {
            return null;
        }

        return $this->orders->map($body, $account);
    }

    /**
     * Who the order goes to: `GET /fbpi/v1/fbpi-order/{fbpi_order_nr}/customer-details/get`.
     *
     * THE ONLY NOON CALL THAT READS A PERSON, and it is gated like no other read here — the buyer-data switch AND a master admin's approval, which `ChannelOperations`
     * enforces before anything leaves.
     *
     * **noon publishes less than a courier needs.** The documented answer is `first_name`, `last_name`, `city` and `administrative_division`. There is NO street address, NO
     * phone number and NO e-mail anywhere in it. So this cannot produce a deliverable recipient, and it does not pretend to: what comes back is assembled honestly and
     * `ChannelRecipient::problems()` then reports the address and phone as missing, which stops the dispatch instead of sending a courier half an address. That is a gap in
     * what noon publishes, not a bug here, and the only honest fixes are outside this code: a documented endpoint that carries an address, or noon's own logistics carrying
     * the parcel (its AWB call exists for exactly that).
     *
     * Nothing read here is logged, kept or put in a snapshot. It is handed to the caller and forgotten.
     */
    public function recipient(SalesChannelAccount $account, ExternalOrderMapping $mapping): ChannelRecipient
    {
        $number = trim((string) $mapping->external_order_id);
        if ($number === '') {
            throw new RecipientUnavailable(RecipientUnavailable::NOT_FOUND);
        }

        $response = $this->client->call(
            $account,
            'noon.fbpi_order_customer_data',
            'GET',
            str_replace('{fbpi_order_nr}', rawurlencode($number), NoonOperations::paths()['noon.fbpi_order_customer_data']),
            capability: IntegrationCapability::OrderImport,
            scope: ChannelOperations::SCOPE_BUYER_DATA_TRANSFER,
        );
        if ($response->isDryRun()) {
            throw new RecipientUnavailable(RecipientUnavailable::NOT_PERMITTED, ['dry_run']);
        }
        if ($response->status === 404) {
            throw new RecipientUnavailable(RecipientUnavailable::NOT_FOUND);
        }
        if (!$response->ok()) {
            throw new RecipientUnavailable(RecipientUnavailable::CHANNEL_ERROR, [(string) $response->status]);
        }

        return new ChannelRecipient(
            name: trim(trim((string) $response->get('first_name', '')) . ' ' . trim((string) $response->get('last_name', ''))),
            // noon documents neither, and an empty string is the truth about them. `problems()` turns that truth into a refusal to dispatch.
            phone: '',
            addressLine: '',
            city: trim((string) $response->get('city', '')),
            state: trim((string) $response->get('administrative_division', '')) ?: null,
            country: strtoupper(trim((string) ($mapping->account?->setting('country_code', 'sa') ?? 'sa'))),
        );
    }

    /**
     * Tells noon that units of an order cannot be supplied: `POST /fbpi/v1/fbpi-order/update`.
     *
     * **THIS CANCELS THOSE UNITS AT NOON.** Its page: "the unavailable items will be canceled on Noon's side, and the rest will proceed to fulfillment". There is no undo
     * documented, and `OUT_OF_STOCK` is the only status the call accepts. So it is never reached from a failed stock read, a timeout or a retry — only from a decision
     * somebody made — and it refuses an empty list rather than sending a call that would mean nothing.
     *
     * Its 200 carries no body, so success is the absence of a refusal. That is weak, and it is why this returns what it actually knows rather than a bare true.
     *
     * @param list<string> $itemNumbers noon's own `mp_item_nr` values
     * @return array{sent: bool, refused: string|null}
     */
    public function cannotSupply(SalesChannelAccount $account, string $orderNumber, array $itemNumbers): array
    {
        $number = trim($orderNumber);
        $items = array_values(array_unique(array_filter(array_map('trim', $itemNumbers), static fn (string $item): bool => $item !== '')));
        if ($number === '' || $items === []) {
            return ['sent' => false, 'refused' => 'An order number and at least one mp_item_nr are needed; noon cancels what this names, so it is never sent on a guess.'];
        }

        $response = $this->client->call(
            $account,
            'noon.fbpi_order_update',
            'POST',
            NoonOperations::paths()['noon.fbpi_order_update'],
            json: [
                'fbpi_order_nr' => $number,
                'items' => array_map(static fn (string $item): array => [
                    'mp_item_nr' => $item,
                    'status' => 'UPDATE_ORDER_REQUEST_ITEM_STATUS_OUT_OF_STOCK',
                ], $items),
            ],
            capability: IntegrationCapability::FulfillmentDispatch,
            scope: 'fulfillment_dispatch',
        );
        if ($response->isDryRun()) {
            return ['sent' => false, 'refused' => null];
        }

        return $response->ok()
            ? ['sent' => true, 'refused' => null]
            : ['sent' => false, 'refused' => 'noon refused the out-of-stock notice (HTTP ' . $response->status . ': ' . $response->message() . '), so nothing was cancelled.'];
    }

    // ---- shipments of an FBPI order ------------------------------------------------------------------------------

    /**
     * Tells noon an order has shipped, through the shared `ConfirmsShipments` contract.
     *
     * The contract speaks in QUANTITIES per line; noon speaks in `mp_item_nr` values, one per unit. The line ids this module produced are the comma-joined unit numbers of
     * the line (see `NoonOrderMapper`), so the units are recovered from them and the first `quantity` of each line's units are the ones named. Within one line every unit is
     * the same SKU of the same order, so which of them is named cannot change what physically ships; it is still a choice this code makes rather than one noon documents,
     * and `createShipment()` is there for a caller that knows the exact unit numbers.
     */
    public function confirmShipment(SalesChannelAccount $account, ShipmentConfirmation $confirmation): bool
    {
        $units = [];
        foreach ($confirmation->lineQuantities as $lineId => $quantity) {
            $ofTheLine = array_values(array_filter(array_map('trim', explode(',', (string) $lineId))));
            foreach (array_slice($ofTheLine, 0, max(0, (int) $quantity)) as $unit) {
                $units[] = $unit;
            }
        }

        $result = $this->createShipment(
            $account,
            $confirmation->orderId,
            $units,
            [['courier' => $confirmation->carrierName ?? $confirmation->carrierCode, 'awb_nr' => $confirmation->trackingNumber]],
        );
        if ($result['busy'] ?? false) {
            // Not a refusal: another process owns this shipment and is finishing it. Raised rather than answered `false`, so
            // that a caller counting the channel's refusals does not count this one and park work that is already in hand.
            throw new ChannelUnavailableException(ChannelUnavailableException::ANOTHER_ATTEMPT_RUNNING, (string) $result['reason']);
        }

        return $result['confirmed'];
    }

    /**
     * Creates the shipment of some units of an FBPI order: `POST /fbpi/v1/shipment/create`.
     *
     * TWO THINGS NOON DOES NOT PUBLISH SHAPE THIS WHOLE METHOD.
     *
     * **Its 200 carries no body at all.** Not a shipment number, not a status, nothing. So "noon answered 2xx" is the only thing a create ever tells us, and that is not
     * enough to record a shipment against an order. The shipment is therefore READ BACK afterwards (`shipment/get`), and `confirmed` is true only when noon itself reports
     * the shipment. A create that was taken but cannot be read back comes back as `sent` without `confirmed`, which is the truth and is what a person has to see.
     *
     * **Nothing is documented about a REPEATED `integration_shipment_nr`.** No idempotency rule, no error, nothing. So a repeat is never assumed safe, and two defences sit
     * around it. First the number is DERIVED from the order and the exact units, so a retry of the same work asks about the same shipment rather than minting a second one
     * for goods already on their way. Then noon is asked whether that number already exists -- and the answer is read as THREE: held (reported as already shipped, nothing
     * sent), held-by-noons-own-word-not-at-all (the only answer a create is made on), or unanswerable. The derived number alone is not a defence, because without a
     * documented idempotency rule re-sending it is not harmless; it is what makes the QUESTION the same question. So when noon cannot be asked -- a 503, a timeout, a reset,
     * a 401, a 2xx nobody could read -- nothing is sent and the attempt comes back `needs_review` for a person, since creating might ship the same goods twice and
     * cancelling a noon shipment cancels every item in it.
     *
     * @param list<string> $unitNumbers noon's own `mp_item_nr` values
     * @param list<array{courier: string, awb_nr: string}> $awbs
     * @return array{sent: bool, confirmed: bool, shipment: string|null, reason: string|null, needs_review?: bool, busy?: bool}
     */
    public function createShipment(SalesChannelAccount $account, string $orderNumber, array $unitNumbers, array $awbs): array
    {
        $warehouse = trim((string) $account->setting('warehouse_code', ''));
        $order = trim($orderNumber);
        $units = array_values(array_unique(array_filter(array_map('trim', $unitNumbers), static fn (string $unit): bool => $unit !== '')));
        $waybills = [];
        foreach ($awbs as $awb) {
            $courier = trim((string) ($awb['courier'] ?? ''));
            $number = trim((string) ($awb['awb_nr'] ?? ''));
            if ($courier !== '' && $number !== '') {
                $waybills[] = ['courier' => $courier, 'awb_nr' => $number];
            }
        }

        // Every one of these is required by noon's schema, and a shipment is the one write here that puts goods on a van: it is refused on anything missing rather than
        // sent half-formed.
        $missing = array_keys(array_filter([
            'warehouse_code' => $warehouse === '',
            'fbpi_order_nr' => $order === '',
            'items' => $units === [],
            'awbs' => $waybills === [],
        ]));
        if ($missing !== []) {
            return ['sent' => false, 'confirmed' => false, 'shipment' => null, 'reason' => 'noon requires ' . implode(', ', $missing) . ' on a shipment, and this one has none.'];
        }

        $shipmentNumber = $this->shipmentNumberFor($order, $units);

        // FIRST, OWNERSHIP. Two processes asking noon at the same instant are both told "no such shipment" and both send, and
        // the derived number does not stop that: noon documents no idempotency for `integration_shipment_nr`, so deriving the
        // same value means the second attempt ASKS about the same shipment, not that re-sending it is harmless. One unique row
        // per shipment reference decides who owns the attempt, and remembers whether a send ever left -- which is the one thing
        // noon cannot be asked about.
        $permission = $this->sends->claim($account, $shipmentNumber, $order);
        if ($permission->isConfirmed()) {
            return ['sent' => false, 'confirmed' => true, 'shipment' => $shipmentNumber, 'reason' => 'noon has already confirmed this shipment, so nothing was sent again.'];
        }
        if ($permission->isBusy()) {
            return [
                'sent' => false, 'confirmed' => false, 'shipment' => $shipmentNumber,
                'reason' => 'another attempt at this very shipment is already running, so nothing was sent. Whatever it does will be the one shipment.',
                'busy' => true,
            ];
        }
        $token = (string) $permission->token;

        // THEN THE THREE ANSWERS, AND ONLY ONE OF THEM PERMITS A CREATE.
        //
        // A shipment noon already holds under this number is the same work, already done. An answer nobody could read is NOT an
        // absence: a 503, a timeout, an auth error or a 2xx we could not parse all leave open the possibility that an earlier
        // attempt reached noon and its answer was lost. Creating on that would ship the same goods twice, and cancelling a noon
        // shipment cancels every item in it.
        //
        // The single exception is a rehearsal, which asked nobody anything and sends nothing either way: it walks on and the
        // write below answers `isDryRun()`.
        $check = $this->checkShipment($account, $shipmentNumber);
        if ($check->isPresent()) {
            $this->sends->confirmed($token);

            return ['sent' => false, 'confirmed' => true, 'shipment' => $shipmentNumber, 'reason' => 'noon already holds this shipment, so nothing was sent again.'];
        }

        // An earlier attempt's send is unaccounted for and noon does not show the shipment. A machine never resolves that by
        // sending again -- not even when noon says, in so many words, that it holds no such shipment: `shipment/get` is not
        // documented to be read-after-write consistent, and the cost of being wrong is a second van.
        if ($permission->sendMayHaveLeft) {
            $this->sends->needsReview($token, $check->mayCreate() ? 'sent_but_noon_shows_no_shipment' : 'sent_and_noon_could_not_be_asked');

            return [
                'sent' => false, 'confirmed' => false, 'shipment' => $shipmentNumber,
                'reason' => 'a send for this shipment already left and noon does not confirm it ('
                    . ($check->mayCreate() ? 'noon says it holds no such shipment' : (string) $check->reason)
                    . '), so nothing was sent again. A person has to settle it: sending now might ship the same goods twice.',
                'needs_review' => true,
            ];
        }

        if (!$check->mayCreate() && !$check->isRehearsal()) {
            // Nothing left, so the reference stays claimable: the next cycle asks noon again rather than needing a person to
            // release it. The attempt itself is still reported for review, because goods are waiting and nobody shipped them.
            $this->sends->nothingWasSent($token, (string) $check->reason);

            return [
                'sent' => false,
                'confirmed' => false,
                'shipment' => $shipmentNumber,
                'reason' => 'noon could not say whether it already holds this shipment (' . $check->reason . '), so nothing was sent. A person has to resolve it:'
                    . ' creating it now might ship the same goods twice, and noon publishes no way to make that safe.',
                'needs_review' => true,
            ];
        }

        // THE FENCE, AND IT GOES BEFORE THE REQUEST.
        //
        // From here the row durably says a create may be at noon, so a process that dies at ANY later instant -- including
        // between the socket write and the next statement -- leaves a row that forbids a second send. Marking it after the call
        // returned left a window of exactly that shape: the goods on their way, and the database still saying nothing had been
        // sent. Its lease would then expire and the next worker would ask noon, read a 404 and ship the same units again.
        //
        // It is pessimistic on purpose. Everything below that can PROVE noon did not process the request resets it.
        //
        // **AND THE FENCE CAN FAIL, WHICH ABORTS THE SEND.** It is a compare-and-set: it affects nothing when this attempt no
        // longer owns the reference, because another worker took it over or because this worker's own lease lapsed while it was
        // paused -- the existence check above is a network call, and a worker can lose minutes in one. A worker that cannot
        // fence cannot send: its create would leave this host with no durable record that it had, which is exactly the hole the
        // fence closes. So nothing is sent, and whoever holds the row now is the one shipment.
        if (!$this->sends->aboutToSend($token, 'noon_create_in_flight')) {
            return [
                'sent' => false, 'confirmed' => false, 'shipment' => $shipmentNumber,
                'reason' => 'this attempt no longer owns the shipment -- another one holds it, or this one was paused past its'
                    . ' claim -- so nothing was sent. Whatever the holder does will be the one shipment.',
                'busy' => true,
            ];
        }

        try {
            $response = $this->client->call(
                $account,
                'noon.shipment_create',
                'POST',
                NoonOperations::paths()['noon.shipment_create'],
                json: [
                    'warehouse_code' => $warehouse,
                    'integration_shipment_nr' => $shipmentNumber,
                    'fbpi_order_nr' => $order,
                    'awbs' => $waybills,
                    'items' => array_map(static fn (string $unit): array => ['mp_item_nr' => $unit], $units),
                ],
                capability: IntegrationCapability::FulfillmentDispatch,
                scope: 'fulfillment_dispatch',
            );
        } catch (ChannelUnavailableException $gate) {
            // A gate refuses BEFORE anything leaves this host -- a switch, a capability, a missing approval -- so nothing was
            // sent and the fence is lifted. Without this, turning a switch off would park a shipment for a person.
            $this->sends->nothingWasSent($token, $gate->reason);

            throw $gate;
        }

        if ($response->isDryRun()) {
            $this->sends->nothingWasSent($token, 'dry_run');

            return ['sent' => false, 'confirmed' => false, 'shipment' => null, 'reason' => null];
        }
        if (!$response->ok()) {
            // THE ONE ROAD BACK FROM THE FENCE, and it takes proof rather than an absence of bad news. `ChannelResponse`
            // documents a 401, a 403, a 429 and every other 4xx as refused BEFORE processing, and a 5xx or a lost answer as
            // cases where the channel may have acted. Only the first group clears the row for another send.
            $mayHaveLanded = in_array($response->kind, [ChannelResponse::SERVER_ERROR, ChannelResponse::AMBIGUOUS], true);
            $mayHaveLanded
                ? $this->sends->releaseAfterUncertainSend($token, 'noon_create_outcome_unknown:' . ($response->status ?? 0))
                : $this->sends->nothingWasSent($token, 'noon_refused:' . ($response->status ?? 0));

            return [
                'sent' => false,
                'confirmed' => false,
                'shipment' => $shipmentNumber,
                // An ambiguous failure may have reached noon. It is NOT reported as nothing-happened, and it is NOT retried: the claim above now says a send may have
                // left, so the next attempt derives the same number, ASKS, and hands it to a person if noon does not show the shipment.
                // Only the two kinds that genuinely leave doubt, named rather than inferred: ChannelResponse documents a 5xx as "the channel may have acted" for a write
                // and an ambiguous result as a timeout or reset AFTER the request went. A 4xx is a definitive no and must not be dressed up as a maybe --
                // `certainlyNotProcessed()` does not cover it, so negating that helper would warn about every refusal.
                'reason' => 'noon refused the shipment (HTTP ' . $response->status . ': ' . $response->message() . ').'
                    . ($mayHaveLanded
                        ? ' The request may still have reached noon; the next attempt asks about the same shipment number instead of sending, and a person settles it if noon does not show it.'
                        : ''),
            ];
        }

        // It certainly left -- which the fence above already recorded, before it did.

        // The 200 said nothing, so noon is asked what it holds. Only its own answer confirms a shipment.
        $after = $this->checkShipment($account, $shipmentNumber);
        if ($after->isPresent()) {
            $this->sends->confirmed($token);

            return ['sent' => true, 'confirmed' => true, 'shipment' => $shipmentNumber, 'reason' => null];
        }
        $after->isUnknown()
            ? $this->sends->needsReview($token, (string) $after->reason)
            // noon ANSWERED, and says it does not hold the shipment yet. That is a lag, not a doubt, so no person is called for
            // -- but the attempt is over, so the claim goes back while the row keeps saying a send has left. Leaving the claim
            // held here would park the reference until its lease ran out, for nothing.
            : $this->sends->releaseAfterUncertainSend($token, 'noon_does_not_report_the_shipment_yet');

        return [
            'sent' => true,
            'confirmed' => false,
            'shipment' => $shipmentNumber,
            // Two different situations, and the second one is not for a machine to retry: noon took the create and then could
            // not be asked about it, so whether the shipment exists is open. The next attempt asks again rather than sending.
            'reason' => $after->mayCreate()
                ? 'noon took the shipment but does not report it yet, so nothing is recorded as shipped.'
                : 'noon took the shipment and then could not be asked about it (' . $after->reason . '), so whether it exists is unresolved. A person has to check before anything is sent again.',
            'needs_review' => $after->isUnknown(),
        ];
    }

    /**
     * Whether noon holds a shipment under one of our numbers: `POST /fbpi/v1/shipment/get`, as one of three answers.
     *
     * This is the only way a create is ever verified, because a create answers no body; and it is the only thing that may permit
     * one, because `NoonShipmentCheck` keeps "noon says it holds none" apart from "noon could not be asked". It reads and
     * changes nothing.
     */
    public function checkShipment(SalesChannelAccount $account, string $shipmentNumber): NoonShipmentCheck
    {
        $number = trim($shipmentNumber);
        $warehouse = trim((string) $account->setting('warehouse_code', ''));
        if ($number === '' || $warehouse === '') {
            // We cannot even ask, so we know nothing. This used to answer the same null as a successful "not there", which made
            // a blank warehouse look like permission to ship.
            return NoonShipmentCheck::unknown('noon_cannot_be_asked_without_a_shipment_number_and_a_warehouse');
        }

        $response = $this->client->call(
            $account,
            'noon.shipment_get',
            'POST',
            NoonOperations::paths()['noon.shipment_get'],
            json: ['warehouse_code' => $warehouse, 'integration_shipment_nr' => $number],
            capability: IntegrationCapability::OrderImport,
        );

        return NoonShipmentCheck::of($response);
    }

    /**
     * What noon holds for one of our shipment numbers, or null when it holds none AND SAID SO.
     *
     * Kept for callers that only want the shipment itself. It answers null for a confirmed absence and for an unknown alike, so
     * **it must not be used to decide whether to create**: `checkShipment()` is what distinguishes those, and `createShipment()`
     * uses that. A null here means "no shipment to show you", never "go ahead".
     *
     * @return array{fbpi_order_nr: string, awbs: list<array<string, mixed>>, items: list<array<string, mixed>>}|null
     */
    public function shipment(SalesChannelAccount $account, string $shipmentNumber): ?array
    {
        return $this->checkShipment($account, $shipmentNumber)->shipment;
    }

    /**
     * Cancels a shipment at noon: `POST /fbpi/v1/shipment/cancel`.
     *
     * **IT CANCELS EVERY ITEM IN THE SHIPMENT**, in noon's own words, not merely the shipment record. So it is a decision somebody makes and never a way of retrying a
     * create: a failed create is retried by asking noon about the same derived number first, and sending again only if noon itself says it holds no such shipment.
     *
     * @return array{sent: bool, reason: string|null}
     */
    public function cancelShipment(SalesChannelAccount $account, string $shipmentNumber): array
    {
        $number = trim($shipmentNumber);
        $warehouse = trim((string) $account->setting('warehouse_code', ''));
        if ($number === '' || $warehouse === '') {
            return ['sent' => false, 'reason' => 'A shipment number and the account\'s warehouse are both needed, and cancelling takes every item of the shipment with it.'];
        }

        $response = $this->client->call(
            $account,
            'noon.shipment_cancel',
            'POST',
            NoonOperations::paths()['noon.shipment_cancel'],
            json: ['warehouse_code' => $warehouse, 'integration_shipment_nr' => $number],
            capability: IntegrationCapability::FulfillmentDispatch,
            scope: 'fulfillment_dispatch',
        );
        if ($response->isDryRun()) {
            return ['sent' => false, 'reason' => null];
        }

        return $response->ok()
            ? ['sent' => true, 'reason' => null]
            : ['sent' => false, 'reason' => 'noon refused the cancellation (HTTP ' . $response->status . ': ' . $response->message() . '), so the shipment stands.'];
    }

    /**
     * Adds a courier and waybill to a shipment noon already holds: `POST /fbpi/v1/shipment/courier-awbs/add`.
     *
     * Its page gives the reason it exists: the warehouse could not scan one at the time. Its 200 carries no body, so the shipment is read back and the waybill looked for by
     * number -- the same rule as a create, for the same reason.
     *
     * @param list<array{courier: string, awb_nr: string}> $awbs
     * @return array{sent: bool, confirmed: bool, reason: string|null}
     */
    public function addWaybills(SalesChannelAccount $account, string $shipmentNumber, array $awbs): array
    {
        $number = trim($shipmentNumber);
        $warehouse = trim((string) $account->setting('warehouse_code', ''));
        $waybills = [];
        foreach ($awbs as $awb) {
            $courier = trim((string) ($awb['courier'] ?? ''));
            $awbNumber = trim((string) ($awb['awb_nr'] ?? ''));
            if ($courier !== '' && $awbNumber !== '') {
                $waybills[] = ['courier' => $courier, 'awb_nr' => $awbNumber];
            }
        }
        if ($number === '' || $warehouse === '' || $waybills === []) {
            return ['sent' => false, 'confirmed' => false, 'reason' => 'A shipment number, the account\'s warehouse and at least one courier with a waybill are needed.'];
        }

        $response = $this->client->call(
            $account,
            'noon.shipment_awbs_add',
            'POST',
            NoonOperations::paths()['noon.shipment_awbs_add'],
            json: ['warehouse_code' => $warehouse, 'integration_shipment_nr' => $number, 'awbs' => $waybills],
            capability: IntegrationCapability::FulfillmentDispatch,
            scope: 'fulfillment_dispatch',
        );
        if ($response->isDryRun()) {
            return ['sent' => false, 'confirmed' => false, 'reason' => null];
        }
        if (!$response->ok()) {
            return ['sent' => false, 'confirmed' => false, 'reason' => 'noon refused the waybill (HTTP ' . $response->status . ': ' . $response->message() . ').'];
        }

        // The same distinction as the create, for the same reason: a read that failed says nothing, and reporting it as "noon
        // does not show the waybill yet" would be reporting an outage as noon's answer.
        $held = $this->checkShipment($account, $number);
        $wanted = array_column($waybills, 'awb_nr');
        $there = array_values(array_filter(array_map(static fn (array $awb): string => trim((string) ($awb['awb_nr'] ?? '')), $held->shipment['awbs'] ?? [])));
        $missing = array_diff($wanted, $there);

        return [
            'sent' => true,
            'confirmed' => $held->isPresent() && $missing === [],
            'reason' => match (true) {
                $held->isPresent() && $missing === [] => null,
                $held->isUnknown() => 'noon took the waybill and then could not be asked about the shipment (' . $held->reason . '), so it is not recorded as carried.',
                default => 'noon took the waybill but does not report it on the shipment yet.',
            },
        ];
    }

    /**
     * Asks noon's own logistics for waybill numbers: `POST /fbpi/v1/shipment/noon-logistics-awbs/get`.
     *
     * **IT MINTS NUMBERS.** Each call hands out new ones and there is no documented way to list the numbers already issued, so a repeat does not return what the last call
     * returned -- it burns more. That is why it is never retried blindly, never called to find out whether the connection works, and why it refuses a quantity above the
     * documented ceiling of 1 000 here rather than finding out what noon does with one.
     *
     * @return list<string>
     */
    public function noonWaybills(SalesChannelAccount $account, int $quantity, ?string $countryCode = null): array
    {
        $wanted = $quantity;
        $country = mb_strtolower(trim($countryCode ?? (string) $account->setting('country_code', '')));
        if ($wanted < 1 || $wanted > 1000 || $country === '') {
            throw new RuntimeException('noon issues between 1 and 1 000 waybills for one named country, and this call would have asked for ' . $wanted . ($country === '' ? ' with no country' : ' for ' . $country) . '.');
        }

        $response = $this->client->call(
            $account,
            'noon.noon_logistics_awbs_get',
            'POST',
            NoonOperations::paths()['noon.noon_logistics_awbs_get'],
            json: ['country_code' => $country, 'qty' => $wanted],
            capability: IntegrationCapability::FulfillmentDispatch,
            scope: 'fulfillment_dispatch',
        );
        if ($response->isDryRun()) {
            return [];
        }
        if (!$response->ok()) {
            // Nothing is retried here by this method: numbers may have been issued and lost, and only a person can decide to ask again.
            throw new RuntimeException('noon would not issue waybills (HTTP ' . $response->status . ': ' . $response->message() . ').');
        }

        return array_values(array_filter(array_map(
            static fn ($number): string => trim((string) $number),
            (array) $response->get('awb_nrs', []),
        ), static fn (string $number): bool => $number !== ''));
    }

    /**
     * Our own shipment number, derived so that the SAME work always produces the SAME number.
     *
     * noon requires `integration_shipment_nr` to be unique and documents nothing about a repeat, so a number minted afresh on a retry would create a second shipment for
     * goods already on their way. A hash of the order and its exact units cannot do that: a retry of the same work derives the same number, so the existence check and the
     * claim are about the SAME shipment. Shipping DIFFERENT units of the same order gives a different number, which is right -- that really is a second shipment.
     *
     * **It is not a defence on its own**, which is what made this method's own comment wrong until 2026-11: without a documented idempotency rule, an identical number makes
     * the question the same question and nothing more. What the number is sent WITH -- noon's own word that it holds no such shipment, and one claimed row saying no send has
     * left -- is the defence.
     *
     * Public because it is the reference this system records a shipment against (`channel_shipment_sends.reference`), so an admin screen and a caller holding a claim both
     * need to be able to name it.
     *
     * @param list<string> $units
     */
    public function shipmentNumberFor(string $orderNumber, array $units): string
    {
        $sorted = $units;
        // Sorted, so that the same units named in another order are still the same shipment.
        sort($sorted);

        return 'PK-' . mb_substr(hash('sha256', $orderNumber . '|' . implode('|', $sorted)), 0, 24);
    }

    // ---- returns -------------------------------------------------------------------------------------------------

    /**
     * What a returned parcel's barcode belongs to: `POST /returns/v1/return-references/list`.
     *
     * **THIS IS NOON'S ONLY DOCUMENTED RETURNS OPERATION, AND IT IS A LOOKUP, NOT A SWEEP.** There is no "list the returns since Tuesday" anywhere in noon's index, so a
     * noon return CANNOT be polled for and is never discovered by a scheduled run. It is recognised at the moment a parcel is scanned: a barcode goes in, and the purchase
     * items it belongs to come out. Anything in this application that wants to know about noon returns has to be driven by that scan.
     *
     * Note the path carries NO `/fbpi` segment: returns are their own service at noon, not part of the FBPI family.
     *
     * `merchant_codes` is marked both required and optional on the same page — a contradiction in noon's own documentation. It is sent when the account names merchants and
     * omitted otherwise, which is what the description says happens by default (the results are scoped to the project code). noon's ceiling of 200 is applied here.
     *
     * **WHAT THIS CANNOT TELL YOU, and nothing here pretends otherwise:** whether the return was accepted or refused, what it is worth, what noon charged for it, and who
     * bore the cost. None of that is in the answer and noon publishes no endpoint that carries it. `OperationalCosts` is therefore given NO figure for a noon return, and
     * the decision to accept or refuse one is not made here either — noon's own documentation does not even settle who makes it: the FBPI overview's comparison table says
     * the seller while its prose on the same page says noon after pickup, and the Returns reference does not mention FBPI at all.
     *
     * @param list<string> $merchantCodes
     * @return list<array{mp_code: string, purchase_item_nr: string, partner_sku: string, merchant_code: string, order_nr: string, references: list<array{barcode: string, barcode_type: string}>}>
     */
    public function returnReferencesFor(SalesChannelAccount $account, string $barcode, array $merchantCodes = []): array
    {
        $code = trim($barcode);
        if ($code === '') {
            return [];
        }

        $merchants = array_slice(array_values(array_unique(array_filter(array_map('trim', $merchantCodes), static fn (string $merchant): bool => $merchant !== ''))), 0, 200);
        $response = $this->client->call(
            $account,
            'noon.return_references_list',
            'POST',
            NoonOperations::paths()['noon.return_references_list'],
            json: $merchants === [] ? ['barcode' => $code] : ['barcode' => $code, 'merchant_codes' => $merchants],
            capability: IntegrationCapability::OrderImport,
        );
        if (!$response->ok()) {
            // A lookup that failed is NOT "this barcode belongs to no return". A warehouse that read it that way would put returned goods back on the shelf as unsold.
            throw new RuntimeException('noon could not be asked about the barcode on this return (HTTP ' . $response->status . ': ' . $response->message() . ').');
        }

        $found = [];
        foreach ((array) $response->get('items', []) as $item) {
            if (!is_array($item) || trim((string) ($item['purchase_item_nr'] ?? '')) === '') {
                continue;
            }
            $references = [];
            foreach ((array) ($item['references'] ?? []) as $reference) {
                $referenceBarcode = trim((string) ((array) $reference)['barcode'] ?? '');
                if ($referenceBarcode !== '') {
                    $references[] = ['barcode' => $referenceBarcode, 'barcode_type' => trim((string) (((array) $reference)['barcode_type'] ?? ''))];
                }
            }

            $found[] = [
                'mp_code' => trim((string) ($item['mp_code'] ?? '')),
                'purchase_item_nr' => trim((string) $item['purchase_item_nr']),
                'partner_sku' => trim((string) ($item['partner_sku'] ?? '')),
                'merchant_code' => trim((string) ($item['merchant_code'] ?? '')),
                'order_nr' => trim((string) ($item['order_nr'] ?? '')),
                'references' => $references,
            ];
        }

        return $found;
    }

    /**
     * The one call behind both create and amend.
     *
     * @param array<string, mixed> $attributes the category's attributes, already without any offer attribute
     */
    private function upsertProduct(SalesChannelAccount $account, ChannelListingDraft $draft, array $attributes): ListingSubmission
    {
        $payload = (array) ($draft->payload ?? []);
        $partnerSku = trim((string) ($payload['sku'] ?? ''));
        $category = trim((string) ($draft->product_type ?? ''));
        $brand = trim((string) ($payload['brand'] ?? ''));
        $images = $this->images($payload);

        // Each of these is required by noon's own schema. Refusing here rather than there means the person reads our sentence about what to fill in, not a gateway's.
        $missing = array_keys(array_filter([
            'partner_sku' => $partnerSku === '',
            'category' => $category === '',
            'brand' => $brand === '',
            'images' => $images === [],
            'attributes' => $attributes === [],
        ]));
        if ($missing !== []) {
            return ListingSubmission::rejected(['noon requires ' . implode(', ', $missing) . ' on every catalogue item, and this draft has none.']);
        }

        $response = $this->client->call(
            $account,
            'noon.product_upsert',
            'POST',
            NoonOperations::paths()['noon.product_upsert'],
            json: [
                // One entry per variation. `size` is noon's only variation axis on this call and is omitted rather than sent empty, because its own schema calls it nullable
                // and a blank size is not the same as no size.
                'skus' => [array_filter(['partner_sku' => $partnerSku, 'size' => trim((string) ($payload['size'] ?? ''))], static fn (string $value): bool => $value !== '')],
                'brand' => $brand,
                'category' => $category,
                'images' => $images,
                'attributes' => $attributes,
            ],
            capability: IntegrationCapability::ProductSync,
            scope: ChannelOperations::SCOPE_PRODUCT_WRITES,
        );
        if ($response->isDryRun()) {
            return ListingSubmission::unknown('dry_run');
        }
        if (!$response->ok()) {
            return ListingSubmission::rejected(['noon refused the catalogue item (HTTP ' . $response->status . ': ' . $response->message() . ').'], channelStatus: (string) $response->status);
        }

        // The 200's own `status` is the same object as every per-item status in this module, so a request that was processed can still have refused the product.
        $status = NoonItemStatus::of(['status' => (array) $response->get('status', [])], $partnerSku);
        if (!$status->ok && $response->get('status') !== null) {
            return ListingSubmission::rejected([$status->reason()], channelStatus: $status->code);
        }

        $parent = trim((string) $response->get('sku_parent', ''));
        if ($parent === '') {
            // Taken, perhaps, but unfollowable: `sku_parent` is the only key `content/get` accepts and noon offers no way to look one up later. Reporting this as accepted
            // would leave a product nobody can ever ask about again.
            return ListingSubmission::rejected(['noon accepted the catalogue item but named no sku_parent, so nothing could ever be asked about it again.'], channelStatus: 'no_sku_parent');
        }

        return ListingSubmission::acceptedForProcessing($parent, 'upserted');
    }

    /**
     * noon's `images[]`: a url and a `sort` position, both required by its schema.
     *
     * The draft's own images are used in the order a person put them in; `sort` is that position and is not read from the draft, because two images sharing a sort value is
     * noon's problem to refuse and ours to avoid.
     *
     * @param array<string, mixed> $payload
     * @return list<array{url: string, sort: int}>
     */
    private function images(array $payload): array
    {
        $images = [];
        foreach ((array) ($payload['images'] ?? []) as $image) {
            $url = trim((string) (is_array($image) ? ($image['url'] ?? '') : $image));
            if ($url !== '') {
                $images[] = ['url' => $url, 'sort' => count($images) + 1];
            }
        }

        return $images;
    }

    /**
     * The whole attribute set a draft holds, with every offer attribute taken out.
     *
     * The publisher does this filtering for the attributes it passes in; an amend sends the complete set instead (see `updateListing()`), so it has to do the same filtering
     * itself or an offer field would reach noon through the one call no price switch guards.
     *
     * @return array<string, mixed>
     */
    private function attributesOf(ChannelListingDraft $draft): array
    {
        $payload = (array) ($draft->payload ?? []);
        $attributes = (array) ($payload['attributes'] ?? []);
        if ($attributes === []) {
            $attributes = array_diff_key($payload, array_flip(['sku', 'product_type', 'attributes', 'brand', 'images', 'size']));
        }

        return array_diff_key($attributes, array_flip($this->offerAttributes()));
    }

    /**
     * What noon's content state means for the listing, read language by language.
     *
     * The order of the three judgements is the whole of it: a refusal outranks an unfinished item, and an unfinished item outranks an active one. Taking `overall_status`
     * first would report a listing live while noon's quality review was still rejecting its Arabic content.
     */
    private function contentState(ChannelResponse $response, string $parent): ListingSubmission
    {
        $statuses = array_values(array_filter((array) $response->get('statuses', []), 'is_array'));
        if ($statuses === []) {
            // A 200 with no `statuses[]` settles nothing: noon holds the sku_parent, so this is not an absence, and we have no judgement to act on either.
            return ListingSubmission::working(null, 'no_statuses_from_noon');
        }

        $problems = [];
        $rejected = false;
        $incomplete = false;
        $words = [];

        foreach ($statuses as $entry) {
            $language = $this->shortEnum((string) ($entry['language'] ?? ''), 'LANGUAGE_');
            $content = (array) ($entry['content'] ?? []);
            $qc = (array) ($entry['qc'] ?? []);
            $qcStatus = $this->shortEnum((string) ($qc['status'] ?? ''), 'QC_STATUS_');
            $overall = $this->shortEnum((string) ($entry['overall_status'] ?? ''), 'OVERALL_STATUS_');

            foreach ((array) ($entry['errors'] ?? []) as $error) {
                $problems[] = $language . ': ' . $this->codeAndMessage((array) $error);
                $rejected = true;
            }
            if ($qcStatus === 'REJECTED') {
                $rejected = true;
                foreach ((array) ($qc['rejection_reasons'] ?? []) as $reason) {
                    $problems[] = $language . ': ' . (string) $reason;
                }
                $comment = trim((string) ($qc['comment'] ?? ''));
                if ($comment !== '') {
                    $problems[] = $language . ': ' . $comment;
                }
            }

            // noon's guide: an item cannot enter quality review below 100% completeness. So a missing attribute is not noon being slow, it is us not having sent something.
            foreach (['missing_attributes' => 'missing', 'invalid_attributes' => 'invalid'] as $field => $what) {
                foreach ((array) ($content[$field] ?? []) as $attribute) {
                    $problems[] = $language . ': ' . $what . ' ' . (string) $attribute;
                    $incomplete = true;
                }
            }
            if (in_array($qcStatus, ['PENDING', 'NOT_ELIGIBLE', 'UNSPECIFIED', ''], true) || $overall !== 'ACTIVE') {
                $incomplete = true;
            }

            $words = array_merge($words, array_filter([
                $language === '' ? null : $language . '_qc_' . ($qcStatus === '' ? 'unspecified' : mb_strtolower($qcStatus)),
                $language === '' ? null : $language . '_' . ($overall === '' ? 'unspecified' : mb_strtolower($overall)),
                trim((string) ($content['completeness'] ?? '')) === '' ? null : $language . '_complete_' . trim((string) $content['completeness']),
            ]));
        }

        $problems = array_values(array_unique($problems));

        return match (true) {
            $rejected => ListingSubmission::rejected($problems !== [] ? $problems : ['noon rejected this product and gave no reason we could read.'], $parent, 'qc_rejected'),
            // Waiting on noon, or waiting on us: either way nothing is sent again on the strength of it. The problems say which.
            $incomplete => new ListingSubmission(ListingSubmission::WORKING, $parent, $problems, implode(',', $words) ?: null, null, $words),
            default => ListingSubmission::live($parent, $words, $parent),
        };
    }

    private function instantOf(mixed $value): ?CarbonImmutable
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : CarbonImmutable::parse($text);
    }

    /** noon writes its enums in full (`QC_STATUS_APPROVED`); the part that carries the meaning is what a person reads. */
    private function shortEnum(string $value, string $prefix): string
    {
        return str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }

    /** @param array<string, mixed> $issue */
    private function codeAndMessage(array $issue): string
    {
        $code = trim((string) ($issue['code'] ?? ''));
        $message = trim((string) ($issue['message'] ?? ''));

        return trim($code . ($message === '' ? '' : ': ' . $message)) ?: 'unreadable_issue_from_noon';
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
            'catalog_product_upsert' => ['documented' => true, 'built' => true, 'note' => 'POST /content/v1/product/upsert. skus[] (partner_sku required, size nullable), brand, category, images[] (url, sort) and an attributes map are EVERY ONE required by noon\'s schema; it answers sku_parent, variants[] (sku, partner_sku, psku_code, size) and a status object of the same shape as a per-item status -- so a 200 can still be a refusal. noon decides create from amend by our own partner_sku and offers no create-only call. WHETHER AN AMEND MERGES OR REPLACES ITS ATTRIBUTES IS NOT STATED on its page, so an amend sends the WHOLE attribute set rather than the changed fields: against a replace, sending only what changed would empty every attribute nobody touched. UNRESOLVED: what-is-fbpi says an item must be listed in Seller Lab and cannot be created via API, while the Content API page names no fulfilment model and no page states a role model -- so whether an FBPI seller may call this is settled only by a real account\'s roles.'],
            'catalog_content_state' => ['documented' => true, 'built' => true, 'note' => 'POST /content/v1/product/content/get, and it takes sku_parent ONLY -- our partner_sku is not a key it accepts, and noon publishes NO operation that looks a sku_parent up from a partner_sku. So the pair is remembered from the upsert that answered it (see NoonParentSku), and a product we hold no sku_parent for is UNKNOWN rather than absent. The answer carries statuses[] once PER LANGUAGE (EN, AR) with content.completeness, content.missing_attributes, content.invalid_attributes, qc.status (APPROVED/REJECTED/PENDING/NOT_ELIGIBLE), qc.rejection_reasons, qc.comment, overall_status and errors[]; plus images[] with visibility, review_status and issues[]. A product is only as live as its worst language, so every entry is read and a refusal in any of them wins.'],
            'barcode_map' => ['documented' => true, 'built' => true, 'note' => 'POST /catplat/v1/barcode/map, body items[] (barcode, partner_sku, force_map nullable), answering items[] (barcode, partner_sku, status) with per-item status in a 200. noon\'s own words: the barcode is created if it does not exist already. force_map is NOT sent: its page documents the field and says nothing whatever about what it does, and the identically named field on the Batch SKU map page is a different endpoint whose meaning would be a guess with a seller\'s barcodes at stake.'],
            'product_groups' => ['documented' => true, 'built' => false, 'note' => 'POST /content/v1/product/group/upsert groups sku_parents under shared attribute_codes, and its lists are full replacements whenever it is amended. NOT BUILT YET: nothing here groups catalogue items.'],
            'fbpi_orders' => ['documented' => true, 'built' => true, 'note' => 'POST /fbpi/v1/fbpi-orders/list for ONE named warehouse (there is no all-warehouses call), pages of up to 50, continued with next_token as a QUERY parameter, and filtered by created_after / created_before in UTC. Each order carries fbpi_order_nr, mp_code (noon or namshi_v2), mp_order_nr, mp_country_code, customer_country_code, merchant_code, currency_code, warehouse_code, order_created_at and items[] of mp_item_nr, partner_sku, mp_status, integration_status, delivered_invoice_price, cancellation_reason_code and estimated_shipping_date. THREE GAPS IN WHAT NOON PUBLISHES, all of them handled rather than papered over: an item has NO quantity field, so one items[] entry is read as one unit and entries sharing a partner_sku become one line (recorded as UNVERIFIED); an order has NO status, so the order\'s state is derived from its items and externalStatus carries the real values it came from; and there is NO order total, shipping, tax, discount, payment method or cash-on-delivery flag -- the only money field is delivered_invoice_price, which is a DELIVERED figure a fresh order does not have. Nothing is estimated from any of that. There is also no last-modified on an order, so a sweep can only narrow by creation time.'],
            'fbpi_order_read' => ['documented' => true, 'built' => true, 'note' => 'GET /fbpi/v1/fbpi-order/{fbpi_order_nr}/get, the number in the PATH, answering the same order object as the list and unwrapped. Its page says noon sends a webhook whenever the marketplace creates an order but does not document that webhook\'s payload, so nothing is read out of a webhook body: its order number is used to ask noon, and noon\'s own answer is what is believed. This is also the only way to see an order that changed long after it was created, which the creation-time filters cannot find again.'],
            'fbpi_buyer_data' => ['documented' => true, 'built' => true, 'note' => 'GET /fbpi/v1/fbpi-order/{fbpi_order_nr}/customer-details/get, the ONLY noon call that reads a person, behind the buyer-data switch AND a master admin\'s approval that no other noon read needs. NOON PUBLISHES LESS THAN A COURIER NEEDS: the documented answer is first_name, last_name, city and administrative_division, with NO street address, NO phone and NO e-mail. So this cannot produce a deliverable recipient and does not pretend to -- what comes back is assembled honestly and ChannelRecipient::problems() then reports the address and phone as missing, which stops the dispatch instead of sending a courier half an address. Closing that needs something outside this code: an endpoint that carries an address, or noon\'s own logistics carrying the parcel.'],
            'fbpi_order_item_refusal' => ['documented' => true, 'built' => true, 'note' => 'POST /fbpi/v1/fbpi-order/update marks items OUT_OF_STOCK, which is the only status it accepts; noon then CANCELS those units and lets the rest proceed to fulfilment. There is no documented undo, so it is never reached from a failed stock read, a timeout or a retry -- only from a decision somebody made -- and an empty item list is refused rather than sent. Its 200 carries no body, so success is the absence of a refusal and the method returns what it actually knows rather than a bare true.'],
            'shipments' => ['documented' => true, 'built' => true, 'note' => 'POST /fbpi/v1/shipment/create with warehouse_code, a unique integration_shipment_nr, the fbpi_order_nr, awbs[] (courier, awb_nr) and items[] (mp_item_nr) -- every field required. TWO SILENCES SHAPE THE WHOLE IMPLEMENTATION. Its 200 CARRIES NO BODY AT ALL, so a 2xx is the only thing a create ever says and it is not enough to record a shipment: the shipment is read back with shipment/get and confirmed only by noon\'s own answer. And NOTHING is documented about a repeated integration_shipment_nr -- no idempotency rule, no error -- so the number is DERIVED from the order and its exact units (a retry of the same work sends the same number, different units a different one), and noon is asked whether it already holds that number before anything is sent. Together those turn an ambiguous failure from a risk of shipping twice into a question with an answer.'],
            'shipment_lifecycle' => ['documented' => true, 'built' => true, 'note' => 'POST /fbpi/v1/shipment/get (warehouse_code, integration_shipment_nr) answers fbpi_order_nr, awbs[] and items[] (mp_item_nr, partner_sku): the only way a create is verified, and a FAILED read is never read as an absence -- that would be what creates a shipment twice. POST /fbpi/v1/shipment/cancel cancels the shipment AND EVERY ITEM IN IT in noon\'s own words, so it is a decision somebody makes and never a way of retrying a create. POST /fbpi/v1/shipment/courier-awbs/add adds a courier and waybill where the warehouse could not scan one; its 200 is empty too, so the shipment is read back and the waybill looked for by number. Listing couriers is not documented anywhere; the only described value is noon or our own provider.'],
            'noon_logistics_awbs' => ['documented' => true, 'built' => true, 'note' => 'POST /fbpi/v1/shipment/noon-logistics-awbs/get takes country_code and a qty of at most 1 000 and answers awb_nrs[]. IT MINTS NUMBERS: each call hands out new ones, no operation lists the numbers already issued, and a repeat does not return the last call\'s -- it burns more. So it is never retried by this code, never called to see whether the connection works, and a quantity outside 1..1 000 is refused here rather than finding out what noon does with it.'],
            'sku_batch_map' => ['documented' => true, 'built' => false, 'note' => 'POST /catplat/v1/sku/batch-map ties our partner_sku values to nsku_children. Registered because its page was read, and deliberately uncalled: whether an nsku_child is the same thing as the sku_parent the content calls use is NOT STATED, so using it to resolve a sku_parent would be a guess. NOT BUILT YET.'],
            'event_destinations' => ['documented' => true, 'built' => false, 'note' => 'GET /event-notifications/v1/destination/list shows where noon sends events, with credentials redacted on read. CREATING a destination is a separate operation whose page has not been read, so it is not registered: a webhook is configured in noon\'s own portal until it is. NOT BUILT YET: the path and the body are known, nothing calls it.'],
            'oauth_for_integrators' => ['documented' => true, 'built' => false, 'note' => 'An OAuth flow exists for integrators acting on many sellers\' behalf. Its endpoint paths were not read, so nothing is registered for it, and this application holds one seller\'s own key file in any case.'],
            'returns' => ['documented' => true, 'built' => true, 'note' => 'ONE operation exists and it is a LOOKUP, not a sweep: POST /returns/v1/return-references/list (the path carries no /fbpi segment -- returns are their own service) takes a barcode and merchant_codes[] and answers items[] of mp_code, purchase_item_nr, partner_sku, merchant_code, order_nr and references[] (barcode, barcode_type: awb_nr or rms_barcode). There is NO list-the-returns-since-a-date call anywhere, so a noon return cannot be polled for and is recognised when a parcel is scanned. A failed lookup is never read as \'this barcode belongs to no return\', which would put returned goods back on the shelf as unsold. WHAT IT CANNOT SAY: whether the return was accepted or refused, what it is worth, what noon charged, or who bore the cost -- none of it is in the answer and noon publishes no endpoint carrying it, so OperationalCosts is given no figure for a noon return. merchant_codes is marked both required and optional on the same page. WHO HANDLES AN FBPI RETURN is unsettled in noon\'s own documentation: the FBPI overview\'s comparison table says the seller, its prose says noon after pickup, and the Returns reference does not mention FBPI.'],
            'settlements_and_fees' => ['documented' => false, 'built' => false, 'note' => 'NO settlement, payout, fee or commission endpoint appears anywhere in noon\'s index. So noon\'s commissions cannot be read automatically, OperationalCosts has nothing to ingest for this channel, and no financial figure for noon is invented to fill the gap. A Reports/export service exists whose pages were not read and which may or may not cover financial data.'],
            'fbp' => ['documented' => false, 'built' => false, 'note' => 'FBP is not defined on any reference or guide page read here, and nothing is concluded from that silence: noon\'s help centre describes FBP, and that is where it has to be read before anything is built for it. What the pages read do describe: FBPI (our warehouse, we fulfil, we create shipments), FBN (a noon fulfilment centre, noon fulfils and handles returns, inbound by ASN) and FBPO (purchase-order driven, for B2B2C).'],
        ];
    }
}
