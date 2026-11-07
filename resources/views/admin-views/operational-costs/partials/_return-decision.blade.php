{{-- What came back of one order line and what was decided about it: the decisions made, and the form for the units nobody has decided about.
     Expects $line (ExternalOrderLine), $standing (ReturnDecisionService::standing()), $locations (the active locations of the order's seller) and $canDecide (may this admin decide). --}}
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Services\OperationalCosts\Returns\ReturnDecisionService')
@use('Illuminate\Support\Str')
@php
    $awaiting = $standing['awaiting'];
    $recording = $awaiting === [] && $standing['canRecordAReturn'];
    $defaultLocation = old('location_id', $line->inventory_location_id);
@endphp
<div class="border rounded p-2 mb-2" data-return-line="{{ $line->id }}">
    <div class="fs-12 fw-semibold text-break">{{ $line->title ?: $line->external_line_id }}</div>
    <div class="fs-12 text-muted">
        {{ translate('sku') }}: {{ $line->sku ?? '—' }} · {{ translate('returned') }}: {{ $line->quantity_returned }}
        · {{ translate('restocked_units') }}: {{ $standing['decisions']->sum('units_restocked') }} · {{ translate('written_off_units') }}: {{ $standing['decisions']->sum('units_written_off') }}
        @if ($line->refunded_total !== null) · {{ translate('refunded') }}: <span class="k-num">{{ CostFormat::amount($line->refunded_total, $line->currency) }}</span>@endif
    </div>

    @if ($standing['decisions']->isNotEmpty())
        <ul class="list-unstyled fs-12 mb-2 mt-1">
            @foreach ($standing['decisions'] as $decision)
                <li>
                    <x-k.badge tone="success" :dot="false">{{ translate('decided') }}</x-k.badge>
                    {{ translate('restocked_units') }}: {{ $decision->units_restocked }} · {{ translate('written_off_units') }}: {{ $decision->units_written_off }}
                    · {{ $decision->decided_at?->format('Y-m-d H:i') }}
                    @if ($decision->automatic)<x-k.badge tone="info" :dot="false">{{ translate('automatic') }}</x-k.badge>@endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($awaiting !== [])
        <div class="fs-12 text-warning mt-1">{{ translate('units_awaiting_a_restock_decision') }}: {{ array_sum($awaiting) }}</div>
    @endif

    @if ($canDecide)
        @foreach ($awaiting as $event => $units)
            @include('admin-views.operational-costs.partials._return-decision-form', ['claim' => substr($event, strlen(ReturnDecisionService::CLAIM_PREFIX)), 'token' => null, 'units' => $units, 'restockUnits' => $units])
        @endforeach
        @if ($recording)
            @include('admin-views.operational-costs.partials._return-decision-form', ['claim' => null, 'token' => (string) Str::uuid(), 'units' => null, 'restockUnits' => 1])
        @endif
    @endif
</div>
