@php use Illuminate\Support\Str; @endphp
@extends('layouts.vendor.app')

@section('title', translate('refund_list'))

@section('content')
    <div class="content container-fluid">
        <h2 class="h1 text-capitalize d-flex align-items-center gap-2 mb-3">
            <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/refund-request-list.png')}}" alt="">
            {{translate('refund_request_list')}}
        </h2>

        @php($refundStatus = request('status'))
        <x-k.data-view :title="translate('refund_requests')" :count="$refundList->total()"
                       searchName="search" :searchValue="$searchValue"
                       :searchPlaceholder="translate('search_by_order_id_or_refund_id')">

            <x-slot:tabs>
                @foreach(['pending', 'approved', 'refunded', 'rejected'] as $statusTab)
                    <a class="k-tab" href="{{ route('vendor.refund.index', [$statusTab, 'search' => request('search')]) }}"
                       aria-selected="{{ $refundStatus === $statusTab ? 'true' : 'false' }}">{{ translate($statusTab) }}</a>
                @endforeach
            </x-slot:tabs>

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{route('vendor.refund.export',['status'=> request('status'),'search'=>request('search'), 'from_date' => request('from_date'), 'to_date' => request('to_date')])}}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
                @php($refundFilterActive = !empty(request('from_date')) || !empty(request('to_date')))
                <button type="button" class="k-btn {{ $refundFilterActive ? 'k-btn--primary' : 'k-btn--secondary' }}"
                        data-toggle="offcanvas" data-target="#PendingRefundRequestFilter">
                    <x-k.icon name="filter" :size="15" /> {{ translate('Filter') }}
                </button>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('refund_id')}}</th>
                    <th>{{translate('order_ID')}}</th>
                    <th>{{translate('product_Info')}}</th>
                    <th>{{translate('customer_Info')}}</th>
                    <th class="k-table__num">{{ translate('total_amount') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($refundList as $key=>$refund)
                    @php($isProductUnavailable = $refund->product === null)
                    @php($productDetails = $refund?->orderDetails?->product_details ? json_decode($refund->orderDetails->product_details, true) : null)
                    <tr>
                        <td><span class="k-num">{{$refundList->firstItem()+$key}}</span></td>
                        <td>
                            <a class="title-color hover-c1"
                               href="{{route('vendor.refund.details',['id'=>$refund['id']])}}">
                                {{$refund['id']}}
                            </a>
                        </td>
                        <td>
                            <a class="title-color hover-c1"
                               href="{{route('vendor.orders.details',[$refund->order_id])}}">
                                {{$refund->order_id}}
                            </a>
                        </td>
                        <td>
                            @if (!$isProductUnavailable)
                                <a href="{{ route('vendor.products.view', [$refund->product->id]) }}" class="k-row">
                                    <img src="{{ getStorageImages(path: $refund->product->thumbnail_full_url, type: 'backend-product') }}"
                                         alt="" width="40" height="40"
                                         style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span style="min-inline-size:0">
                                        <span class="k-truncate" style="display:block;max-inline-size:220px" title="{{ $refund->product->name }}">
                                            {{ Str::limit($refund->product->name, 35) }}
                                        </span>
                                        <span class="k-text-subtle">{{ translate('qty') }} : <span class="k-num">{{ $refund?->orderDetails?->qty ?? 0 }}</span></span>
                                    </span>
                                </a>
                            @else
                                <div class="k-row" style="opacity:.5" data-toggle="tooltip"
                                     title="{{ translate('Product_has_been_deleted') }}">
                                    <img src="{{ getStorageImages(path: '', type: 'backend-product') }}"
                                         alt="" width="40" height="40"
                                         style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span style="min-inline-size:0">
                                        <span class="k-truncate" style="display:block;max-inline-size:220px">
                                            {{ Str::limit($productDetails['name'] ?? translate('product_name_not_found'), 35) }}
                                        </span>
                                        <span class="k-text-subtle">{{ translate('qty') }} : <span class="k-num">{{ $refund?->orderDetails?->qty ?? 0 }}</span></span>
                                    </span>
                                </div>
                            @endif
                        </td>
                        <td>
                            @if ($refund->customer !=null)
                                <strong class="title-color">
                                    {{$refund->customer->f_name.' '.$refund->customer->l_name}}
                                </strong>
                                <div class="k-text-subtle">
                                    {{ $refund->customer->phone ?: $refund->customer->email }}
                                </div>
                            @else
                                <span class="k-text-subtle">{{translate('customer_not_found')}}</span>
                            @endif
                        </td>
                        <td class="k-table__num">
                            <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $refund->amount), currencyCode: getCurrencyCode()) }}</span>
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('view') }}"
                                   href="{{route('vendor.refund.details',['id'=>$refund['id']])}}">
                                    <x-k.icon name="eye" :size="15" />
                                </a>
                                @if($refund['status'] != 'refunded')
                                    @if($refund['status'] != 'rejected')
                                        <button class="k-btn k-btn--ghost k-btn--sm k-btn--icon" type="button"
                                                title="{{ translate('reject') }}"
                                                data-toggle="modal"
                                                data-target="#rejectModal-{{$refund['id']}}">
                                            <x-k.icon name="close" :size="15" />
                                        </button>
                                    @endif
                                    @if($refund['status'] != 'approved')
                                        <button class="k-btn k-btn--ghost k-btn--sm k-btn--icon" type="button"
                                                title="{{ translate('approve') }}"
                                                data-toggle="modal"
                                                data-target="#approveModal-{{$refund['id']}}">
                                            <x-k.icon name="check" :size="15" />
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($refundList)==0)
                <x-k.empty icon="refresh" :title="translate('no_refund_request_found')"
                           :text="request('search') ? translate('no_refund_request_matches_your_search') : null" />
            @endif

            @if ($refundList->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $refundList->firstItem() }}–{{ $refundList->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $refundList->total() }}</span>
                    </span>
                    <div>{!! $refundList->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
    @include('vendor-views.refund.partials._filter-offcanvas')

    @if(count($refundList ?? []) > 0)
        @foreach($refundList as $refund)
            @include('vendor-views.refund.partials._approval-modal')
            @include('vendor-views.refund.partials._reject-modal')
        @endforeach
    @endif
@endsection
