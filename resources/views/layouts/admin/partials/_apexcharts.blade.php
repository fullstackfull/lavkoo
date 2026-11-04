@php
    use Carbon\Carbon;
@endphp
<div class="card remove-card-shadow h-100">
    <div class="card-body p-3 p-sm-4">
        <div class="row g-2 d-flex align-items-center justify-content-between">
            <h3 class="text-capitalize">
                {{ translate($title) }}
            </h3>
            @isset($average)
                <h5>
                    <span>{{ translate('average_Earning_Value') }} :</span>
                    <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: array_sum($chartEarningStatistics)/count($chartEarningStatistics)), currencyCode: getCurrencyCode()) }}</span>
                </h5>
            @endisset
        </div>
        <div id="apex-line-chart"></div>
    </div>
</div>
<span id="statistics-data" data-statistics-title="{{translate($statisticsTitle)}}"
      data-statistics-value="{{json_encode($statisticsValue)}}" data-label="{{ json_encode($label) }}"></span>
<input name="dateType" value="{{ requestString('date_type') }}" data-count="{{count($label)}}"
       data-start="{{Carbon::parse(request('from'))->format('d')}}"
       data-end="{{Carbon::parse(request('to'))->format('d')}}"
       data-from="{{Carbon::parse(request('from'))->format('m')}}"
       data-to="{{Carbon::parse(request('to'))->format('m')}}" hidden>
<input name="currency_symbol_show_status" id="get-currency-status" value="{{!(isset($getCurrency)&& !$getCurrency) }}" hidden>
