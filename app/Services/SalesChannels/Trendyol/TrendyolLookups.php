<?php

namespace App\Services\SalesChannels\Trendyol;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;

/**
 * The three Trendyol reads a person needs before they can list or reconcile anything — each registered long ago and none of
 * them called until now.
 *
 * They are reads. Nothing here writes, and nothing here decides: each one answers what Trendyol said, or says plainly that
 * nobody could be told. That distinction is the whole discipline of this module and it earns its keep three times over below.
 *
 * ## 1. Carrier codes — because the wrong one is a sentence, not a list
 *
 * `GET /integration/product/lookup/cargo-providers`. Trendyol accepts only its own carrier codes, and a person configuring
 * them by hand gets a rejection in prose instead of a list to choose from.
 *
 * **It is limited by storefront, and Trendyol says so outright**: "You can use SA and AE storefrontcode only. This service is
 * working only limited storefrontcode", with an invalid one answered `400 invalid.storefrontcode`, and no header at all
 * treated as Turkey. So an account on another storefront is told that rather than shown Turkey's carriers as if they were its
 * own — which is exactly the mistake the default would make for us.
 *
 * The returned `code` goes in the `cargoProviders` field of a product create or update. That is NOT the same field as the
 * `providerCode` of a tracking-number update, and conflating the two is how a parcel ends up declared to the wrong carrier.
 *
 * ## 2. Long attribute values — the categories a listing could not be built for
 *
 * `GET /integration/product/categories/{categoryId}/attributes/{attributeId}/values`, paged. The category-attributes call
 * embeds values only where the list is short enough; for anything longer this is the only way to learn what Trendyol will
 * accept. Without it, a category whose values are paginated simply could not be listed in.
 *
 * `size` and `page` are each capped at 1000 by Trendyol. Values are localised, and `Accept-Language: ar` is what Trendyol
 * documents for the SA and AE storefronts.
 *
 * ## 3. Price and stock read back — and the trap in it
 *
 * `GET /integration/product/sellers/{sellerId}/products/approved/inventory-and-price`. The publisher already compares what it
 * wrote with what it last wrote; this compares OUR figure with TRENDYOL'S.
 *
 * **"This service only provides stock and pricing information for your approved products."** So a barcode absent from this
 * answer is not a barcode Trendyol does not have: it may be sitting unapproved, which is a different fact with the opposite
 * consequence — creating it would duplicate the product and lock the barcode. This class therefore never reports an absence,
 * only what was found, and a caller that needs to know whether a barcode exists at all asks the approved AND unapproved
 * filters, which is what `TrendyolConnector` already does.
 *
 * `stockLastModifiedDate` "returns a value only if a stock update has occurred for the product; otherwise, it returns null",
 * so null is read as "never updated" and never as the epoch.
 */
class TrendyolLookups
{
    /** The storefronts Trendyol documents this service for. Anything else is refused here rather than answered with Turkey's list. */
    public const CARRIER_STOREFRONTS = ['SA', 'AE'];

    /** Trendyol's cap on both paging parameters of the attribute-values service. */
    private const MOST_PER_PAGE = 1000;

    /** Trendyol's cap on `size` for the inventory-and-price filter. */
    private const MOST_PRODUCTS_PER_PAGE = 100;

    /** `page` x `size` may not exceed this; past it Trendyol documents `nextPageToken` instead. */
    private const MOST_BY_PAGE_NUMBER = 10_000;

    /** Trendyol's cap on how many barcodes one inventory-and-price call may filter by. */
    public const MOST_BARCODES = 50;

    public function __construct(private readonly TrendyolClient $client)
    {
    }

