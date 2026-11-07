{{-- A cost entry's state as a badge: estimated, actual, reconciled, missing or disputed. Expects $state (App\Enums\OperationalCosts\CostState). --}}
@use('App\Http\Controllers\Admin\OperationalCosts\CostTones')
<x-k.badge :tone="CostTones::state($state)">{{ translate($state->labelKey()) }}</x-k.badge>
