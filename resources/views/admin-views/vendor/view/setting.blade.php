@php
    use Illuminate\Support\Facades\Session;
@endphp
@extends('layouts.admin.app')

@section('title', $seller?->shop->name ?? translate("shop_name_not_found"))

@section('content')
    @php($direction =Session::get('direction'))
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png')}}" alt="">
                {{translate('vendor_details')}}
            </h2>
        </div>

        <div class="flex-between d-sm-flex row align-items-center justify-content-between mb-2 mx-1">
            <div>
                @if ($seller->status=="pending")
                    <div class="mt-4 pe-2">
                        <div class="flex-start">
                            <div class="mx-1"><h4><i class="fi fi-rr-shop"></i></h4></div>
                            <div><h4>{{translate('vendor_request_for_open_a_shop')}}.</h4></div>
                        </div>
                        <div class="text-center">
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller->id}}">
                                <input type="hidden" name="status" value="approved">
                                <button type="submit" class="btn btn-primary btn-sm">{{translate('approve')}}</button>
                            </form>
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller->id}}">
                                <input type="hidden" name="status" value="rejected">
                                <button type="submit" class="btn btn-danger btn-sm">{{translate('reject')}}</button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="page-header mb-4">
            <h2 class="page-header-title mb-3">{{ $seller?->shop->name ?? translate("shop_Name")." : ".translate("update_Please") }}</h2>

            <div class="position-relative nav--tab-wrapper">
                <ul class="nav nav-pills nav--tab">
                    <li class="nav-item">
                        <a class="nav-link " href="{{ route('admin.vendors.view',$seller->id) }}">{{translate('shop')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'order']) }}">{{translate('order')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'product']) }}">{{translate('product')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller['id'], 'tab'=>'clearance_sale']) }}">{{translate('clearance_sale_products')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active"
                           href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'setting']) }}">{{translate('setting')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'transaction']) }}">{{translate('transaction')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'review']) }}">{{translate('review')}}</a>
                    </li>

                </ul>
                <div class="nav--tab__prev">
                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                        <i class="fi fi-sr-angle-left"></i>
                    </button>
                </div>
                <div class="nav--tab__next">
                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                        <i class="fi fi-sr-angle-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <form action="{{ route('admin.vendors.update-setting',['id'=>$seller['id']]) }}" method="post" id="update-setting-form-0">
                    @csrf
                    <div class="card">
                        <div class="card-header d-flex justify-content-between gap-2">
                            <h4 class="mb-0 text-capitalize"> {{translate('sales_commission').' '.':'}} </h4>
                            <label class="switcher" for="commission-status">
                                <input
                                    class="switcher_input custom-modal-plugin"
                                    type="checkbox" value="1" name="commission_status"
                                    id="commission-status"
                                    {{ $seller['sales_commission_percentage'] != null ? 'checked':'' }}
                                    data-modal-type="input-change"
                                    data-modal-form="#update-setting-form-0"
                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/general-icon.lpng') }}"
                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/general-icon.png') }}"
                                    data-on-title="{{translate('want_to_Turn_ON_Sales_Commission_For_This_Vendor')}}"
                                    data-off-title="{{translate('want_to_Turn_OFF_Sales_Commission_For_This_Vendor')}}"
                                    data-on-message="<p>{{translate('if_sales_commission_is_enabled_here_the_this_commission_will_be_applied')}}</p>"
                                    data-off-message="<p>{{translate('if_sales_commission_is_disabled_here_the_system_default_commission_will_be_applied')}}</p>"
                                    data-on-button-text="{{ translate('turn_on') }}"
                                data-off-button-text="{{ translate('turn_off') }}">
                                <span class="switcher_control"></span>
                            </label>
                        </div>
                        <div class="card-body">
                            <small class="badge badge-info text-bg-info text-wrap mb-3">
                                {{translate('if_sales_commission_is_disabled_here_the_system_default_commission_will_be_applied')}}.
                            </small>
                            <div class="form-group">
                                <label class="mb-2">{{translate('commission').'( % )'}}</label>
                                <input type="number" value="{{$seller['sales_commission_percentage']}}"
                                       class="form-control" name="commission">
                            </div>
                            <button type="submit" class="btn btn-primary">{{translate('update')}}</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-md-6">
                <form action="{{ route('admin.vendors.update-setting',['id'=>$seller['id']]) }}" method="post" id="update-setting-form-1">
                    @csrf
                    <div class="card">
                        <div class="card-header d-flex justify-content-between gap-2">
                            <h4 class="mb-0"> {{translate('GST_Number').':'}}</h4>
                            <label class="switcher" for="gst-status">
                                <input
                                    class="switcher_input custom-modal-plugin"
                                    type="checkbox" value="1" name="gst_status"
                                    id="gst-status"
                                    {{ $seller['gst'] !=null ? 'checked':'' }}
                                    data-modal-type="input-change"
                                    data-modal-form="#update-setting-form-1"
                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/general-icon.png') }}"
                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/general-icon.png') }}"
                                    data-on-title="{{translate('want_to_Turn_ON_GST_Number_For_This_Vendor')}}"
                                    data-off-title="{{translate('want_to_Turn_OFF_GST_Number_For_This_Vendor')}}"
                                    data-on-message="<p>{{translate('if_GST_number_is_enabled_here_it_will_be_show_in_invoice')}}</p>"
                                    data-off-message="<p>{{translate('if_GST_number_is_disabled_here_it_will_not_show_in_invoice')}}</p>
                                    data-on-button-text="{{ translate('turn_on') }}"
                                    data-off-button-text="{{ translate('turn_off') }}">
                                <span class="switcher_control"></span>
                            </label>
                        </div>
                        <div class="card-body">
                            <small class="badge text-wrap badge-info text-bg-info mb-3">
                                {{translate('if_GST_number_is_disabled_here_it_will_not_show_in_invoice')}}.
                            </small>
                            <div class="form-group">
                                <label class="mb-2"> {{translate('number')}} </label>
                                <input type="text" value="{{$seller['gst']}}" class="form-control" name="gst">
                            </div>
                            <button type="submit" class="btn btn-primary">{{translate('update')}} </button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between gap-2">
                        <h4 class="mb-0">{{translate('vendor_POS')}}</h4>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.vendors.update-setting',['id'=>$seller['id']]) }}" method="post" id="update-setting-form-2">
                            @csrf
                            <input type="hidden" name="seller_pos_update" value="1">
                            <div class="form-group">
                                <div class="d-flex justify-content-between align-items-center gap-10 form-control">
                                    <span class="title-color text-capitalize">
                                        {{translate('vendor_POS_permission')}}
                                        <span class="input-label-secondary cursor-pointer" data-bs-toggle="tooltip" data-bs-placement="right" title="{{translate('if_enabled_this_vendor_can_access_POS_from_the_website_and_vendor_app') }}">
                                            <img width="16" src="{{dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg')}}" alt="">
                                        </span>
                                    </span>
                                    <label class="switcher" for="seller-pos">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="seller_pos"
                                            id="seller-pos"
                                            {{ $seller['pos_status'] == 1 ? 'checked':'' }}
                                            data-modal-type="input-change"
                                            data-modal-form="#update-setting-form-2"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/general-icon.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/general-icon.png') }}"
                                            data-on-title="{{translate('want_to_Turn_ON_POS_For_This_Vendor')}}"
                                            data-off-title="{{translate('want_to_Turn_OFF_POS_For_This_Vendor')}}"
                                            data-on-message="<p>{{translate('if_enabled_this_vendor_can_access_POS_from_the_website_and_vendor_app')}}</p>"
                                            data-off-message="<p>{{translate('if_disabled_this_vendor_cannot_access_POS_from_the_website_and_vendor_app')}}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">{{translate('save')}}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