    /**
     * The carrier codes this account's storefront really offers.
     *
     * @return array{providers: array<string, string>|null, problem: string|null} code => name, or why we could not be told
     */
    public function carrierCodes(SalesChannelAccount $account): array
    {
        // The same accessor and the same default the transport itself uses for the `storeFrontCode` header, so this check
        // judges the storefront the request would really carry rather than a second opinion about it.
        $storefront = strtoupper(trim((string) $account->setting('storefront_code', 'SA')));
        if (!in_array($storefront, self::CARRIER_STOREFRONTS, true)) {
            // Trendyol documents this service for SA and AE only. Calling it anyway returns Turkey's carriers when the header
            // is missing, and `400 invalid.storefrontcode` when it is wrong -- neither of which is this account's list, and
            // the first would be silently accepted as if it were.
            return ['providers' => null, 'problem' => 'trendyol_carrier_list_is_not_published_for_this_storefront:' . ($storefront === '' ? 'none_set' : $storefront)];
        }

        $response = $this->client->call($account, 'trendyol.cargo_providers', 'GET', '/product/lookup/cargo-providers');
        if (!$response->ok()) {
            return ['providers' => null, 'problem' => $this->problemOf($response, 'trendyol_carrier_list_could_not_be_read')];
        }
        // The body is a bare array, not an object with a `content` key: this endpoint is not paged.
        $body = $response->json;
        if (!is_array($body) || array_is_list($body) === false) {
            return ['providers' => null, 'problem' => 'trendyol_carrier_list_is_not_the_documented_shape'];
        }

        $providers = [];
        foreach ($body as $provider) {
            if (!is_array($provider)) {
                continue;
            }
            $code = trim((string) ($provider['code'] ?? ''));
            $name = trim((string) ($provider['name'] ?? ''));
            if ($code !== '') {
                // Both are documented non-nullable. A code with no name is still a usable code, so the code stands in.
                $providers[$code] = $name === '' ? $code : $name;
            }
        }

        // An EMPTY list is an answer and is returned as one: this storefront offers no carrier through the API, which a
        // person must see rather than have read as a failure to ask.
        return ['providers' => $providers, 'problem' => null];
    }

    /**
     * Every value Trendyol accepts for one attribute of one category, across all its pages.
     *
     * A page we could not read stops the walk and is reported, because a partial value list read as a whole one is worse than
     * none: the missing value looks like a value Trendyol rejects, and a listing is built without it.
     *
     * @return array{values: array<int, string>|null, pages_read: int, problem: string|null} attributeValueId => attributeValue
     */
    public function attributeValues(SalesChannelAccount $account, int $categoryId, int $attributeId, int $perPage = 500): array
    {
        if ($categoryId < 1 || $attributeId < 1) {
            return ['values' => null, 'pages_read' => 0, 'problem' => 'trendyol_attribute_values_need_a_category_and_an_attribute'];
        }
        $perPage = max(1, min(self::MOST_PER_PAGE, $perPage));

        $values = [];
        $page = 0;
        $pagesRead = 0;
        $totalPages = 1;
        while ($page < $totalPages && $page < self::MOST_PER_PAGE) {
            $response = $this->client->call(
                account: $account,
                operation: 'trendyol.category_attribute_values',
                method: 'GET',
                path: '/product/categories/' . $categoryId . '/attributes/' . $attributeId . '/values',
                query: ['page' => $page, 'size' => $perPage],
            );
            if (!$response->ok()) {
                return ['values' => null, 'pages_read' => $pagesRead, 'problem' => $this->problemOf($response, 'trendyol_attribute_values_page_could_not_be_read:' . $page)];
            }
            $body = $response->json;
            if (!is_array($body) || !is_array($body['content'] ?? null)) {
                return ['values' => null, 'pages_read' => $pagesRead, 'problem' => 'trendyol_attribute_values_reply_is_not_the_documented_shape'];
            }

            foreach ($body['content'] as $value) {
                if (!is_array($value)) {
                    continue;
                }
                $id = (int) ($value['attributeValueId'] ?? 0);
                if ($id > 0) {
                    // The id is what a write sends; the name is for a person to choose by.
                    $values[$id] = trim((string) ($value['attributeValue'] ?? ''));
                }
            }

            $pagesRead++;
            $totalPages = max(1, (int) ($body['totalPages'] ?? 1));
            $page++;
            if ($body['content'] === []) {
                // A page Trendyol filled with nothing ends the walk whatever `totalPages` claimed, so a wrong count cannot
                // spin this loop against an endpoint rated at 50 requests per 10 seconds.
                break;
            }
        }

        return ['values' => $values, 'pages_read' => $pagesRead, 'problem' => null];
    }

