
<?php
$totalCartItemProduct = 0;
foreach ($cartItems['cartItemValue'] as $key => $item) {
    if (is_array($item)) {
        $totalCartItemProduct++;
    }
}
$isExpanded = $totalCartItemProduct > 0;
?>
<form action="{{route('vendor.pos.order-place') }}" method="post" id='order-place'>
    @csrf
    <div class="d-flex flex-column gap-20 pb-9">
        <div class="">
            <button
                class="btn-collapse d-flex gap-3 align-items-center justify-content-between bg-section2 rounded-top rounded-on-collapse text-dark border-0 p-3 w-100 {{ $isExpanded ? '' : 'collapsed' }}"
                type="button"
                data-toggle="collapse"
                data-target="#collapsecCartList"
                aria-expanded="{{ $isExpanded ? 'true' : 'false' }}">
                <span class="fw-medium">{{ translate('Cart_Item_List') }}</span>
                @if($isExpanded)
                    <div class="btn-collapse-icon">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                @endif
            </button>
            <div class="collapse {{ $isExpanded ? 'show' : '' }}" id="collapsecCartList">
                <div>
                    <div class="table-responsive pos-cart-table max-h-300">
                        <table class="table align-middle m-0 text-dark table-borderless tr-border-bottom">
                            <tbody>
                                @foreach($cartItems['cartItemValue'] as $key => $item)
                                    @if(is_array($item))
                                        <tr>
                                            <td class="overflow-hidden">
                                                <div class="media d-flex align-items-center gap-2"
                                                     data-toggle="tooltip"
                                                     title="{{ $item['name'] }}">
                                                    <img class="avatar border rounded object-fit-cover"
                                                         src="{{ getStorageImages(path:$item['image'], type: 'backend-product') }}"
                                                         alt="{{ $item['name'] . ' ' . translate('image') }}">
                                                    <div class="media-body">
                                                        <h5 class="text-hover-primary mb-0 d-flex flex-wrap gap-2 fw-medium fs-12 min-w-130">
                                                            {{ Str::limit($item['name'], 12) }}
                                                        </h5>
                                                        <small>{{ Str::limit($item['variant'], 20) }}</small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div
                                                    class="qty-input-group form-control d-flex gap-2 align-items-center w-max-content">
                                                    <button type="button" class="qty-count qty-count--minus fs-18"
                                                            data-action="minus">-
                                                    </button>
                                                    <input class="product-qty text-center action-pos-update-quantity"
                                                           type="number" name="product-qty" min="1"
                                                           value="{{$item['quantity']}}" data-key="{{$key}}"
                                                           data-product-key="{{ $item['id'] }}"
                                                           data-product-variant="{{ $item['variant'] }}">
                                                    <button type="button" class="qty-count qty-count--add fs-18"
                                                            data-action="plus">+
                                                    </button>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fw-medium">
                                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount:$item['productSubtotal']), currencyCode: getCurrencyCode()) }}
                                                </div>
                                            </td>
                                            <td class="pe-3">
                                                <div class="d-flex justify-content-end">
                                                    <a href="javascript:" data-id="{{$item['id']}}"
                                                       data-variant="{{$item['variant']}}"
                                                       class="btn btn-danger btn-circle remove-from-cart"
                                                       style="--size: 20px;">
                                                        <i class="fi fi-rr-cross-small mt-2px"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="">
            <div class="bg-section2 p-3 rounded-top mb-2">
                <div class="fw-medium">{{ translate('Billing_Summary') }}</div>
            </div>

            <div class="">
                <dl>
                    <div class="d-flex gap-2 justify-content-between px-3 py-2">
                        <dt class="text-dark text-capitalize fw-normal">{{ translate('sub_total') }} :</dt>
                        <dd>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $cartItems['subtotal'] + $cartItems['discountOnProduct']), currencyCode: getCurrencyCode()) }}</dd>
                    </div>

                    <div class="d-flex gap-2 justify-content-between px-3 py-2">
                        <dt class="text-dark text-capitalize fw-normal">{{ translate('product_Discount') }} :</dt>
                        <dd>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: round($cartItems['discountOnProduct'], 2)), currencyCode: getCurrencyCode()) }}</dd>
                    </div>

                    <div class="d-flex gap-2 justify-content-between px-3 py-2">
                        <dt class="title-color gap-2 text-capitalize fw-normal">{{ translate('coupon_Discount') }}:
                        </dt>
                        <dd>
                            <button id="coupon_discount"
                                    class="btn btn-sm p-0 border-0 d-flex gap-3 text--primary fw-normal shadow-none"
                                    type="button" data-toggle="modal" data-target="#add-coupon-discount">
                                <i class="fi fi-rr-pencil"></i>
                                <span class="text-underline">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $cartItems['couponDiscount']), currencyCode: getCurrencyCode()) }}
                        </span>
                            </button>
                        </dd>
                    </div>

                    <div class="d-flex gap-2 justify-content-between px-3 py-2">
                        <dt class="text-dark text-capitalize fw-normal">{{ translate('extra_Discount') }} :</dt>
                        <dd>
                            <div class="d-flex align-items-center gap-3">
                                @if($cartItems['extraDiscount'] > 0)
                                    <a href="#" class="text-danger lh-1 " id="pos-extra-discount-remove-vendor">
                                        <i class="fi fi-rr-trash"></i>
                                    </a>
                                @endif
                                <button id="extra_discount"
                                        class="btn btn-sm p-0 border-0 d-flex gap-3 text--primary fw-normal shadow-none"
                                        type="button" data-toggle="modal" data-target="#add-discount">
                                    <i class="fi fi-rr-pencil"></i>
                                    <span class="text-underline">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $cartItems['extraDiscount']), currencyCode: getCurrencyCode()) }}
                                    </span>
                                </button>
                            </div>
                        </dd>
                    </div>

                    @php($systemTaxConfig = getTaxModuleSystemTypesConfig())
                    @if($systemTaxConfig['SystemTaxVat']['is_active'] && !$systemTaxConfig['is_included'])
                        <div class="d-flex gap-2 justify-content-between px-3 py-2">
                            <dt class="text-dark text-capitalize fw-normal">{{ translate('VAT/TAX') }} :</dt>
                            <dd>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: round($cartItems['totalTax'] ?? 0, 2)), currencyCode: getCurrencyCode()) }}</dd>
                        </div>
                    @endif

                    <div class="d-flex gap-2 justify-content-between px-3 py-2">
                        <dt class="fs-18 text-dark fw-semibold text-capitalize">{{ translate('total') }} :</dt>
                        <dd class="fs-18 text-dark fw-semibold">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($cartItems['total'] + ($cartItems['totalTax'] ?? 0) - $cartItems['couponDiscount'])), currencyCode: getCurrencyCode()) }}
                        </dd>
                    </div>
                </dl>
            </div>
            <input type="hidden" class="form-control total-amount" name="amount" min="0" step="0.01"
                   value="{{usdToDefaultCurrency(amount: $cartItems['total']+ ($cartItems['totalTax'] ?? 0) -$cartItems['couponDiscount'])}}"
                   readonly>
            <div class="p-3 bg-section rounded">
                <div class="mb-3">
                    <div class="text-dark fw-medium text-capitalize d-flex mb-3">{{ translate('order_fulfillment') }}:</div>
                    <ul class="list-unstyled option-buttons d-flex flex-wrap gap-2 align-items-center mb-0 p-0">
                        <li>
                            <input type="radio" id="fulfillment-instant" value="instant" name="fulfillment" hidden checked>
                            <label for="fulfillment-instant"
                                   class="btn text-dark border bg-white fw-normal btn-sm mb-0">{{ translate('instant_sale') }}</label>
                        </li>
                        <li>
                            <input type="radio" id="fulfillment-delivery" value="delivery" name="fulfillment" hidden>
                            <label for="fulfillment-delivery"
                                   class="btn text-dark border bg-white fw-normal btn-sm mb-0">{{ translate('home_delivery') }}</label>
                        </li>
                    </ul>
                    <div class="fs-12 mt-2 d-none fulfillment-delivery-note">
                        {{ translate('the_order_starts_as_pending_and_follows_the_regular_order_statuses_from_the_order_details_page._cash_is_collected_on_delivery.') }}
                    </div>
                </div>
                <div>
                    <div class="text-dark fw-medium text-capitalize d-flex mb-3">{{ translate('paid_By') }}:</div>
                    <ul class="list-unstyled option-buttons d-flex flex-wrap gap-2 align-items-center mb-0 p-0">
                        <li>
                            <input type="radio" class="paid-by-cash" id="cash" value="cash" name="type" hidden checked>
                            <label for="cash"
                                   class="btn text-dark border bg-white fw-normal btn-sm mb-0">{{ translate('cash') }}</label>
                        </li>
                        <li>
                            <input type="radio" value="card" id="card" name="type" hidden>
                            <label for="card"
                                   class="btn text-dark border bg-white fw-normal btn-sm mb-0">{{ translate('card') }}</label>
                        </li>
                        @php( $walletStatus = getWebConfig('wallet_status') ?? 0)
                        @if ($walletStatus)
                            <li class="{{ (str_contains(session('current_user'), 'walk-in-customer')) ? 'd-none':'' }}">
                                <input type="radio" value="wallet" id="wallet" name="type" hidden>
                                <label for="wallet"
                                       class="btn text-dark border bg-white fw-normal btn-sm mb-0">{{ translate('wallet') }}</label>
                            </li>
                        @endif
                    </ul>
                </div>
                <div class="cash-change-amount cash-change-section">
                    <div class="d-flex gap-2 justify-content-between align-items-center pt-2">
                        <dt class="text-capitalize fw-normal">{{ translate('Paid_Amount') }} :</dt>
                        <dd>
                            <input type="number"
                                   class="form-control px-2 py-3 bg-white text-end pos-paid-amount-element remove-spin"
                                   placeholder="{{ translate('ex') }}: 1000"
                                   value="{{usdToDefaultCurrency(amount: $cartItems['total']+($cartItems['totalTax'] ?? 0)-$cartItems['couponDiscount'])}}"
                                   name="paid_amount" {{ $totalCartItemProduct <= 0 ? 'disabled' : '' }}
                                   min="{{ usdToDefaultCurrency(amount: ($cartItems['total'] + ($cartItems['totalTax'] ?? 0) - $cartItems['couponDiscount'])) }}"
                                   data-currency-position="{{ getWebConfig('currency_symbol_position') }}"
                                   data-currency-symbol="{{ getCurrencySymbol() }}">
                        </dd>
                    </div>
                    <div class="d-flex gap-2 justify-content-between align-items-center pt-3">
                        <dt class="text-capitalize fw-normal">{{ translate('Change_Amount') }} :</dt>
                        <dd class="font-weight-bold title-color pos-change-amount-element">{{ setCurrencySymbol(amount: 0) }}</dd>
                    </div>
                </div>
                <div class="cash-change-card cash-change-section d-none">
                    <div class="d-flex gap-2 justify-content-between align-items-center pt-2">
                        <dt class="text-capitalize fw-normal">{{ translate('Paid_Amount') }} :</dt>
                        <dd>
                            <input type="number" class="form-control px-2 py-3 bg-white text-end"
                                   placeholder="{{ translate('ex') }}: 1000"
                                   value="{{usdToDefaultCurrency(amount: $cartItems['total']+($cartItems['totalTax'] ?? 0)-$cartItems['couponDiscount'])}}"
                                   disabled>
                        </dd>
                    </div>
                    <div class="d-flex gap-2 justify-content-between align-items-center pt-3">
                        <dt class="text-capitalize fw-normal">{{ translate('Change_Amount') }} :</dt>
                        <dd class="font-weight-bold title-color">{{ setCurrencySymbol(amount: 0) }}</dd>
                    </div>
                </div>
                <div class="cash-change-wallet cash-change-section d-none">
                    <div class="d-flex gap-2 justify-content-between align-items-center pt-2">
                        <dt class="text-capitalize fw-normal">{{ translate('Paid_Amount') }} : <span
                                class="badge badge-soft-danger" id="message-insufficient-balance"
                                data-text="{{ translate('insufficient_balance') }}"></span></dt>
                        <dd>
                            <input type="number" class="form-control px-2 py-3 bg-white text-end wallet-balance-input"
                                   placeholder="{{ translate('ex') }}: 1000"
                                   value="{{usdToDefaultCurrency(amount: $cartItems['total']+($cartItems['totalTax'] ?? 0)-$cartItems['couponDiscount'])}}"
                                   disabled>
                        </dd>
                    </div>
                    <div class="d-flex gap-2 justify-content-between align-items-center pt-3">
                        <dt class="text-capitalize fw-normal">{{ translate('Change_Amount') }} :</dt>
                        <dd class="font-weight-bold title-color">{{ setCurrencySymbol(amount: 0) }}</dd>
                    </div>
                </div>

            </div>

            <?php
            $cartQty = $cartItems['countItem'] ?? 0;
            $clearCartTooltip = $cartQty < 1 ? translate('no_product_added_in_the_cart') : '';
            $holdOrderTooltip = $cartQty < 1 ? translate('no_product_added_in_the_cart_to_hold_order') : '';
            ?>
            <div class="d-flex gap-2 gap-sm-3 align-items-stretch bottom-absolute-buttons shadow-toast z-1">
                @if($cartItems['countItem'] > 0)
                    <button type="button" class="btn btn-outline-danger btn-block m-0 fs-12-mobile p-2 min-h-40 rounded text-nowrap  pos-clear-cart-btn"   data-toggle="modal"
                            data-target="#clearCartModal">
                        {{ translate('clear_Cart')}}
                    </button>
                    <button type="button" class="btn bg-info text-white btn-block m-0 fs-12-mobile p-2 min-h-40 rounded text-nowrap pos-hold-btn"  data-toggle="modal"
                            data-target="#holdOrderModal">
                        {{ translate('hold')}}
                    </button>
                <div class="place-order-wrapper w-100">
                    <button id="submit_order" type="button" class="btn btn--primary btn-block m-0 fs-12-mobile p-2 min-h-40 action-form-submit" data-message="{{ translate('want_to_place_this_order').'?'}}">
                        <i class="fa fa-shopping-bag"></i>
                        {{ translate('place_Order') }}
                    </button>
                </div>
                @else
                    <div title="{{ $clearCartTooltip }}" data-toggle="tooltip" class="flex-grow-1">
                        <button type="button"
                                class="btn btn-outline-danger btn-block m-0 fs-12-mobile p-2 min-h-40 rounded text-nowrap action-clear-cart pos-clear-cart-btn"
                                disabled>
                            {{ translate('clear_Cart')}}
                        </button>
                    </div>
                    <div title="{{ $holdOrderTooltip }}" data-toggle="tooltip" class="flex-grow-1">
                        <button type="button"
                                class="btn bg-info text-white btn-block m-0 fs-12-mobile p-2 min-h-40 rounded text-nowrap action-hold pos-hold-btn"
                                disabled>
                            {{ translate('hold')}}
                        </button>
                    </div>
                    <div title="{{ $clearCartTooltip }}" data-toggle="tooltip" class="flex-grow-1">
                        <button id="submit_order" type="button"
                                class="btn btn--primary btn-block m-0 fs-12-mobile p-2 min-h-40 action-form-submit disabled opacity-75"
                                disabled>
                            <i class="fa fa-shopping-bag"></i>
                            {{ translate('place_Order') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</form>

@include('vendor-views.pos.partials.modals._clear-cart-modal')
@include('vendor-views.pos.partials.modals._hold-order-modal')

@push('script_2')
    <script>
        'use strict';
        $('#type_ext_dis').on('change', function () {
            let type = $('#type_ext_dis').val();
            if (type === 'amount') {
                $('#dis_amount').attr('placeholder', 'Ex: 500');
            } else if (type === 'percent') {
                $('#dis_amount').attr('placeholder', 'Ex: 10%');
            }
        });
        $(function () {
            $('[data-toggle="tooltip"]').tooltip()
        })
    </script>
@endpush
