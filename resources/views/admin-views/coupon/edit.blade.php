@extends('layouts.admin.app')

@section('title', translate('coupon_Edit'))

@section('content')
<div class="content container-fluid">
    <div class="mb-3">
        <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/coupon_setup.png') }}" alt="">
            {{translate('coupon_update') }}
        </h2>
    </div>
    <div class="row">
        <div class="col-md-12">
            <div class="card">

                <div class="card-body">
                    <form action="{{route('admin.coupon.update',[$coupon['id']]) }}" method="post" class="" id="coupon-add-ajax-submit">
                        @csrf
                        <div class="row g-4 mb-4">
                            <div class="col-md-6 col-lg-4">
                                <label for="name" class="form-label">{{translate('coupon_type') }}<span class="text-danger">*</span></label>
                                <div class="select-wrapper">
                                    <select class="form-select" id="coupon_type" name="coupon_type" data-required-msg="{{ translate('coupon_type_is_required') }}" >
                                        <option disabled selected>{{translate('select_Coupon_Type') }}</option>
                                        <option value="discount_on_purchase" {{ $coupon['coupon_type']=='discount_on_purchase'?'selected':'' }}>{{translate('discount_on_Purchase')}}</option>
                                        <option value="free_delivery" {{$coupon['coupon_type']=='free_delivery'?'selected':'' }}>{{translate('free_Delivery')}}</option>
                                        <option value="first_order" {{$coupon['coupon_type']=='first_order'?'selected':'' }}>{{translate('first_Order')}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="form-group">
                                <label for="name" class="form-label">{{translate('coupon_title')}}<span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control" id="title" value="{{$coupon['title'] }}"
                                       placeholder="{{translate('title')}}" data-maxlength="80" data-required-msg="{{ translate('coupon_title_is_required') }}" >
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <div class="d-flex justify-content-between">
                                    <label for="name"
                                           class="form-label text-capitalize">{{translate('coupon_code')}}<span class="text-danger">*</span></label>
                                    <a href="javascript:" class="text-primary fs-12" id="generateCode">{{translate('generate_code')}}</a>
                                </div>
                                <input type="text" name="code" value="{{$coupon['code'] }}"
                                       class="form-control" id="code"
                                       data-required-msg="{{ translate('coupon_code_is_required') }}"
                                       placeholder="{{translate('ex')}}: EID100" >
                            </div>
                            <div class="col-md-6 col-lg-4 first_order">
                                <label for="name" class="form-label">{{translate('coupon_bearer')}}<span class="text-danger">*</span></label>
                                <div class="select-wrapper">
                                    <select class="form-select" name="coupon_bearer" id="coupon_bearer">
                                        <option disabled selected>{{translate('select_coupon_bearer')}}</option>
                                        <option value="seller" {{$coupon['coupon_bearer']=='seller'?'selected':'' }}>{{translate('vendor')}}</option>
                                        <option value="inhouse" {{$coupon['coupon_bearer']=='inhouse'?'selected':'' }}>{{translate('admin')}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6 col-lg-4 coupon_by first_order">
                                <label for="name" class="form-label">{{translate('vendor')}}<span class="text-danger">*</span></label>
                                <select class="custom-select" name="seller_id" id="vendor_wise_coupon">
                                    <option disabled selected>{{translate('select_Vendor')}}</option>
                                    <option value="0" {{$coupon['seller_id']=='0'?'selected':'' }}>{{translate('all_Vendor')}}</option>
                                    @if($coupon['coupon_bearer'] == 'inhouse')
                                    <option value="inhouse" {{is_null($coupon['seller_id'])?'selected':'' }}>{{getInHouseShopConfig(key:'name')}}</option>
                                    @endif
                                    @foreach($sellers as $seller)
                                        <option value="{{ $seller->id }}" {{$coupon['seller_id']==$seller->id?'selected':'' }}>
                                            {{ $seller?->shop?->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 col-lg-4 coupon_type first_order">
                                <label for="name" class="form-label">{{translate('customer')}}<span class="text-danger">*</span></label>
                                <select class="custom-select" name="customer_id" >
                                    <option disabled selected>{{translate('select_customer')}}</option>
                                    <option value="0" {{$coupon['customer_id']=='0'?'selected':'' }}>{{translate('All_Customer')}}</option>
                                    @foreach($customers as $customer)
                                        <option value="{{ $customer->id }}" {{$coupon['customer_id']==$customer->id ? 'selected':'' }}>{{ $customer->f_name. ' '. $customer->l_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 col-lg-4 first_order">
                                <label  for="exampleFormControlInput1" class="form-label">{{translate('limit_for_same_user')}}<span class="text-danger">*</span></label>
                                <input type="number" name="limit" min="0" value="{{ $coupon['limit'] }}" id="coupon_limit" class="form-control" placeholder="{{translate('ex'.':'.'10')}}">
                            </div>
                            <div class="col-md-6 col-lg-4 free_delivery">
                                <label  for="name" class="form-label">{{translate('discount_type')}}<span class="text-danger">*</span></label>
                               <div class="select-wrapper">
                                    <select class="form-select" id="discount_type" name="discount_type">
                                        <option disabled selected>{{translate('select_Discount_Type')}}</option>
                                        <option value="percentage" {{$coupon['discount_type']=='percentage'?'selected':'' }}>{{translate('percentage')}}</option>
                                        <option value="amount" {{$coupon['discount_type']=='amount'?'selected':'' }}>{{translate('amount')}}</option>
                                    </select>
                               </div>
                            </div>
                            <div class="col-md-6 col-lg-4 free_delivery">
                                <label for="name" class="form-label">{{translate('discount_Amount')}}<span class="text-danger">*</span> <span id="discount_percent"> (%)</span>       ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})</label>
                                <input type="number" min="0" max="1000000" step="any" name="discount" class="form-control" id="discount" value="{{$coupon['discount_type']=='amount'? usdToDefaultCurrency(amount:$coupon['discount']):$coupon['discount'] }}"
                                    placeholder="{{translate('ex').':'.'500' }}">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="name" class="form-label">
                                    {{ translate('minimum_Purchase') }}<span class="text-danger">*</span>
                                    ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                </label>
                                <input type="number" min="0" max="1000000" step="any" name="min_purchase" class="form-control" id="minimum purchase" value="{{usdToDefaultCurrency(amount:$coupon['min_purchase'])}}"
                                       placeholder="{{translate('minimum_purchase')}}" >
                            </div>
                            <div class="col-md-6 col-lg-4 free_delivery" id="max-discount">
                                <label for="name" class="form-label">{{translate('maximum_discount')}}<span class="text-danger">*</span>({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})</label>
                                <input type="number" min="0" max="1000000" step="any" name="max_discount" class="form-control" id="maximum discount" value="{{usdToDefaultCurrency(amount:$coupon['max_discount'])}}"
                                       placeholder="{{translate('maximum_discount')}}">
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="name" class="form-label">{{translate('Start_Date')}}<span class="text-danger">*</span></label>
                                <input type="date" name="start_date" class="form-control" id="start_date" value="{{date('Y-m-d',strtotime($coupon['start_date']))}}"
                                       placeholder="{{translate('Start_Date')}}" >
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <label for="name" class="form-label">{{translate('expire_date')}}<span class="text-danger">*</span></label>
                                <input type="date" name="expire_date" class="form-control" id="expire_date" value="{{date('Y-m-d',strtotime($coupon['expire_date']))}}"
                                       placeholder="{{translate('expire_date')}}" >
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-end flex-wrap gap-3">
                            <button type="reset" class="btn btn-secondary px-4">{{translate('reset')}}</button>
                            <button type="submit" class="btn btn-primary px-4">{{translate('Update')}}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<span id="coupon-bearer-url" data-url="{{route('admin.coupon.ajax-get-vendor')}}"></span>
@endsection
@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/coupon.js')}}"></script>
@endpush
