@extends('layouts.admin.app')

@section('title', translate('Withdraw_Request'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/withdraw-icon.png')}}" alt="">
                {{translate('withdraw')}}
            </h2>
        </div>

        <x-k.data-view :title="translate('withdraw_request_table')" :count="$withdrawRequests->total()"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_Name_or_Shop')">

            <x-slot:actions>
                <select name="withdraw_status_filter" data-action="{{url()->current()}}"
                        class="k-input withdraw-status-filter" style="inline-size:auto"
                        aria-label="{{ translate('status') }}">
                    <option value="all" {{request('approved') == 'all' ? 'selected' : ''}}>{{translate('all')}}</option>
                    <option value="approved" {{request('approved') == 'approved' ? 'selected' : ''}}>{{translate('approved')}}</option>
                    <option value="denied" {{request('approved') == 'denied' ? 'selected' : ''}}>{{translate('denied')}}</option>
                    <option value="pending" {{request('approved') == 'pending' ? 'selected' : ''}}>{{translate('pending')}}</option>
                </select>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.vendors.withdraw-list-export-excel') }}?approved={{ requestString('approved') }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th class="k-table__num">{{translate('amount')}}</th>
                    <th>{{ translate('name') }}</th>
                    <th>{{ translate('Shop') }}</th>
                    <th>{{translate('request_time')}}</th>
                    <th>{{translate('status')}}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($withdrawRequests as $key => $withdrawRequest)
                    <tr>
                        <td><span class="k-num">{{$withdrawRequests->firstItem() + $key }}</span></td>
                        <td class="k-table__num">
                            <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $withdrawRequest['amount']), currencyCode: getCurrencyCode(type: 'default')) }}</span>
                        </td>
                        <td>
                            @if (isset($withdrawRequest->seller))
                                <a href="{{route('admin.vendors.view', $withdrawRequest->seller_id)}}" class="text-dark text-hover-primary">{{ $withdrawRequest->seller->f_name . ' ' . $withdrawRequest->seller->l_name }}</a>
                            @else
                                <span class="k-text-subtle">{{translate('not_found')}}</span>
                            @endif
                        </td>
                        <td>{{$withdrawRequest?->seller?->shop?->name ?? '-'}}</td>
                        <td><span class="k-num">{{$withdrawRequest->created_at}}</span></td>
                        <td>
                            @if($withdrawRequest->approved == 0)
                                <x-k.badge tone="info">{{translate('pending')}}</x-k.badge>
                            @elseif($withdrawRequest->approved == 1)
                                <x-k.badge tone="success">{{translate('approved')}}</x-k.badge>
                            @elseif($withdrawRequest->approved == 2)
                                <x-k.badge tone="danger">{{translate('denied')}}</x-k.badge>
                            @endif
                        </td>
                        <td>
                            <div class="k-table__actions">
                                @if (isset($withdrawRequest->seller))
                                    <a href="{{route('admin.vendors.withdraw_view', ['withdrawId' => $withdrawRequest['id'], 'vendorId'=>$withdrawRequest->seller['id']])}}"
                                       class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{translate('Details')}}">
                                        <x-k.icon name="eye" :size="15" />
                                    </a>
                                @else
                                    <span class="k-text-subtle">{{translate('action_disabled')}}</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($withdrawRequests) == 0)
                <x-k.empty icon="reports" :title="translate('no_withdraw_request_found')" />
            @endif

            @if ($withdrawRequests->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $withdrawRequests->firstItem() }}–{{ $withdrawRequests->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $withdrawRequests->total() }}</span>
                    </span>
                    <div>{!! $withdrawRequests->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
@endsection
