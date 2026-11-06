{{-- The amount of a cost entry. A cost nobody could determine has no amount: it shows a dash and says why it is missing, never a zero. Expects $entry (App\Models\OperationalCostEntry). --}}
@use('App\Enums\OperationalCosts\CostState')
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@if ($entry->state === CostState::Missing || $entry->amount === null)
    <span class="text-muted" aria-label="{{ translate('no_amount') }}">{{ CostFormat::DASH }}</span>
    <div class="fs-10 text-danger">{{ translate('cost_state_missing') }}@if ($entry->missing_reason) · {{ CostLabels::of('missing_reason', $entry->missing_reason) }}@endif</div>
@else
    <span class="k-num">{{ CostFormat::amount($entry->amount, $entry->currency) }}</span>
@endif
