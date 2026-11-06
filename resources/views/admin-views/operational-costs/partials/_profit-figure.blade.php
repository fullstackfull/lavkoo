{{-- A profit figure with its margin and a badge that says how complete it is. Expects $profit (Money), $margin (?Money), $completeness (the wording) and $complete (bool). --}}
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
<span class="k-num">{{ CostFormat::amount($profit) }}</span>
<div class="fs-10 text-muted k-num">{{ CostFormat::percent($margin) }}</div>
<x-k.badge :tone="$complete ? 'success' : 'warning'" :dot="false">{{ $completeness }}</x-k.badge>
