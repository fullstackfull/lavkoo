<aside class="col-lg-4 pt-4 pt-lg-2 px-max-md-0 order-summery-aside">
    <div class="__cart-total __cart-total_sticky">
        <div class="cart_total p-0">
            @php($systemTaxConfig = getTaxModuleSystemTypesConfig())
            @php($shippingMethod = getWebConfig(name: 'shipping_method'))
            @php($subTotal = 0)
            @php($totalTax = \App\Utils\CartManager::getCartListTaxAmount())
            @php($totalAmount = 0)
            @php($totalAmountAfterReferralDiscount = 0)
            @php($totalShippingCost = 0)
            @php($referralAmount = 0)
            @php($totalDiscountOnProduct = 0)
            @php($cart = \App\Utils\CartManager::getCartListQuery(type: 'checked'))
            @php($cartGroupIds = \App\Utils\CartManager::get_cart_group_ids())
            @php($getShippingCost = \App\Utils\CartManager::get_shipping_cost(type: 'checked'))
            @php($getShippingCostSavedForFreeDelivery = \App\Utils\CartManager::getShippingCostSavedForFreeDelivery(type: 'checked'))
            @if ($cart->count() > 0)
                @foreach ($cart as $key => $cartItem)
                    @php($subTotal += $cartItem['price'] * $cartItem['quantity'])
                    @php($totalDiscountOnProduct += $cartItem['discount'] * $cartItem['quantity'])
                @endforeach

                @if (session()->missing('coupon_type') || session('coupon_type') != 'free_delivery')
                    @php($totalShippingCost = $getShippingCost - $getShippingCostSavedForFreeDelivery)
                @else
                    @php($totalShippingCost = $getShippingCost)
                @endif
            @endif

            @php($totalSavedAmount = $totalDiscountOnProduct)

            @if (session()->has('coupon_discount') && session('coupon_discount') > 0 && session('coupon_type') != 'free_delivery')
                @php($totalSavedAmount += session('coupon_discount'))
            @endif

            @if ($getShippingCostSavedForFreeDelivery > 0)
                @php($totalSavedAmount += $getShippingCostSavedForFreeDelivery)
            @endif

            @if ($totalSavedAmount > 0)
                <h6 class="text-center text-primary mb-4 d-flex align-items-center justify-content-center gap-2">
                    <img class="svg" src="{{ theme_asset(path: 'public/assets/front-end/img/icons/offer-dynamic.svg') }}" alt="">
                    {{ translate('you_have_Saved') }}
                    <strong>{{ webCurrencyConverter(amount: $totalSavedAmount) }}!</strong>
                </h6>
            @endif

            @if (auth('customer')->check() && !session()->has('coupon_discount'))
                <div class="mb-3">
                    <form class="needs-validation coupon-code-form" action="javascript:" method="post" novalidate
                          id="coupon-code-ajax">
                        <div class="d-flex form-control rounded ps-3 p-1 text-primary">
                            <img width="18"
                                    class="svg mt-1"
                                    src="{{ theme_asset(path: 'public/assets/front-end/img/icons/coupon-dynamic.svg') }}"
                                    alt="">
                            <input class="input_code border-0 px-2 text-dark bg-transparent outline-0 w-100"
                                    type="text" name="code" placeholder="{{ translate('coupon_code') }}"
                                    required>
                            <button class="btn btn--primary rounded text-uppercase py-1 fs-12" type="button"
                                    id="apply-coupon-code">
                                {{ translate('apply') }}
                            </button>
                        </div>
                        <div class="invalid-feedback">{{ translate('please_provide_coupon_code') }}</div>
                    </form>
                </div>
                @php($coupon_dis = 0)
            @endif

            <div class="d-flex justify-content-between">
                <span class="cart_title">{{ translate('sub_total') }}</span>
                <span class="cart_value">
                    {{ webCurrencyConverter(amount: $subTotal) }}
                </span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="cart_title">{{ translate('shipping') }}</span>
                <span class="cart_value">
                    {{ webCurrencyConverter(amount: $totalShippingCost) }}
                </span>
            </div>
            <div class="d-flex justify-content-between">
                <span class="cart_title">{{ translate('discount_on_product') }}</span>
                <span class="cart_value">
                    - {{ webCurrencyConverter(amount: $totalDiscountOnProduct) }}
                </span>
            </div>
            @if($systemTaxConfig['SystemTaxVat']['is_active'] && !$systemTaxConfig['is_included'])
                <div class="d-flex justify-content-between">
                    <span class="cart_title">{{ translate('tax') }}</span>
                    <span class="cart_value">
                    {{ webCurrencyConverter(amount: ($totalTax['item_tax'] + $totalTax['shipping_cost_tax'])) }}
                </span>
                </div>
            @endif

            <?php
            $coupon_dis = 0;
            if (session()->has('coupon_discount')) {
                $couponDiscount = session()->has('coupon_discount') ? session('coupon_discount') : 0;
                $coupon_dis = $couponDiscount;
            }

            $totalAmount = $subTotal + ($totalTax['item_tax'] + $totalTax['shipping_cost_tax']) + $totalShippingCost - $coupon_dis - $totalDiscountOnProduct;
            $referralAmount = \App\Utils\CustomerManager::getReferralDiscountAmount(
                user: (auth('customer')->check() ? auth('customer')->user() : null),
                couponDiscount: $coupon_dis
            );
            ?>

            @if ($referralAmount > 0)
                <div class="d-flex justify-content-between">
                    <span class="cart_title">{{ translate('referral_discount') }}</span>
                    <span class="cart_value">
                        - {{ webCurrencyConverter(amount: $referralAmount) }}
                    </span>
                </div>
            @endif

            @php($coupon_dis = 0)
            @if (auth('customer')->check())
                @if (session()->has('coupon_discount'))
                    @php($couponDiscount = session()->has('coupon_discount') ? session('coupon_discount') : 0)

                    <div class="d-flex justify-content-between">
                        <span class="cart_title">{{ translate('coupon_discount') }}</span>
                        <span class="cart_value">
                            - {{ webCurrencyConverter(amount: $couponDiscount) }}
                        </span>
                    </div>

                    <div class="pt-2">
                        <div class="d-flex align-items-center form-control rounded-pill pl-3 p-1 text-primary">
                            <img width="24" height="24"
                                class="svg mt-1"
                                src="{{ theme_asset(path: 'public/assets/front-end/img/icons/coupon-dynamic.svg') }}"
                                alt="">
                            <div class="px-2 d-flex justify-content-between w-100">
                                <div>
                                    {{ session('coupon_code') }}
                                    <span class="text-primary small">(
                                        -{{ webCurrencyConverter(amount: $couponDiscount) }}
                                        )</span>
                                </div>
                                <div class="bg-transparent text-danger cursor-pointer px-2 get-view-by-onclick"
                                     data-link="{{ route('coupon.remove') }}">x</div>
                            </div>
                        </div>
                    </div>
                    @php($coupon_dis = session('coupon_discount'))
                @endif
            @endif

            <hr class="my-3">
            <div class="d-flex justify-content-between">
                <span class="cart_title text-primary font-weight-bold">
                    {{ translate('total') }}
                    @if($systemTaxConfig['SystemTaxVat']['is_active'] && $systemTaxConfig['is_included'])
                        <span class="fs-12 font-weight--600">({{ translate('Tax_:_Inc.') }})</span>
                    @endif
                </span>
                <span class="cart_value">
                    {{ webCurrencyConverter(amount: $totalAmount - $referralAmount) }}
                </span>
            </div>
        </div>
        @php($company_reliability = getWebConfig(name: 'company_reliability'))
        @if ($company_reliability != null)
        <div class="light-box p--20 mt-20">
            <div class="">
                <h5 class="fs-14 fw-semibold text-dark mb-2">
                    {{ translate('Why_shop_with_us ?') }}
                </h5>
                <div class="footer-sliders d-flex flex-wrap gap-2">
                    @foreach ($company_reliability as $key => $value)
                        @if ($value['status'] == 1 && !empty($value['title']))
                            <div class="bg-white py-2 px-2 rounded d-flex align-items-center gap-2">
                                <img class="order-summery-footer-image" alt=""
                                     src="{{ getStorageImages(path: imagePathProcessing(imageData: $value['image'], path: 'company-reliability'), type: 'source', source: theme_asset(path: 'public/assets/front-end/img') . '/' . $value['item'] . '.png') }}">
                                <div class="deal-title">{{ translate($value['title']) }}</div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <div class="pt-4">
            @if (str_contains(request()->url(), 'checkout-payment'))
                <label class="custom-control custom-checkbox mb-3 d-flex user-select-none cursor-pointer">
                    <input type="checkbox" class="custom-control-input payment-input-checkbox">
                    <span class="custom-control-label">
                <span>{{ translate('i_agree_to_Your') }}</span>
                <a class="font-size-sm text-primary d-inline" target="_blank"
                   href="{{ route('business-page.view', ['slug' => 'terms-and-conditions']) }}">
                    {{ translate('terms_and_condition') }}
                </a>
                @foreach($web_config['business_pages']->where('default_status', 1) as $businessPage)
                            @if($businessPage['slug'] == 'privacy-policy' || $businessPage['slug'] == 'refund-policy')
                                <a class="font-size-sm text-primary d-inline" target="_blank"
                                   href="{{ route('business-page.view', ['slug' => $businessPage['slug']]) }}">
                            , {{ translate(str_replace('-', '_', $businessPage['slug'])) }}
                        </a>
                            @endif
                        @endforeach
                </span>
                </label>
            @endif

            <a class="btn btn--primary btn-block proceed_to_next_button {{ $cart->count() <= 0 ? 'custom-disabled' : '' }} action-checkout-function">
                {{ translate('proceed_to_Checkout') }}
            </a>
        </div>

        <div class="d-flex justify-content-center mt-3">
            <a href="{{ route('home') }}" class="d-flex align-items-center gap-2 text-primary font-weight-bold">
                <i class="tio-back-ui fs-12"></i> {{ translate('continue_Shopping') }}
            </a>
        </div>

    </div>
