<html>
<table>
    <thead>
    <tr>
        <th>{{translate('order_Transaction_Report_List')}}</th>
    </tr>
    <tr>
        <th>{{ translate('filter_Criteria') .' '.'-'}}</th>
        <th></th>
        <th>
            {{translate('search_Bar_Content').' '.'-'.' '. ($data['search'] ?? 'N/A')}}
            <br>
            {{translate('status').' '.'-'.' '. translate($data['status'] ?? translate('all'))}}
            <br>
            {{translate('store')}} - {{ucwords($data['vendor'] != 'all' && $data['vendor'] !='inhouse' ? $data['vendor']?->shop->name : $data['vendor'])}}
            <br>
            {{translate('customer')}} - {{ucwords($data['customer'] != 'all' ? ($data['customer']->f_name.' '.$data['customer']->l_name) : translate('all'))}}
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
        <td> {{translate('order_ID')}}    </td>
        @if(isset($data['data-from']) && $data['data-from'] == 'admin')
        <td> {{translate('shop_Name')}}    </td>
        @endif
        <td> {{translate('customer_Name')}}</td>
        <td> {{translate('total_Product_Amount')}}</td>
        <td> {{translate('product_Discount')}}</td>
        <td> {{translate('coupon_Discount')}}</td>
        <td> {{translate('referral_Discount')}}</td>
        <td> {{translate('discounted_Amount')}}</td>
        <td> {{translate('VAT/TAX')}}</td>
        <td> {{translate('shipping_Charge')}}</td>
        <td> {{translate('order_Amount')}}</td>
        <td> {{translate('Due_Amount')}}</td>
        <td> {{translate('Return_Amount')}}</td>
        <td> {{translate('delivered_By')}}</td>
        <td> {{translate('deliveryman_Incentive')}}</td>
        <td> {{translate('admin_Discount')}}</td>
        <td> {{translate('vendor_Discount')}}</td>
        <td> {{translate('admin_Commission')}}</td>
        @if(isset($data['data-from']) && $data['data-from'] == 'admin')
            <td> {{translate('admin_Net_Income')}}</td>
        @endif
        <td> {{translate('vendor_Net_income')}}</td>
        <td> {{translate('payment_Method')}}</td>
        <td> {{translate('payment_Status')}}</td>
    </tr>
    @foreach($data['transactions'] as $key => $transaction)
        <tr>
            <td>{{ $key }}</td>
            <td>
                <a class="title-color" href="{{ route('admin.orders.details', ['id' => $transaction['order_id']]) }}">
                    {{ $transaction['order_id'] }}
                </a>
            </td>
            @if(isset($data['data-from']) && $data['data-from'] == 'admin')
                <td>
                    {{ $transaction['shop_name'] }}
                    {{ $transaction['is_guest'] }}
                </td>
            @endif
            <td>
                @if (!$transaction['is_guest'] && isset($transaction['customer_name']))
                    {{ $transaction['customer_name']  }}
                @elseif($transaction['is_guest'])
                    {{ translate('guest_customer') }}
                @else
                    {{translate('not_found')}}
                @endif
            </td>
            <td>
                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['total_product_amount']), currencyCode: getCurrencyCode() ) }}
            </td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['product_discount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['coupon_discount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['referral_discount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['discounted_amount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['tax']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['shipping_charge']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['order_amount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['edit_due_amount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['edit_return_amount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ $transaction['delivered_by'] }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['deliveryman_incentive']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_discount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['vendor_discount']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_commission']), currencyCode: getCurrencyCode()) }}</td>
            @if(isset($data['data-from']) && $data['data-from'] == 'admin')
                <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_net_income']), currencyCode: getCurrencyCode()) }}</td>
            @endif
            <td>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['vendor_net_income']), currencyCode: getCurrencyCode()) }}</td>
            <td>{{ ucwords(str_replace('_',' ', $transaction['payment_method'])) }}</td>
            <td>
                {{ translate(str_replace('_',' ', $transaction['payment_status'])) }}
            </td>
        </tr>
    @endforeach
    </thead>
</table>
</html>
