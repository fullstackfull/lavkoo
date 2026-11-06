<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\TestCase;

class CostLedgerTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private CostLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->ledger = app(CostLedger::class);
    }

    private function draft(CostState $state, ?string $amount, string $identity, string $serviceRef = '', array $overrides = []): CostDraft
    {
        return new CostDraft(...($overrides + [
            'subjectType' => 'shipment',
            'subjectId' => 10,
            'component' => CostComponent::Shipping,
            'state' => $state,
            'amount' => $amount === null ? null : Money::of($amount),
            'currency' => 'SAR',
            'periodDate' => CarbonImmutable::parse('2026-11-10'),
            'source' => 'rule',
            'identity' => $identity,
            'serviceRef' => $serviceRef,
            'missingReason' => $state === CostState::Missing ? 'no_contract' : null,
        ]));
    }

    public function test_the_same_computation_is_recorded_once(): void
    {
        $first = $this->ledger->record($this->draft(CostState::Estimated, '12', 'rule-1'));
        $second = $this->ledger->record($this->draft(CostState::Estimated, '12', 'rule-1'));

        $this->assertSame(RecordOutcome::CREATED, $first->result);
        $this->assertSame(RecordOutcome::DUPLICATE, $second->result);
        $this->assertSame($first->entry->id, $second->entry->id);
        $this->assertSame(1, OperationalCostEntry::count());
    }

    public function test_a_missing_cost_has_a_null_amount_not_zero(): void
    {
        $outcome = $this->ledger->record($this->draft(CostState::Missing, null, 'none'));

        $this->assertNull($outcome->entry->fresh()->amount);
        $this->assertSame(CostState::Missing, $outcome->entry->fresh()->state);
        $this->assertSame('no_contract', $outcome->entry->missing_reason);
    }

    public function test_an_amount_on_a_missing_cost_or_none_on_a_known_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->draft(CostState::Missing, '0', 'x');
    }

    public function test_the_actual_charge_supersedes_the_estimate_so_the_service_is_never_counted_twice(): void
    {
        $estimate = $this->ledger->record($this->draft(CostState::Estimated, '12', 'rule-1'))->entry;
        $actual = $this->ledger->record($this->draft(CostState::Actual, '13.5', 'invoice-line-9', overrides: ['source' => 'invoice']))->entry;

        $this->assertFalse($estimate->fresh()->is_effective);
        $this->assertSame($actual->id, $estimate->fresh()->superseded_by_id);
        $this->assertTrue($actual->fresh()->is_effective);

        $effectiveTotal = OperationalCostEntry::effective()->get()->reduce(fn (Money $sum, OperationalCostEntry $entry): Money => $sum->plus($entry->amount), Money::zero());
        $this->assertSame('13.500000', $effectiveTotal->toStorage());
    }

    public function test_a_late_estimate_never_displaces_an_actual_cost(): void
    {
        $actual = $this->ledger->record($this->draft(CostState::Actual, '13.5', 'invoice-line-9'))->entry;
        $late = $this->ledger->record($this->draft(CostState::Estimated, '12', 'rule-2'));

        $this->assertSame(RecordOutcome::IGNORED, $late->result);
        $this->assertSame($actual->id, $late->entry->id);
        $this->assertSame(1, OperationalCostEntry::count());
    }

    public function test_a_recomputed_estimate_replaces_the_earlier_one_and_a_missing_one_never_replaces_an_estimate(): void
    {
        $first = $this->ledger->record($this->draft(CostState::Estimated, '12', 'rule-1'))->entry;
        $second = $this->ledger->record($this->draft(CostState::Estimated, '14', 'rule-2'))->entry;
        $missing = $this->ledger->record($this->draft(CostState::Missing, null, 'lost-contract'));

        $this->assertFalse($first->fresh()->is_effective);
        $this->assertTrue($second->fresh()->is_effective);
        $this->assertSame(RecordOutcome::IGNORED, $missing->result);
    }

    public function test_an_estimate_replaces_a_missing_cost_of_the_same_service(): void
    {
        $missing = $this->ledger->record($this->draft(CostState::Missing, null, 'no-contract'))->entry;
        $estimate = $this->ledger->record($this->draft(CostState::Estimated, '12', 'rule-1'))->entry;

        $this->assertFalse($missing->fresh()->is_effective);
        $this->assertTrue($estimate->fresh()->is_effective);
    }

    public function test_two_services_of_one_component_are_two_costs_when_their_service_references_differ(): void
    {
        $this->ledger->record($this->draft(CostState::Actual, '10', 'parcel-1', serviceRef: 'parcel:1'));
        $this->ledger->record($this->draft(CostState::Actual, '10', 'parcel-2', serviceRef: 'parcel:2'));

        $this->assertSame(2, OperationalCostEntry::effective()->count());
    }

    public function test_an_approved_entry_is_never_recomputed(): void
    {
        $entry = $this->ledger->record($this->draft(CostState::Actual, '13.5', 'invoice-line-9'))->entry;
        $this->ledger->approve($entry, 1);

        $this->expectException(LogicException::class);
        $entry->fresh()->forceFill(['amount' => '99'])->save();
    }

    public function test_a_different_figure_for_an_approved_service_is_kept_beside_it_outside_the_totals(): void
    {
        $approved = $this->ledger->record($this->draft(CostState::Actual, '13.5', 'invoice-line-9'))->entry;
        $this->ledger->approve($approved, 1);

        $conflict = $this->ledger->record($this->draft(CostState::Actual, '20', 'invoice-line-77'));

        $this->assertSame(RecordOutcome::CONFLICT, $conflict->result);
        $this->assertFalse($conflict->entry->is_effective);
        $this->assertSame(CostState::Disputed, $conflict->entry->state);
        $this->assertTrue($approved->fresh()->is_effective);
        $this->assertSame(1, OperationalCostEntry::effective()->count());
    }

    public function test_an_estimate_cannot_be_approved(): void
    {
        $entry = $this->ledger->record($this->draft(CostState::Estimated, '12', 'rule-1'))->entry;

        $this->expectException(InvalidArgumentException::class);
        $this->ledger->approve($entry, 1);
    }

    public function test_nothing_is_ever_deleted(): void
    {
        $entry = $this->ledger->record($this->draft(CostState::Actual, '13.5', 'invoice-line-9'))->entry;

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_a_mistake_is_reversed_with_a_new_entry_and_twice_is_one_reversal(): void
    {
        $entry = $this->ledger->record($this->draft(CostState::Actual, '13.5', 'invoice-line-9'))->entry;
        $this->ledger->approve($entry, 1);

        $this->ledger->reverse($entry, 1, 'billed twice');
        $this->ledger->reverse($entry, 1, 'billed twice');

        $this->assertSame(2, OperationalCostEntry::count());
        $net = OperationalCostEntry::effective()->get()->reduce(fn (Money $sum, OperationalCostEntry $row): Money => $sum->plus($row->amount), Money::zero());
        $this->assertSame('0.000000', $net->toStorage());
    }

    public function test_a_disputed_entry_is_flagged_and_can_be_resolved_either_way(): void
    {
        $entry = $this->ledger->record($this->draft(CostState::Actual, '13.5', 'invoice-line-9'))->entry;
        $this->ledger->dispute($entry, 'overcharged');
        $this->assertSame(CostState::Disputed, $entry->fresh()->state);

        $this->ledger->resolveDispute($entry->fresh(), true, 1, 'provider was right');
        $this->assertSame(CostState::Actual, $entry->fresh()->state);
    }

    public function test_entries_of_different_sellers_do_not_share_a_slot(): void
    {
        $this->ledger->record($this->draft(CostState::Actual, '10', 'a', overrides: ['sellerId' => 0]));
        $this->ledger->record($this->draft(CostState::Actual, '10', 'b', overrides: ['sellerId' => 7]));

        $this->assertSame(2, OperationalCostEntry::effective()->count());
        $this->assertSame(1, OperationalCostEntry::where('seller_id', 7)->count());
    }
}