</aside>

<div class="bottom-sticky3 bg-white p-3 shadow-sm w-100 d-lg-none">
    <div class="d-flex justify-content-center align-items-center fs-14 mb-2">
        <div class="product-description-label fw-semibold text-capitalize">{{ translate('total_price') }} :</div>
        {{-- The same figure the desktop aside shows. This bar used to recompute
             the formula inline — after $coupon_dis had been reset to 0 and
             without the referral discount — so phones showed a higher total
             than the desktop for the same order. --}}
        &nbsp; <strong class="text-base">
            {{ webCurrencyConverter(amount: $totalAmount - $referralAmount) }}
        </strong>
    </div>

    @if (str_contains(request()->url(), 'checkout-payment'))
        <label class="custom-control custom-checkbox mb-3 d-flex user-select-none cursor-pointer">
            <input type="checkbox" class="custom-control-input payment-input-checkbox">
            <span class="custom-control-label">
                <span>{{ translate('i_agree_to_Your') }}</span>
                <a class="font-size-sm text-primary d-inline" target="_blank"
                   href="{{ route('business-page.view', ['slug' => 'terms-and-conditions']) }}">
                    {{ translate('terms_and_condition') }}
                </a>
                @foreach($web_config['business_pages']->where('default_status', 1) as $businessPage)
                    @if($businessPage['slug'] == 'privacy-policy' || $businessPage['slug'] == 'refund-policy')
                        <a class="font-size-sm text-primary d-inline" target="_blank"
                           href="{{ route('business-page.view', ['slug' => $businessPage['slug']]) }}">
                            , {{ translate(str_replace('-', '_', $businessPage['slug'])) }}
                        </a>
                    @endif
                @endforeach
                </span>
        </label>
    @endif

    <a data-route="{{ Route::currentRouteName() }}"
       class="btn btn--primary btn-block proceed_to_next_button text-capitalize {{ $cart->count() <= 0 ? 'custom-disabled' : '' }} action-checkout-function">
        {{ translate('proceed_to_checkout') }}
    </a>
</div>

@push('script')
    <script>
        "use strict";
        $(document).ready(function() {
            orderSummaryStickyFunction()
        });
    </script>
@endpush
