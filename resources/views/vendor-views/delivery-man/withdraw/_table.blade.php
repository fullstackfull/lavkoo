<div class="k-table-wrap">
    <table class="k-table">
        <thead>
        <tr>
            <th>{{translate('SL')}}</th>
            <th class="k-table__num">{{translate('amount')}}</th>
            <th>{{translate('Name') }}</th>
            <th>{{translate('request_time')}}</th>
            <th>{{translate('status')}}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach($withdrawRequests as $key=>$withdrawRequest)
            <tr>
                <td><span class="k-num">{{$withdrawRequests->firstItem()+$key}}</span></td>
                <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $withdrawRequest['amount']), currencyCode: getCurrencyCode(type: 'default'))}}</span></td>
                <td>
                    @if (isset($withdrawRequest->deliveryMan))
                        <span class="title-color">{{ $withdrawRequest->deliveryMan->f_name . ' ' . $withdrawRequest->deliveryMan->l_name }}</span>
                    @else
                        <span class="k-text-subtle">{{translate('not_found')}}</span>
                    @endif
                </td>
                <td><span class="k-num">{{ date_format( $withdrawRequest->created_at, 'd-M-Y, h:i:s A') }}</span></td>
                <td>
                    @if($withdrawRequest->approved==0)
                        <x-k.badge tone="info">{{translate('pending')}}</x-k.badge>
                    @elseif($withdrawRequest->approved==1)
                        <x-k.badge tone="success">{{translate('approved')}}</x-k.badge>
                    @else
                        <x-k.badge tone="danger">{{translate('denied')}}</x-k.badge>
                    @endif
                </td>
                <td>
                    <div class="k-table__actions">
                        @if (isset($withdrawRequest->deliveryMan))
                            <button type="button"
                               class="k-btn k-btn--ghost k-btn--sm k-btn--icon withdraw-info-show"
                               data-action="{{route('vendor.delivery-man.withdraw.details',[$withdrawRequest['id']])}}"
                               title="{{translate('view')}}">
                                <x-k.icon name="eye" :size="15" />
                            </button>
                        @else
                            <span class="k-btn k-btn--ghost k-btn--sm k-btn--icon" style="opacity:.4;pointer-events:none">
                                <x-k.icon name="eye" :size="15" />
                            </span>
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@if(count($withdrawRequests) == 0)
    <x-k.empty icon="reports" :title="translate('no_withdraw_request_found')" />
@endif
<div class="k-pager">
    <span class="k-pager__info">
        @if ($withdrawRequests->total() > 0)
            {{ translate('showing') }}
            <span class="k-num">{{ $withdrawRequests->firstItem() }}–{{ $withdrawRequests->lastItem() }}</span>
            {{ translate('of') }} <span class="k-num">{{ $withdrawRequests->total() }}</span>
        @endif
    </span>
    <div>{!! $withdrawRequests->appends(request()->except('page'))->links() !!}</div>
</div>
