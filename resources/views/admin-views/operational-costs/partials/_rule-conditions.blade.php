{{-- The conditions a contract rule applies under, or a note that it applies to every service of its kind. Expects $rule (App\Models\OperationalCostRule). --}}
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@php $conditions = (array) $rule->conditions; @endphp
@forelse ($conditions as $key => $value)
    <div class="fs-12">
        <span class="text-muted">{{ CostLabels::of('condition', $key) }}:</span>
        @if ($key === 'sales_channels')
            {{ implode(', ', array_map(static fn ($channel): string => CostLabels::channel($channel), (array) $value)) }}
        @elseif (is_array($value))
            {{ implode(', ', $value) }}
        @else
            <span class="k-num">{{ CostFormat::figure($value) }}</span>
        @endif
    </div>
@empty
    <span class="fs-12 text-muted">{{ translate('applies_to_every_service_of_this_kind') }}</span>
@endforelse
