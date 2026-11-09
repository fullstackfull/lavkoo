<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Noon\NoonConnector;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Shipping an FBPI order, which is the one noon write that puts goods on a van.
 *
 * **TWO SILENCES IN NOON'S DOCUMENTATION SHAPE EVERY TEST HERE.**
 *
 * `shipment/create`'s 200 carries NO BODY — not a shipment number, not a status, nothing. So a 2xx is the only thing a create ever says, and it is not enough to record a
 * shipment against an order: the shipment is read back with `shipment/get`, and only noon's own answer confirms it.
 *
 * And NOTHING is documented about a repeated `integration_shipment_nr` — no idempotency rule, no error, nothing. noon only requires it to be unique. So the number is
 * DERIVED from the order and its exact units, which makes a retry of the same work send the same number instead of minting a second shipment for goods already on their
 * way; and noon is asked whether it already holds that number before anything is sent. Those two together are what turn an ambiguous failure — a timeout, a reset — from a
 * risk of shipping twice into a question with an answer. Most of this file is that one scenario, from several directions.
 *
 * Every response is a fixture shaped after noon's own published schemas. No test reaches noon, and nothing here proves a real seller account works.
 */
class NoonShipmentsTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const PRODUCTION = 'https://noon-api-gateway.noon.partners';
    private const LOGIN = self::PRODUCTION . '/identity/public/v1/api/login';
    private const CREATE = self::PRODUCTION . '/fbpi/v1/shipment/create';
    private const GET = self::PRODUCTION . '/fbpi/v1/shipment/get';
    private const CANCEL = self::PRODUCTION . '/fbpi/v1/shipment/cancel';
    private const ADD_AWB = self::PRODUCTION . '/fbpi/v1/shipment/courier-awbs/add';
    private const NOON_AWBS = self::PRODUCTION . '/fbpi/v1/shipment/noon-logistics-awbs/get';
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

    // ---- the body noon documents --------------------------------------------------------------------------------

    public function test_a_shipment_sends_every_field_noons_schema_requires(): void
    {
        $this->fake([self::GET => $this->noShipmentThenOne(), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1', 'I-2'], [['courier' => 'noon', 'awb_nr' => 'AWB-9']]);

        $this->assertTrue($result['sent']);
        $body = $this->bodyOf(self::CREATE);
        $this->assertSame(self::WAREHOUSE, $body['warehouse_code']);
        $this->assertSame('NO-1', $body['fbpi_order_nr']);
        $this->assertSame([['courier' => 'noon', 'awb_nr' => 'AWB-9']], $body['awbs']);
        $this->assertSame([['mp_item_nr' => 'I-1'], ['mp_item_nr' => 'I-2']], $body['items']);
        $this->assertNotSame('', $body['integration_shipment_nr']);
    }

    public function test_a_shipment_missing_a_field_noon_requires_is_refused_before_anything_is_sent(): void
    {
        $this->fake([self::GET => Http::response([]), self::CREATE => Http::response([])]);

        foreach ([
            [[], [['courier' => 'noon', 'awb_nr' => 'A']]],
            [['I-1'], []],
            [['I-1'], [['courier' => '', 'awb_nr' => 'A']]],
        ] as [$units, $awbs]) {
            $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', $units, $awbs);

            $this->assertFalse($result['sent']);
            $this->assertStringContainsString('noon requires', (string) $result['reason']);
        }
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_an_account_with_no_warehouse_cannot_ship_and_sends_nothing(): void
    {
        $this->account->forceFill(['settings' => ['country_code' => 'sa']])->save();
        $this->fake([self::GET => Http::response([]), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account->fresh(), 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('warehouse_code', (string) $result['reason']);
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    // ---- the 200 says nothing, so noon is asked -----------------------------------------------------------------

    public function test_a_two_hundred_alone_does_not_confirm_a_shipment(): void
    {
        // noon's create answers no body whatsoever. Recording a shipment on the strength of a 2xx would be believing something noon never said.
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertTrue($result['sent']);
        $this->assertFalse($result['confirmed'], 'noon took it but does not report it');
        $this->assertStringContainsString('does not report it yet', (string) $result['reason']);
    }

    public function test_a_shipment_noon_reports_back_is_confirmed(): void
    {
        $this->fake([self::GET => $this->noShipmentThenOne(), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'AWB-9']]);

        $this->assertTrue($result['confirmed']);
        $this->assertCount(2, $this->sentTo(self::GET), 'asked once before and once after, and the second is what confirms it');
    }

    // ---- the same work never ships twice -------------------------------------------------------------------------

    public function test_the_shipment_number_is_derived_so_a_retry_of_the_same_work_reuses_it(): void
    {
        // THE defence against shipping twice. A number minted afresh on a retry would create a second shipment for goods already on their way.
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);
        $first = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1', 'I-2'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);
        $again = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1', 'I-2'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertSame($first['shipment'], $again['shipment']);
    }

    public function test_the_same_units_named_in_another_order_are_still_the_same_shipment(): void
    {
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);
        $forwards = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1', 'I-2'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);
        $backwards = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-2', 'I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertSame($forwards['shipment'], $backwards['shipment']);
    }

    public function test_shipping_different_units_of_one_order_is_a_different_shipment(): void
    {
        // Which is right: that really is a second shipment, and noon has to be told about it separately.
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);
        $first = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);
        $second = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-2'], [['courier' => 'noon', 'awb_nr' => 'B']]);

        $this->assertNotSame($first['shipment'], $second['shipment']);
    }

    public function test_a_shipment_noon_already_holds_is_not_sent_again(): void
    {
        // The retry after an ambiguous failure: noon took the first attempt even though we never saw the answer, and this is what finds out.
        $this->fake([self::GET => Http::response($this->heldShipment()), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent'], 'nothing was sent');
        $this->assertTrue($result['confirmed'], 'because noon already has it');
        $this->assertStringContainsString('already holds this shipment', (string) $result['reason']);
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_a_failed_existence_check_is_not_read_as_an_absence(): void
    {
        // A read that failed tells us nothing. Treating it as "noon holds no such shipment" is exactly what would ship the same goods twice, so the create goes ahead with
        // the SAME derived number -- which is safe -- rather than with a fresh one.
        $this->fake([self::GET => Http::response([], 503), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertTrue($result['sent']);
        $this->assertFalse($result['confirmed']);
        $this->assertSame($this->bodyOf(self::CREATE)['integration_shipment_nr'], $result['shipment'], 'the derived number, not a new one');
    }

    public function test_an_ambiguous_failure_says_the_request_may_still_have_arrived(): void
    {
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([], 500)]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertFalse($result['confirmed']);
        $this->assertStringContainsString('may still have reached noon', (string) $result['reason']);
        $this->assertStringContainsString('same shipment number', (string) $result['reason']);
    }

    public function test_a_refusal_noon_certainly_did_not_act_on_does_not_warn_about_a_maybe(): void
    {
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response(['message' => 'bad order'], 400)]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('noon refused the shipment', (string) $result['reason']);
        $this->assertStringNotContainsString('may still have reached', (string) $result['reason']);
    }

    // ---- the shared contract ------------------------------------------------------------------------------------

    public function test_the_shared_confirmation_turns_quantities_back_into_noons_unit_numbers(): void
    {
        // The contract speaks in quantities per line; noon speaks in mp_item_nr values. The line ids this module produces ARE the unit numbers, joined.
        $this->fake([self::GET => $this->noShipmentThenOne(), self::CREATE => Http::response([])]);

        $taken = app(NoonConnector::class)->confirmShipment($this->account, new ShipmentConfirmation(
            orderId: 'NO-1',
            packageId: '',
            carrierCode: 'smsa',
            carrierName: 'SMSA Express',
            trackingNumber: 'AWB-77',
            shippedAt: CarbonImmutable::parse('2026-11-14 10:00:00'),
            lineQuantities: ['I-1,I-2' => 2, 'I-3' => 1],
        ));

        $this->assertTrue($taken);
        $body = $this->bodyOf(self::CREATE);
        $this->assertSame([['mp_item_nr' => 'I-1'], ['mp_item_nr' => 'I-2'], ['mp_item_nr' => 'I-3']], $body['items']);
        $this->assertSame([['courier' => 'SMSA Express', 'awb_nr' => 'AWB-77']], $body['awbs']);
    }

    public function test_a_part_shipped_line_names_only_as_many_units_as_shipped(): void
    {
        $this->fake([self::GET => $this->noShipmentThenOne(), self::CREATE => Http::response([])]);

        app(NoonConnector::class)->confirmShipment($this->account, new ShipmentConfirmation(
            orderId: 'NO-1', packageId: '', carrierCode: 'noon', carrierName: null, trackingNumber: 'AWB-1',
            shippedAt: CarbonImmutable::parse('2026-11-14 10:00:00'), lineQuantities: ['I-1,I-2,I-3' => 2],
        ));

        $this->assertSame([['mp_item_nr' => 'I-1'], ['mp_item_nr' => 'I-2']], $this->bodyOf(self::CREATE)['items']);
    }

    public function test_an_unconfirmed_shipment_is_not_reported_as_taken_through_the_shared_contract(): void
    {
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);

        $taken = app(NoonConnector::class)->confirmShipment($this->account, new ShipmentConfirmation(
            orderId: 'NO-1', packageId: '', carrierCode: 'noon', carrierName: null, trackingNumber: 'AWB-1',
            shippedAt: CarbonImmutable::parse('2026-11-14 10:00:00'), lineQuantities: ['I-1' => 1],
        ));

        $this->assertFalse($taken, 'noon never said it holds the shipment');
    }

    // ---- reading, cancelling, adding a waybill -------------------------------------------------------------------

    public function test_a_shipment_is_read_by_its_number_and_the_accounts_warehouse(): void
    {
        $this->fake([self::GET => Http::response($this->heldShipment())]);

        $held = app(NoonConnector::class)->shipment($this->account, 'PK-ABC');

        $this->assertSame('NO-1', $held['fbpi_order_nr']);
        $this->assertSame(['warehouse_code' => self::WAREHOUSE, 'integration_shipment_nr' => 'PK-ABC'], $this->bodyOf(self::GET));
    }

    public function test_a_shipment_noon_answers_without_an_order_number_is_not_a_shipment(): void
    {
        $this->fake([self::GET => Http::response(['awbs' => [], 'items' => []])]);

        $this->assertNull(app(NoonConnector::class)->shipment($this->account, 'PK-ABC'));
    }

    public function test_cancelling_takes_every_item_of_the_shipment_and_is_never_a_retry(): void
    {
        $this->fake([self::CANCEL => Http::response([])]);

        $result = app(NoonConnector::class)->cancelShipment($this->account, 'PK-ABC');

        $this->assertTrue($result['sent']);
        $this->assertSame(['warehouse_code' => self::WAREHOUSE, 'integration_shipment_nr' => 'PK-ABC'], $this->bodyOf(self::CANCEL));
    }

    public function test_a_refused_cancellation_leaves_the_shipment_standing_and_says_so(): void
    {
        $this->fake([self::CANCEL => Http::response(['message' => 'already delivered'], 409)]);

        $result = app(NoonConnector::class)->cancelShipment($this->account, 'PK-ABC');

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('the shipment stands', (string) $result['reason']);
    }

    public function test_a_waybill_is_confirmed_by_reading_the_shipment_back(): void
    {
        $this->fake([self::ADD_AWB => Http::response([]), self::GET => Http::response($this->heldShipment(['AWB-9', 'AWB-10']))]);

        $result = app(NoonConnector::class)->addWaybills($this->account, 'PK-ABC', [['courier' => 'smsa', 'awb_nr' => 'AWB-10']]);

        $this->assertTrue($result['sent']);
        $this->assertTrue($result['confirmed']);
    }

    public function test_a_waybill_noon_does_not_report_on_the_shipment_is_not_confirmed(): void
    {
        // Its 200 is empty too, so the only confirmation is the shipment itself carrying the number.
        $this->fake([self::ADD_AWB => Http::response([]), self::GET => Http::response($this->heldShipment(['AWB-9']))]);

        $result = app(NoonConnector::class)->addWaybills($this->account, 'PK-ABC', [['courier' => 'smsa', 'awb_nr' => 'AWB-10']]);

        $this->assertTrue($result['sent']);
        $this->assertFalse($result['confirmed']);
        $this->assertStringContainsString('does not report it on the shipment yet', (string) $result['reason']);
    }

    // ---- noon's own waybills, which are minted and not listed ---------------------------------------------------

    public function test_noons_own_waybills_are_asked_for_by_country_and_quantity(): void
    {
        $this->fake([self::NOON_AWBS => Http::response(['awb_nrs' => ['A1', 'A2']])]);

        $numbers = app(NoonConnector::class)->noonWaybills($this->account, 2);

        $this->assertSame(['A1', 'A2'], $numbers);
        $this->assertSame(['country_code' => 'sa', 'qty' => 2], $this->bodyOf(self::NOON_AWBS));
    }

    public function test_a_quantity_above_noons_documented_ceiling_is_refused_here(): void
    {
        // It MINTS numbers. Finding out what noon does with 1 001 would burn a thousand of them to learn it.
        $this->fake([self::NOON_AWBS => Http::response(['awb_nrs' => []])]);

        $this->expectException(RuntimeException::class);
        try {
            app(NoonConnector::class)->noonWaybills($this->account, 1001);
        } finally {
            $this->assertSame([], $this->sentTo(self::NOON_AWBS));
        }
    }

    public function test_a_quantity_of_nothing_is_refused_rather_than_sent(): void
    {
        $this->fake([self::NOON_AWBS => Http::response(['awb_nrs' => []])]);

        $this->expectException(RuntimeException::class);
        try {
            app(NoonConnector::class)->noonWaybills($this->account, 0);
        } finally {
            $this->assertSame([], $this->sentTo(self::NOON_AWBS));
        }
    }

    public function test_an_account_with_no_country_cannot_ask_for_waybills(): void
    {
        $this->account->forceFill(['settings' => ['warehouse_code' => self::WAREHOUSE, 'country_code' => '']])->save();
        $this->fake([self::NOON_AWBS => Http::response(['awb_nrs' => []])]);

        $this->expectException(RuntimeException::class);
        try {
            app(NoonConnector::class)->noonWaybills($this->account->fresh(), 5);
        } finally {
            $this->assertSame([], $this->sentTo(self::NOON_AWBS));
        }
    }

    public function test_a_refused_waybill_request_is_not_retried_here_because_numbers_may_be_lost(): void
    {
        $this->fake([self::NOON_AWBS => Http::response([], 500)]);

        try {
            app(NoonConnector::class)->noonWaybills($this->account, 5);
            $this->fail('a refusal must be reported');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('would not issue waybills', $exception->getMessage());
        }
        $this->assertCount(1, $this->sentTo(self::NOON_AWBS), 'asked once; a repeat burns more numbers');
    }

    // ---- the switches hold here too -----------------------------------------------------------------------------

    public function test_without_the_dispatch_capability_no_shipment_leaves(): void
    {
        $this->account->forceFill(['capabilities' => ['connection_test', 'order_import']])->save();
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);

        $this->expectException(ChannelUnavailableException::class);
        try {
            app(NoonConnector::class)->createShipment($this->account->fresh(), 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);
        } finally {
            $this->assertSame([], $this->sentTo(self::CREATE));
        }
    }

    public function test_with_the_host_switch_off_no_shipment_leaves(): void
    {
        config(['sales_channels.external_writes_enabled' => false]);
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);

        $this->expectException(ChannelUnavailableException::class);
        try {
            app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);
        } finally {
            $this->assertSame([], $this->sentTo(self::CREATE));
        }
    }

    public function test_a_rehearsing_account_ships_nothing_and_claims_nothing(): void
    {
        $this->account->forceFill(['dry_run' => true])->save();
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account->fresh(), 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertFalse($result['confirmed']);
        $this->assertNull($result['reason'], 'a rehearsal is not a refusal');
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_the_shipment_calls_carry_the_service_segment_noon_documents(): void
    {
        foreach ([
            'noon.shipment_create' => '/fbpi/v1/shipment/create',
            'noon.shipment_cancel' => '/fbpi/v1/shipment/cancel',
            'noon.shipment_get' => '/fbpi/v1/shipment/get',
            'noon.shipment_awbs_add' => '/fbpi/v1/shipment/courier-awbs/add',
            'noon.noon_logistics_awbs_get' => '/fbpi/v1/shipment/noon-logistics-awbs/get',
        ] as $operation => $expected) {
            $this->assertSame($expected, \App\Services\SalesChannels\Noon\NoonOperations::paths()[$operation]);
        }
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
            'capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'fulfillment_dispatch'],
            'capability_approvals' => ['order_import' => ['by' => 1], 'fulfillment_dispatch' => ['by' => 1]],
        ])->save();

        return $account->fresh();
    }

    /** noon holds no such shipment, and then — after the create — it does. Laravel plays a sequence in order. */
    private function noShipmentThenOne(): \Illuminate\Http\Client\ResponseSequence
    {
        return Http::sequence()->push([], 404)->push($this->heldShipment());
    }

    /**
     * @param list<string> $waybills
     * @return array<string, mixed>
     */
    private function heldShipment(array $waybills = ['AWB-9']): array
    {
        return [
            'fbpi_order_nr' => 'NO-1',
            'awbs' => array_map(static fn (string $number): array => ['courier' => 'noon', 'awb_nr' => $number], $waybills),
            'items' => [['mp_item_nr' => 'I-1', 'partner_sku' => 'SKU-1']],
        ];
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
        $patterns = [self::LOGIN => Http::response('', 200, ['Set-Cookie' => 'noon_session=abc123; Path=/'])];
        foreach ($responses as $url => $response) {
            $patterns[$url . '*'] = $response;
        }

        Http::fake($patterns);
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
