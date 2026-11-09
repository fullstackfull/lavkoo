<?php

namespace Tests\Feature\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OdooPosting;
use App\Models\OperationalCostEntry;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooOutbox;
use RuntimeException;
use App\Services\Accounting\Odoo\OdooPostingRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * The outbox, and the one rule that keeps a cost out of somebody's books twice.
 *
 * **Odoo publishes no idempotency mechanism at all** — no external-reference field, no uniqueness constraint, no upsert.
 * `create` creates, every time it is called. So a second attempt at the same fact produces a second journal entry, and the
 * customer's accountant finds the same cost twice with nothing in Odoo to have stopped it.
 *
 * Four things stop it here, and every one of them is tested below:
 *
 *  1. a unique key on `(source_type, source_id, source_version)`, so the same fact at the same version cannot be queued twice;
 *  2. a claim, so of two runs reaching one row exactly one works it;
 *  3. **a question put to Odoo by our own reference before any create** — the only uniqueness Odoo can be asked for;
 *  4. and, when that question cannot be answered, **a row that waits for a person and that no run picks up again.**
 *
 * The fourth is the one that cannot be automated away. Retrying might post the same cost twice; abandoning it might lose one.
 * Nothing in this module chooses between those on somebody's behalf.
 */
class OdooOutboxTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;
    use CreatesOperationalCostData;

    private SalesChannelAccount $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildOdooSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00 UTC'));
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true, 'odoo.draft_creation_enabled' => true]);
        $this->connection = $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
    }

    // ---- the queue ----------------------------------------------------------------------------------------------

    public function test_the_same_fact_queued_twice_is_one_row(): void
    {
        $entry = $this->settledCost('10');

        $first = app(OdooOutbox::class)->enqueue($entry, self::ODOO_COMPANY);
        $again = app(OdooOutbox::class)->enqueue($entry, self::ODOO_COMPANY);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, OdooPosting::count());
    }

    public function test_a_changed_figure_is_a_new_version_and_a_new_row_rather_than_a_second_posting(): void
    {
        // A corrected figure is settled by a reversal, never by posting the same version again or editing an entry already in
        // the books. So it is a different row with a different reference.
        $entry = $this->settledCost('10');
        $first = app(OdooOutbox::class)->enqueue($entry, self::ODOO_COMPANY);

        $entry->forceFill(['amount' => '12.000000', 'base_amount' => '12.000000'])->saveQuietly();
        $second = app(OdooOutbox::class)->enqueue($entry->fresh(), self::ODOO_COMPANY);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->odoo_ref, $second->odoo_ref);
        $this->assertSame(2, OdooPosting::count());
    }

    public function test_a_row_one_run_holds_is_not_handed_to_another(): void
    {
        app(OdooOutbox::class)->enqueue($this->settledCost('10'), self::ODOO_COMPANY);

        $first = app(OdooOutbox::class)->claim();
        $second = app(OdooOutbox::class)->claim();

        $this->assertCount(1, $first);
        $this->assertSame([], $second, 'the row is already held');
    }

    public function test_a_row_a_dead_run_still_holds_is_given_up_after_the_lease(): void
    {
        app(OdooOutbox::class)->enqueue($this->settledCost('10'), self::ODOO_COMPANY);
        app(OdooOutbox::class)->claim();
        $this->travel((int) config('odoo.outbox.claim_minutes') + 1)->minutes();

        $reclaimed = app(OdooOutbox::class)->reclaimAbandoned();

        $this->assertSame(1, $reclaimed);
        $this->assertCount(1, app(OdooOutbox::class)->claim());
        $this->assertSame(0, OdooPosting::first()->attempts, 'and no attempt is counted: none was finished');
    }

    // ---- Odoo is asked before anything is written ----------------------------------------------------------------

    public function test_an_entry_odoo_already_holds_is_not_created_again(): void
    {
        // The retry after a lost answer: an earlier attempt landed and we never saw the reply. Odoo's own word closes the row.
        $this->enqueued('10');
        $this->fake(['search' => $this->odooXml([['id' => 55, 'ref' => 'x', 'state' => 'draft', 'name' => 'MISC/2026/0007']])]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['found']);
        $this->assertSame(0, $stats['posted']);
        $this->assertSame(OdooPosting::POSTED, OdooPosting::first()->state);
        $this->assertSame(55, OdooPosting::first()->odoo_move_id);
        $this->assertNotNull(OdooPosting::first()->verified_at, 'found by asking, not by creating, and the row says which');
        $this->assertCount(1, $this->objectCalls(), 'ONE call: the question. No create.');
        $this->assertSame(0, $this->createsSent());
    }

    public function test_a_create_odoo_answers_with_an_id_is_posted(): void
    {
        $row = $this->enqueued('10');
        $this->fake(['search' => $this->odooXml([]), 'create' => $this->odooXml(91)]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['posted']);
        $this->assertSame(91, OdooPosting::first()->odoo_move_id);
        $this->assertSame(OdooPosting::POSTED, OdooPosting::first()->state);
        $this->assertNull(OdooPosting::first()->verified_at, 'Odoo answered the create directly; nothing needed verifying');
        $this->assertStringContainsString($row->odoo_ref, $this->objectCalls()[1], 'our reference goes into the entry');
    }

    public function test_a_posted_row_is_never_worked_again(): void
    {
        $this->enqueued('10');
        $this->fake(['search' => $this->odooXml([]), 'create' => $this->odooXml(91)]);
        $this->drain();

        $this->assertSame([], app(OdooOutbox::class)->claim(), 'a posted row is not due');
    }

    // ---- THE CASE THAT MUST NEVER RETRY ITSELF -------------------------------------------------------------------

    public function test_a_create_whose_answer_was_lost_is_verified_and_posted_when_odoo_has_it(): void
    {
        // The request went, the answer did not come back, and the entry DOES exist. Asking is what turns that from a duplicate
        // into a resolved row.
        $this->enqueued('10');
        $this->fakeSequence([
            $this->odooXml([]),                                                     // nothing there before the create
            fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'),
            $this->odooXml([['id' => 77, 'ref' => 'x', 'state' => 'draft']]),       // and Odoo does hold it
        ]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['found']);
        $this->assertSame(77, OdooPosting::first()->odoo_move_id);
        $this->assertSame(OdooPosting::POSTED, OdooPosting::first()->state);
        $this->assertSame(1, $this->createsSent(), 'exactly one create, ever');
    }

    public function test_a_create_whose_answer_was_lost_is_left_for_a_person_even_when_odoo_says_it_holds_none(): void
    {
        // **THE STRICT LINE, and it is deliberate.** Odoo answered that it holds no entry with this reference, which looks like
        // proof the create did not land. It is not: a read can be behind its own write, and Odoo documents neither way. The
        // owner's rule is that only CONCLUSIVE proof lifts the fence, so this waits for a person rather than being retried.
        //
        // The cost is real and worth naming: an Odoo outage during a create now parks rows instead of healing itself. The
        // alternative is a second entry in somebody's ledger, corrected only by a reversal they have to explain.
        $this->enqueued('10');
        $this->fakeSequence([
            $this->odooXml([]),
            fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'),
            $this->odooXml([]),
        ]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['needs_review']);
        $row = OdooPosting::first();
        $this->assertSame(OdooPosting::NEEDS_REVIEW, $row->state);
        $this->assertSame('odoo_create_outcome_unknown', $row->failure_code);
        $this->assertStringContainsString('lagging read', (string) $row->failure_detail, 'and the row says WHY it is not proof');
        $this->assertNull($row->available_at, 'nothing makes it due again');
    }

    public function test_a_create_nobody_can_confirm_or_deny_waits_for_a_person_and_no_run_touches_it(): void
    {
        // **THE financial guarantee.** The create may have posted into the customer's books and the verification could not be
        // made either. Retrying might post the cost twice; abandoning it might lose it. No automatic retry, ever.
        $this->enqueued('10');
        $this->fakeSequence([
            $this->odooXml([]),
            fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'),
            fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'),
        ]);

        $stats = $this->drain();

        $row = OdooPosting::first();
        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame(OdooPosting::NEEDS_REVIEW, $row->state);
        $this->assertSame('odoo_create_outcome_unknown', $row->failure_code);
        $this->assertNull($row->available_at, 'nothing makes it due again');
        $this->assertNotNull($row->needs_review_since, 'and the clock on the decision a person is owed starts now');
        $this->assertNull($row->odoo_move_id);

        // And a later run does not pick it up, however much time passes.
        $this->travel(30)->days();
        $this->assertSame([], app(OdooOutbox::class)->claim());
    }

    public function test_a_second_run_after_an_unknown_outcome_sends_no_second_create(): void
    {
        // The whole point, stated as a count. Two runs, one create.
        $this->enqueued('10');
        $this->fakeSequence([
            $this->odooXml([]),
            fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);
        $this->drain();

        $this->drain();

        $this->assertSame(1, $this->createsSent());
        $this->assertSame(OdooPosting::NEEDS_REVIEW, OdooPosting::first()->state);
    }

    public function test_a_five_hundred_on_the_create_is_treated_as_may_have_landed(): void
    {
        // A 5xx for a write proves nothing: Odoo may have committed the entry and failed afterwards.
        $this->enqueued('10');
        $this->fakeSequence([$this->odooXml([]), Http::response('', 503), Http::response('', 503)]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame(1, $this->createsSent(), 'and a write is not retried by the transport either');
    }

    public function test_a_two_hundred_nobody_could_parse_is_treated_as_may_have_landed(): void
    {
        $this->enqueued('10');
        $this->fakeSequence([$this->odooXml([]), Http::response('<html>502</html>'), $this->odooXml([['id' => 81, 'ref' => 'x']])]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['found'], 'asked, and Odoo did hold it');
        $this->assertSame(81, OdooPosting::first()->odoo_move_id);
    }

    public function test_a_request_that_never_left_this_host_is_the_one_failure_that_may_simply_be_retried(): void
    {
        $this->enqueued('10');
        $this->fakeSequence([$this->odooXml([]), fn () => throw new ConnectionException('cURL error 6: Could not resolve host: books.example.test')]);

        $stats = $this->drain();

        $row = OdooPosting::first();
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(OdooPosting::PENDING, $row->state);
        $this->assertSame('odoo_create_never_left_this_host', $row->failure_code);
        $this->assertNotNull($row->available_at);
    }

    public function test_a_refusal_from_odoo_is_not_retried_by_itself(): void
    {
        // Odoo arrived at an answer. The same request would be refused again, so retrying it just burns the row's attempts: it
        // waits for a person or for a changed mapping.
        $this->enqueued('10');
        $this->fakeSequence([$this->odooXml([]), $this->odooFault(2, "ValidationError\nTraceback ...")]);

        $stats = $this->drain();

        $row = OdooPosting::first();
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(OdooPosting::FAILED, $row->state);
        $this->assertSame('odoo_refused:2', $row->failure_code);
        $this->assertNull($row->available_at, 'not made due again by itself');
        $this->assertStringContainsString('Traceback', (string) $row->failure_detail, 'kept for a person to read');
    }

    public function test_odoo_that_cannot_be_asked_before_the_create_risks_nothing_and_waits(): void
    {
        // Nothing has been written by this row, so there is nothing to be ambiguous about: it could not be asked, and it waits.
        $this->enqueued('10');
        $this->fake(['search' => Http::response('', 503)]);

        $stats = $this->drain();

        $row = OdooPosting::first();
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(OdooPosting::PENDING, $row->state);
        $this->assertSame('odoo_could_not_be_asked_before_creating', $row->failure_code);
        $this->assertSame(0, $this->createsSent());
    }

    // ---- the crash window: between the request leaving and the answer being recorded ------------------------------
    //
    // THE SECOND BLOCKING FINDING ON THIS WORK, and the same one the noon shipments had. The row was marked after `create`
    // returned, so a run that died in between left it reading `claimed` -- ownership as a state, which says nothing about the
    // books -- and `reclaimAbandoned()` then set it back to `pending`. The row that had a journal entry somewhere in Odoo came
    // back looking brand new, and the next run posted the cost again.
    //
    // The fence is now written BEFORE the request, ownership lives in the claim columns alone, and giving up a lease never
    // touches the state.

    public function test_a_run_that_dies_after_odoo_receives_the_create_leaves_no_room_for_a_second_one(): void
    {
        $this->enqueued('10');
        $creates = 0;
        $this->fakeSequence([
            $this->odooXml([]),
            function () use (&$creates) {
                $creates++;
                // Odoo has the request. This run stops existing right here, before PHP records anything at all.
                throw new RuntimeException('the run was killed after the request went out');
            },
        ]);

        try {
            $this->drain();
            $this->fail('the simulated crash should have propagated');
        } catch (RuntimeException $died) {
            $this->assertStringContainsString('killed after the request', $died->getMessage());
        }

        $this->assertSame(1, $creates, 'Odoo got one create');
        $this->assertSame(OdooPosting::MAY_HAVE_POSTED, OdooPosting::first()->state, 'and the row knew it BEFORE the request went');

        // The dead run's lease expires, and another run takes the row over. Odoo answers that it holds no such entry -- which a
        // read behind its own write says as readily as a database that never got the create.
        $this->travel((int) config('odoo.outbox.claim_minutes') + 1)->minutes();
        $this->fakeSequence([$this->odooXml([])]);

        $stats = $this->drain();

        $this->assertSame(1, $this->createsSent(), 'ONE create in the whole test -- the one the dying run made, and no second one');
        $this->assertSame(1, $stats['needs_review']);
        $this->assertSame(OdooPosting::NEEDS_REVIEW, OdooPosting::first()->state);
    }

    public function test_a_fenced_row_odoo_turns_out_to_hold_is_resolved_without_a_create(): void
    {
        // The happy recovery from the same crash: the entry IS there, so Odoo's own word closes the row and nothing is sent.
        $this->enqueued('10');
        $this->fakeSequence([
            $this->odooXml([]),
            function () { throw new RuntimeException('killed'); },
        ]);
        try {
            $this->drain();
        } catch (RuntimeException) {
        }
        $this->travel((int) config('odoo.outbox.claim_minutes') + 1)->minutes();
        $this->fakeSequence([$this->odooXml([['id' => 64, 'ref' => 'x', 'state' => 'draft']])]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['found']);
        $this->assertSame(64, OdooPosting::first()->odoo_move_id);
        $this->assertSame(OdooPosting::POSTED, OdooPosting::first()->state);
        $this->assertSame(1, $this->createsSent(), 'the dying run\'s create, and no other');
    }

    public function test_giving_up_an_expired_lease_never_clears_what_is_known_about_the_books(): void
    {
        // `reclaimAbandoned()` used to set the state back to `pending`. That single line is what turned a crashed run into a
        // duplicate entry, so it now clears the claim and nothing else.
        $row = $this->enqueued('10');
        $outbox = new OdooOutbox();
        $claimed = $outbox->claim()[0];
        $outbox->aboutToCreate($claimed);
        $this->travel((int) config('odoo.outbox.claim_minutes') + 1)->minutes();

        $this->assertSame(1, $outbox->reclaimAbandoned());

        $fresh = OdooPosting::firstOrFail();
        $this->assertSame(OdooPosting::MAY_HAVE_POSTED, $fresh->state);
        $this->assertNull($fresh->claimed_by, 'the claim went back');
        $this->assertTrue($fresh->mayHavePosted());
    }

    public function test_a_late_settle_from_an_overtaken_run_changes_nothing(): void
    {
        // A slow run whose lease ran out, coming back to report. It is speaking about a world it stopped observing, and every
        // settle is guarded by the run that owns the row, so its word is a no-op.
        $this->enqueued('10');
        $slow = new OdooOutbox();
        $live = new OdooOutbox();
        $heldBySlow = $slow->claim()[0];
        $slow->aboutToCreate($heldBySlow);
        $this->travel((int) config('odoo.outbox.claim_minutes') + 1)->minutes();
        $heldByLive = $live->claim()[0];

        $slow->posted($heldBySlow, 999);
        $slow->failed($heldBySlow, 'odoo_refused:400', retry: false, keepFence: false);

        $fresh = OdooPosting::firstOrFail();
        $this->assertNull($fresh->odoo_move_id, 'the overtaken run did not post anything');
        $this->assertSame(OdooPosting::MAY_HAVE_POSTED, $fresh->state, 'nor did it clear the fence');
        $this->assertSame($live->runId(), $fresh->claimed_by);
    }

    public function test_a_gate_before_the_create_lifts_the_fence_rather_than_parking_the_cost(): void
    {
        // The fence is pessimistic, so the cases that PROVE nothing was sent have to lift it. A switch is one: it refuses before
        // anything leaves this host. Without this, turning writes off would hand every queued cost to a person.
        $this->enqueued('10');
        config(['odoo.external_writes_enabled' => false]);
        $this->fakeSequence([$this->odooXml([])]);

        $stats = $this->drain();

        $this->assertSame('writes_off_environment', $stats['stopped']);
        $this->assertSame(0, $this->createsSent());
        $row = OdooPosting::firstOrFail();
        $this->assertSame(OdooPosting::PENDING, $row->state, 'proof nothing was sent');
        $this->assertSame(0, $row->attempts, 'and the operator\'s decision cost the row nothing');
    }

    public function test_a_fenced_row_is_never_created_from_however_many_runs_reach_it(): void
    {
        // The invariant, stated as a count: once the fence is up, no number of runs produces a second create.
        $this->enqueued('10');
        $outbox = new OdooOutbox();
        $outbox->aboutToCreate($outbox->claim()[0]);
        $this->travel((int) config('odoo.outbox.claim_minutes') + 1)->minutes();
        $this->fakeSequence([$this->odooXml([]), $this->odooXml([]), $this->odooXml([])]);

        $this->drain();
        $this->drain();
        $this->drain();

        $this->assertSame(0, $this->createsSent());
    }

    // ---- what the builder refuses ---------------------------------------------------------------------------------

    public function test_a_row_whose_mapping_is_missing_waits_for_a_person_to_add_it(): void
    {
        \App\Models\OdooAccountMapping::query()->delete();
        $this->enqueued('10');
        $this->fake([]);

        $stats = $this->drain();

        $row = OdooPosting::first();
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(OdooPosting::PENDING, $row->state, 'a mapping can be added, so the row waits');
        $this->assertStringContainsString('odoo_account_not_mapped', (string) $row->failure_code);
        Http::assertNothingSent();
    }

    public function test_a_fact_that_can_never_be_posted_is_laid_to_rest_rather_than_retried_for_ever(): void
    {
        // An estimate with no approved policy behind it will not become postable by waiting. The row is kept and counted,
        // because a cost nobody posted belongs on a screen.
        $estimate = $this->recordCost(CostState::Estimated, '30', CostComponent::Shipping);
        app(OdooOutbox::class)->enqueue($estimate, self::ODOO_COMPANY);
        $this->fake([]);

        $stats = $this->drain();

        $row = OdooPosting::first();
        $this->assertSame(1, $stats['not_postable']);
        $this->assertSame(OdooPosting::DEAD, $row->state);
        $this->assertSame('cost_is_an_estimate_and_no_approved_policy_posts_estimates', $row->failure_code);
        Http::assertNothingSent();
    }

    public function test_a_row_whose_fact_has_moved_on_is_not_posted_under_the_old_reference(): void
    {
        // The figure changed between the enqueue and the run. Posting it now would put the old number in the books under a
        // reference that no longer describes it; the new version has a row of its own.
        $entry = $this->settledCost('10');
        app(OdooOutbox::class)->enqueue($entry, self::ODOO_COMPANY);
        $entry->forceFill(['amount' => '11.000000', 'base_amount' => '11.000000'])->saveQuietly();
        $this->fake([]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['not_postable']);
        $this->assertSame('source_version_superseded', OdooPosting::first()->failure_code);
        Http::assertNothingSent();
    }

    public function test_a_row_whose_source_is_gone_is_closed_rather_than_retried(): void
    {
        $entry = $this->settledCost('10');
        app(OdooOutbox::class)->enqueue($entry, self::ODOO_COMPANY);
        OperationalCostEntry::whereKey($entry->id)->delete();
        $this->fake([]);

        $stats = $this->drain();

        $this->assertSame(1, $stats['not_postable']);
        $this->assertSame('source_row_is_gone', OdooPosting::first()->failure_code);
    }

    public function test_attempts_run_out_and_the_row_is_laid_to_rest(): void
    {
        $this->enqueued('10');
        OdooPosting::query()->update(['attempts' => (int) config('odoo.outbox.attempts') - 1]);
        $this->fake(['search' => Http::response('', 503)]);

        $this->drain();

        $this->assertSame(OdooPosting::DEAD, OdooPosting::first()->state);
        $this->assertNull(OdooPosting::first()->available_at);
    }

    // ---- a switch thrown off mid-run ------------------------------------------------------------------------------

    public function test_a_switch_thrown_off_mid_run_stops_it_and_costs_the_row_nothing(): void
    {
        // Somebody who turns posting off means it to stop now, not at the end of the batch. The claim goes back untouched: their
        // decision does not spend the row's retries.
        $this->enqueued('10');
        $this->enqueued('20');
        config(['odoo.external_writes_enabled' => false]);
        $this->fake(['search' => $this->odooXml([])]);

        $stats = $this->drain();

        $this->assertSame('writes_off_environment', $stats['stopped']);
        $this->assertSame(0, $this->createsSent());
        foreach (OdooPosting::all() as $row) {
            $this->assertSame(0, $row->attempts, 'no attempt was made');
            $this->assertSame(OdooPosting::PENDING, $row->state);
        }
    }

    public function test_the_draft_switch_off_stops_the_run_before_any_entry_is_created(): void
    {
        $this->enqueued('10');
        config(['odoo.draft_creation_enabled' => false]);
        $this->fake(['search' => $this->odooXml([])]);

        $stats = $this->drain();

        $this->assertSame('writes_off_environment', $stats['stopped']);
        $this->assertSame(0, $this->createsSent());
    }

    public function test_a_rehearsing_connection_posts_nothing_and_claims_nothing(): void
    {
        $this->enqueued('10');
        $this->connection->forceFill(['dry_run' => true])->save();
        $this->connection = $this->connection->fresh();
        $this->fake(['search' => $this->odooXml([]), 'create' => $this->odooXml(91)]);

        $stats = $this->drain();

        $this->assertSame(0, $stats['posted']);
        $this->assertSame(1, $stats['failed'], 'a rehearsal proves nothing happened, so the row simply waits');
        $this->assertSame('odoo_create_never_left_this_host', OdooPosting::first()->failure_code);
        $this->assertSame(0, $this->createsSent());
    }

    // ---- fixtures -------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function drain(int $limit = 50): array
    {
        return app(OdooPostingRun::class)->drain($this->connection->fresh(), $this->odooPreflight(), $limit);
    }

    private function enqueued(string $amount): OdooPosting
    {
        return app(OdooOutbox::class)->enqueue($this->settledCost($amount), self::ODOO_COMPANY);
    }

    private function settledCost(string $amount): OperationalCostEntry
    {
        return $this->recordCost(CostState::Actual, $amount, CostComponent::Shipping, '2026-11-10');
    }

    /**
     * Both XML-RPC calls land on the same URL, so a test that wants to answer the search and the create differently gives a
     * SEQUENCE. `search` and `create` are a convenience for the common case where each shape is answered the same way.
     *
     * @param array{search?: mixed, create?: mixed} $answers
     */
    private function fake(array $answers): void
    {
        $sequence = [];
        if (isset($answers['search'])) {
            $sequence[] = $answers['search'];
        }
        if (isset($answers['create'])) {
            $sequence[] = $answers['create'];
        }
        $this->fakeSequence($sequence);
    }

    /** @var list<string> every XML-RPC body the run sent, recorded in the stub because a request that raised a connection failure is never recorded by the client. */
    private array $bodies = [];

    /** @var list<mixed> what Odoo answers next, in order; replaced between drains rather than re-faked. */
    private array $answers = [];

    private bool $faked = false;

    /**
     * What Odoo will answer, in the order the run asks.
     *
     * **THE STUB IS REGISTERED ONCE.** `Http::fake()` APPENDS stub callbacks and the first match wins, so a second
     * `Http::fake([$url => ...])` inside one test is silently the first one -- which made a test here pass for the wrong
     * reason, by falling through to the empty-queue 503. So the queue is a property and later calls only replace it.
     *
     * @param list<mixed> $answers
     */
    private function fakeSequence(array $answers): void
    {
        $this->answers = $answers;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        $this->bodies = [];

        Http::fake([self::ODOO_OBJECT => function (Request $request) {
            $this->bodies[] = $request->body();
            $next = array_shift($this->answers);
            if ($next === null) {
                // Nothing left to say: a run that asks more than the test expected should fail visibly rather than read a
                // repeat of the last answer as a fresh one.
                return Http::response('', 503);
            }
            if (is_callable($next)) {
                return $next();
            }

            // A raw XML string is a body, not a response: the stub has to hand back something Laravel can resolve.
            return is_string($next) ? Http::response($next) : $next;
        }]);
    }

    /** @return list<string> */
    private function objectCalls(): array
    {
        return $this->bodies;
    }

    private function createsSent(): int
    {
        return count(array_filter($this->bodies, static fn (string $body): bool => str_contains($body, '<string>create</string>')));
    }
}
