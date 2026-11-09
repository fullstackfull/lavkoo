<?php

namespace Tests\Feature\SalesChannels;

use App\Models\ChannelShipmentSend;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelAccountService;
use App\Services\SalesChannels\Shipments\ChannelShipmentSends;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

/**
 * Who owns an attempt to tell a channel that goods shipped — the mutual exclusion the channel itself cannot provide.
 *
 * Asking the channel "do you already hold this shipment?" is necessary and not sufficient. Two processes can be told "no" in
 * the same instant and both send, and the derived shipment reference does not help: noon documents no idempotency rule for
 * `integration_shipment_nr`, so an identical reference makes the second attempt ASK about the same shipment, nothing more. The
 * winner is therefore decided by one unique row per `(account, reference)` — by the engine, not by the order two reads land in.
 *
 * The row also carries what no channel can be asked for: whether a send ever LEFT this system. After an answer nobody could
 * read, that memory is the whole difference between "ask again" and "send again".
 *
 * These run on MySQL and MariaDB in CI as well as SQLite, because the guarantee is the engine's unique key.
 */
class ChannelShipmentSendsTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private const REFERENCE = 'PK-7F3A91';

    private SalesChannelAccount $account;

    private ChannelShipmentSends $sends;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00 UTC'));
        $this->account = $this->anAccount('partner-1');
        $this->sends = app(ChannelShipmentSends::class);
    }

    public function test_a_first_claim_may_go_on_and_may_send(): void
    {
        $permission = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($permission->mayGoOn());
        $this->assertTrue($permission->maySend(), 'nothing has left yet');
        $this->assertNotNull($permission->token);
        $this->assertSame(1, $this->row()->attempts);
        $this->assertSame(ChannelShipmentSend::NOT_SENT, $this->row()->state, 'taking ownership is not news about the goods');
        $this->assertTrue($this->row()->claimIsLive(), 'ownership is the token and the clock, nothing else');
    }

    public function test_a_second_claim_while_the_first_is_live_is_told_somebody_else_has_it(): void
    {
        // THE RACE, in two lines. Without this both attempts would ask noon, both be told "no such shipment", and both send.
        $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $second = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($second->isBusy());
        $this->assertFalse($second->mayGoOn());
        $this->assertFalse($second->maySend());
        $this->assertNull($second->token);
        $this->assertSame(1, $this->row()->attempts, 'a refused claim is not an attempt');
    }

    public function test_a_claim_whose_process_died_is_given_up_after_the_lease(): void
    {
        $first = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->travel((int) config('sales_channels.shipment_sends.claim_minutes') + 1)->minutes();

        $second = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($second->mayGoOn());
        $this->assertNotSame($first->token, $second->token);
        $this->assertSame(2, $this->row()->attempts);
    }

    public function test_a_claim_taken_over_from_an_unsettled_send_may_ask_but_never_send(): void
    {
        // The crash that matters: a process sent, and died before it could read the shipment back. Whoever picks the reference
        // up next inherits the doubt, not a clean slate.
        $first = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->aboutToSend((string) $first->token);
        $this->travel((int) config('sales_channels.shipment_sends.claim_minutes') + 1)->minutes();

        $second = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($second->mayGoOn(), 'it may ask the channel');
        $this->assertTrue($second->sendMayHaveLeft);
        $this->assertFalse($second->maySend(), 'and it may not send');
    }

    public function test_the_fence_keeps_the_claim_because_the_attempt_is_not_over(): void
    {
        // The read-back still has to happen, and it settles the row with the same token.
        $permission = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->aboutToSend((string) $permission->token);

        $this->assertSame($permission->token, $this->row()->claim_token);
        $this->assertSame(ChannelShipmentSend::MAY_HAVE_SENT, $this->row()->state);
        $this->assertTrue($this->row()->claimIsLive(), 'the attempt is still running');

        $this->sends->confirmed((string) $permission->token);

        $this->assertSame(ChannelShipmentSend::CONFIRMED, $this->row()->state);
        $this->assertNull($this->row()->claim_token);
    }

    public function test_an_expired_fence_still_forbids_a_send_after_a_takeover(): void
    {
        // **THE CRASH WINDOW.** A worker fenced the attempt, the request went, and the process died before it could record
        // anything else. Its lease runs out. Whoever picks the reference up next must inherit the doubt -- and before the fence
        // moved ahead of the request, this row would have read `claimed`, which said nothing about the goods at all.
        $first = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->aboutToSend((string) $first->token, 'noon_create_in_flight');
        $this->travel((int) config('sales_channels.shipment_sends.claim_minutes') + 1)->minutes();

        $second = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($second->mayGoOn(), 'it may ask noon');
        $this->assertTrue($second->sendMayHaveLeft);
        $this->assertFalse($second->maySend(), 'and it may NOT send');
        $this->assertSame('noon_create_in_flight', $second->reason);
        $this->assertSame(ChannelShipmentSend::MAY_HAVE_SENT, $this->row()->state, 'the takeover did not clear what is known');
    }

    public function test_a_slow_attempt_that_was_overtaken_cannot_clear_the_fence_afterwards(): void
    {
        // The other half of the same window: the first worker is alive, its request is still in flight, and its lease expires.
        // When it finally returns and reports "nothing was sent", it is speaking about a world it stopped observing -- and the
        // write is keyed on its token, which the new owner has replaced, so it changes nothing.
        $slow = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->aboutToSend((string) $slow->token, 'noon_create_in_flight');
        $this->travel((int) config('sales_channels.shipment_sends.claim_minutes') + 1)->minutes();
        $live = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->sends->nothingWasSent((string) $slow->token, 'noon_refused:400');

        $this->assertSame(ChannelShipmentSend::MAY_HAVE_SENT, $this->row()->state, 'its late word changed nothing');
        $this->assertSame($live->token, $this->row()->claim_token);
        $this->assertTrue($this->sends->claim($this->account, self::REFERENCE, 'NO-1')->isBusy(), 'and the live owner still holds it');
    }

    public function test_only_proof_clears_the_fence(): void
    {
        // The one road back from `may_have_sent`, and it takes proof: a definitive refusal, a rate limit, a credential refusal,
        // a request that never left, a rehearsal, a gate. An absence of bad news is not proof.
        $first = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->aboutToSend((string) $first->token);
        $this->sends->nothingWasSent((string) $first->token, 'noon_refused:400');

        $this->assertSame(ChannelShipmentSend::NOT_SENT, $this->row()->state);
        $this->assertNull($this->row()->sent_at, 'and the row stops claiming a send ever went');
        $this->assertTrue($this->sends->claim($this->account, self::REFERENCE, 'NO-1')->maySend());
    }

    public function test_an_uncertain_send_gives_the_claim_back_without_giving_up_what_it_knows(): void
    {
        // Releasing ownership and clearing the send state are two different acts. A 5xx ends the attempt; it does not end the
        // doubt.
        $first = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->aboutToSend((string) $first->token);
        $this->sends->releaseAfterUncertainSend((string) $first->token, 'noon_create_outcome_unknown:503');

        $this->assertNull($this->row()->claim_token, 'the attempt is over');
        $this->assertFalse($this->row()->claimIsLive());

        $second = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($second->mayGoOn());
        $this->assertFalse($second->maySend(), 'but nothing is sent again');
    }

    public function test_a_confirmed_shipment_is_terminal_and_is_never_claimed_again(): void
    {
        $permission = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->confirmed((string) $permission->token);

        $again = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($again->isConfirmed());
        $this->assertFalse($again->mayGoOn());
        $this->assertSame(1, $this->row()->attempts);
    }

    public function test_a_send_that_provably_never_left_may_be_sent_again(): void
    {
        // A definitive refusal from the channel: it did not process the shipment, so repeating it once the cause is fixed is
        // not a duplicate. This is the ONLY road back to sending.
        $first = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->nothingWasSent((string) $first->token, 'noon_refused:400');

        $second = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($second->maySend());
        $this->assertFalse($second->sendMayHaveLeft);
        $this->assertSame(2, $this->row()->attempts);
    }

    public function test_a_shipment_awaiting_a_person_may_still_be_asked_about_but_not_sent(): void
    {
        // Reads are allowed to heal it -- the channel may finally show the shipment -- and only reads.
        $first = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->aboutToSend((string) $first->token);
        $this->sends->needsReview((string) $first->token, 'sent_and_noon_could_not_be_asked');

        $second = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->assertTrue($second->mayGoOn());
        $this->assertFalse($second->maySend());
        $this->assertSame('sent_and_noon_could_not_be_asked', $second->reason);
    }

    public function test_an_expired_attempt_cannot_write_over_the_row_the_next_one_owns(): void
    {
        // The settle is keyed on the token, so a process that comes back to life after its lease ran out changes nothing.
        $stale = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->travel((int) config('sales_channels.shipment_sends.claim_minutes') + 1)->minutes();
        $live = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->sends->confirmed((string) $stale->token);

        $this->assertSame($live->token, $this->row()->claim_token, 'the live attempt still owns it');
        $this->assertSame($live->token, $this->row()->claim_token);
    }

    public function test_the_engine_itself_refuses_a_second_row_for_one_shipment(): void
    {
        // Not the application: the unique key. Every guarantee above rests on this one line being true under any two
        // processes, which is why it is asserted against the real engine rather than reasoned about.
        $this->sends->claim($this->account, self::REFERENCE, 'NO-1');

        $this->expectException(QueryException::class);
        DB::table('channel_shipment_sends')->insert([
            'account_id' => $this->account->id, 'channel' => 'noon', 'reference' => self::REFERENCE,
            'external_order_id' => 'NO-1', 'state' => ChannelShipmentSend::NOT_SENT, 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_two_accounts_can_hold_the_same_reference_because_they_are_different_shipments(): void
    {
        $other = $this->anAccount('partner-2');

        $this->assertTrue($this->sends->claim($this->account, self::REFERENCE, 'NO-1')->maySend());
        $this->assertTrue($this->sends->claim($other, self::REFERENCE, 'NO-1')->maySend());
        $this->assertSame(2, ChannelShipmentSend::where('reference', self::REFERENCE)->count());
    }

    public function test_what_is_known_about_a_reference_can_be_read_back_for_a_screen(): void
    {
        $this->assertNull($this->sends->of($this->account, self::REFERENCE));

        $permission = $this->sends->claim($this->account, self::REFERENCE, 'NO-1');
        $this->sends->aboutToSend((string) $permission->token);

        $row = $this->sends->of($this->account, self::REFERENCE);
        $this->assertSame(ChannelShipmentSend::MAY_HAVE_SENT, $row->state);
        $this->assertSame('NO-1', $row->external_order_id);
        $this->assertSame('noon', $row->channel);
        $this->assertTrue($row->mayHaveReachedTheChannel());
    }

    private function row(): ChannelShipmentSend
    {
        return ChannelShipmentSend::where('account_id', $this->account->id)->where('reference', self::REFERENCE)->firstOrFail();
    }

    private function anAccount(string $external): SalesChannelAccount
    {
        return app(ChannelAccountService::class)->create(
            ['channel' => 'noon', 'name' => 'noon ' . $external, 'external_account_id' => $external, 'currency' => 'SAR', 'settings' => ['warehouse_code' => 'WH-RUH-01']],
            ['key_id' => 'key-abc', 'private_key' => 'not-used-here', 'project_code' => 'proj_x', 'channel_identifier' => 'noon'],
            adminId: 1,
        )->fresh();
    }
}
