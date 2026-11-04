<div class="row">
    <div class="col-lg-12 text-center ">
        <h1>{{ translate('Vendor_Vat_Report') }}</h1>
    </div>
    <div class="col-lg-12">
        <table>
            <thead>
                <tr>
                    <th>{{ translate('Summary') }}</th>
                    <th></th>
                    <th></th>
                    <th>
                        @if (isset($data['totalOrders']))
                            <br>
                            {{ translate('total_orders') }}
                            - {{ $data['totalOrders'] }}
                        @endif
                        @if (isset($data['totalOrderAmount']))
                            <br>
                            {{ translate('total_order_amount') }}
                            - {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['totalOrderAmount']), currencyCode: getCurrencyCode()) }}
                        @endif
                        @if (isset($data['totalTax']))
                            <br>
                            {{ translate('total_tax') }}
                            - {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['totalTax']), currencyCode: getCurrencyCode()) }}
                        @endif
                        @if (isset($data['startDate']))
                            <br>
                            {{ translate('from') }}
                            - {{ $data['startDate']->format('m/d/Y') }}
                        @endif
                        @if (isset($data['endDate']))
                            <br>
                            {{ translate('to') }}
                            - {{ $data['endDate']->format('m/d/Y') }}
                        @endif
                        <br>
                        {{ translate('Search_Bar_Content') }}- {{ $data['search'] ?? translate('N/A') }}
                        <br>
                    </th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
                <tr>
                    <th class="border-0">{{ translate('SL') }}</th>
                    <th class="border-0">{{ translate('Order_ID') }}</th>
                    <th class="border-0">{{ translate('Order_Date') }}</th>
                    <th class="border-0">{{ translate('Order_Amount') }}</th>
                    <th class="border-0">{{ translate('VAT_Type') }}</th>
                    <th class="border-0">{{ translate('VAT_Amount') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($data['orderTransactions'] as $key => $orderTransaction)
                <tr>
                    <td>{{ $key + 1 }}</td>
                    <td>
                        {{ '#'.$orderTransaction['order_id'] }}
                    </td>
                    <td>
                        {{ $orderTransaction?->order?->created_at?->format('d M, Y') ?? "N/a" }}
                    </td>
                    <td>
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction['order_amount']), currencyCode: getCurrencyCode()) }}
                    </td>
                    <td>
                        @if($orderTransaction?->order?->tax_model == 'include')
                            {{ translate('Tax_Included') }}
                        @else
                            {{ translate($orderTransaction?->orderTaxes?->first()?->tax_type ?? 'Order_Wise') }}
                        @endif
                    </td>
                    <td>
                        <div>
                            <div class="d-flex gap-2">
                                <span class="min-w-40"> {{ translate('Total') }} : </span>
                                @if(count($orderTransaction?->orderTaxes) > 0)
                                    <span>
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction?->orderTaxes?->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                                    </span>
                                @else
                                    <span>
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction?->order?->total_tax_amount), currencyCode: getCurrencyCode()) }}
                                    </span>
                                @endif
                            </div>
                            @foreach($orderTransaction?->orderTaxes->groupBy('tax_on')->sortKeys() as $orderTaxItemKey => $orderTaxItem)
                                <br />
                                <br />
                                @if($orderTaxItemKey == 'basic')
                                    <span class="fs-12 fw-semibold">{{ ucwords('Order Tax') }} : </span>
                                @else
                                    <span class="fs-12 fw-semibold">{{ ucwords(str_replace('_', ' ', $orderTaxItemKey)) }} : </span>
                                @endif
                                @foreach($orderTaxItem->groupBy('tax_name') as $taxItemKey => $orderTaxItem)
                                    <br />
                                    <br />
                                    <div class="d-flex gap-2 fs-12 text-body">
                                        <span class="min-w-40"> {{ ucwords($taxItemKey) }} : </span>
                                        <span>
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTaxItem->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                                        </span>
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
