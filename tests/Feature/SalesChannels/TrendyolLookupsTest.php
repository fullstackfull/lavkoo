<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Trendyol\TrendyolLookups;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * Three Trendyol reads that were registered and never called, and the thing each one gets wrong if read loosely.
 *
 * Every path, parameter and field name below is from developers.trendyol.com v3.0 (International Marketplace), read on
 * 2026-11-22. Stray requests are refused, and none of these writes anything.
 *
 *  1. **Carrier codes are storefront-limited and Trendyol says so**: "You can use SA and AE storefrontcode only." With no
 *     header the service answers for Turkey, which would be accepted as this account's list -- so an account on another
 *     storefront is refused here instead of shown somebody else's carriers.
 *  2. **A half-read value list is worse than none.** A missing value looks exactly like a value Trendyol rejects, and the
 *     listing gets built without it. So a page that could not be read abandons the whole answer.
 *  3. **"This service only provides stock and pricing information for your approved products."** A barcode absent from that
 *     answer may be sitting unapproved -- holding the barcode, and certain to be duplicated by a create. So this read reports
 *     what was FOUND and never hands anybody a list of "missing" barcodes.
 */
class TrendyolLookupsTest extends TestCase
{
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const API = 'https://apigw.trendyol.com/integration';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        config(['sales_channels.enabled' => true]);
        $this->account = $this->trendyolAccount();
    }

    // ---- 1. carrier codes ----------------------------------------------------------------------------------------

    public function test_the_carrier_codes_are_read_as_code_to_name(): void
    {
        // The body is a bare JSON array -- this endpoint is not paged and has no `content` wrapper.
        Http::fake([self::API . '/product/lookup/cargo-providers' => Http::response([
            ['code' => 'SPL', 'name' => 'SPL'],
            ['code' => 'JTEXPRESS', 'name' => 'J&T Express'],
            ['code' => 'SHIPA', 'name' => 'Shipa'],
        ])]);

        $result = app(TrendyolLookups::class)->carrierCodes($this->account);

        $this->assertNull($result['problem']);
        $this->assertSame(['SPL' => 'SPL', 'JTEXPRESS' => 'J&T Express', 'SHIPA' => 'Shipa'], $result['providers']);
        // And the storefront header really went, because without it Trendyol answers for Turkey.
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('storeFrontCode', 'SA'));
    }

    public function test_a_storefront_trendyol_does_not_publish_this_service_for_is_refused_before_the_call(): void
    {
        // **The defect this prevents.** Trendyol documents the service for SA and AE only; with a wrong code it answers
        // `400 invalid.storefrontcode`, and with no code at all it answers for TURKEY -- a list of Turkish carriers that
        // would be accepted as this account's own and configured against parcels in Saudi Arabia.
        $this->account->forceFill(['settings' => array_merge((array) $this->account->settings, ['storefront_code' => 'DE'])])->save();

        $result = app(TrendyolLookups::class)->carrierCodes($this->account->fresh());

        $this->assertNull($result['providers']);
        $this->assertSame('trendyol_carrier_list_is_not_published_for_this_storefront:DE', $result['problem']);
        Http::assertNothingSent();
    }

    public function test_an_empty_carrier_list_is_an_answer_and_not_a_failure(): void
    {
        // This storefront offers no carrier through the API. A person has to see that, rather than have it read as "we could
        // not ask" and retried for ever.
        Http::fake([self::API . '/product/lookup/cargo-providers' => Http::response([])]);

        $result = app(TrendyolLookups::class)->carrierCodes($this->account);

        $this->assertNull($result['problem']);
        $this->assertSame([], $result['providers']);
    }

    public function test_a_carrier_list_we_could_not_read_is_never_an_empty_list(): void
    {
        Http::fake([self::API . '/product/lookup/cargo-providers' => Http::response('gateway timeout', 504)]);

        $result = app(TrendyolLookups::class)->carrierCodes($this->account);

        $this->assertNull($result['providers'], 'a failed read was handed back as "no carriers"');
        $this->assertStringStartsWith('trendyol_carrier_list_could_not_be_read', (string) $result['problem']);
    }

    public function test_a_body_that_is_not_the_documented_array_is_refused(): void
    {
        // An object where Trendyol documents an array is not this service's answer, and reading it loosely would produce an
        // empty carrier list indistinguishable from a real one.
        Http::fake([self::API . '/product/lookup/cargo-providers' => Http::response(['content' => []])]);

        $result = app(TrendyolLookups::class)->carrierCodes($this->account);

        $this->assertNull($result['providers']);
        $this->assertSame('trendyol_carrier_list_is_not_the_documented_shape', $result['problem']);
    }

    // ---- 2. long attribute values --------------------------------------------------------------------------------

    public function test_every_page_of_a_long_value_list_is_walked(): void
    {
        // The categories that could not be listed in at all: the attributes call embeds values only where the list is short,
        // so for anything longer this is the only way to learn what Trendyol accepts.
        $pages = [
            ['totalElements' => 3, 'totalPages' => 2, 'page' => 0, 'size' => 2, 'content' => [
                ['attributeValueId' => 4872, 'attributeValue' => 'Tek Ebat'],
                ['attributeValueId' => 4873, 'attributeValue' => 'S'],
            ]],
            ['totalElements' => 3, 'totalPages' => 2, 'page' => 1, 'size' => 2, 'content' => [
                ['attributeValueId' => 4874, 'attributeValue' => 'M'],
            ]],
        ];
        Http::fake([self::API . '/product/categories/411/attributes/338/values*' => function () use (&$pages) {
            return Http::response(array_shift($pages) ?? ['content' => []]);
        }]);

        $result = app(TrendyolLookups::class)->attributeValues($this->account, 411, 338, perPage: 2);

        $this->assertNull($result['problem']);
        $this->assertSame([4872 => 'Tek Ebat', 4873 => 'S', 4874 => 'M'], $result['values']);
        $this->assertSame(2, $result['pages_read']);
    }

    public function test_a_page_that_could_not_be_read_abandons_the_whole_value_list(): void
    {
        // **Half a list is the dangerous answer.** A value Trendyol would have accepted, missing from our list, looks exactly
        // like a value Trendyol rejects -- and the listing is built without it rather than refused.
        $replies = [
            Http::response(['totalElements' => 4, 'totalPages' => 2, 'page' => 0, 'size' => 2, 'content' => [['attributeValueId' => 1, 'attributeValue' => 'A']]]),
            Http::response('service unavailable', 503),
        ];
        Http::fake([self::API . '/product/categories/411/attributes/338/values*' => function () use (&$replies) {
            return array_shift($replies) ?? Http::response('', 500);
        }]);

        $result = app(TrendyolLookups::class)->attributeValues($this->account, 411, 338, perPage: 2);

        $this->assertNull($result['values'], 'a partial value list was handed back as a whole one');
        $this->assertSame(1, $result['pages_read']);
        $this->assertStringStartsWith('trendyol_attribute_values_page_could_not_be_read:1', (string) $result['problem']);
    }

    public function test_the_walk_stops_on_an_empty_page_whatever_the_page_count_claimed(): void
    {
        // A wrong `totalPages` must not spin a loop against an endpoint limited to 50 requests per 10 seconds.
        $calls = 0;
        Http::fake([self::API . '/product/categories/411/attributes/338/values*' => function () use (&$calls) {
            $calls++;

            return Http::response(['totalElements' => 9999, 'totalPages' => 500, 'page' => 0, 'size' => 2, 'content' => []]);
        }]);

        $result = app(TrendyolLookups::class)->attributeValues($this->account, 411, 338, perPage: 2);

        $this->assertNull($result['problem']);
        $this->assertSame([], $result['values']);
        $this->assertSame(1, $calls, 'the walk kept asking after Trendyol ran out of values');
    }

    public function test_the_page_size_is_held_to_trendyols_own_cap(): void
    {
        Http::fake([self::API . '/product/categories/411/attributes/338/values*' => Http::response(['totalPages' => 1, 'content' => []])]);

        app(TrendyolLookups::class)->attributeValues($this->account, 411, 338, perPage: 99999);

        // Trendyol: "The maximum value can be 1000."
        Http::assertSent(fn (Request $request): bool => (string) ($request->data()['size'] ?? '') === '1000');
    }

    public function test_a_value_list_needs_a_category_and_an_attribute(): void
    {
        $result = app(TrendyolLookups::class)->attributeValues($this->account, 0, 338);

        $this->assertSame('trendyol_attribute_values_need_a_category_and_an_attribute', $result['problem']);
        Http::assertNothingSent();
    }

    // ---- 3. price and stock read back ----------------------------------------------------------------------------

    public function test_price_and_stock_come_back_keyed_by_barcode(): void
    {
        Http::fake([self::API . '/product/sellers/*/products/approved/inventory-and-price*' => Http::response([
            'totalElements' => 1, 'totalPages' => 1, 'page' => 0, 'size' => 1,
            'content' => [[
                'contentId' => 12431242141,
                'productMainId' => '1242141241',
                'variants' => [
                    ['variantId' => 3953959353, 'barcode' => '60506560', 'salePrice' => 699.99, 'listPrice' => 749.5, 'quantity' => 50, 'stockCode' => '056565964', 'stockLastModifiedDate' => 1780463592464],
                ],
            ]],
        ])]);

        $result = app(TrendyolLookups::class)->approvedPriceAndStock($this->account, ['60506560']);

        $this->assertNull($result['problem']);
        $this->assertSame([
            '60506560' => [
                'sale_price' => 699.99,
                'list_price' => 749.5,
                'quantity' => 50,
                'stock_code' => '056565964',
                'content_id' => 12431242141,
                'product_main_id' => '1242141241',
                'stock_changed_at' => 1780463592464,
            ],
        ], $result['found']);
    }

    public function test_a_barcode_trendyol_did_not_mention_is_not_reported_as_absent(): void
    {
        // **The trap.** Trendyol: "This service only provides stock and pricing information for your approved products." A
        // barcode missing from the answer may be sitting UNAPPROVED -- it exists, it holds the barcode, and creating it again
        // would duplicate the product and lock the barcode. So this read offers no "missing" list for anybody to act on.
        Http::fake([self::API . '/product/sellers/*/products/approved/inventory-and-price*' => Http::response(['totalElements' => 0, 'totalPages' => 0, 'page' => 0, 'size' => 0, 'content' => []])]);

        $result = app(TrendyolLookups::class)->approvedPriceAndStock($this->account, ['NOT-APPROVED-1', 'NOT-APPROVED-2']);

        $this->assertNull($result['problem']);
        $this->assertSame([], $result['found']);
        $this->assertArrayNotHasKey('missing', $result, 'the read offered a list of absent barcodes, which it cannot know');
    }

    public function test_a_stock_date_trendyol_left_null_stays_null(): void
    {
        // Trendyol: `stockLastModifiedDate` "returns a value only if a stock update has occurred for the product; otherwise,
        // it returns null". Cast to an int it becomes the epoch, and the product reads as last updated in 1970.
        Http::fake([self::API . '/product/sellers/*/products/approved/inventory-and-price*' => Http::response([
            'content' => [['contentId' => 1, 'productMainId' => 'M-1', 'variants' => [['barcode' => 'B-1', 'salePrice' => 10.0, 'listPrice' => 12.0, 'quantity' => 3, 'stockCode' => null, 'stockLastModifiedDate' => null]]]],
        ])]);

        $result = app(TrendyolLookups::class)->approvedPriceAndStock($this->account, ['B-1']);

        $this->assertNull($result['found']['B-1']['stock_changed_at']);
        $this->assertNull($result['found']['B-1']['stock_code']);
    }

    public function test_more_barcodes_than_trendyol_takes_are_refused_rather_than_truncated(): void
    {
        // A truncated filter answers about some barcodes and looks like an answer about all of them -- and the dropped ones
        // would read as absent, which already means something else here.
        $barcodes = array_map(static fn (int $i): string => 'B-' . $i, range(1, TrendyolLookups::MOST_BARCODES + 1));

        $result = app(TrendyolLookups::class)->approvedPriceAndStock($this->account, $barcodes);

        $this->assertNull($result['found']);
        $this->assertSame('trendyol_price_and_stock_read_takes_at_most_50_barcodes:51', $result['problem']);
        Http::assertNothingSent();
    }

    public function test_a_failed_price_read_is_never_an_empty_result(): void
    {
        Http::fake([self::API . '/product/sellers/*/products/approved/inventory-and-price*' => Http::response('', 500)]);

        $result = app(TrendyolLookups::class)->approvedPriceAndStock($this->account, ['B-1']);

        $this->assertNull($result['found'], 'a 500 was read as "Trendyol holds nothing for this barcode"');
        $this->assertStringStartsWith('trendyol_price_and_stock_could_not_be_read', (string) $result['problem']);
    }

    // ---- 4. seller addresses -------------------------------------------------------------------------------------

    public function test_the_seller_addresses_come_back_keyed_by_the_id_a_write_would_send(): void
    {
        Http::fake([self::API . '/sellers/*/addresses' => Http::response($this->addressesReply())]);

        $result = app(TrendyolLookups::class)->sellerAddresses($this->account);

        $this->assertNull($result['problem']);
        $this->assertSame([1, 2, 3], array_keys($result['addresses']));
        $this->assertSame('Shipment', $result['addresses'][1]['type']);
        $this->assertTrue($result['addresses'][1]['shipment']);
        $this->assertFalse($result['addresses'][1]['invoice']);
        $this->assertSame('Gebze', $result['addresses'][1]['district']);
    }

    public function test_the_returning_default_is_a_presence_flag_and_is_not_read_as_an_address_id(): void
    {
        // **Trendyol's own sample documents this asymmetry.** `defaultShipmentAddress` and `defaultInvoiceAddress` are full
        // address objects; `defaultReturningAddress` is `{"present": true}`. Treating the three alike reads the returning
        // default's id as 0 -- and 0 would then be sent as an address id on a product create.
        Http::fake([self::API . '/sellers/*/addresses' => Http::response($this->addressesReply())]);

        $result = app(TrendyolLookups::class)->sellerAddresses($this->account);

        $this->assertTrue($result['has_default_returning']);
        $this->assertSame(2, $result['default_invoice_id']);
        // And the shipment default in Trendyol's sample carries `id: 0`, which is a placeholder and not an address.
        $this->assertNull($result['default_shipment_id'], 'a placeholder id of 0 was offered as an address to send');
    }

    public function test_an_address_with_no_id_is_not_offered_as_one_a_write_could_name(): void
    {
        Http::fake([self::API . '/sellers/*/addresses' => Http::response([
            'supplierAddresses' => [['id' => 0, 'addressType' => 'Shipment', 'city' => 'Riyadh', 'shipmentAddress' => true]],
        ])]);

        $result = app(TrendyolLookups::class)->sellerAddresses($this->account);

        $this->assertSame([], $result['addresses']);
        $this->assertNull($result['problem'], 'an unusable address was reported as a failure to read');
    }

    public function test_a_failed_address_read_is_never_an_empty_address_book(): void
    {
        Http::fake([self::API . '/sellers/*/addresses' => Http::response('service unavailable', 503)]);

        $result = app(TrendyolLookups::class)->sellerAddresses($this->account);

        $this->assertNull($result['addresses'], 'a 503 was read as "this seller has no addresses"');
        $this->assertNull($result['has_default_returning'], 'a failed read answered a question about the returning default');
        $this->assertStringStartsWith('trendyol_seller_addresses_could_not_be_read', (string) $result['problem']);
    }

    public function test_a_403_raises_the_modules_own_refusal_rather_than_reading_as_no_addresses(): void
    {
        // The transport turns 401 and 403 into exceptions for every Trendyol call, and this read is no exception to that.
        // It matters here in particular: Trendyol's page says "If SELLER APPLICATION PROCESS is not fully completed, you
        // should not use this service", so a 403 is a real and meaningful answer about the ACCOUNT -- and swallowing it into
        // an empty address book would read a half-finished application as a seller with nowhere to ship from.
        Http::fake([self::API . '/sellers/*/addresses' => Http::response('forbidden', 403)]);

        $this->expectException(\App\Services\SalesChannels\Exceptions\ChannelPermissionDenied::class);
        app(TrendyolLookups::class)->sellerAddresses($this->account);
    }

    public function test_the_page_limit_trendyol_documents_is_known(): void
    {
        // `page` x `size` may not exceed 10,000; past it Trendyol documents `nextPageToken` instead. The number is theirs.
        $this->assertFalse(TrendyolLookups::pastThePageLimit(page: 99, size: 100));
        $this->assertTrue(TrendyolLookups::pastThePageLimit(page: 100, size: 100));
    }

    /** Shaped exactly after Trendyol's own sample, including the `defaultReturningAddress` asymmetry and the id-0 placeholder. */
    private function addressesReply(): array
    {
        return [
            'supplierAddresses' => [
                ['id' => 1, 'addressType' => 'Shipment', 'country' => 'Saudi Arabia', 'city' => 'Kocaeli', 'cityCode' => 0, 'district' => 'Gebze', 'districtId' => 0, 'postCode' => 'post-code-0', 'address' => 'Address-0', 'returningAddress' => false, 'fullAddress' => "John Doe's House", 'shipmentAddress' => true, 'invoiceAddress' => false, 'default' => true],
                ['id' => 2, 'addressType' => 'Invoice', 'country' => 'Saudi Arabia', 'city' => 'Ankara', 'district' => 'Mamak', 'postCode' => 'post-code-2', 'address' => 'Address-2', 'returningAddress' => false, 'fullAddress' => "John Doe's House", 'shipmentAddress' => false, 'invoiceAddress' => true, 'default' => true],
                ['id' => 3, 'addressType' => 'Returning', 'country' => 'Saudi Arabia', 'city' => 'Bursa', 'district' => 'Teleferik', 'postCode' => 'post-code-3', 'address' => 'Address-3', 'returningAddress' => true, 'fullAddress' => "John Doe's House", 'shipmentAddress' => false, 'invoiceAddress' => false, 'default' => true],
            ],
            'defaultShipmentAddress' => ['id' => 0, 'addressType' => 'Shipment', 'city' => 'Kocaeli'],
            'defaultInvoiceAddress' => ['id' => 2, 'addressType' => 'Invoice', 'city' => 'Ankara'],
            'defaultReturningAddress' => ['present' => true],
        ];
    }

    public function test_nothing_here_writes(): void
    {
        // All four are reads. The guard is cheap and the claim is load-bearing: these run against a live seller's catalogue.
        foreach (['trendyol.cargo_providers', 'trendyol.category_attribute_values', 'trendyol.products_inventory_price', 'trendyol.addresses'] as $operation) {
            $this->assertSame(ChannelOperations::READ, ChannelOperations::kindOf($operation, 'GET'), "'{$operation}' is registered as something other than a read.");
            $this->assertFalse(ChannelOperations::methodAllowed($operation, 'POST'), "'{$operation}' accepts a POST.");
        }
    }
}
