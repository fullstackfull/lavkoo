<div class="row">
    <div class="col-lg-12 text-center ">
        <h1>{{ translate($data['taxSource']) }} {{ translate('Tax_Details_Report') }}</h1>
    </div>
    <div class="col-lg-12">
        <table>
            <thead>
            <tr>
                <th>{{ translate('Search_Criteria') }}</th>
                <th></th>
                <th></th>
                <th>
                    <br>
                    {{ translate('total_tax_amount') }} - {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['totalTaxAmount']), currencyCode: getCurrencyCode()) }}
                    <br>

                    @if($data['taxSource'] == 'admin_commission')
                    {{ translate('Total_Commission') }} - {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['totalAmount']), currencyCode: getCurrencyCode()) }}
                    @else
                        {{ translate('Total_Delivery_Charge') }} - {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['totalAmount']), currencyCode: getCurrencyCode()) }}
                    @endif

                    @if (isset($data['startDate']))
                        <br>
                        {{ translate('from') }} -
                        {{ $data['startDate']?->format('d M, Y') }}
                    @endif
                    @if (isset($data['endDate']))
                        <br>
                        {{ translate('to') }} -
                        {{ $data['endDate']?->format('d M, Y') }}
                    @endif
                    <br>
                    <br>
                </th>
                <th> </th>
                <th></th>
                <th></th>
                <th></th>
            </tr>
            <tr>
                <th class="border-0">{{ translate('Sl') }}</th>
                <th class="border-0">{{ translate('Order_ID') }}</th>
                <th class="border-0">{{ translate('Transaction_ID') }}</th>
                @if($data['taxSource'] == 'admin_commission')
                    <th class="border-0">{{ translate('Commission') }}</th>
                @else
                    <th class="border-0">{{ translate('Delivery_Charge') }}</th>
                @endif
                <th class="border-0">{{ translate('Tax_Amount') }}</th>
            </thead>
            <tbody>
            @foreach($data['transactions'] as $key => $transaction)
                <tr>
                    <td>
                        {{ $key + 1 }}
                    </td>
                    <td>
                        {{ '#'.$transaction['order_id'] }}
                    </td>
                    <td>
                        {{ Str::upper($transaction['transaction_id']) }}
                    </td>
                    <td>
                        @if($data['taxSource'] == 'admin_commission')
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_commission']), currencyCode: getCurrencyCode()) }}
                        @else
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['delivery_charge']), currencyCode: getCurrencyCode()) }}
                        @endif
                    </td>

                    <td>
                        <div class="d-flex gap-1">
                            <span class="min-w-120">
                                {{ translate('Total_Tax') }} ({{ $data['taxRates']->sum('tax_rate') }}%)
                            </span>:
                            <span>
                                @if($data['taxSource'] == 'admin_commission')
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction['admin_commission'] * $data['taxRates']->sum('tax_rate')) / 100), currencyCode: getCurrencyCode()) }}
                                @else
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction['delivery_charge'] * $data['taxRates']->sum('tax_rate')) / 100), currencyCode: getCurrencyCode()) }}
                                @endif
                            </span>
                        </div>
                        @foreach($data['taxRates'] as $taxRate)
                            <br>
                            <div class="d-flex gap-1 fs-12 text-body">
                                <span class="min-w-120">
                                    {{ $taxRate['name'] }} ({{ $taxRate['tax_rate'] }}%)
                                </span>
                                <span class="px-3"> : </span>
                                <span>
                                    @if($data['taxSource'] == 'admin_commission')
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction['admin_commission'] * $taxRate['tax_rate']) / 100), currencyCode: getCurrencyCode()) }}
                                    @else
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction['delivery_charge'] * $taxRate['tax_rate']) / 100), currencyCode: getCurrencyCode()) }}
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </td>

                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
