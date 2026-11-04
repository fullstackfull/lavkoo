<html>
<table>
    <thead>
    <tr>
        <th>{{translate('vendor_Earning_Report')}}</th>
    </tr>
    <tr>

        <th>{{ translate('filter_Criteria') .' '.'-'}}</th>
        <th></th>
        <th>
            {{translate('date_type').' '.'-'.' '.translate($data['dateType'])}}
            <br>
            @if($data['from'] && $data['to'])
                {{translate('from').' '.'-'.' '.date('d M, Y',strtotime($data['from']))}}
                <br>
                {{translate('to').' '.'-'.' '.date('d M, Y',strtotime($data['to']))}}
                <br>
            @endif
        </th>
    </tr>
    <tr>
        <td> {{translate('SL')}}</td>
        <td> {{translate('vendor_Info')}}    </td>
        <td> {{translate('earn_From_Order')}}    </td>
        <td> {{translate('earn_From_Shipping')}}</td>
        <td> {{translate('deliveryman_Incentive')}}</td>
        <td> {{translate('commission_Given')}}</td>
        <td> {{translate('discount_Given')}}</td>
        <td> {{translate('tax_Collected')}}</td>
        <td> {{translate('refund_Given')}}</td>
        <td> {{translate('total_Earning')}}</td>
    </tr>
   @foreach($data['vendorEarnTable'] as $key=>$seller_earn)
        <tr>
            <td>{{$loop->iteration++}}</td>
            <td>{{ $seller_earn['vendor_info'] }}</td>
            <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['earn_from_order']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['earn_from_shipping']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['deliveryman_incentive']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['commission_given']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['discount_given']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['tax_collected']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['refund_given']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['total_earning']), currencyCode: getCurrencyCode()) }}</td>
        </tr>
    @endforeach
    </thead>
</table>
</html>
