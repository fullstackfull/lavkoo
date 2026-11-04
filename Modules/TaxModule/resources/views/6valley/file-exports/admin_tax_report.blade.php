<div class="row">
    <div class="col-lg-12 text-center ">
        <h1> {{ translate('Admin_Tax_Report') }}</h1>
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
                    {{ translate('total_tax_amount') }} - {{  setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_tax_amount']), currencyCode: getCurrencyCode()) }}
                    <br>
                    {{ translate('total_amount') }} - {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['total_amount']), currencyCode: getCurrencyCode()) }}

                    @if ($data['from'])
                        <br>
                        {{ translate('from') }} -
                        {{ Carbon\Carbon::parse($data['from'])->format('d M Y') }}
                    @endif
                    @if ($data['to'])
                        <br>
                        {{ translate('to') }} -
                        {{ Carbon\Carbon::parse($data['to'])->format('d M Y') }}
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
                <th class="border-0">{{ translate('sl') }}</th>
                <th class="border-0">{{ translate('Income_Source') }}</th>
                <th class="border-0">{{ translate('Total_Income') }}</th>
                <th class="border-0">{{ translate('Total_Tax') }}</th>
            </thead>
            <tbody>
            @php
                $count = 1;
            @endphp
            @foreach ($data['taxData'] as $key => $reportItem)
                <tr>
                    <td>
                        {{ $count++ }}
                    </td>
                    <td>
                        {{ ucwords(translate($reportItem['type'])) }}
                    </td>
                    <td>
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $reportItem['amount']), currencyCode: getCurrencyCode()) }}
                    </td>
                    <td>
                        <div>
                            <div class="d-flex gap-1">
                                <span class="min-w-120">
                                    {{ translate('Total') }} ({{ $reportItem['total_tax_percentage'] }}%)
                                </span>:
                                <span>
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $reportItem['total_tax_amount']), currencyCode: getCurrencyCode()) }}
                                </span>
                            </div>

                            @foreach ($reportItem['taxes'] as $taxName => $taxItems)
                                <br>
                                <div class="d-flex gap-1 fs-12 text-body">
                                    <span class="min-w-120"> {{ $taxItems['name'] }} ({{ $taxItems['tax_rate'] }}%)</span>:
                                    <span>
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $taxItems['applicable_amount']), currencyCode: getCurrencyCode()) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
