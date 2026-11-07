{{-- What a figure left out because its currency has no recorded exchange rate: an "incomplete" badge naming the currencies, or nothing when nothing was left out. Expects $gaps (App\Services\OperationalCosts\Profit\ConversionGaps). --}}
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@if (! $gaps->isEmpty())
    <x-k.badge tone="warning" :dot="false">{{ translate('incomplete') }}: {{ CostLabels::noExchangeRate($gaps) }}</x-k.badge>
@endif
