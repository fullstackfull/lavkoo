<?php

namespace Tests\Unit\SalesChannels;

use App\Services\SalesChannels\Data\ChannelResponse;
use App\Services\SalesChannels\Noon\NoonShipmentCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether noon holds a shipment under one of our numbers, read from one `shipment/get` answer.
 *
 * **This class exists for one defect, and it is the worst kind this module can have.** The read used to answer `?array`: the
 * shipment, or null. One null served a 503, a timeout, an auth error and a 2xx whose body could not be parsed — and the create
 * took any null as "noon holds no such shipment" and sent one. So a first attempt that reached noon and whose answer was lost
 * was followed by a SECOND SHIPMENT OF THE SAME GOODS, which cannot be taken back: cancelling a noon shipment cancels every
 * item in it.
 *
 * Hence three answers and not two, and hence this file: every case below is one answer `shipment/get` can give, and exactly one
 * row in it permits a create.
 *
 * The authoritative absence is a 404 and nothing else. noon's reference page for `shipment/get` does not document what it
 * answers for a number it does not hold, so the HTTP-standard meaning is the only one available — and anything less certain
 * than that is UNKNOWN by construction, including a 2xx that carries no `fbpi_order_nr`.
 */
class NoonShipmentCheckTest extends TestCase
{
    /** @return array<string, array{0: ChannelResponse, 1: string}> */
    public static function answers(): array
    {
        return [
            // The only two answers that settle anything.
            'noon holds the shipment' => [self::response(ChannelResponse::SUCCESS, 200, ['fbpi_order_nr' => 'NO-1']), NoonShipmentCheck::PRESENT],
            'noon says it holds no such shipment' => [self::response(ChannelResponse::REJECTED, 404, []), NoonShipmentCheck::ABSENT],

            // Everything else. Each of these answered the same null as the line above it, which is the whole defect.
            'noon is failing' => [self::response(ChannelResponse::SERVER_ERROR, 503, []), NoonShipmentCheck::UNKNOWN],
            'noon is broken' => [self::response(ChannelResponse::SERVER_ERROR, 500, []), NoonShipmentCheck::UNKNOWN],
            'the answer was lost after the request went' => [self::response(ChannelResponse::AMBIGUOUS, null, null, 'cURL error 28: Operation timed out'), NoonShipmentCheck::UNKNOWN],
            'the request never left' => [self::response(ChannelResponse::NOT_SENT, null, null, 'cURL error 6: Could not resolve host'), NoonShipmentCheck::UNKNOWN],
            'noon refused our credentials' => [self::response(ChannelResponse::AUTH_ERROR, 401, []), NoonShipmentCheck::UNKNOWN],
            'noon refused our role' => [self::response(ChannelResponse::AUTH_ERROR, 403, []), NoonShipmentCheck::UNKNOWN],
            'noon told us to slow down' => [self::response(ChannelResponse::RATE_LIMITED, 429, []), NoonShipmentCheck::UNKNOWN],
            'noon refused the question itself' => [self::response(ChannelResponse::REJECTED, 400, ['message' => 'bad warehouse']), NoonShipmentCheck::UNKNOWN],
            'a gateway answered instead of noon' => [self::response(ChannelResponse::SERVER_ERROR, 502, []), NoonShipmentCheck::UNKNOWN],
            'nobody was asked, because this is a rehearsal' => [self::response(ChannelResponse::DRY_RUN, null, null), NoonShipmentCheck::UNKNOWN],

            // A 2xx is not an answer on its own. noon does not document a not-found body, so a 200 without the order number
            // might be noon saying "not here" or a body that lost its content on the way, and the two are indistinguishable.
            'a two hundred with no order number' => [self::response(ChannelResponse::SUCCESS, 200, ['awbs' => [], 'items' => []]), NoonShipmentCheck::UNKNOWN],
            'a two hundred with an empty order number' => [self::response(ChannelResponse::SUCCESS, 200, ['fbpi_order_nr' => '']), NoonShipmentCheck::UNKNOWN],
            'a two hundred with a blank order number' => [self::response(ChannelResponse::SUCCESS, 200, ['fbpi_order_nr' => '   ']), NoonShipmentCheck::UNKNOWN],
            'a two hundred with a null order number' => [self::response(ChannelResponse::SUCCESS, 200, ['fbpi_order_nr' => null]), NoonShipmentCheck::UNKNOWN],
            'a two hundred with no body at all' => [self::response(ChannelResponse::SUCCESS, 200, null), NoonShipmentCheck::UNKNOWN],
            'a two hundred that was not json' => [self::response(ChannelResponse::SUCCESS, 200, null, null, '<html>maintenance</html>'), NoonShipmentCheck::UNKNOWN],
        ];
    }

