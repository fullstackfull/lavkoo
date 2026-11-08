<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\OperationalCosts\Support\Money;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\PublishUpdate;
use App\Services\SalesChannels\Noon\NoonConnector;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * noon's stock, price and category calls: the real paths, the real bodies, and the thing that makes noon different from a channel that answers plainly.
 *
 * **A 200 FROM NOON IS NOT A SUCCESS.** Every batch call answers one `items[]` entry per item submitted, each with its own `status`, and noon's pricing guide says it
 * outright: *"a `200` response does not mean every price was set."* A caller that read only the HTTP status would report a price nobody set. Most of this file is about that.
 *
 * Two more documented properties that would be silent corruptions if got wrong: stock `qty` is **absolute** (it becomes the new available quantity, never added to what noon
 * holds), and a null price **leaves a price alone** rather than clearing it.
 *
 * The paths carry their service segment — `/stock/v1/stock-update`, not `/v1/stock-update`. An earlier version of this connector had the latter and would have 404ed on
 * every call; a test below pins the prefix of each one.
 *
 * Every response is a fixture shaped after noon's own published examples. No test reaches noon, and nothing here proves a real seller account works.
 */
class NoonStockPricingTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const PRODUCTION = 'https://noon-api-gateway.noon.partners';
    private const LOGIN = self::PRODUCTION . '/identity/public/v1/api/login';
    private const STOCK_UPDATE = self::PRODUCTION . '/stock/v1/stock-update';
    private const PRICING_UPSERT = self::PRODUCTION . '/pricing/v1/pricing/upsert';
    private const CATEGORIES = self::PRODUCTION . '/content/v1/categories/list';
    private const ATTRIBUTES = self::PRODUCTION . '/content/v1/categories/attributes/list';
    private const WAREHOUSES = self::PRODUCTION . '/warehouse-platform/v1/warehouses/list';
    private const WAREHOUSE = 'WH-RUH-01';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00 UTC'));
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        $this->account = $this->noonAccount();
    }

    // ---- the paths carry their service segment -------------------------------------------------------------------

    public function test_every_documented_path_begins_with_its_service(): void
    {
        $expected = [
            'noon.login' => '/identity/public/v1/api/login',
            'noon.whoami' => '/identity/v1/whoami',
            'noon.stock_update' => '/stock/v1/stock-update',
            'noon.stock_list' => '/stock/v1/stock-list',
            'noon.pricing_upsert' => '/pricing/v1/pricing/upsert',
            'noon.pricing_get' => '/pricing/v1/pricing/get',
            'noon.product_upsert' => '/content/v1/product/upsert',
            'noon.categories_list' => '/content/v1/categories/list',
            'noon.category_attributes_list' => '/content/v1/categories/attributes/list',
            'noon.barcode_map' => '/catplat/v1/barcode/map',
            'noon.warehouses_list' => '/warehouse-platform/v1/warehouses/list',
            'noon.fbpi_orders_list' => '/fbpi/v1/fbpi-orders/list',
            'noon.shipment_create' => '/fbpi/v1/shipment/create',
        ];

        foreach ($expected as $operation => $path) {
            $this->assertSame($path, \App\Services\SalesChannels\Noon\NoonOperations::paths()[$operation], "{$operation} must carry noon's own full path, service segment and all");
        }
    }

    // ---- stock ---------------------------------------------------------------------------------------------------

    public function test_a_quantity_is_sent_as_an_absolute_figure_against_a_warehouse_and_partner_sku(): void
    {
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([['SKU-1', 'OK', 0]]))]);

        $result = app(NoonConnector::class)->publishStock($this->account, [$this->update(11, 'SKU-1', quantity: 25)]);

        $this->assertSame([11 => ''], $result->accepted);
        $this->assertSame([], $result->failed);
        $body = $this->bodyOf(self::STOCK_UPDATE);
        // noon's own example shape, and the quantity is what the shelf holds, never a delta.
        $this->assertSame([['warehouse_code' => self::WAREHOUSE, 'partner_sku' => 'SKU-1', 'qty' => 25]], $body['items']);
    }

    public function test_a_two_hundred_whose_item_failed_is_a_failure_for_that_item(): void
    {
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([
            ['SKU-1', 'OK', 0],
            ['SKU-2', 'SKU_NOT_FOUND', 5, 'noon holds no such partner_sku'],
        ]))]);

        $result = app(NoonConnector::class)->publishStock($this->account, [
            $this->update(11, 'SKU-1', quantity: 25),
            $this->update(12, 'SKU-2', quantity: 7),
        ]);

        $this->assertSame([11 => ''], $result->accepted, 'only the item noon said OK about');
        $this->assertArrayHasKey(12, $result->failed);
        $this->assertStringContainsString('SKU_NOT_FOUND', $result->failed[12]);
        $this->assertStringContainsString('no such partner_sku', $result->failed[12], 'noon\'s own words are kept');
    }

    public function test_an_item_noon_never_answered_about_is_not_treated_as_set(): void
    {
        // Two sent, one answered. Silence is not confirmation.
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([['SKU-1', 'OK', 0]]))]);

        $result = app(NoonConnector::class)->publishStock($this->account, [
            $this->update(11, 'SKU-1', quantity: 25),
            $this->update(12, 'SKU-2', quantity: 7),
        ]);

        $this->assertSame([11 => ''], $result->accepted);
        $this->assertStringContainsString('did not answer', $result->failed[12]);
    }

    public function test_a_status_code_of_ok_with_a_non_zero_id_is_not_success(): void
    {
        // Both fields have to agree; one of them alone is an answer we do not understand.
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([['SKU-1', 'OK', 7]]))]);

        $result = app(NoonConnector::class)->publishStock($this->account, [$this->update(11, 'SKU-1', quantity: 25)]);

        $this->assertSame([], $result->accepted);
        $this->assertArrayHasKey(11, $result->failed);
    }

    public function test_an_item_with_no_status_at_all_is_not_success(): void
    {
        $this->fake([self::STOCK_UPDATE => Http::response(['items' => [['warehouse_code' => self::WAREHOUSE, 'partner_sku' => 'SKU-1']]])]);

        $result = app(NoonConnector::class)->publishStock($this->account, [$this->update(11, 'SKU-1', quantity: 25)]);

        $this->assertSame([], $result->accepted, 'a missing status is not a quiet yes');
        $this->assertStringContainsString('no_status_from_noon', $result->failed[11]);
    }

    public function test_answers_are_matched_by_their_own_identifiers_not_by_position(): void
    {
        // noon echoes the fields it was given; it does not promise an order. The second answer belongs to the first item.
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([
            ['SKU-2', 'OK', 0],
            ['SKU-1', 'BAD_REQUEST', 3],
        ]))]);

        $result = app(NoonConnector::class)->publishStock($this->account, [
            $this->update(11, 'SKU-1', quantity: 25),
            $this->update(12, 'SKU-2', quantity: 7),
        ]);

        $this->assertSame([12 => ''], $result->accepted, 'SKU-2 is mapping 12, whatever order noon answered in');
        $this->assertArrayHasKey(11, $result->failed);
    }

    public function test_an_account_with_no_warehouse_cannot_publish_stock_and_sends_nothing(): void
    {
        $this->account->forceFill(['settings' => ['warehouse_code' => '', 'country_code' => 'sa']])->save();
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([['SKU-1', 'OK', 0]]))]);

        $result = app(NoonConnector::class)->publishStock($this->account->fresh(), [$this->update(11, 'SKU-1', quantity: 25)]);

        $this->assertSame([], $result->accepted);
        $this->assertStringContainsString('warehouse', $result->failed[11]);
        $this->assertCount(0, $this->sentTo('/stock/v1/'), 'noon has no quantity for a product without a warehouse, so nothing is asked');
    }

    // ---- price ---------------------------------------------------------------------------------------------------

    public function test_a_price_is_sent_per_country_with_the_list_price_as_msrp(): void
    {
        $this->fake([self::PRICING_UPSERT => Http::response($this->pricingAnswer([['SKU-1', 'sa', 'OK', 0]]))]);

        $result = app(NoonConnector::class)->publishPrices($this->account, [
            $this->update(11, 'SKU-1', price: Money::of('149.00'), listPrice: Money::of('199.00')),
        ]);

        $this->assertSame([11 => ''], $result->accepted);
        $this->assertSame([['partner_sku' => 'SKU-1', 'country_code' => 'sa', 'price' => 149.0, 'msrp' => 199.0]], $this->bodyOf(self::PRICING_UPSERT)['items']);
    }

    public function test_no_list_price_means_no_msrp_field_rather_than_a_null_one(): void
    {
        $this->fake([self::PRICING_UPSERT => Http::response($this->pricingAnswer([['SKU-1', 'sa', 'OK', 0]]))]);

        app(NoonConnector::class)->publishPrices($this->account, [$this->update(11, 'SKU-1', price: Money::of('149.00'))]);

        $item = $this->bodyOf(self::PRICING_UPSERT)['items'][0];
        // A null would be read as "leave it alone", which is right, but sending one invites a reader to think it clears the value. noon documents no way to clear one.
        $this->assertArrayNotHasKey('msrp', $item);
    }

    public function test_a_price_that_rounds_to_zero_is_refused_before_anything_is_sent(): void
    {
        $this->fake([self::PRICING_UPSERT => Http::response($this->pricingAnswer([]))]);

        $result = app(NoonConnector::class)->publishPrices($this->account, [$this->update(11, 'SKU-1', price: Money::of('0.004'))]);

        $this->assertSame([], $result->accepted);
        $this->assertStringContainsString('zero', $result->failed[11]);
        Http::assertNothingSent();
    }

    public function test_a_price_noon_refused_per_item_is_reported_with_its_own_reason(): void
    {
        $this->fake([self::PRICING_UPSERT => Http::response($this->pricingAnswer([
            ['SKU-1', 'sa', 'OK', 0],
            ['SKU-2', 'sa', 'NOT_FOUND', 5, 'Pricing not configured for this SKU and country'],
        ]))]);

        $result = app(NoonConnector::class)->publishPrices($this->account, [
            $this->update(11, 'SKU-1', price: Money::of('149.00')),
            $this->update(12, 'SKU-2', price: Money::of('99.00')),
        ]);

        $this->assertSame([11 => ''], $result->accepted);
        $this->assertStringContainsString('Pricing not configured', $result->failed[12]);
    }

    // ---- a request that failed outright --------------------------------------------------------------------------

    public function test_a_failed_request_sets_nothing_and_a_transient_one_is_worth_repeating(): void
    {
        $this->fake([self::STOCK_UPDATE => Http::response('', 503)]);

        $result = app(NoonConnector::class)->publishStock($this->account, [$this->update(11, 'SKU-1', quantity: 25)]);

        $this->assertSame([], $result->accepted);
        $this->assertArrayHasKey(11, $result->failed);
        $this->assertSame([11], $result->transient, 'a channel that was busy may be asked again; nothing was set');
    }

    public function test_a_rejected_request_is_not_offered_for_a_retry(): void
    {
        $this->fake([self::STOCK_UPDATE => Http::response(['status_code' => 'BAD_REQUEST'], 400)]);

        $result = app(NoonConnector::class)->publishStock($this->account, [$this->update(11, 'SKU-1', quantity: 25)]);

        $this->assertSame([], $result->transient, 'repeating a request noon rejected would be refused the same way');
    }

    // ---- nothing leaves the host with a switch off ---------------------------------------------------------------

    public function test_with_the_host_switch_off_no_write_leaves_and_the_call_is_refused(): void
    {
        config(['sales_channels.external_writes_enabled' => false]);
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([['SKU-1', 'OK', 0]]))]);

        $this->expectExceptionMessageMatches('/read-only|EXTERNAL_WRITES/i');
        try {
            app(NoonConnector::class)->publishStock($this->account, [$this->update(11, 'SKU-1', quantity: 25)]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_without_the_approval_no_write_leaves(): void
    {
        $this->account->forceFill(['capability_approvals' => []])->save();
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([['SKU-1', 'OK', 0]]))]);

        $this->expectExceptionMessageMatches('/approv/i');
        try {
            app(NoonConnector::class)->publishStock($this->account->fresh(), [$this->update(11, 'SKU-1', quantity: 25)]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_without_the_capability_no_write_leaves(): void
    {
        $this->account->forceFill(['capabilities' => ['connection_test', 'read_only_sync']])->save();
        $this->fake([self::PRICING_UPSERT => Http::response($this->pricingAnswer([['SKU-1', 'sa', 'OK', 0]]))]);

        $this->expectExceptionMessageMatches('/price_publishing|capabilit/i');
        try {
            app(NoonConnector::class)->publishPrices($this->account->fresh(), [$this->update(11, 'SKU-1', price: Money::of('149.00'))]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_rehearsing_account_sends_no_write_and_claims_nothing(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        $this->fake([self::STOCK_UPDATE => Http::response($this->stockAnswer([['SKU-1', 'OK', 0]]))]);

        $result = app(NoonConnector::class)->publishStock($this->account->fresh(), [$this->update(11, 'SKU-1', quantity: 25)]);

        $this->assertTrue($result->dryRun);
        $this->assertSame([], $result->accepted);
        $this->assertCount(0, $this->sentTo('/stock/v1/'));
    }

    // ---- the session is renewed when it lapses -------------------------------------------------------------------

    public function test_a_lapsed_session_is_signed_in_for_again_and_the_call_retried(): void
    {
        Http::fake([
            self::LOGIN => Http::sequence()
                ->push('', 200, ['Set-Cookie' => 'noon_session=first; Path=/'])
                ->push('', 200, ['Set-Cookie' => 'noon_session=second; Path=/']),
            // noon answers 401 to the session it just gave, as it would once the session lapsed.
            self::STOCK_UPDATE => Http::sequence()
                ->push(['status_code' => 'UNAUTHENTICATED'], 401)
                ->push($this->stockAnswer([['SKU-1', 'OK', 0]])),
        ]);

        $result = app(NoonConnector::class)->publishStock($this->account, [$this->update(11, 'SKU-1', quantity: 25)]);

        $this->assertSame([11 => ''], $result->accepted, 'the retry after a fresh sign-in is what set the quantity');
        $this->assertCount(2, $this->sentTo('/identity/public/v1/api/login'), 'signed in twice: once at the start and once when the session was refused');
        $stock = $this->sentTo('/stock/v1/stock-update');
        $this->assertCount(2, $stock);
        $this->assertStringContainsString('noon_session=second', (string) (array_change_key_case($stock[1]->headers(), CASE_LOWER)['cookie'][0] ?? ''));
    }

    public function test_a_session_refused_twice_is_reported_and_not_tried_for_ever(): void
    {
        Http::fake([
            self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc; Path=/']),
            self::STOCK_UPDATE => Http::response(['status_code' => 'PERMISSION_DENIED'], 403),
        ]);

        $this->expectExceptionMessageMatches('/refused this call after a fresh sign-in/');
        app(NoonConnector::class)->publishStock($this->account, [$this->update(11, 'SKU-1', quantity: 25)]);
    }

    // ---- what noon requires of a category ------------------------------------------------------------------------

    public function test_a_categorys_mandatory_attributes_are_required_and_its_options_are_the_allowed_values(): void
    {
        $this->fake([self::ATTRIBUTES => Http::response(['attributes' => [
            ['attribute_code' => 'colour', 'attribute_type' => 'ATTRIBUTE_TYPE_SELECT', 'is_mandatory' => true, 'attribute_options' => [['code' => 'red'], ['code' => 'blue']]],
            ['attribute_code' => 'care_instructions', 'attribute_type' => 'ATTRIBUTE_TYPE_TEXT', 'is_mandatory' => false],
        ]])]);

        $requirements = app(NoonConnector::class)->listingRequirements($this->account, 'apparel-shoes-sneakers');

        $this->assertFalse($requirements->unknown);
        $this->assertSame(['colour'], $requirements->required);
        $this->assertSame(['care_instructions'], $requirements->optional);
        $this->assertSame(['red', 'blue'], $requirements->allowedValues['colour']);
        $this->assertArrayNotHasKey('care_instructions', $requirements->allowedValues, 'free text is not limited to a list');
    }

    public function test_a_category_that_cannot_be_read_is_unknown_not_an_empty_requirement_list(): void
    {
        $this->fake([self::ATTRIBUTES => Http::response('', 503)]);

        $this->assertTrue(app(NoonConnector::class)->listingRequirements($this->account, 'apparel-shoes-sneakers')->unknown);
    }

    public function test_a_category_that_names_no_attribute_is_unknown(): void
    {
        $this->fake([self::ATTRIBUTES => Http::response(['attributes' => []])]);

        $this->assertTrue(app(NoonConnector::class)->listingRequirements($this->account, 'apparel-shoes-sneakers')->unknown, 'silence is not permission');
    }

    public function test_the_category_codes_come_back_as_noon_writes_them(): void
    {
        $this->fake([self::CATEGORIES => Http::response(['categories' => ['apparel-shoes-sneakers', 'electronics-mobiles-smartphones']])]);

        $this->assertSame(['apparel-shoes-sneakers', 'electronics-mobiles-smartphones'], app(NoonConnector::class)->categories($this->account));
    }

    // ---- the warehouses stock is addressed by --------------------------------------------------------------------

    public function test_the_warehouses_are_read_through_every_page(): void
    {
        Http::fake([
            self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc; Path=/']),
            self::WAREHOUSES => Http::sequence()
                ->push(['next_token' => 'page-2', 'warehouses' => [['warehouse_code' => 'WH-1', 'display_name' => 'Riyadh', 'fulfillment_system_code' => 'fbpi', 'is_active' => true]]])
                ->push(['next_token' => '', 'warehouses' => [['warehouse_code' => 'WH-2', 'is_active' => false]]]),
        ]);

        $warehouses = app(NoonConnector::class)->warehouses($this->account);

        $this->assertCount(2, $warehouses, 'a tight rate limit is the reason every page is taken in one pass');
        $this->assertSame(['WH-1', 'WH-2'], array_column($warehouses, 'code'));
        $this->assertSame('fbpi', $warehouses[0]['fulfillment_system']);
        $this->assertFalse($warehouses[1]['active']);
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    private function noonAccount(): SalesChannelAccount
    {
        $account = app(ChannelAccountService::class)->create(
            ['channel' => 'noon', 'name' => 'noon KSA', 'external_account_id' => 'partner-1', 'currency' => 'SAR', 'settings' => ['warehouse_code' => self::WAREHOUSE, 'country_code' => 'sa']],
            ['key_id' => 'key-abc', 'private_key' => $this->aRealPrivateKey(), 'project_code' => 'proj_x', 'channel_identifier' => 'noon'],
            adminId: 1,
        );
        $account->forceFill([
            'enabled' => true, 'write_enabled' => true, 'dry_run' => false,
            'capabilities' => ['connection_test', 'read_only_sync', 'product_sync', 'inventory_publishing', 'price_publishing'],
            'capability_approvals' => ['inventory_publishing' => ['by' => 1], 'price_publishing' => ['by' => 1]],
        ])->save();

        return $account;
    }

    private function aRealPrivateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    /** @param array<string, mixed> $responses */
    private function fake(array $responses): void
    {
        Http::fake([self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc123; Path=/'])] + $responses);
    }

    private function update(int $mappingId, string $sku, ?int $quantity = null, ?Money $price = null, ?Money $listPrice = null): PublishUpdate
    {
        return new PublishUpdate($mappingId, $sku, $sku, null, $quantity, $price, $listPrice, 'SAR');
    }

    /** @param list<array<int, mixed>> $rows [partner_sku, code, id, message?] */
    private function stockAnswer(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'warehouse_code' => self::WAREHOUSE,
                'partner_sku' => $row[0],
                'status' => ['status_id' => $row[2], 'status_code' => $row[1], 'message' => $row[3] ?? '', 'details' => []],
            ];
        }

        return ['items' => $items];
    }

    /** @param list<array<int, mixed>> $rows [partner_sku, country, code, id, message?] */
    private function pricingAnswer(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'partner_sku' => $row[0],
                'country_code' => $row[1],
                'status' => ['status_id' => $row[3], 'status_code' => $row[2], 'message' => $row[4] ?? ''],
            ];
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    private function bodyOf(string $url): array
    {
        return (array) $this->sentTo($url)[0]->data();
    }

    /** @return list<Request> */
    private function sentTo(string $needle): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), $needle))->values()->map(static fn (array $pair): Request => $pair[0])->all();
    }
}
