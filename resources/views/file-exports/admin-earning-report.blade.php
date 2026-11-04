<html>
<table>
    <thead>
    <tr>
        <th>{{translate('admin_Earning_Report')}}</th>
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
        <td> {{translate('duration')}}    </td>
        <td> {{translate('in-House_Earning')}}    </td>
        <td> {{translate('commission_Earning')}}</td>
        <td> {{translate('earn_From_Shipping')}}</td>
        <td> {{translate('deliveryMan_Incentive')}}</td>
        <td> {{translate('discount_Given')}}</td>
        <td> {{translate('VAT/TAX')}}</td>
        <td> {{translate('refund_Given')}}</td>
        <td> {{translate('total_Earning')}}</td>
    </tr>
        @foreach ($data['inhouseEarn'] as $key=>$earning)
            <tr>
                <td>{{$loop->iteration++}} </td>
                <td>{{ $earning['duration'] }}</td>
                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['in_house_earning']), currencyCode: getCurrencyCode()) }}</td>
                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['commission_earning']), currencyCode: getCurrencyCode()) }}</td>
                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['earn_from_shipping']), currencyCode: getCurrencyCode()) }}</td>
                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['deliveryman_incentive']), currencyCode: getCurrencyCode()) }}</td>
                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['discount_given']), currencyCode: getCurrencyCode()) }}</td>
                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['vat_tax']), currencyCode: getCurrencyCode()) }}</td>
                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['refund_given']), currencyCode: getCurrencyCode()) }}</td>
                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['total_earning']), currencyCode: getCurrencyCode()) }}</td>
        @endforeach
    </thead>
</table>
</html>
