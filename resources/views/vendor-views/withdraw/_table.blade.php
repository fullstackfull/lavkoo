<div class="k-table-wrap">
    <table class="k-table">
        <thead>
            <tr>
                <th>{{ translate('SL') }}</th>
                <th class="k-table__num">{{ translate('amount') }}</th>
                <th>{{ translate('request_time') }}</th>
                <th>{{ translate('Transaction_Note') }}</th>
                <th>{{ translate('status') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @if ($withdrawRequests->count() > 0)
                @foreach ($withdrawRequests as $key => $withdrawRequest)
                    <tr>
                        <td><span class="k-num">{{ $withdrawRequests->firstitem() + $key }}</span></td>
                        <td class="k-table__num">
                            <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $withdrawRequest['amount']), currencyCode: getCurrencyCode(type: 'default')) }}</span>
                        </td>
                        <td>
                            <div class="k-num">{{ date('d M Y', strtotime($withdrawRequest->created_at)) }}</div>
                            <div class="k-text-subtle k-num">{{ date('h:i a', strtotime($withdrawRequest->created_at)) }}</div>
                        </td>
                        <td>
                            <p class="max-w-400 text-wrap mb-0">{{ $withdrawRequest?->transaction_note ?? 'N/a' }}</p>
                        </td>
                        <td>
                            @if ($withdrawRequest->approved == 0)
                                <x-k.badge tone="info">{{ translate('pending') }}</x-k.badge>
                            @elseif($withdrawRequest->approved == 1)
                                <x-k.badge tone="success">{{ translate('approved') }}</x-k.badge>
                            @else
                                <x-k.badge tone="danger">{{ translate('denied') }}</x-k.badge>
                            @endif
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <button type="button" data-target="#withdrawInfoViewOffcanvas{{ $withdrawRequest['id'] }}" data-toggle="offcanvas"
                                        class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{translate('view')}}">
                                    <x-k.icon name="eye" :size="15" />
                                </button>
                                @if ($withdrawRequest->approved == 0)
                                    <button type="button" data-alert-title="{{ translate('you_want_to_delete_this_withdrawal_request') }}?"
                                    data-alert-text="{{ translate('once_deleted,_the_withdrawal_request_cannot_be_recovered_and_it_will_not_appear_in_your_withdrawal_request_method_list')}}." data-toggle="tooltip" title="{{ translate('Remove Withdraw Request') }}"
                                        class="k-btn k-btn--ghost k-btn--sm k-btn--icon delete show-delete-data-alert"
                                        data-id="vendor-withdraw-delete-form-{{ $withdrawRequest['id'] }}">
                                        <x-k.icon name="trash" :size="15" />
                                    </button>
                                @endif
                            </div>
                            <form id="vendor-withdraw-delete-form-{{ $withdrawRequest['id'] }}" method="GET" style="display: none;"
                             action="{{ route('vendor.business-settings.withdraw.close', [$withdrawRequest['id']]) }}">
                                @csrf
                                @method('GET')
                            </form>
                        </td>
                    </tr>
                @endforeach
            @endif
        </tbody>
    </table>
</div>

@if ($withdrawRequests->total() <= 0)
    <x-k.empty icon="reports" :title="translate('No_Request_Done_Yet') . '!'" />
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
