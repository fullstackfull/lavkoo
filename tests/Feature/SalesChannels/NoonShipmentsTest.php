<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelShipmentSend;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Data\ShipmentConfirmation;
use App\Services\SalesChannels\Exceptions\ChannelPermissionDenied;
use App\Services\SalesChannels\Exceptions\ChannelUnavailableException;
use App\Services\SalesChannels\Noon\NoonConnector;
use App\Services\SalesChannels\Shipments\ChannelShipmentSends;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
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

    // ---- a read that failed is not an absence --------------------------------------------------------------------
    //
    // THIS SECTION EXISTS BECAUSE IT ONCE ASSERTED THE OPPOSITE. `test_a_failed_existence_check_is_not_read_as_an_absence()`
    // used to expect a 503 on the read to be followed by a create, justified in its own comment by the derived number being
    // "safe" to re-send. noon documents no idempotency rule for `integration_shipment_nr`, so that justification was wrong, and
    // a green suite was covering a second shipment of goods already on a van. Every case below is one of the ways the read can
    // fail to answer, and every one of them must send nothing.

    public function test_a_failed_existence_check_is_not_read_as_an_absence(): void
    {
        $this->fake([self::GET => Http::response([], 503), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent'], 'a 503 says nothing about what noon holds');
        $this->assertFalse($result['confirmed']);
        $this->assertTrue($result['needs_review'] ?? false, 'a person has to resolve it');
        $this->assertSame([], $this->sentTo(self::CREATE));
        $this->assertStringContainsString('could not say whether it already holds', (string) $result['reason']);
        $this->assertStringContainsString('503', (string) $result['reason'], 'and what actually happened is named');
    }

    public function test_a_connection_that_drops_during_the_check_sends_nothing(): void
    {
        // The exact scenario the derived number was supposed to make safe, and the one it cannot: a read whose answer never
        // arrived may have been about a shipment noon already holds.
        $this->fake([
            self::GET => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'),
            self::CREATE => Http::response([]),
        ]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertTrue($result['needs_review'] ?? false);
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_a_check_that_never_left_the_host_sends_nothing(): void
    {
        $this->fake([
            self::GET => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: noon-api-gateway.noon.partners'),
            self::CREATE => Http::response([]),
        ]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertTrue($result['needs_review'] ?? false);
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_a_rate_limited_check_is_not_an_absence(): void
    {
        $this->fake([self::GET => Http::response(['message' => 'too many requests'], 429), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent'], 'being told to slow down is not being told the shipment does not exist');
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_a_refused_check_stops_the_shipment_rather_than_creating_on_it(): void
    {
        // noon refuses the read on credentials or role. The client signs in afresh and asks once more, and a second refusal is
        // a permission problem, not an answer -- so it raises rather than letting a create through.
        $this->fake([self::GET => Http::response(['message' => 'forbidden'], 403), self::CREATE => Http::response([])]);

        try {
            app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);
            $this->fail('a refused read must not end in a shipment');
        } catch (ChannelPermissionDenied $e) {
            $this->assertStringContainsString('noon refused this call', $e->getMessage());
        }

        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_a_two_hundred_the_check_cannot_read_is_not_an_absence(): void
    {
        // A 200 with no `fbpi_order_nr` might be noon's way of saying "not found" -- its reference page does not say -- or a
        // truncated body from something in between. Both look identical here, and only one of them makes creating safe.
        $this->fake([self::GET => Http::response(['awbs' => [], 'items' => []]), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertTrue($result['needs_review'] ?? false);
        $this->assertSame([], $this->sentTo(self::CREATE));
        $this->assertStringContainsString('noon_answered_without_a_shipment_we_could_read', (string) $result['reason']);
    }

    public function test_an_empty_two_hundred_body_on_the_check_is_not_an_absence(): void
    {
        $this->fake([self::GET => Http::response(''), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_noons_own_word_that_it_holds_no_such_shipment_is_the_one_answer_a_create_is_made_on(): void
    {
        // The positive side of the same rule, so the refusals above cannot be satisfied by refusing everything.
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertTrue($result['sent']);
        $this->assertFalse($result['needs_review'] ?? false);
        $this->assertCount(1, $this->sentTo(self::CREATE));
    }

    public function test_the_shipment_number_alone_is_not_treated_as_a_guarantee_against_a_duplicate(): void
    {
        // Deriving the same `integration_shipment_nr` makes the retry ask about the same shipment; it does not make re-sending
        // it harmless, because noon publishes no idempotency rule for that field. So the number being identical is never
        // allowed to stand in for an answer: a read that did not answer sends nothing even though the number is the same one.
        $this->fake([
            // asked before the create, asked again after it, and from then on noon cannot be asked at all
            self::GET => Http::sequence()->push([], 404)->push([], 404)->whenEmpty(Http::response([], 503)),
            self::CREATE => Http::response([]),
        ]);
        $connector = app(NoonConnector::class);

        $first = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);
        $again = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertSame($first['shipment'], $again['shipment'], 'the same work, so the same number');
        $this->assertTrue($first['sent']);
        $this->assertFalse($again['sent'], 'and the same number is still not a reason to send it again');
        $this->assertCount(1, $this->sentTo(self::CREATE), 'one create for one shipment');
    }

    // ---- the read after the create can fail too ------------------------------------------------------------------

    public function test_a_check_that_fails_after_the_create_is_left_for_a_person(): void
    {
        // noon took the create and then could not be asked about it. Whether the shipment exists is open, and a machine must
        // not settle it: the next attempt asks again, and if that read fails too it stops here again rather than sending.
        $this->fake([self::GET => Http::sequence()->push([], 404)->whenEmpty(Http::response([], 503)), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertTrue($result['sent']);
        $this->assertFalse($result['confirmed']);
        $this->assertTrue($result['needs_review'] ?? false);
        $this->assertStringContainsString('could not be asked about it', (string) $result['reason']);
        $this->assertCount(1, $this->sentTo(self::CREATE), 'the failed read after the create never causes a second create');
    }

    public function test_a_create_noon_does_not_report_yet_is_not_handed_to_a_person(): void
    {
        // The difference worth keeping: noon ANSWERED, and said it holds nothing yet. That is a lag, not a doubt, so it is
        // reported as unconfirmed without calling for a person.
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertTrue($result['sent']);
        $this->assertFalse($result['confirmed']);
        $this->assertFalse($result['needs_review'] ?? false);
        $this->assertStringContainsString('does not report it yet', (string) $result['reason']);
    }

    public function test_an_unresolved_shipment_is_resolved_by_asking_again_and_never_by_sending_again(): void
    {
        // The whole recovery path, end to end. First attempt: noon takes it and then the read fails, so it is unresolved.
        // Second attempt: the read succeeds and finds the shipment noon has held all along -- and nothing is sent.
        // noon's gateway is down for the read that follows the create, and back up by the time anybody asks again. A sequence
        // cannot say that, because a read is retry-safe and one 503 is several requests; a closure says it exactly.
        $reads = 0;
        $backUp = false;
        $this->fake([
            self::GET => function () use (&$reads, &$backUp) {
                $reads++;

                return $reads === 1 ? Http::response([], 404) : ($backUp ? Http::response($this->heldShipment()) : Http::response([], 503));
            },
            self::CREATE => Http::response([]),
        ]);
        $connector = app(NoonConnector::class);

        $first = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);
        $this->assertTrue($first['needs_review'] ?? false, 'noon took it and then could not be asked');

        $backUp = true;
        $second = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($second['sent'], 'nothing is sent the second time');
        $this->assertTrue($second['confirmed'], 'because noon holds the first attempt');
        $this->assertSame($first['shipment'], $second['shipment']);
        $this->assertCount(1, $this->sentTo(self::CREATE), 'one create for one shipment, across both attempts');
    }

    // ---- two workers on the same shipment ------------------------------------------------------------------------

    public function test_a_second_worker_finds_the_first_workers_shipment_and_sends_nothing(): void
    {
        // Two workers pick up the same fulfilment. The first creates; by the time the second asks, noon holds it. Both derive
        // the same number, which is what makes the second one's question the right question.
        $this->fake([
            self::GET => Http::sequence()->push([], 404)->push($this->heldShipment())->push($this->heldShipment()),
            self::CREATE => Http::response([]),
        ]);
        $connector = app(NoonConnector::class);

        $first = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'AWB-9']]);
        $second = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'AWB-9']]);

        $this->assertTrue($first['confirmed']);
        $this->assertFalse($second['sent']);
        $this->assertTrue($second['confirmed']);
        $this->assertCount(1, $this->sentTo(self::CREATE));
    }

    public function test_a_second_worker_that_cannot_read_stops_instead_of_racing_the_first(): void
    {
        // The dangerous half of the race: the first worker's create has gone out and noon does not report it yet, and the
        // second worker cannot get an answer at all. The temptation is to send -- the number is the same, after all -- and that
        // is exactly the duplicate this refuses.
        $this->fake([
            self::GET => Http::sequence()->push([], 404)->push([], 404)->whenEmpty(Http::response([], 503)),
            self::CREATE => Http::response([]),
        ]);
        $connector = app(NoonConnector::class);

        $first = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'AWB-9']]);
        $second = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'AWB-9']]);

        $this->assertTrue($first['sent']);
        $this->assertFalse($second['sent']);
        $this->assertTrue($second['needs_review'] ?? false);
        $this->assertCount(1, $this->sentTo(self::CREATE), 'the one create the first worker made');
    }

    public function test_a_five_hundred_on_the_create_is_never_followed_by_a_second_send(): void
    {
        // THE CASE THE DERIVED NUMBER CANNOT COVER. noon answers 500, so the create may have landed; the next attempt asks and
        // noon says it holds no such shipment. That is not permission to send: `shipment/get` is not documented to be
        // read-after-write consistent, and a wrong guess here is a second van. A person settles it.
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response(['message' => 'oops'], 500)]);
        $connector = app(NoonConnector::class);

        $first = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);
        $second = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertStringContainsString('may still have reached noon', (string) $first['reason']);
        $this->assertFalse($second['sent']);
        $this->assertTrue($second['needs_review'] ?? false);
        $this->assertStringContainsString('already left and noon does not confirm it', (string) $second['reason']);
        $this->assertCount(1, $this->sentTo(self::CREATE), 'the 500 was the only create');
        $this->assertSame(ChannelShipmentSend::NEEDS_REVIEW, $this->sendRow()->state);
    }

    public function test_a_refusal_noon_certainly_did_not_process_leaves_the_shipment_sendable(): void
    {
        // The other side of the same rule, and the only road back to sending: a 400 is proof noon did not take it, so once
        // whatever it refused is fixed the same reference goes out normally.
        $this->fake([
            self::GET => Http::response([], 404),
            self::CREATE => Http::sequence()->push(['message' => 'bad awb'], 400)->push([]),
        ]);
        $connector = app(NoonConnector::class);

        $refused = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);
        $this->assertSame(ChannelShipmentSend::NOT_SENT, $this->sendRow()->state);

        $again = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'AWB-FIXED']]);

        $this->assertFalse($refused['sent']);
        $this->assertTrue($again['sent'], 'nothing had left, so this is not a repeat');
        $this->assertCount(2, $this->sentTo(self::CREATE));
    }

    public function test_an_attempt_another_process_is_already_making_sends_nothing(): void
    {
        // The claim another worker holds, from this worker's side: it does not ask noon, it does not send, and it does not call
        // for a person -- the other attempt is the one shipment.
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);
        app(ChannelShipmentSends::class)->claim($this->account, app(NoonConnector::class)->shipmentNumberFor('NO-1', ['I-1']), 'NO-1');

        $result = app(NoonConnector::class)->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'A']]);

        $this->assertFalse($result['sent']);
        $this->assertFalse($result['confirmed']);
        $this->assertFalse($result['needs_review'] ?? false, 'the other attempt is handling it');
        $this->assertStringContainsString('already running', (string) $result['reason']);
        $this->assertSame([], $this->sentTo(self::CREATE));
        $this->assertSame([], $this->sentTo(self::GET), 'noon is not even asked');
    }

    public function test_the_shared_contract_treats_another_running_attempt_as_a_skip_and_not_a_refusal(): void
    {
        // It matters which one it is. `ChannelShipmentNotifier` counts a channel's refusals and stops asking after three, so
        // reporting a colleague's work as a refusal would park shipments nobody refused.
        $this->fake([self::GET => Http::response([], 404), self::CREATE => Http::response([])]);
        app(ChannelShipmentSends::class)->claim($this->account, app(NoonConnector::class)->shipmentNumberFor('NO-1', ['I-1']), 'NO-1');

        try {
            app(NoonConnector::class)->confirmShipment($this->account, new ShipmentConfirmation(
                orderId: 'NO-1', packageId: '', carrierCode: 'noon', carrierName: null, trackingNumber: 'AWB-1',
                shippedAt: CarbonImmutable::parse('2026-11-14 10:00:00'), lineQuantities: ['I-1' => 1],
            ));
            $this->fail('a busy claim must not be answered as "the channel did not confirm"');
        } catch (ChannelUnavailableException $exception) {
            $this->assertSame(ChannelUnavailableException::ANOTHER_ATTEMPT_RUNNING, $exception->reason);
        }

        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    public function test_a_confirmed_shipment_is_not_asked_about_again(): void
    {
        $this->fake([self::GET => $this->noShipmentThenOne(), self::CREATE => Http::response([])]);
        $connector = app(NoonConnector::class);

        $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'AWB-9']]);
        $this->assertSame(ChannelShipmentSend::CONFIRMED, $this->sendRow()->state);

        $again = $connector->createShipment($this->account, 'NO-1', ['I-1'], [['courier' => 'noon', 'awb_nr' => 'AWB-9']]);

        $this->assertTrue($again['confirmed']);
        $this->assertFalse($again['sent']);
        $this->assertCount(2, $this->sentTo(self::GET), 'the two of the first attempt, and none after it was settled');
    }

    public function test_the_shared_contract_reports_an_unresolved_shipment_as_not_taken(): void
    {
        $this->fake([self::GET => Http::response([], 503), self::CREATE => Http::response([])]);

        $taken = app(NoonConnector::class)->confirmShipment($this->account, new ShipmentConfirmation(
            orderId: 'NO-1', packageId: '', carrierCode: 'noon', carrierName: null, trackingNumber: 'AWB-1',
            shippedAt: CarbonImmutable::parse('2026-11-14 10:00:00'), lineQuantities: ['I-1' => 1],
        ));

        $this->assertFalse($taken);
        $this->assertSame([], $this->sentTo(self::CREATE));
    }

    // ---- the three answers, through the connector ----------------------------------------------------------------

    public function test_the_check_separates_held_from_not_held_from_unanswerable(): void
    {
        $this->fake([self::GET => Http::sequence()->push($this->heldShipment())->push([], 404)->whenEmpty(Http::response([], 503))]);
        $connector = app(NoonConnector::class);

        $this->assertTrue($connector->checkShipment($this->account, 'PK-ABC')->isPresent());
        $this->assertTrue($connector->checkShipment($this->account, 'PK-ABC')->mayCreate(), 'the only answer a create is made on');

        $unknown = $connector->checkShipment($this->account, 'PK-ABC');
        $this->assertTrue($unknown->isUnknown());
        $this->assertFalse($unknown->mayCreate());
    }

    public function test_reading_a_shipment_answers_nothing_for_an_absence_and_for_a_failure_alike(): void
    {
        // Which is why `shipment()` must never decide a create: its null is two different things. The test is here so that a
        // future caller reaching for it finds this written down.
        $this->fake([self::GET => Http::sequence()->push([], 404)->whenEmpty(Http::response([], 503))]);
        $connector = app(NoonConnector::class);

        $this->assertNull($connector->shipment($this->account, 'PK-ABC'), 'noon holds none');
        $this->assertNull($connector->shipment($this->account, 'PK-ABC'), 'and noon could not be asked -- the same null');
    }

    public function test_a_shipment_cannot_even_be_asked_about_without_a_warehouse(): void
    {
        $this->account->forceFill(['settings' => ['country_code' => 'sa']])->save();
        $this->fake([self::GET => Http::response([], 404)]);

        $check = app(NoonConnector::class)->checkShipment($this->account->fresh(), 'PK-ABC');

        $this->assertTrue($check->isUnknown(), 'not asking is not an absence either');
        $this->assertSame([], $this->sentTo(self::GET));
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

    private function sendRow(): ChannelShipmentSend
    {
        return ChannelShipmentSend::where('account_id', $this->account->id)->firstOrFail();
    }

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
