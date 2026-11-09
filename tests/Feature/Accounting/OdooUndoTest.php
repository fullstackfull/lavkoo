<?php

namespace Tests\Feature\Accounting;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OdooPosting;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostReturnDecision;
use App\Models\SalesChannelAccount;
use App\Services\Accounting\Odoo\OdooDraftBuilder;
use App\Services\Accounting\Odoo\OdooOutbox;
use App\Services\Accounting\Odoo\OdooPostingRun;
use App\Services\Accounting\Odoo\OdooUndoLink;
use App\Services\Accounting\Odoo\OdooUndoLinks;
use App\Services\OperationalCosts\CostLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsOdooWorld;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * **A figure already in the books is corrected by a new entry, never by changing or deleting the old one** — and a return is its
 * own entry linked to the sale's.
 *
 * That is the owner's policy (docs/sales-channels/odoo-accounting.md §4) and this file is what holds the code to it. Three things
 * are tested, and the second is the one that cost real thought:
 *
 *  1. **The link is read, never inferred.** Only two things count: `calc_inputs['reverses']`, which the ledger writes when it
 *     cancels an entry, and a row in `operational_cost_return_decisions`, which is how a return records the entry that took its
 *     units' cost of goods back off the books. Two entries that merely have opposite amounts are NOT a pair, because a wrong
 *     link reads exactly like a verified one.
 *  2. **An undo never reaches the books before the entry it undoes.** Posting the credit alone leaves the expense account short
 *     by the amount, which on a trial balance is indistinguishable from a cost nobody recorded. So it waits — and it waits
 *     without spending an attempt, because nothing about it is wrong; and it stops waiting by itself the moment the original
 *     lands. An original that was never queued for Odoo at all is a different case and must still post, or a shop that connects
 *     its accountant today could never book a return against last month's sale.
 *  3. **Nothing anywhere here edits or deletes an Odoo entry.** `write` and `unlink` are off the operation allow-list, so the
 *     only thing this integration can do with a wrong figure is add the entry that corrects it.
 */
class OdooUndoTest extends TestCase
{
    use BuildsOdooWorld;
    use BuildsSalesChannelSchema;
    use CreatesOperationalCostData;

    private SalesChannelAccount $connection;

    /** @var list<mixed> what Odoo answers next, in order. */
    private array $answers = [];

    /** @var list<string> every body the run sent, recorded in the stub rather than read back from the client. */
    private array $bodies = [];

