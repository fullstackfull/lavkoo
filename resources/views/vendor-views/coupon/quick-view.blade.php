@php use Carbon\Carbon; @endphp

<div class="d-flex justify-content-end position-relative z-1">
    <button type="button" class="btn btn-circle bg-light fs-10 text-body m-2 position-absolute" style="--size: 1.5rem;" data-dismiss="modal" aria-label="Close">
        <i class="fi fi-sr-cross"></i>
    </button>
</div>
<div class="coupon__details">
    <div class="coupon__details-left">
        <div class="text-start">
            <h6 class="title" id="title">{{ $coupon->title }}</h6>
            <h6 class="subtitle">{{translate('code')}} : <span id="coupon_code">{{ $coupon->code }}</span></h6>
            <div class="text-capitalize">
                <span>{{translate(str_replace('_',' ',$coupon->coupon_type))}}</span>
            </div>
        </div>
        <div class="coupon-info">
            <div class="coupon-info-item d-flex gap-1 align-items-center flex-wrap">
                <span class="w-120 font-weight-normal">{{translate('minimum_purchase')}}</span> :
                <span
                    id="min_purchase">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $coupon->min_purchase), currencyCode: getCurrencyCode(type: 'default'))}}</span>
            </div>
            @if($coupon->coupon_type != 'free_delivery' && $coupon->discount_type == 'percentage')
                <div class="coupon-info-item d-flex gap-1 align-items-center flex-wrap" id="max_discount_modal_div">
                    <span class="w-120 font-weight-normal">{{translate('maximum_discount')}}</span> :
                    <span
                        id="max_discount">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount:$coupon->max_discount), currencyCode: getCurrencyCode(type: 'default'))}}</span>
                </div>
            @endif
            <div class="coupon-info-item d-flex gap-1 align-items-center flex-wrap">
                <span class="w-120 font-weight-normal">{{translate('Start_Date')}}</span> :
                <span id="start_date">{{ Carbon::parse($coupon->start_date)->format('dS M Y') }}</span>
            </div>
            <div class="coupon-info-item d-flex gap-1 align-items-center flex-wrap">
                <span class="w-120 font-weight-normal">{{translate('expire_date')}}</span> :
                <span id="expire_date">{{ Carbon::parse($coupon->expire_date)->format('dS M Y') }}</span>
            </div>
            <div class="coupon-info-item d-flex gap-1 align-items-center flex-wrap">
                <span class="w-120 font-weight-normal">{{translate('discount_bearer')}}</span> :
                <span id="expire_date">
                    @if($coupon->coupon_bearer == 'inhouse')
                        {{ translate('admin') }}
                    @elseif($coupon->coupon_bearer == 'seller')
                        {{ translate('vendor') }}
                    @endif
                </span>
            </div>
        </div>
    </div>
    <div class="coupon__details-right">
        <div class="coupon">
            @if($coupon->coupon_type == 'free_delivery')
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/free-delivery.png') }}"
                     alt="{{translate('free_delivery')}}" width="100">
            @else
                <div class="d-flex">
                    @php($couponDiscountText = $coupon->discount_type=='amount'?setCurrencySymbol(amount: usdToDefaultCurrency(amount:$coupon->discount), currencyCode: getCurrencyCode(type: 'default')):$coupon->discount.'%')
                    <h4 id="discount" class="{{ Str::length($couponDiscountText) > 7 ? 'transform-scale-75' : '' }}">
                        {{ $couponDiscountText }}
                    </h4>
                </div>

                <span>{{ translate('off') }}</span>
            @endif
        </div>
    </div>
</div>
