<div class="col-sm-12 mb-3">
    <div class="k-card">
        <div class="k-table-wrap">
            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('SL') }}</th>
                    <th>{{ translate('order_no') }}</th>
                    <th class="k-table__num">{{ translate('earning') }}</th>
                    <th>{{ translate('earning_status') }}</th>
                    <th>{{ translate('payment_method') }}</th>
                    <th>{{ translate('status') }}</th>
                </tr>
                </thead>
                <tbody id="set-rows">
                @foreach($orders as $key=>$order)
                    <tr>
                        <td><span class="k-num">{{ $orders->firstItem()+$key }}</span></td>
                        <td>
                            <a class="text-dark" title="{{translate('order_details')}}"
                               href="{{route('admin.orders.details',['id'=>$order['id']])}}">
                                {{ $order->id }}
                            </a>
                        </td>
                        <td class="k-table__num">
                            <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order->deliveryman_charge), currencyCode: getCurrencyCode()) }}</span>
                        </td>
                        <td class="text-capitalize">
                            @if($order['order_status'] == 'delivered' && $order['payment_status']=='paid')
                                <x-k.badge tone="success">{{translate('received')}}</x-k.badge>
                            @else
                                <x-k.badge tone="danger">{{translate('not_received')}}</x-k.badge>
                            @endif
                        </td>
                        <td>{{translate($order['payment_method'])}}</td>
                        <td class="text-capitalize">
                            @switch($order['order_status'])
                                @case('pending')
                                    <x-k.badge tone="info">{{ translate($order['order_status']) }}</x-k.badge>
                                    @break
                                @case('processing')
                                    <x-k.badge tone="warning">{{ translate('packaging') }}</x-k.badge>
                                    @break
                                @case('out_for_delivery')
                                    <x-k.badge tone="warning">{{ translate(str_replace('_',' ',$order['order_status'])) }}</x-k.badge>
                                    @break
                                @case('confirmed')
                                @case('delivered')
                                    <x-k.badge tone="success">{{ translate($order['order_status']) }}</x-k.badge>
                                    @break
                                @case('failed')
                                    <x-k.badge tone="danger">{{ translate('Failed_To_Deliver') }}</x-k.badge>
                                    @break
                                @default
                                    <x-k.badge tone="danger">{{ translate($order['order_status']) }}</x-k.badge>
                            @endswitch
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if(count($orders)==0)
            <x-k.empty icon="orders" :title="translate('no_data_to_show')" />
        @endif

        <div class="k-pager">
            <span class="k-pager__info">
                @if ($orders->total() > 0)
                    {{ translate('showing') }}
                    <span class="k-num">{{ $orders->firstItem() }}–{{ $orders->lastItem() }}</span>
                    {{ translate('of') }} <span class="k-num">{{ $orders->total() }}</span>
                @endif
            </span>
            <div>
                @if(isset($currentFilters))
                    {!! $orders->appends([
                        'order_status' => $currentFilters['order_status'],
                        'payment_status' => $currentFilters['payment_status']
                    ])->links() !!}
                @else
                    {!! $orders->links() !!}
                @endif
            </div>
        </div>
    </div>
</div>