    /**
     * What Trendyol holds as the price and quantity of our approved listings.
     *
     * **An absence here is not an absence at Trendyol** -- this service answers for approved products only, and an unapproved
     * one exists, holds the barcode, and would be duplicated by a create. So the answer is what was FOUND, keyed by barcode,
     * and the caller is given no list of "missing" barcodes to act on.
     *
     * @param list<string> $barcodes at most 50, which is Trendyol's own cap
     * @return array{found: array<string, array{sale_price: float, list_price: float, quantity: int, stock_code: string|null, content_id: int|null, product_main_id: string|null, stock_changed_at: int|null}>|null, problem: string|null}
     */
    public function approvedPriceAndStock(SalesChannelAccount $account, array $barcodes): array
    {
        $barcodes = array_values(array_unique(array_filter(array_map('trim', $barcodes), static fn (string $barcode): bool => $barcode !== '')));
        if ($barcodes === []) {
            return ['found' => null, 'problem' => 'trendyol_price_and_stock_read_needs_a_barcode'];
        }
        if (count($barcodes) > self::MOST_BARCODES) {
            // Refused rather than truncated: a truncated filter answers about some of the barcodes and looks like an answer
            // about all of them, so the ones dropped would read as absent -- and absent here already means something else.
            return ['found' => null, 'problem' => 'trendyol_price_and_stock_read_takes_at_most_' . self::MOST_BARCODES . '_barcodes:' . count($barcodes)];
        }

        $response = $this->client->call(
            account: $account,
            operation: 'trendyol.products_inventory_price',
            method: 'GET',
            path: '/product/sellers/' . $this->sellerId($account) . '/products/approved/inventory-and-price',
            query: ['barcodes' => implode(',', $barcodes), 'size' => min(self::MOST_PRODUCTS_PER_PAGE, max(1, count($barcodes)))],
        );
        if (!$response->ok()) {
            return ['found' => null, 'problem' => $this->problemOf($response, 'trendyol_price_and_stock_could_not_be_read')];
        }
        $body = $response->json;
        if (!is_array($body) || !is_array($body['content'] ?? null)) {
            return ['found' => null, 'problem' => 'trendyol_price_and_stock_reply_is_not_the_documented_shape'];
        }

        $found = [];
        foreach ($body['content'] as $product) {
            if (!is_array($product)) {
                continue;
            }
            foreach ((array) ($product['variants'] ?? []) as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $barcode = trim((string) ($variant['barcode'] ?? ''));
                if ($barcode === '') {
                    continue;
                }
                $found[$barcode] = [
                    'sale_price' => (float) ($variant['salePrice'] ?? 0),
                    'list_price' => (float) ($variant['listPrice'] ?? 0),
                    'quantity' => (int) ($variant['quantity'] ?? 0),
                    'stock_code' => ($code = trim((string) ($variant['stockCode'] ?? ''))) === '' ? null : $code,
                    'content_id' => isset($product['contentId']) ? (int) $product['contentId'] : null,
                    'product_main_id' => ($main = trim((string) ($product['productMainId'] ?? ''))) === '' ? null : $main,
                    // Null where Trendyol says null: "returns a value only if a stock update has occurred". The epoch is not
                    // a stand-in for never.
                    'stock_changed_at' => is_numeric($variant['stockLastModifiedDate'] ?? null) ? (int) $variant['stockLastModifiedDate'] : null,
                ];
            }
        }

        return ['found' => $found, 'problem' => null];
    }

