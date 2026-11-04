@php use Carbon\Carbon; @endphp
@extends('layouts.admin.app')

@section('title', translate('subscriber_list'))

@section('content')
<div class="content container-fluid">
    <div class="mb-3">
        <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
            <img src="{{dynamicAsset(path: 'public/assets/back-end/img/subscribers.png')}}" width="20" alt="">
            {{translate('subscriber_list')}}
            <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $totalSubscribers }}</span>
        </h2>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <form action="{{ url()->current() }}" method="GET">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{ translate('Subscription_Date') }}</label>
                            <div class="position-relative">
                            <span class="fi fi-sr-calendar icon-absolute-on-right"></span>
                                <input type="text" name="subscription_date" value="{{ requestString('subscription_date', '') }}" class="js-daterangepicker-with-range form-control cursor-pointer" value="{{ requestString('subscription_date') }}" placeholder="{{ translate('Select_Date') }}" autocomplete="off" readonly>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{translate('Sort_By')}}</label>
                            <select class="custom-select" name="sort_by">
                                <option disabled {{ is_null(request('sort_by')) ? 'selected' : '' }}>{{ translate('select_mail_sorting_order') }}</option>
                                <option value="asc" {{ request('sort_by') === 'asc' ? 'selected' : '' }}>{{ translate('Sort_by_oldest') }}</option>
                                <option value="desc" {{ request('sort_by') === 'desc' ? 'selected' : '' }}>{{ translate('Sort_by_newest') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">{{translate('Choose_First')}}</label>
                            <input type="number" name="choose_first" min="1" value="{{ requestString('choose_first') }}" class="form-control" placeholder="{{translate('Ex')}} : {{translate('100')}}">
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3 justify-content-end mt-3">
                    <a href="{{ route('admin.customer.subscriber-list') }}"
                       class="btn btn-secondary min-w-120">
                        {{ translate('reset') }}
                    </a>
                    <button type="submit" class="btn btn-primary min-w-120">{{translate('Filter')}}</button>
                </div>
            </form>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <x-k.data-view :title="translate('subscriber_list')" :count="$subscriberList->total()"
                           searchName="searchValue" :searchValue="requestString('searchValue')"
                           :searchPlaceholder="translate('search_by_email')">

                <x-slot:actions>
                    <a class="k-btn k-btn--secondary"
                       href="{{route('admin.customer.subscriber-list.export', ['sort_by' => request('sort_by'), 'choose_first' => request('choose_first'), 'subscription_date' => request('subscription_date'), 'searchValue' => request('searchValue')])}}">
                        <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                    </a>
                </x-slot:actions>

                <table class="k-table">
                    <thead>
                    <tr>
                        <th>{{ translate('email') }}</th>
                        <th>{{ translate('subscription_date') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($subscriberList as $item)
                        <tr>
                            <td>
                                <a href="mailto:{{ $item->email }}">{{ $item->email }}</a>
                            </td>
                            <td>
                                <span class="k-num">{{ date('d M Y, h:i A', strtotime($item->created_at)) }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                @if(count($subscriberList)==0)
                    <x-k.empty icon="customers" :title="translate('no_subscriber_found')"
                               :text="request('searchValue') ? translate('no_subscriber_matches_your_search') : null" />
                @endif

                @if ($subscriberList->total() > 0)
                    <x-slot:pager>
                        <span class="k-pager__info">
                            {{ translate('showing') }}
                            <span class="k-num">{{ $subscriberList->firstItem() }}–{{ $subscriberList->lastItem() }}</span>
                            {{ translate('of') }} <span class="k-num">{{ $subscriberList->total() }}</span>
                        </span>
                        <div>{!! $subscriberList->appends(request()->except('page'))->links() !!}</div>
                    </x-slot:pager>
                @endif
            </x-k.data-view>
        </div>
    </div>
</div>
@endsection

@push('script')
    <script type="text/javascript">
        changeInputTypeForDateRangePicker($('input[name="subscription_date"]'));
    </script>
@endpush