    #[DataProvider('answers')]
    public function test_each_answer_noon_can_give_lands_in_one_of_three_states(ChannelResponse $response, string $expected): void
    {
        $this->assertSame($expected, NoonShipmentCheck::of($response)->state);
    }

    #[DataProvider('answers')]
    public function test_only_noons_own_word_that_it_holds_none_permits_a_create(ChannelResponse $response, string $expected): void
    {
        // The assertion the defect would have failed: a create is permitted by ONE state, and the test is written from the
        // state rather than from the response so that a new response shape cannot quietly join the permitted side.
        $this->assertSame($expected === NoonShipmentCheck::ABSENT, NoonShipmentCheck::of($response)->mayCreate());
    }

    public function test_the_shipment_noon_reports_is_carried_through(): void
    {
        $check = NoonShipmentCheck::of(self::response(ChannelResponse::SUCCESS, 200, [
            'fbpi_order_nr' => 'NO-1',
            'awbs' => [['courier' => 'noon', 'awb_nr' => 'AWB-9'], 'not an awb'],
            'items' => [['mp_item_nr' => 'I-1']],
        ]));

        $this->assertTrue($check->isPresent());
        $this->assertSame('NO-1', $check->shipment['fbpi_order_nr']);
        $this->assertSame([['courier' => 'noon', 'awb_nr' => 'AWB-9']], $check->shipment['awbs'], 'what is not a record is dropped rather than carried as junk');
        $this->assertSame([['mp_item_nr' => 'I-1']], $check->shipment['items']);
    }

    public function test_an_unknown_says_what_happened_so_a_person_can_act_on_it(): void
    {
        // A flat "failed" would leave somebody guessing between an outage and a permission problem while goods wait.
        $this->assertSame('noon_could_not_be_asked:server_error:503', NoonShipmentCheck::of(self::response(ChannelResponse::SERVER_ERROR, 503, []))->reason);
        $this->assertSame('noon_could_not_be_asked:auth_error:401', NoonShipmentCheck::of(self::response(ChannelResponse::AUTH_ERROR, 401, []))->reason);
        $this->assertSame('noon_could_not_be_asked:ambiguous', NoonShipmentCheck::of(self::response(ChannelResponse::AMBIGUOUS, null, null))->reason);
        $this->assertSame('noon_answered_without_a_shipment_we_could_read', NoonShipmentCheck::of(self::response(ChannelResponse::SUCCESS, 200, []))->reason);
    }

    public function test_a_rehearsal_is_the_one_unknown_that_needs_nobody(): void
    {
        // It asked nobody anything and it sends nothing either way, so it walks the whole path instead of coming back as a
        // shipment awaiting human review. Every other unknown is the opposite.
        $rehearsal = NoonShipmentCheck::of(self::response(ChannelResponse::DRY_RUN, null, null));
        $this->assertTrue($rehearsal->isRehearsal());
        $this->assertFalse($rehearsal->mayCreate(), 'and it still never permits a create');

        $this->assertFalse(NoonShipmentCheck::of(self::response(ChannelResponse::SERVER_ERROR, 503, []))->isRehearsal());
        $this->assertFalse(NoonShipmentCheck::unknown('noon_cannot_be_asked_without_a_shipment_number_and_a_warehouse')->isRehearsal());
    }

    public function test_not_being_able_to_ask_at_all_is_an_unknown_and_not_an_absence(): void
    {
        // A blank warehouse or shipment number means no question was put. The old null made that look like permission to ship.
        $check = NoonShipmentCheck::unknown('noon_cannot_be_asked_without_a_shipment_number_and_a_warehouse');

        $this->assertTrue($check->isUnknown());
        $this->assertFalse($check->mayCreate());
        $this->assertNull($check->shipment);
    }

    public function test_the_three_states_are_mutually_exclusive(): void
    {
        foreach ([
            NoonShipmentCheck::present(['fbpi_order_nr' => 'NO-1', 'awbs' => [], 'items' => []]),
            NoonShipmentCheck::absent(),
            NoonShipmentCheck::unknown('whatever'),
        ] as $check) {
            $this->assertSame(1, (int) $check->isPresent() + (int) $check->mayCreate() + (int) $check->isUnknown(), 'exactly one of the three holds');
        }
    }

    /** @param array<array-key, mixed>|null $json */
    private static function response(string $kind, ?int $status, ?array $json, ?string $error = null, string $body = ''): ChannelResponse
    {
        return new ChannelResponse(
            kind: $kind,
            status: $status,
            json: $json,
            body: $body !== '' ? $body : ($json === null ? '' : (string) json_encode($json)),
            error: $error,
            durationMs: 12,
            correlationId: 'test',
        );
    }
}
