{{-- An amount of money, or the fact that there is none. Expects $amount (string|null) and $currency (string|null). --}}
@if ($amount === null)
    @include('admin-views.sales-channels.partials._missing', ['label' => translate('unknown_value')])
@else
    <span class="k-num">{{ number_format((float) $amount, 2) }}</span> {{ $currency }}
@endif
