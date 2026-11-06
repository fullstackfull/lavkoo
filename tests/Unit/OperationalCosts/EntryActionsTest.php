<?php

namespace Tests\Unit\OperationalCosts;

use App\Enums\OperationalCosts\CostState;
use App\Http\Controllers\Admin\OperationalCosts\EntryActions;
use App\Models\OperationalCostEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EntryActionsTest extends TestCase
{
    private function entry(CostState $state, ?string $amount = '5.000000', bool $effective = true, bool $approved = false, ?int $supersededBy = null): OperationalCostEntry
    {
        return (new OperationalCostEntry())->forceFill(['state' => $state, 'amount' => $amount, 'is_effective' => $effective, 'approved_at' => $approved ? '2026-11-05 10:00:00' : null, 'superseded_by_id' => $supersededBy]);
    }

    /** @return array<string, array{CostState, ?string, bool, bool, list<string>, 5?: ?int}> */
    public static function situations(): array
    {
        return [
            'an actual charge nobody checked' => [CostState::Actual, '5.000000', true, false, ['approve', 'dispute', 'reverse']],
            'an approved charge' => [CostState::Actual, '5.000000', true, true, ['dispute', 'reverse']],
            'a reconciled charge' => [CostState::Reconciled, '5.000000', true, true, ['dispute', 'reverse']],
            'an estimate' => [CostState::Estimated, '5.000000', true, false, []],
            'a missing cost' => [CostState::Missing, null, true, false, []],
            'a disputed charge' => [CostState::Disputed, '5.000000', true, false, ['resolve']],
            'a charge that was replaced' => [CostState::Actual, '5.000000', false, false, []],
            'a replaced estimate' => [CostState::Estimated, '5.000000', false, false, []],
            'a reversal, which is a negative charge' => [CostState::Actual, '-5.000000', true, false, ['approve', 'dispute']],
            'a charge of nothing' => [CostState::Actual, '0.000000', true, false, ['approve', 'dispute']],
            'a newer figure kept beside an approved one' => [CostState::Disputed, '9.000000', false, false, ['resolve']],
            'a contested charge a better figure replaced' => [CostState::Disputed, '5.000000', false, false, [], 77],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('situations')]
    public function test_only_what_makes_sense_for_an_entry_is_offered(CostState $state, ?string $amount, bool $effective, bool $approved, array $expected, ?int $supersededBy = null): void
    {
        $this->assertSame($expected, EntryActions::available($this->entry($state, $amount, $effective, $approved, $supersededBy)));
    }

    public function test_an_unknown_action_is_never_allowed(): void
    {
        $this->assertFalse(EntryActions::allows('edit', $this->entry(CostState::Actual)));
        $this->assertFalse(EntryActions::allows('delete', $this->entry(CostState::Actual)));
        $this->assertSame(['approve', 'dispute', 'reverse', 'resolve'], EntryActions::ALL, 'There is no edit and no delete among them.');
    }
}
