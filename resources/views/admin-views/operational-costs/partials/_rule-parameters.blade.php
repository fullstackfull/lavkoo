{{-- The figures of a contract rule, in words, for the method it calculates with. Expects $rule (App\Models\OperationalCostRule) and $currency. --}}
@use('App\Enums\OperationalCosts\CalcMethod')
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@php
    $params = (array) $rule->params;
    $method = $rule->method();
    $money = static fn ($amount): string => CostFormat::amount($amount, $currency);
    $ending = static fn ($value, string $open): string => $value === null ? $open : CostFormat::figure($value);
@endphp
<dl class="mb-0 fs-12">
    @switch($method)
        @case(CalcMethod::Fixed)
            <dt>{{ translate('rule_flat_amount') }}</dt><dd class="k-num">{{ $money($params['amount'] ?? null) }}</dd>
            @break
        @case(CalcMethod::PerUnit)
            <dt>{{ translate('rule_amount_per_unit') }}</dt><dd class="k-num">{{ $money($params['amount'] ?? null) }}</dd>
            @break
        @case(CalcMethod::PerShipment)
            <dt>{{ translate('rule_amount_per_shipment') }}</dt><dd class="k-num">{{ $money($params['amount'] ?? null) }}</dd>
            @break
        @case(CalcMethod::PerOrder)
            <dt>{{ translate('rule_amount_per_order') }}</dt><dd class="k-num">{{ $money($params['amount'] ?? null) }}</dd>
            @break
        @case(CalcMethod::Percent)
        @case(CalcMethod::PercentPlusFixed)
            <dt>{{ translate('rule_percentage') }}</dt><dd class="k-num">{{ CostFormat::figure($params['percent'] ?? null) }}%</dd>
            @if ($method === CalcMethod::PercentPlusFixed)
                <dt>{{ translate('rule_plus_a_fixed_amount') }}</dt><dd class="k-num">{{ $money($params['amount'] ?? null) }}</dd>
            @endif
            @isset($params['min'])<dt>{{ translate('rule_minimum_charge') }}</dt><dd class="k-num">{{ $money($params['min']) }}</dd>@endisset
            @isset($params['max'])<dt>{{ translate('rule_maximum_charge') }}</dt><dd class="k-num">{{ $money($params['max']) }}</dd>@endisset
            @break
        @case(CalcMethod::PerDay)
            <dt>{{ translate('rule_amount_per_day') }}</dt><dd class="k-num">{{ $money($params['amount'] ?? null) }}</dd>
            <dt>{{ translate('rule_multiplied_by') }}</dt><dd>{{ CostLabels::of('multiply_by', $params['multiply_by'] ?? 'none') }}</dd>
            @break
        @case(CalcMethod::ByWeight)
        @case(CalcMethod::ByVolume)
            @php
                $isWeight = $method === CalcMethod::ByWeight;
                $limitKey = $isWeight ? 'up_to_kg' : 'up_to_m3';
                $extraKey = $isWeight ? 'extra_per_kg' : 'extra_per_m3';
                $stepKey = $isWeight ? 'extra_step_kg' : 'extra_step_m3';
            @endphp
            @foreach ((array) ($params['steps'] ?? []) as $step)
                <dt>{{ $isWeight ? translate('rule_up_to_kg') : translate('rule_up_to_m3') }} {{ CostFormat::figure($step[$limitKey] ?? null) }}</dt><dd class="k-num">{{ $money($step['amount'] ?? null) }}</dd>
            @endforeach
            @isset($params[$extraKey])
                <dt>{{ translate('rule_beyond_the_last_step_each_extra') }} {{ CostFormat::figure($params[$stepKey] ?? null) }} {{ $isWeight ? translate('rule_kg') : translate('rule_m3') }}</dt><dd class="k-num">{{ $money($params[$extraKey]) }}</dd>
            @else
                <dt>{{ translate('rule_beyond_the_last_step') }}</dt><dd>{{ translate('rule_not_priced_so_the_cost_is_missing') }}</dd>
            @endisset
            @break
        @case(CalcMethod::ByZone)
            @foreach ((array) ($params['zones'] ?? []) as $zone)
                <dt>{{ $zone['name'] !== '' ? $zone['name'] : translate('rule_zone') }}</dt>
                <dd class="k-num">{{ $money($zone['amount'] ?? null) }}
                    <span class="text-muted">· {{ implode(', ', array_merge((array) ($zone['cities'] ?? []), (array) ($zone['countries'] ?? []))) }}</span></dd>
            @endforeach
            @break
        @case(CalcMethod::TieredQuantity)
            @foreach ((array) ($params['tiers'] ?? []) as $tier)
                <dt>{{ translate('rule_from') }} {{ CostFormat::figure($tier['from'] ?? null) }} {{ translate('rule_to') }} {{ $ending($tier['to'] ?? null, translate('rule_and_above')) }} {{ translate('rule_units') }}</dt>
                <dd class="k-num">{{ $money($tier['amount'] ?? null) }} <span class="text-muted">· {{ CostLabels::of('tier_mode', $tier['mode'] ?? 'flat') }}</span></dd>
            @endforeach
            @break
        @case(CalcMethod::TieredValue)
            @foreach ((array) ($params['tiers'] ?? []) as $tier)
                <dt>{{ translate('rule_from') }} {{ $money($tier['from'] ?? null) }} {{ translate('rule_to') }} {{ isset($tier['to']) ? $money($tier['to']) : translate('rule_and_above') }}</dt>
                <dd class="k-num">{{ isset($tier['percent']) ? CostFormat::figure($tier['percent']) . '%' : $money($tier['amount'] ?? null) }}</dd>
            @endforeach
            @break
    @endswitch
</dl>
