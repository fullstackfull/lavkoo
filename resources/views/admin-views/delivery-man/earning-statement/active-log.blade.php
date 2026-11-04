@extends('layouts.admin.app')
@section('title', translate('order_History_Log'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png')}}" alt="">
                {{translate('earning_statement')}}
            </h2>
        </div>
        @include('admin-views.delivery-man.pages-inline-menu')

        <div class="card mb-3">
            <div class="card-body">
                <div class="px-3 py-4">
                    <div class="row g-4 align-items-center">
                        <div class="col-md-4 col-lg-6 mb-2 mb-md-0">

                            <h3 class="d-flex align-items-center text-capitalize gap-2 mb-0">
                                {{ translate('order_list') }}
                                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $orders->total() }}</span>
                            </h3>
                        </div>
                        <div class="col-md-8 col-lg-6">
                            <div class="d-flex align-items-center justify-content-md-end flex-wrap flex-sm-nowrap gap-2">
                                <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                                    <form action="" method="GET">
                                        <div class="input-group">
                                            <input id="datatableSearch_" type="search" name="searchValue" class="form-control" placeholder="{{ translate('search_by_order_no') }}" aria-label="Search orders" value="{{ requestString('searchValue') }}">
                                            <input type="hidden" name="page_name" value="active_log">
                                            <div class="input-group-append search-submit">
                                                <button type="submit">
                                                    <i class="fi fi-rr-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="dropdown">
                                    <a type="button" class="btn btn-outline-primary" href="{{route('admin.delivery-man.order-history-log-export',['id'=>$deliveryMan->id,'type'=>'log','searchValue'=>request('searchValue')])}}">
                                        <i class="fi fi-sr-inbox-in"></i>
                                        <span class="fs-12">{{ translate('export') }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-sm-12 mb-3">
                        <div class="card">
                            <div class="table-responsive datatable-custom br-inherit">
                                <table class="k-table">
                                    <thead class="text-capitalize table-nowrap">
                                    <tr>
                                        <th>{{ translate('SL') }}</th>
                                        <th>{{ translate('order_no') }}</th>
                                        <th class="text-center">{{ translate('current_status') }}</th>
                                        <th class="text-center">{{ translate('history') }}</th>
                                    </tr>
                                    </thead>

                                    <tbody id="set-rows">
                                    @foreach($orders as $key => $order)
                                        <tr>
                                            <td>{{ $orders->firstItem()+$key }}</td>
                                            <td>
                                                <div class="media align-items-center gap-2 flex-wrap">
                                                    <a class="text-dark text-hover-primary" title="{{translate('order_details')}}"
                                                       href="{{route('admin.orders.details',['id'=>$order['id']])}}">
                                                        {{ $order->id }}
                                                    </a>
                                                </div>
                                            </td>
                                            <td class="text-center text-capitalize">
                                                @if($order['order_status']=='pending')
                                                    <span class="badge badge-info text-bg-info fs-12">
                                                        {{translate($order['order_status'])}}
                                                </span>

                                                @elseif($order['order_status']=='processing' || $order['order_status']=='out_for_delivery')
                                                    <span class="badge badge-warning text-bg-warning fs-12">
                                                    {{translate(str_replace('_',' ',$order['order_status'] == 'processing' ? 'packaging':$order['order_status']))}}
                                                </span>
                                                @elseif($order['order_status']=='confirmed')
                                                    <span class="badge badge-success text-bg-success fs-12">
                                                    {{translate($order['order_status'])}}
                                                </span>
                                                @elseif($order['order_status']=='failed')
                                                    <span class="badge badge-danger fs-12">
                                                    {{translate('Failed_To_Deliver')}}
                                                </span>
                                                @elseif($order['order_status']=='delivered')
                                                    <span class="badge badge-success text-bg-success fs-12">
                                                    {{translate($order['order_status'])}}
                                                </span>
                                                @else
                                                    <span class="badge badge-danger text-bg-danger fs-12">
                                                    {{translate($order['order_status'])}}
                                                </span>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="d-flex justify-content-center align-items-center gap-2 flex-wrap">
                                                    <button data-id="{{ $order['id'] }}" class="btn btn-info text-white icon-btn order-status-history"  data-bs-toggle="modal" data-bs-target="#exampleModalLong">
                                                        <i class="fi fi-sr-time-past"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="table-responsive mt-4">
                                <div class="px-4 d-flex justify-content-lg-end">
                                    {{ $orders->links() }}
                                </div>
                            </div>
                            @if(count($orders)==0)
                                @include('layouts.admin.partials._empty-state',['text'=>'no_order_found'],['image'=>'default'])
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="exampleModalLong" tabindex="-1" role="dialog" aria-labelledby="exampleModalLongTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content load-with-ajax">

            </div>
        </div>
    </div>
    <span class="status-history-url" data-url="{{ route('admin.delivery-man.ajax-order-status-history', ['order' => ':id'] ) }}"></span>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/admin/deliveryman.js')}}"></script>
@endpush
