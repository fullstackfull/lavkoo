<html>
<table>
    <thead>
    <tr>
        <th>{{translate('Expense_Transaction_Report_List')}}</th>
    </tr>
    <tr>
        <th>{{ translate('filter_Criteria') .' '.'-'}}</th>
        <th></th>
        <th>
            {{translate('search_Bar_Content').' '.'-'.' '. ($data['search'] ?? 'N/A')}}
            @if(isset($data['vendor']))
            <br>
                {{translate('store_Name')}} - {{$data['vendor']?->shop?->name}}
            @endif
            <br>
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
        <th>{{translate('XID')}}</th>
        <th>{{translate('transaction_Date')}}</th>
        <th>{{translate('order_ID')}}</th>
        <th>{{translate('expense_Amount')}}</th>
        <th>{{translate('expense_Type')}}</th>
    </tr>
    @foreach ($data['transactions'] as $key => $transaction)
        <tr>
            <td>{{ ++$key }} </td>
            <td>{{ $transaction->orderTransaction->transaction_id }}</td>
            <td>{{ date_format($transaction?->orderTransaction->updated_at, 'd F Y h:i:s a') }}</td>
            <td>{{ $transaction->id }}</td>
            <td>
                @if(isset($data['data-from']) && $data['data-from'] == 'vendor')
                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction->coupon_discount_bearer == 'seller' ? $transaction->discount_amount:0) + ($transaction->free_delivery_bearer=='seller'?$transaction->extra_discount:0)), currencyCode: getCurrencyCode()) }}
                @else
                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction?->refer_and_earn_discount ?? 0) + (($transaction['seller_is'] == 'admin' || $transaction->coupon_discount_bearer == 'inhouse') ? $transaction->discount_amount : 0) + ($transaction->free_delivery_bearer=='admin' ? $transaction->extra_discount : (($transaction->coupon_discount_bearer == 'seller' ? $transaction->discount_amount : 0) + ($transaction->free_delivery_bearer == 'seller' ? $transaction->extra_discount:0)))), currencyCode: getCurrencyCode()) }}
                @endif
            </td>
            <td>
                @if(isset($data['data-from']) && $data['data-from'] == 'vendor')
                    {{ $transaction->coupon_discount_bearer == 'seller'?(isset($transaction->coupon->coupon_type) ? ($transaction->coupon->coupon_type == 'free_delivery' ? 'Free Delivery Promotion':ucwords(str_replace('_', ' ', $transaction->coupon->coupon_type))) : ''):'' }}
                    @if($transaction->free_delivery_bearer == 'seller')
                        {{ ucwords(str_replace('_', ' ', $transaction->extra_discount_type)) }}
                        <br>
                    @endif
                @else
                    @if ($transaction->coupon_discount_bearer == 'inhouse' || ($transaction->coupon_discount_bearer == 'seller' && $transaction->seller_is == 'admin'))
                        @if (isset($transaction->coupon->coupon_type))
                            @if ($transaction->coupon->coupon_type == 'free_delivery')
                                {{ translate('Free_Delivery_Promotion') }}
                            @else
                                {{ ucwords(str_replace('_', ' ', ($transaction->coupon->coupon_type))) }}
                            @endif
                        @elseif(!is_null($transaction->coupon_code) && $transaction?->coupon_code != 0)
                            {{ translate('Coupon_Discount') }}
                            <br>
                        @endif
                    @endif
                    @if ($transaction->free_delivery_bearer == 'admin')
                        {{ ucwords(str_replace('_', ' ', $transaction->extra_discount_type)) }}
                            <br>
                    @endif
                    @if($transaction?->refer_and_earn_discount > 0)
                        {{ translate('Referral_Discount') }}
                    @endif
                @endif
            </td>
        </tr>
    @endforeach
    </thead>
</table>
</html>
