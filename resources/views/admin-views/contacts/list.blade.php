@extends('layouts.admin.app')
@section('title', translate('contact_List'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/message.png')}}" alt="">
                {{translate('customer_message')}}
            </h2>
        </div>

        <div class="card mt-4">
            <div class="card-body">
                <div class="row justify-content-between align-items-center flex-grow-1">
                    <div class="col-sm-4 col-md-6 col-lg-8 mb-2 mb-sm-0">
                        <h3 class="d-flex gap-2 align-items-center text-capitalize">
                            {{translate('customer_message_table')}}
                            <span class="badge text-bg-info badge-info fs-12" id="row-count">{{ $contacts->total() }}
                            </span>
                        </h3>
                    </div>
                    <div class="col-sm-8 col-md-6 col-lg-4">
                        <div class="d-flex flex-wrap flex-md-nowrap gap-2">
                            <form action="{{ url()->current() }}" method="GET" class="flex-grow-1">
                                <div class="form-group">
                                    <div class="input-group">
                                        <input type="search" name="searchValue" class="form-control" value="{{ requestString('searchValue') }}" placeholder="{{translate('search_by_Name_or_Mobile_No_or_Email')}}">
                                        <div class="input-group-append search-submit">
                                            <button type="submit">
                                                <i class="fi fi-rr-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                            <div class="dropdown">
                                <a class="btn btn-primary" data-bs-toggle="dropdown" href="javascript:;">
                                    <i class="fi fi-rr-columns-3"></i> {{ translate('Filter') }}
                                </a>

                                <div id="menu" class="dropdown-menu dropdown-menu-sm-right px-3 py-4">
                                    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                                        <span>
                                            {{ translate('reply_sent') }}
                                        </span>
                                        <label class="switcher">
                                            <input type="checkbox" class="switcher_input status-filter" name="reply_status" value="replied">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        <span>
                                            {{ translate('reply_not_sent') }}
                                        </span>
                                        <label class="switcher">
                                            <input type="checkbox" class="switcher_input status-filter" name="reply_status" value="not_replied">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div id="status-wise-view" class="mt-4">
                    @include('admin-views.contacts._table')
                </div>
            </div>
        </div>
    </div>
    <span id="get-filter-route" data-action="{{route('admin.contact.filter')}}"></span>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/admin/contact.js')}}"></script>
@endpush