    /**
     * The return and shipment addresses already on this seller's Trendyol account.
     *
     * `GET /integration/sellers/{sellerId}/addresses`. Needed because a product create carries a shipment address id, and
     * this version had no way to learn what ids exist -- so the field was either left out or filled from somebody's memory.
     *
     * **Trendyol's own sample documents a shape trap**, and it is the reason this returns what it returns: `defaultShipmentAddress`
     * and `defaultInvoiceAddress` are full address objects, while `defaultReturningAddress` is `{"present": true}`. A reader
     * that assumed the three were alike would read the returning default's `id` as 0 and send 0 as an address id. So each
     * default is reported as an id ONLY where Trendyol gave one, and the returning default separately as known-to-exist.
     *
     * Read-only: Trendyol documents no way to add an address through this service, and the page says plainly that it lists
     * "the return and shipping address information that you add to your account".
     *
     * @return array{addresses: array<int, array{type: string, city: string|null, district: string|null, country: string|null, post_code: string|null, full: string|null, shipment: bool, invoice: bool, returning: bool, default: bool}>|null, default_shipment_id: int|null, default_invoice_id: int|null, has_default_returning: bool|null, problem: string|null}
     */
    public function sellerAddresses(SalesChannelAccount $account): array
    {
        $response = $this->client->call($account, 'trendyol.addresses', 'GET', '/sellers/' . $this->sellerId($account) . '/addresses');
        if (!$response->ok()) {
            return ['addresses' => null, 'default_shipment_id' => null, 'default_invoice_id' => null, 'has_default_returning' => null, 'problem' => $this->problemOf($response, 'trendyol_seller_addresses_could_not_be_read')];
        }
        $body = $response->json;
        if (!is_array($body) || !is_array($body['supplierAddresses'] ?? null)) {
            return ['addresses' => null, 'default_shipment_id' => null, 'default_invoice_id' => null, 'has_default_returning' => null, 'problem' => 'trendyol_seller_addresses_reply_is_not_the_documented_shape'];
        }

        $addresses = [];
        foreach ($body['supplierAddresses'] as $address) {
            if (!is_array($address)) {
                continue;
            }
            $id = (int) ($address['id'] ?? 0);
            if ($id < 1) {
                // An address with no id cannot be named in a product create, so it is not offered as if it could.
                continue;
            }
            $addresses[$id] = [
                'type' => trim((string) ($address['addressType'] ?? '')),
                'city' => ($city = trim((string) ($address['city'] ?? ''))) === '' ? null : $city,
                'district' => ($district = trim((string) ($address['district'] ?? ''))) === '' ? null : $district,
                'country' => ($country = trim((string) ($address['country'] ?? ''))) === '' ? null : $country,
                'post_code' => ($post = trim((string) ($address['postCode'] ?? ''))) === '' ? null : $post,
                'full' => ($full = trim((string) ($address['fullAddress'] ?? ''))) === '' ? null : $full,
                // The booleans, not the label: Trendyol carries both and a write is matched on what the flags say.
                'shipment' => (bool) ($address['shipmentAddress'] ?? false),
                'invoice' => (bool) ($address['invoiceAddress'] ?? false),
                'returning' => (bool) ($address['returningAddress'] ?? false),
                'default' => (bool) ($address['default'] ?? false),
            ];
        }

        return [
            'addresses' => $addresses,
            'default_shipment_id' => $this->defaultIdOf($body['defaultShipmentAddress'] ?? null),
            'default_invoice_id' => $this->defaultIdOf($body['defaultInvoiceAddress'] ?? null),
            // Deliberately a boolean and not an id: Trendyol's own sample gives this one as `{"present": true}` with no id in
            // it, so there is no id to report and inventing 0 would send 0.
            'has_default_returning' => $this->presenceOf($body['defaultReturningAddress'] ?? null),
            'problem' => null,
        ];
    }

    /** The id of a default address object, or null where Trendyol sent no usable one. */
    private function defaultIdOf(mixed $address): ?int
    {
        if (!is_array($address)) {
            return null;
        }
        $id = (int) ($address['id'] ?? 0);

        // Zero is not an id. Trendyol's own sample shows `defaultShipmentAddress.id` as 0 while the matching entry in
        // `supplierAddresses` is 1, so a 0 here is a placeholder and sending it would name no address at all.
        return $id > 0 ? $id : null;
    }

    /** Whether a default Trendyol reports only as a presence flag is there. Null means it said nothing either way. */
    private function presenceOf(mixed $value): ?bool
    {
        if (!is_array($value)) {
            return null;
        }
        if (array_key_exists('present', $value)) {
            return (bool) $value['present'];
        }

        // A full object instead of the flag: its existence is the answer.
        return ($value['id'] ?? null) !== null;
    }

    /**
     * Whether `page` x `size` has reached the point past which Trendyol documents `nextPageToken` instead.
     *
     * Public because it is a fact about the endpoint rather than about this class: a caller walking pages has to stop at the
     * same place, and the number is Trendyol's, not ours.
     */
    public static function pastThePageLimit(int $page, int $size): bool
    {
        return ($page + 1) * max(1, $size) > self::MOST_BY_PAGE_NUMBER;
    }

    private function sellerId(SalesChannelAccount $account): string
    {
        return rawurlencode(trim((string) $account->external_account_id));
    }

    private function problemOf(ChannelResponse $response, string $code): string
    {
        return $response->isDryRun() ? 'trendyol_rehearsal_asked_nothing' : $code . ': ' . $response->message();
    }
}