    private int $restocks = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildOdooSchema();
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00:00 UTC'));
        config(['odoo.enabled' => true, 'odoo.external_writes_enabled' => true, 'odoo.draft_creation_enabled' => true]);
        $this->connection = $this->odooConnection();
        $this->mapOdooComponent(CostComponent::Shipping->value);
        $this->mapOdooComponent(CostComponent::Cogs->value);
    }

    // ---- what counts as a link, and what does not ---------------------------------------------------------------

    public function test_a_reversal_the_ledger_itself_wrote_is_recognised_as_undoing_the_entry_it_names(): void
    {
        // Through the real `CostLedger::reverse()`, not a hand-built row: the whole point is that this module reads the
        // vocabulary the ledger actually writes. If `reverses` were ever renamed or dropped, this is the test that fails.
        $charge = $this->settledCost('100');
        $reversal = app(CostLedger::class)->reverse($charge, adminId: 1, reason: 'the carrier withdrew the charge');

        $link = app(OdooUndoLinks::class)->for($reversal);

        $this->assertSame(OdooUndoLink::REVERSAL, $link->kind);
        $this->assertSame((int) $charge->id, $link->originalEntryId);
    }

    public function test_an_entry_with_the_opposite_amount_and_no_recorded_link_undoes_nothing(): void
    {
        // Resemblance is not provenance. Two entries for the same service with opposite amounts may be a pair, or may be two
        // separate corrections, and a link guessed from the figures would read as a verified one.
        $this->settledCost('100');
        $lookalike = $this->settledCost('-100');

        $this->assertSame(OdooUndoLink::NONE, app(OdooUndoLinks::class)->for($lookalike)->kind);
    }

    public function test_a_return_decisions_own_entry_is_recognised_as_a_return_of_the_lines_cost(): void
    {
        $sold = $this->goodsCost('60');
        $restock = $this->restockEntry('-20');
        $this->returnDecision($restock, 'RET-1');

        $link = app(OdooUndoLinks::class)->for($restock);

        $this->assertSame(OdooUndoLink::RETURN_TO_STOCK, $link->kind);
        $this->assertSame((int) $sold->id, $link->originalEntryId, 'the line\'s own cost of goods, not another return');
        $this->assertSame('RET-1', $link->returnEvent);
    }

    public function test_a_second_return_of_one_line_undoes_the_sale_and_not_the_first_return(): void
    {
        // Every restock entry shares the line's subject and component, so without excluding them the second return would
        // "undo" the first -- and then wait for the first to post before posting itself, for no reason at all.
        $sold = $this->goodsCost('60');
        $first = $this->restockEntry('-20');
        $this->returnDecision($first, 'RET-1');
        $second = $this->restockEntry('-20');
        $this->returnDecision($second, 'RET-2');

        $this->assertSame((int) $sold->id, app(OdooUndoLinks::class)->for($second)->originalEntryId);
    }

    public function test_the_lines_own_cost_entry_is_found_even_when_it_is_newer_than_the_restocks(): void
    {
        // The restocks are excluded BY ID, from the decisions table, rather than left to come out second in an ordering. Units
        // come back, and then the line's cost of goods is corrected -- so the entry that now speaks for the sale is newer than
        // both returns, and a candidate list that merely sorted by id would hand the second return the FIRST return to undo.
        $this->goodsCost('60');
        $first = $this->restockEntry('-20');
        $this->returnDecision($first, 'RET-1');
        $second = $this->restockEntry('-20');
        $this->returnDecision($second, 'RET-2');
        $corrected = $this->goodsCost('64');

        $link = app(OdooUndoLinks::class)->for($second);

        $this->assertSame((int) $corrected->id, $link->originalEntryId);
        $this->assertNotSame((int) $first->id, $link->originalEntryId, 'a return never undoes another return');
    }

    public function test_a_return_whose_line_has_no_recorded_cost_posts_unlinked_rather_than_waiting_for_ever(): void
    {
        $restock = $this->restockEntry('-20');
        $this->returnDecision($restock, 'RET-ORPHAN');

        $link = app(OdooUndoLinks::class)->for($restock);

        $this->assertSame(OdooUndoLink::RETURN_TO_STOCK, $link->kind);
        $this->assertNull($link->originalEntryId);
        $this->assertNull($link->refusal(), 'there is nothing to be out of order with');
        $this->assertStringContainsString('was not identified', (string) $link->describe());
    }

    public function test_a_posted_row_speaks_for_the_original_whatever_version_it_posted(): void
    {
        // An entry may have been queued more than once -- reopened and re-approved -- and the row that matters is the one that
        // put a figure in the books, not the newest row.
        $charge = $this->settledCost('100');
        $posted = $this->postedRowFor($charge, moveId: 71);
        $charge->forceFill(['amount' => '110.000000', 'base_amount' => '110.000000'])->saveQuietly();
        $newer = app(OdooOutbox::class)->enqueue($charge->fresh(), self::ODOO_COMPANY);
        $reversal = $this->reversalOf($charge, '-100');

        $link = app(OdooUndoLinks::class)->for($reversal);

        $this->assertNotSame($newer->id, $posted->id);
        $this->assertSame($posted->id, $link->originalPosting?->id);
        $this->assertSame(71, $link->odooMoveId());
    }

    // ---- the order of the pair ----------------------------------------------------------------------------------

    public function test_a_reversal_is_not_created_while_the_entry_it_reverses_is_still_queued(): void
    {
        $charge = $this->settledCost('100');
        app(OdooOutbox::class)->enqueue($charge, self::ODOO_COMPANY);
        $row = app(OdooOutbox::class)->enqueue($this->reversalOf($charge, '-100'), self::ODOO_COMPANY);
        $this->fakeSequence([]);

        $stats = $this->drainOnly($row);

        $this->assertSame(1, $stats['waiting']);
        $this->assertSame(0, $this->createsSent(), 'nothing was created');
        $row = $row->fresh();
        $this->assertSame(OdooPosting::PENDING, $row->state);
        $this->assertSame('odoo_original_entry_is_not_posted_yet:pending', $row->failure_code);
        $this->assertSame(0, $row->attempts, 'waiting on another row is not a failed attempt');
        $this->assertNotNull($row->available_at, 'and it will look again by itself');
    }

    public function test_a_reversal_keeps_waiting_while_its_original_waits_for_a_person(): void
    {
        // The worst case: the original's create went out and nobody knows whether Odoo holds it. Posting the reversal now would
        // be a guess in both directions at once.
        $charge = $this->settledCost('100');
        $original = app(OdooOutbox::class)->enqueue($charge, self::ODOO_COMPANY);
        $original->forceFill(['state' => OdooPosting::NEEDS_REVIEW, 'needs_review_since' => now()])->save();
        $row = app(OdooOutbox::class)->enqueue($this->reversalOf($charge, '-100'), self::ODOO_COMPANY);
        $this->fakeSequence([]);

        $stats = $this->drainOnly($row);

        $this->assertSame(1, $stats['waiting']);
        $this->assertSame('odoo_original_entry_is_not_posted_yet:needs_review', $row->fresh()->failure_code);
        $this->assertSame(0, $this->createsSent());
    }

    public function test_a_reversal_is_laid_to_rest_when_its_original_will_never_be_posted(): void
    {
        // Nothing to take back: the charge's own row is closed. A credit posted here would land on an expense account that was
        // never debited, and no amount of waiting changes that, so the row rests rather than retrying for ever.
        $charge = $this->settledCost('100');
        $original = app(OdooOutbox::class)->enqueue($charge, self::ODOO_COMPANY);
        $original->forceFill(['state' => OdooPosting::DEAD, 'outcome' => 'closed_by_a_person'])->save();
        $row = app(OdooOutbox::class)->enqueue($this->reversalOf($charge, '-100'), self::ODOO_COMPANY);
        $this->fakeSequence([]);

        $stats = $this->drainOnly($row);

        $this->assertSame(1, $stats['not_postable']);
        $this->assertSame(OdooPosting::DEAD, $row->fresh()->state);
        $this->assertSame('odoo_original_entry_will_never_be_posted', $row->fresh()->failure_code);
        $this->assertSame(0, $this->createsSent());
    }

    public function test_a_reversal_is_created_once_its_original_is_in_the_books_and_names_it(): void
    {
        $charge = $this->settledCost('100');
        $this->postedRowFor($charge, moveId: 71);
        $row = app(OdooOutbox::class)->enqueue($this->reversalOf($charge, '-100'), self::ODOO_COMPANY);
        $this->fakeSequence([$this->odooXml([]), $this->odooXml(88)]);

        $stats = $this->drainOnly($row);

        $this->assertSame(1, $stats['posted']);
        $this->assertSame(88, (int) $row->fresh()->odoo_move_id);
        $create = $this->lastCreate();
        $this->assertStringContainsString('<name>reversed_entry_id</name>', $create, 'the pair is linked in Odoo itself');
        $this->assertStringContainsString('<int>71</int>', $create);
        $this->assertStringContainsString('entry id 71', $create, 'and spelled out in the narration, for an instance with no such field');
    }

    public function test_a_reversal_of_an_entry_that_was_never_queued_is_created_and_says_so(): void
    {
        // A shop that connects its accountant today has months of settled costs that were never queued. A reversal of one of
        // those is the only record there will be of it; refusing it for ever would lose the movement entirely.
        $charge = $this->settledCost('100');
        $row = app(OdooOutbox::class)->enqueue($this->reversalOf($charge, '-100'), self::ODOO_COMPANY);
        $this->fakeSequence([$this->odooXml([]), $this->odooXml(89)]);

        $stats = $this->drainOnly($row);

        $this->assertSame(1, $stats['posted']);
        $create = $this->lastCreate();
        $this->assertStringNotContainsString('reversed_entry_id', $create, 'there is no entry at Odoo to point at');
        $this->assertStringContainsString('never queued for Odoo', $create);
    }

    // ---- the entry itself ---------------------------------------------------------------------------------------

    public function test_a_reversal_puts_the_figure_in_the_other_column_and_labels_the_line(): void
    {
        $charge = $this->settledCost('100');
        $this->postedRowFor($charge, moveId: 71);
        $reversal = $this->reversalOf($charge, '-100');

        $result = app(OdooDraftBuilder::class)->forCostEntry($reversal, $this->odooPreflight());

        $this->assertTrue($result->isReady(), implode(', ', $result->refusals));
        $expense = $result->draft->lines[0];
        $counterpart = $result->draft->lines[1];
        $this->assertSame(0.0, $expense->debit);
        $this->assertSame(100.0, $expense->credit, 'a reversal is a positive figure in the other column, never a negative debit');
        $this->assertSame(100.0, $counterpart->debit);
        $this->assertStringStartsWith('reversal of #' . $charge->id, $expense->name, 'a credit on an expense account reads as a mistake until something says otherwise');
        $this->assertSame(71, $result->draft->reversedEntryId);
    }

    public function test_a_return_labels_its_line_as_a_return_to_stock(): void
    {
        $this->goodsCost('60');
        $restock = $this->restockEntry('-20');
        $this->returnDecision($restock, 'RET-9');

        $result = app(OdooDraftBuilder::class)->forCostEntry($restock, $this->odooPreflight());

        $this->assertTrue($result->isReady(), implode(', ', $result->refusals));
        $this->assertStringStartsWith('return to stock', $result->draft->lines[0]->name);
        $this->assertStringContainsString('return RET-9', (string) $result->draft->narration);
    }

    public function test_the_link_to_odoo_is_not_written_on_an_instance_without_the_field(): void
    {
        // Odoo documents no `account.move` page at all, so every field is written only where the instance reports it. Losing
        // the link in Odoo's interface costs the audit trail nothing: it is also in `ref` and in the narration.
        $charge = $this->settledCost('100');
        $this->postedRowFor($charge, moveId: 71);
        $reversal = $this->reversalOf($charge, '-100');
        $withoutTheField = $this->odooPreflight(moveFields: [
            'move_type', 'journal_id', 'date', 'ref', 'line_ids', 'company_id', 'currency_id', 'state', 'narration',
        ]);

        $values = app(OdooDraftBuilder::class)->forCostEntry($reversal, $withoutTheField)->draft->toValues($withoutTheField);

        $this->assertArrayNotHasKey('reversed_entry_id', $values);
        $this->assertStringContainsString('entry id 71', (string) $values['narration']);
    }

    public function test_the_reference_of_a_reversal_is_an_ordinary_cost_reference(): void
    {
        // The reference is an IDENTITY, not a description. It is written into the entry at Odoo and searched for before every
        // create, so giving reversals a prefix of their own would have made every row already queued search for one string and
        // create another -- which is a duplicate in somebody's books.
        $charge = $this->settledCost('100');
        $reversal = $this->reversalOf($charge, '-100');

        $row = app(OdooOutbox::class)->enqueue($reversal, self::ODOO_COMPANY);

        $this->assertStringStartsWith('PKSA-COST-' . $reversal->id . '-', $row->odoo_ref);
    }

    public function test_a_row_whose_reference_stopped_matching_what_would_be_created_is_never_created(): void
    {
        // A guard against a change nobody has made yet. Every protection in the run is keyed to one string: the search before
        // the create asks for the ROW's reference and the create writes the DRAFT's. If those two ever stopped agreeing, a
        // retry would search for the first, not find it, and post a second entry -- with every check in place and all of them
        // looking at the wrong value.
        $row = app(OdooOutbox::class)->enqueue($this->settledCost('100'), self::ODOO_COMPANY);
        $row->forceFill(['odoo_ref' => $row->odoo_ref . '-TAMPERED'])->save();
        $this->fakeSequence([]);

        $stats = $this->drainOnly($row);

        $this->assertSame(1, $stats['not_postable']);
        $this->assertSame('odoo_reference_no_longer_matches_the_row', $row->fresh()->failure_code);
        $this->assertSame(0, $this->createsSent(), 'and Odoo was not even asked');
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------

    /**
     * Works a batch holding ONLY this row.
     *
     * Every other row is pushed past its due time rather than altered, so a test about a reversal whose original is still
     * PENDING really has an original that is still pending -- which is the state under test. A drain that took both rows would
     * post the charge first and the pair would never be out of order.
     */
    private function drainOnly(OdooPosting $row): array
    {
        OdooPosting::whereKeyNot($row->id)->update(['available_at' => now()->addDay()]);

        return app(OdooPostingRun::class)->drain($this->connection->fresh(), $this->odooPreflight());
    }

    private function settledCost(string $amount): OperationalCostEntry
    {
        return $this->recordCost(CostState::Actual, $amount, CostComponent::Shipping, '2026-11-10');
    }

    private function goodsCost(string $amount): OperationalCostEntry
    {
        return $this->recordCost(CostState::Actual, $amount, CostComponent::Cogs, '2026-11-10', [
            'subjectType' => OperationalCostReturnDecision::TARGET_CHANNEL_ORDER_LINE,
            'subjectId' => 5001,
            'identity' => 'sold-' . (string) $amount,
        ]);
    }

    /** A restock entry in a slot of its own, as `SaleCostService::restock()` records one per return event. */
    private function restockEntry(string $amount): OperationalCostEntry
    {
        $sequence = ++$this->restocks;

        return $this->recordCost(CostState::Actual, $amount, CostComponent::Cogs, '2026-11-12', [
            'subjectType' => OperationalCostReturnDecision::TARGET_CHANNEL_ORDER_LINE,
            'subjectId' => 5001,
            'identity' => 'restock:RET-' . $sequence,
            'serviceRef' => 'restock:RET-' . $sequence,
        ]);
    }

    /** The row that records which entry took a return's units' cost of goods back off the ledger. */
    private function returnDecision(OperationalCostEntry $entry, string $event): OperationalCostReturnDecision
    {
        return OperationalCostReturnDecision::create([
            'seller_id' => 0,
            'target_type' => OperationalCostReturnDecision::TARGET_CHANNEL_ORDER_LINE,
            'target_id' => 5001,
            'return_event' => $event,
            'units_restocked' => 1,
            'units_written_off' => 0,
            'entry_id' => $entry->id,
            'decided_at' => now(),
        ]);
    }

    /**
     * An entry that cancels another, carrying the link in the field the ledger uses for it.
     *
     * In its own slot rather than through `CostLedger::reverse()`, which puts the reversal in the original's slot and so
     * supersedes it: these tests are about the ODOO side of a pair that both still count, and the ledger's own vocabulary is
     * asserted against the real `reverse()` in the first test of this file.
     */
    private function reversalOf(OperationalCostEntry $original, string $amount): OperationalCostEntry
    {
        $reversal = $this->recordCost(CostState::Actual, $amount, CostComponent::Shipping, '2026-11-12', [
            'subjectId' => (int) $original->subject_id,
            'identity' => 'reversal-of-' . $original->id,
            'serviceRef' => 'reversal:' . $original->id,
        ]);
        $reversal->forceFill(['calc_inputs' => ['reverses' => (int) $original->id]])->save();

        return $reversal->fresh();
    }

    /** The charge's own row, as the outbox leaves one once Odoo has reported the entry. */
    private function postedRowFor(OperationalCostEntry $entry, int $moveId): OdooPosting
    {
        $row = app(OdooOutbox::class)->enqueue($entry, self::ODOO_COMPANY);
        $row->forceFill(['state' => OdooPosting::POSTED, 'odoo_move_id' => $moveId, 'posted_at' => now()])->save();

        return $row->fresh();
    }

    /** @param list<mixed> $answers */
    private function fakeSequence(array $answers): void
    {
        $this->answers = $answers;
        $this->bodies = [];
        Http::fake([self::ODOO_OBJECT => function (Request $request) {
            $this->bodies[] = $request->body();
            $next = array_shift($this->answers);

            // Nothing left to say: a run that asks more than the test expected fails visibly rather than reading a repeat of
            // the last answer as a fresh one.
            return $next === null ? Http::response('', 503) : Http::response($next);
        }]);
    }

    private function createsSent(): int
    {
        return count(array_filter($this->bodies, static fn (string $body): bool => str_contains($body, '<string>create</string>')));
    }

    private function lastCreate(): string
    {
        $creates = array_values(array_filter($this->bodies, static fn (string $body): bool => str_contains($body, '<string>create</string>')));
        $this->assertNotSame([], $creates, 'no create was sent');

        return $creates[count($creates) - 1];
    }
}
