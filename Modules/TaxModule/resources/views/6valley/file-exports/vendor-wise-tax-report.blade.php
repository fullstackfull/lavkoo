<html>
    <table>
        <thead>
            <tr>
                <th style="font-size: 18px">{{ translate('Vendor_Vat_Report') }}</th>
            </tr>
            <tr>
                <th>{{ translate('Search_Criteria') . ' - ' }}</th>
                <th></th>
                <th>
                    @if (isset($data['totalOrders']))
                        {{ translate('total_orders') . ' - ' . $data['totalOrders'] }}
                        <br>
                    @endif
                    @if (isset($data['totalOrderAmount']))
                        {{ translate('total_order_amount') . ' - ' }}{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['totalOrderAmount']), currencyCode: getCurrencyCode()) }}
                        <br>
                    @endif
                    @if (isset($data['totalTax']))
                        {{ translate('total_tax') . ' - ' }}{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $data['totalTax']), currencyCode: getCurrencyCode()) }}
                        <br>
                    @endif
                    @if (isset($data['startDate']))
                        {{ translate('from') . ' - ' . $data['startDate']->format('m/d/Y') }}
                        <br>
                    @endif
                    @if (isset($data['endDate']))
                        {{ translate('to') . ' - ' . $data['endDate']->format('m/d/Y') }}
                        <br>
                    @endif
                    {{ translate('Search_Bar_Content') . ' - ' . ($data['search'] ?? translate('N/A')) }}
                </th>
            </tr>
            <tr>
                <td>{{ translate('SL') }}</td>
                <td>{{ translate('Vendor_Info') }}</td>
                <td>{{ translate('Total_Order') }}</td>
                <td>{{ translate('Total_Order_Amount') }}</td>
                <td>{{ translate('VAT_Amount') }}</td>
            </tr>
            @php($count = 1)
            @foreach($data['shopTaxList'] as $key => $shopTaxItem)
                @php($shopItem = $shopTaxItem->first()->shop ?? null)
                <tr>
                    <td>{{ $count++ }}</td>
                    <td>
                        {{ $shopItem['name'] ?? 'N/A' }}
                        <br />
                        @if(!empty($shopItem['contact']))
                            ({{ $shopItem['contact'] }})
                        @endif
                    </td>
                    <td>{{ count($shopTaxItem) }}</td>
                    <td>
                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $shopTaxItem->sum('order_amount')), currencyCode: getCurrencyCode()) }}
                    </td>
                    <td>
                        {{ translate('Total') . ' : ' }}{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $shopTaxItem?->sum('tax') ?? 0), currencyCode: getCurrencyCode()) }}
                        @foreach($shopTaxItem?->pluck('orderTaxes')->flatten()->groupBy('tax_on')->sortKeys() as $orderTaxItemKey => $orderTaxItem)
                            @if($orderTaxItemKey == 'basic')
                                @php($groupLabel = ucwords('Order Tax'))
                            @else
                                @php($groupLabel = ucwords(str_replace('_', ' ', $orderTaxItemKey)))
                            @endif
                            <br><br>
                            {{ $groupLabel . ' : ' }}
                            @foreach($orderTaxItem->groupBy('tax_name') as $taxItemKey => $taxGroup)
                                <br>
                                {{ ucwords($taxItemKey) . ' : ' }}{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $taxGroup->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                            @endforeach
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </thead>
    </table>
</html>
