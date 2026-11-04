@extends('layouts.front-end.app')


@section('title', translate('order_Details'))
@push('css_or_js')
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/payment.css') }}">
@endpush

@section('content')
    <div class="container pb-5 mb-2 mb-md-4 mt-3 rtl __inline-47 text-align-direction">
        <div class="row g-3">
            @include('web-views.partials._profile-aside')


            <section class="col-lg-9">
                @include('web-views.users-profile.account-details.partial')
                <?php $digitalProduct = false; ?>
                @foreach ($order->details as $key=>$detail)
                    @if(isset($detail->product->digital_product_type))
                            <?php
                            $digitalProduct = $detail->product->product_type === 'digital' ? true : false;
                            ?>
                        @if($digitalProduct === true)
                            @break
                        @else
                            @continue
                        @endif
                    @endif
                @endforeach
                <div class="bg-white cus-shadow rounded-10 mobile-full">
                    <div class="p-xxl-4 p-lg-3 p-0">
                        @if(($order['payment_method'] == 'cash_on_delivery' || $order?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery') && $order['bring_change_amount'] > 0)
                            <div class="__badge soft-primary py-2 px-xxl-4 px-3 fs-14 text-dark rounded mb-3">
                                {{ translate('Please bring') }}
                                <strong> {{ $order['bring_change_amount'] }} {{ $order['bring_change_amount_currency'] ?? '' }}</strong> {{ translate('in change when making the delivery') }}
                            </div>
                        @endif


                        @if($order['order_type'] === "POS")
                            <div
                                class="p--20 mb-15px light-box rounded-8 d-flex align-items-center gap-2 justify-content-between flex-wrap">
                                <h6 class="m-0 fs-14 text-dark fw-semibold">{{ translate('Order info') }}</h6>
                                <div class="d-flex align-items-center flex-wrap order_info-top">
                                    <div class="d-flex align-items-center gap-2">
                                        <span
                                            class="m-0 fs-12 title-semidark lh-1">{{ translate('Order Type') }} :</span>
                                        <h6 class="m-0 fs-12 web-text-primary fw-semibold lh-1">{{ translate($order['order_type'] == "POS" ? "POS" : "Default" )}}</h6>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span
                                            class="m-0 fs-12 title-semidark lh-1">{{ translate('payment_status') }} :</span>
                                        <h6 class="m-0 fs-12 text-success fw-semibold lh-1">{{ $order['payment_status'] }}</h6>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span
                                            class="m-0 fs-12 title-semidark lh-1">{{ translate('Payment method') }} :</span>
                                        <h6 class="m-0 fs-12 text-dark fw-semibold lh-1">{{ translate(str_replace('_',' ',$order['payment_method'])) }}</h6>
                                    </div>
                                </div>
                            </div>
                        @endif
                        <?php
                        $showVerificationCode = $order->order_status != 'delivered' && $order->order_type == 'default_type' && getWebConfig(name: 'order_verification');
                        $colClass = 'col-md-12';
                        if ($order->edited_status == 1 && ($order?->payment_method != "cash_on_delivery" || $order?->latestEditHistory?->order_due_payment_status == "paid") && !$showVerificationCode) {
                            $colClass = 'col-md-6';
                        }
                        ?>
                        @if($order['order_type'] === "default_type")
                            <div class="row g-3 mb-2">
                                <!-- Order Edit -->
                                <div class="{{ $showVerificationCode ? 'col-md-6' : 'col-md-12' }}">
                                    <div
                                        class="d-flex justify-content-between gap-2 flex-wrap align-items-center h-100 light-box rounded-8 p--20">
                                        <div class="">
                                            <h6 class="fs-13 fw-semibold text-capitalize">{{translate('Order_info')}}</h6>
                                        </div>
                                        <div class="d-flex flex-column gap-2">
                                            <div class="fs-12 d-flex justify-content-end gap-2">
                                                <span
                                                    class="text-muted text-capitalize">{{translate('Order_Type')}} :</span>
                                                <span
                                                    class="text-primary text-capitalize fw-semibold">{{  translate($order['order_type'])  }}</span>
                                            </div>
                                            @if($order['payment_method'] == "cash_on_delivery")
                                                <div class="fs-12 d-flex justify-content-end gap-2">
                                                <span
                                                    class="text-muted text-capitalize">{{translate('payment_status')}} :</span>
                                                    <span
                                                        class="text-{{$order['payment_status'] == 'paid' ? 'success' : 'danger'}} text-capitalize fw-semibold">{{  translate($order['payment_status'])  }}</span>
                                                </div>
                                                <div class="fs-12 d-flex justify-content-end gap-2">
                                                <span
                                                    class="text-muted text-capitalize">{{translate('payment_method')}} :</span>
                                                    <span
                                                        class=" text-capitalize fw-semibold">{{  translate($order['payment_method'])  }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @if($showVerificationCode)
                                    <div class="col-md-6 col-sm-6">
                                        <div
                                            class="d-flex justify-content-between align-items-center h-100 light-box rounded-8 p--20 gap-2 flex-wrap">
                                            <div
                                                class="fs-14 text-dark ">{{ translate('Order verification code') }}</div>
                                            <h5 class="text-dark font-weight-bold">{{ $order['verification_code'] }}</h5>
                                        </div>
                                    </div>
                                @endif

                                @if($order['payment_method'] != "cash_on_delivery")
                                    <div class="col-12">
                                        <div class="h-100 light-box rounded-8 p--20">
                                            <div class="d-flex flex-column justify-content-between gap-2">
                                                <div class="">
                                                    <h6 class="fs-13 fw-semibold text-capitalize">{{translate('payment_info')}}</h6>
                                                </div>

                                                <div class="row g-3">
                                                        <?php
                                                        $showOnlyPaymentInfo = ($order->edited_status == 1 && ($order?->latestEditHistory?->order_due_payment_method == "offline_payment" || $order?->latestEditHistory?->order_due_payment_method == "cash_on_delivery" || $order?->latestEditHistory?->order_due_payment_status == "paid")) ||
                                                            ($order->edited_status == 1 && $order->edit_due_amount > 0 && $order?->payment_method != "cash_on_delivery") ||
                                                            ($order->edited_status == 1 && $order?->latestEditHistory?->order_return_payment_status == 'pending') ||
                                                            ($order->edited_status == 1 && $order?->latestEditHistory?->order_return_payment_status == "returned");
                                                        ?>

                                                    <div class="{{ $showOnlyPaymentInfo ? 'col-md-6' : 'col-md-12' }}">
                                                        <div
                                                            class="d-flex flex-column gap-2 bg-white rounded py-3 px-3">
                                                            <div class="fs-12 d-flex justify-content-start gap-2">
                                                                <span class="text-muted text-capitalize">{{translate('payment_status')}} :</span>
                                                                @if($order->edited_status == 1 && $order->edit_due_amount > 0 && $order?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $order?->latestEditHistory?->order_due_payment_status == "unpaid")
                                                                    <span
                                                                        class="text-success text-capitalize fw-semibold">{{ translate("Partially_Paid") }}</span>
                                                                @else
                                                                    <span
                                                                        class="text-{{$order['payment_status'] == 'paid' ? 'success' : 'danger'}} text-capitalize fw-semibold">{{$order['payment_status']}}</span>
                                                                @endif
                                                            </div>
                                                            <div class="fs-12 d-flex justify-content-start gap-2">
                                                                <span class="text-muted text-capitalize">{{translate('payment_method')}} :</span>
                                                                <span
                                                                    class="text-dark text-capitalize fw-semibold">{{translate($order['payment_method'])}}</span>
                                                            </div>
                                                            <div class="fs-12 d-flex justify-content-start gap-2">
                                                            <span class="text-muted text-capitalize">
                                                                {{translate('Amount')}} :
                                                            </span>
                                                                @if(($order['total_order_amount'] ?? 0) > 0)
                                                                    <span class="text-dark text-capitalize fw-semibold">
                                                                {{ webCurrencyConverter(amount: $order['total_order_amount'])  }}
                                                            </span>
                                                                @else
                                                                    <span class="text-dark text-capitalize fw-semibold">
                                                                {{ webCurrencyConverter(amount:  $order['init_order_amount'])  }}
                                                            </span>
                                                                @endif
                                                            </div>
                                                            @if($order->payment_method == 'offline_payment' && isset($order->offlinePayments))
                                                                <div class="fs-12 d-flex justify-content-start gap-2">
                                                                    <button type="button"
                                                                            class="btn bg-primary text-hover-white bg-opacity-10 mt-1 rounded-pill btn-sm text-capitalize fs-12 font-semi-bold"
                                                                            data-toggle="modal"
                                                                            data-target="#verifyViewModal">
                                                                        {{ translate('see_payment_details') }}
                                                                    </button>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    @if($order->edited_status == 1 && ($order?->latestEditHistory?->order_due_payment_method == "offline_payment" || $order?->latestEditHistory?->order_due_payment_method == "cash_on_delivery" || $order?->latestEditHistory?->order_due_payment_status == "paid"))
                                                        <div class="col-md-6 col-sm-6">
                                                            <div
                                                                class="bg-white d-flex flex-column gap-2 h-100 px-3 py-3 rounded">
                                                                <div
                                                                    class="fs-14 d-flex w-100 justify-content-between gap-2">
                                                                    <span
                                                                        class="text-capitalize text-dark font-weight-bold">{{translate('Another Payment Info')}} :</span>
                                                                    <span
                                                                        class="fs-12 fw-semibold text-{{ $order?->latestEditHistory?->order_due_payment_status == 'paid' ? 'success' : 'danger'}} text-capitalize">{{ $order?->latestEditHistory?->order_due_payment_status }}</span>
                                                                </div>
                                                                <div class="fs-12 d-flex justify-content-start gap-2">
                                                                    <span class="text-muted text-capitalize">{{translate('payment_method')}} :</span>
                                                                    <span
                                                                        class="text-dark text-capitalize fw-semibold">{{translate($order?->latestEditHistory?->order_due_payment_method)}}</span>
                                                                </div>
                                                                <div class="fs-12 d-flex justify-content-start gap-2">
                                                                    <span class="text-muted text-capitalize">{{translate('Due amount')}} :</span>
                                                                    <span
                                                                        class="text-dark text-capitalize fw-semibold">{{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_due_amount ?? 0) }}</span>
                                                                </div>
                                                                @if($order?->latestEditHistory?->order_due_payment_method == "offline_payment" && $order?->latestEditHistory?->order_due_payment_info)
                                                                    <div
                                                                        class="fs-12 d-flex justify-content-start gap-2">
                                                                        <button type="button"
                                                                                class="btn bg--secondary mt-1 rounded-pill btn-sm text-capitalize fs-12 font-semi-bold"
                                                                                data-toggle="modal"
                                                                                data-target="#orderDuePaymentInfoModal">
                                                                            {{ translate('see_payment_details') }}
                                                                        </button>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @elseif($order->edited_status == 1 && $order->edit_due_amount > 0 &&  $order?->payment_method != "cash_on_delivery")
                                                        <div class="col-md-6 col-sm-6">
                                                            <div
                                                                class="bg-white d-flex flex-column gap-2 h-100 px-3 py-3 rounded">
                                                                <div
                                                                    class="d-flex flex-column justify-content-center align-items-center gap-1 h-100">
                                                                    <div class="">
                                                                        <h6 class="fs-16 mb-1 text-danger fw-semibold text-capitalize">{{translate('Pay Due Bill')}}</h6>
                                                                    </div>
                                                                    <p class="fs-12 text-center">
                                                                        {{ translate('After editing your product list, you need to pay an additional') }}
                                                                        <strong class="font-weight-bold fs-16">
                                                                            {{ webCurrencyConverter(amount: $order['edit_due_amount'] )  }}
                                                                        </strong> {{ translate('to continue processing this order') }}
                                                                    </p>
                                                                    <button type="button" data-toggle="modal"
                                                                            data-target="#choosePaymentMethodModal-{{ $order['id'] }}"
                                                                            class="btn btn--primary font-bold py-2 px-3 font-weight-normal">
                                                                        {{ translate('Pay Now') }}
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @elseif($order->edited_status == 1 && $order?->latestEditHistory?->order_return_payment_status == 'pending')
                                                        <div class="col-md-6 col-sm-6">
                                                            <div
                                                                class="bg-white d-flex flex-column gap-2 h-100 px-3 py-3 rounded">
                                                                <div>
                                                                    <h6 class="fs-16 mb-1 text-success fw-semibold text-capitalize">
                                                                        {{ translate('Amount to Be Returned') }}
                                                                    </h6>
                                                                </div>
                                                                <p class="fs-12">
                                                                    {{ translate('After editing your product list, you will receive') }}
                                                                    <strong class="font-weight-bold fs-16">
                                                                        {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_return_amount ?? 0) }}
                                                                    </strong>
                                                                    {{ translate('Please wait for the admin to process the returned amount') }}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    @elseif($order->edited_status == 1 && $order?->latestEditHistory?->order_return_payment_status == "returned")
                                                        <div class="col-md-6 col-sm-6">
                                                            <div
                                                                class="bg-white d-flex flex-column gap-2 h-100 px-3 py-3 rounded">
                                                                <div class="fs-12 d-flex justify-content-start gap-2">
                                                                    <span class="text-capitalize text-dark">{{translate('Another Payment Info')}} :</span>
                                                                </div>
                                                                <div class="fs-12 d-flex justify-content-start gap-2">
                                                                    <span class="text-muted text-capitalize">{{translate('Payment_status')}} :</span>
                                                                    <span
                                                                        class="text-{{$order?->latestEditHistory?->order_return_payment_status == 'returned' ? 'success' : 'danger'}} text-capitalize fw-semibold">{{ $order?->latestEditHistory?->order_return_payment_status }}</span>
                                                                </div>
                                                                <div class="fs-12 d-flex justify-content-start gap-2">
                                                                    <span class="text-muted text-capitalize">{{translate('Return_Amount')}} :</span>
                                                                    <span
                                                                        class="text-dark text-capitalize fw-semibold">{{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_return_amount) }}</span>
                                                                </div>
                                                                <div class="fs-12 d-flex justify-content-start gap-2">
                                                                    <span class="text-muted text-capitalize">{{translate('Return Payment method')}} :</span>
                                                                    <span
                                                                        class="text-dark text-capitalize fw-semibold">{{translate($order?->latestEditHistory?->order_return_payment_method)}}</span>
                                                                </div>
                                                                <div
                                                                    class="bg-white py-2 px-2 rounded text-start fs-14">
                                                                    #Note
                                                                    : {{translate($order?->latestEditHistory?->order_return_payment_note)}}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endif
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if($order->order_status == 'delivered')
                                    <div class="col-md-6 col-sm-6">
                                        <div
                                            class="d-flex justify-content-between align-items-center h-100 light-box rounded-8 p--20 gap-2 flex-wrap mb-3">
                                            <p class="m-0 fs-14 text-dark fw-semibold">
                                                {{ translate('Want to order the same items again') }}?
                                            </p>
                                            <div>
                                                @if($order->order_type == 'POS')
                                                    <div>
                                                        <span
                                                            class="pos-btn hover-none">{{ translate('POS_Order') }}</span>
                                                    </div>
                                                @endif
                                                <div class="d-flex align-items-center gap-2">
                                                    <button
                                                        class="btn btn--primary btn-sm h-40px rounded text_capitalize get-order-again-function"
                                                        data-id="{{ $order->id }}">
                                                        {{ translate('reorder') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                                @if( $order->order_type == 'default_type')
                                        <?php
                                        $shipping = isset($order->shipping_address_data)
                                            ? (is_string($order->shipping_address_data)
                                                ? json_decode($order->shipping_address_data)
                                                : $order->shipping_address_data)
                                            : null;


                                        $billing = isset($order->billing_address_data)
                                            ? (is_string($order->billing_address_data)
                                                ? json_decode($order->billing_address_data)
                                                : $order->billing_address_data)
                                            : null;


                                        $hasShipping = $shipping;
                                        $hasBilling = $billing;
                                        $colClass = ($hasShipping && $hasBilling) ? 'col-md-6 col-sm-6' : 'col-md-12 col-sm-12';
                                        ?>


                                    @php($shipping = $order['shipping_address_data'] ?? null)
                                    @if($hasShipping && !empty((array) $shipping))
                                        <div class="{{ $colClass }}">
                                            <div class="light-box rounded-8 p--20 h-100">
                                                <div class="pb-1">
                                                    <h6 class="fs-13 fw-semibold text-capitalize">
                                                        {{ translate('shipping_address') }}:
                                                    </h6>
                                                </div>
                                                <div class="text-capitalize fs-12">
                                                    <span
                                                        class="min-w-60px title-semidark">{{ translate('name') }}</span>
                                                    : {{ $shipping->contact_person_name }}<br>
                                                    <span
                                                        class="min-w-60px title-semidark">{{ translate('phone') }}</span>
                                                    : {{ $shipping->phone }}<br>
                                                    <span
                                                        class="min-w-60px title-semidark">{{ translate('city') }} / {{ translate('zip') }}</span>
                                                    :
                                                    {{ $shipping->city }}, {{ $shipping->zip }}<br>
                                                    <span
                                                        class="min-w-60px title-semidark">{{ translate('address') }}</span>
                                                    :
                                                    {{ $shipping->address }}
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @if($hasBilling && !empty((array) $billing))
                                        <div class="{{ $colClass }}">
                                            <div class="light-box rounded-8 p--20 h-100">
                                                <div class="pb-1">
                                                    <h6 class="fs-13 fw-semibold text-capitalize">
                                                        {{ translate('billing_address') }}:
                                                    </h6>
                                                </div>

                                                    <?php
                                                    $isSameAddress = $billing && $shipping && !empty((array) $shipping) &&
                                                        ($billing->address == $shipping->address) &&
                                                        ($billing->city == $shipping->city) &&
                                                        ($billing->zip == $shipping->zip);
                                                    ?>
                                                @if($isSameAddress)
                                                    <div class="bg-white card">
                                                        <div class="d-center py-5 px-4">
                                                            <p class="fs-14 m-0">{{ translate('Same as shipping address') }}</p>
                                                        </div>
                                                    </div>
                                                @else
                                                    <div class="text-capitalize fs-12">
                                                        <span
                                                            class="min-w-60px title-semidark">{{ translate('name') }}</span>
                                                        :
                                                        {{ $billing->contact_person_name }}<br>
                                                        <span
                                                            class="min-w-60px title-semidark">{{ translate('phone') }}</span>
                                                        :
                                                        {{ $billing->phone }}<br>
                                                        <span class="min-w-60px title-semidark">{{ translate('city') }} / {{ translate('zip') }}</span>
                                                        :
                                                        {{ $billing->city }}, {{ $billing->zip }}<br>
                                                        <span
                                                            class="min-w-60px title-semidark">{{ translate('address') }}</span>
                                                        :
                                                        {{ $billing->address }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endif
                        <div class="border overflow-hidden rounded-10 mb-3">
                            <div class="payment table-responsive d-nones d-lg-block">
                                <table class="table table-border min-width-600px">
                                    <thead class="thead-light text-capitalize">
                                    <tr class="fs-13 font-semi-bold">
                                        <th class="fw-semibold fs-14 text-nowrap ">{{translate('Sl')}}</th>
                                        <th class="fw-semibold fs-14 text-nowrap ">{{translate('Item List')}}</th>
                                        <th class="fw-semibold fs-14 text-nowrap text-center">{{translate('qty')}}</th>
                                        <th class="fw-semibold fs-14 text-nowrap text-right">{{translate('price')}}</th>
                                        <th class="fw-semibold fs-14 text-nowrap text-right">{{translate('discount')}}</th>
                                        <th class="fw-semibold fs-14 text-nowrap text-right">{{translate('Total')}}</th>
                                        <th class="fw-semibold fs-14 text-nowrap text-center">{{translate('Action')}}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php $orderDetailsIndex = 1; ?>
                                    @foreach ($order->details as $key => $detail)
                                        @php($product = $detail?->productAllStatus ?? json_decode($detail->product_details, true))
                                        @if($product)
                                            <tr>
                                                <td class="align-middle">
                                                    {{ $orderDetailsIndex }}
                                                </td>
                                                <td class="for-tab-img">
                                                    <div class="media gap-3 min-w-200 align-items-center">
                                                        <div
                                                            class="position-relative border h-70 w-60 min-w-60px rounded overflow-hidden">
                                                            @if(getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0)
                                                                <span
                                                                    class="for-discount-value px-1 mx-1 fs-10 text-wrap overflow-wrap-anywhere direction-ltr">
                                                                           -{{ getProductPriceByType(product: $product, type: 'discount', result: 'string') }}
                                                                       </span>
                                                            @endif
                                                            <img class="d-block w-100 h-100 get-view-by-onclick w-70px"
                                                                 data-link="{{ route('product',$product['slug']) }}"
                                                                 src="{{ getStorageImages(path: $detail?->productAllStatus?->thumbnail_full_url, type: 'product') }}"
                                                                 alt="{{ translate('product') }}">
                                                        </div>


                                                        <div class="media-body">
                                                            <a href="{{route('product',[$product['slug']])}}"
                                                               class="fs-14 font-semi-bold mb-2 line--limit-2 max-w-200px">
                                                                {{isset($product['name']) ? Str::limit($product['name'], 60) : ''}}
                                                            </a>
                                                            <div class="fs-12 text-capitalize mb-1">
                                                                {{ translate('unit_price_:') }}
                                                                {{ webCurrencyConverter($detail->price) }}
                                                            </div>
                                                            @if($detail->refund_request == 1)
                                                                <small> ({{translate('refund_pending')}}) </small>
                                                                <br>
                                                            @elseif($detail->refund_request == 2)
                                                                <small> ({{translate('refund_approved')}}) </small>
                                                                <br>
                                                            @elseif($detail->refund_request == 3)
                                                                <small> ({{translate('refund_rejected')}}) </small>
                                                                <br>
                                                            @elseif($detail->refund_request == 4)
                                                                <small> ({{translate('refund_refunded')}}) </small>
                                                                <br>
                                                            @endif


                                                            @if($detail->variant)
                                                                <small class="fs-12 text-secondary-50">
                                                                    <span
                                                                        class="font-bold">{{translate('variant')}} : </span>
                                                                    <span
                                                                        class="font-semi-bold">{{$detail->variant}}</span>
                                                                </small>
                                                            @endif


                                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                                    <?php
                                                                    $refund_day_limit = getWebConfig(name: 'refund_day_limit');
                                                                    $current = \Carbon\Carbon::now();
                                                                    $length = $detail?->refund_started_at?->diffInDays($current);
                                                                    ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="align-middle">
                                                    <div class="pl-2">
                                                               <span class="word-nobreak">
                                                                   {{$detail->qty}}
                                                               </span>
                                                    </div>
                                                </td>
                                                <td class="text-right align-middle">
                                                           <span class="fw-semibold amount text-nowrap">
                                                               {{webCurrencyConverter($detail->price * $detail->qty)}}
                                                           </span>
                                                </td>
                                                <td class="text-right align-middle">
                                                           <span class="fw-semibold amount text-nowrap">
                                                               {{webCurrencyConverter($detail->discount)}}
                                                           </span>
                                                </td>
                                                <td class="text-right align-middle">
                                                           <span class="fw-semibold amount text-nowrap">
                                                               {{webCurrencyConverter(($detail->qty*$detail->price)-$detail->discount)}}
                                                           </span>
                                                </td>
                                                <td class="align-middle">
                                                    @if(
                                                      ($order->order_type == 'default_type' && ($order->order_status=='delivered' || (isset($digitalProduct) && ($order->payment_status == 'paid' && $digitalProduct)))) ||
                                                      ($order->order_type != 'default_type' && $order->order_status=='delivered')
                                                  )
                                                        <div
                                                            class="d-flex align-items-center justify-content-center text-center gap-2">
                                                            @if($order->order_type == 'default_type' && $order->order_status=='delivered')
                                                                @if (isset($detail->product))
                                                                    <button type="button"
                                                                            class="btn web-text-primary p-0 m-0 fs-14"
                                                                            data-toggle="modal"
                                                                            data-target="#submitReviewModal{{$detail->id}}">
                                                                        @if (isset($detail->reviewData))
                                                                            {{translate('Update_Review')}}
                                                                        @else
                                                                            {{translate('Give Review')}}
                                                                        @endif
                                                                    </button>
                                                                @endif
                                                            @endif
                                                            @if($product && $order->payment_status == 'paid' && isset($product['digital_product_type']) && $product['digital_product_type'] == 'ready_product')
                                                                <a href="javascript:"
                                                                   class="btn __badge h-30 py-1 px-2 rounded soft-primary action-digital-product-download"
                                                                   data-link="{{ route('digital-product-download', $detail->id) }}">
                                                                    <i class="fi fi-rr-download fs-12"></i>
                                                                </a>
                                                            @elseif($product && $order->payment_status == 'paid' && isset($product['digital_product_type']) && $product['digital_product_type'] == 'ready_after_sell')
                                                                @if($detail->digital_file_after_sell)
                                                                    <a href="javascript:"
                                                                       data-link="{{ route('digital-product-download', $detail->id) }}"
                                                                       class="btn __badge h-30 py-1 px-2 rounded soft-primary action-digital-product-download"
                                                                       data-toggle="tooltip"
                                                                       data-placement="top"
                                                                       data-bs-custom-class="custom-tooltip"
                                                                       data-title="Download"
                                                                       download>
                                                                        <i class="fi fi-rr-download fs-12"></i>
                                                                    </a>
                                                                @else
                                                                    <a href="javascript:"
                                                                       class="btn __badge h-30 py-1 px-2 rounded soft-primary"
                                                                       data-placement="top"
                                                                       data-bs-custom-class="custom-tooltip"
                                                                       title="Admin hasn’t uploaded it yet"
                                                                    >
                                                                        <i class="fi fi-rr-download fs-12"></i>
                                                                    </a>
                                                                @endif
                                                            @endif


                                                        </div>
                                                    @else
                                                        <div class="text-center text-muted">--</div>
                                                    @endif
                                                </td>
                                            </tr>
                                                <?php $orderDetailsIndex++; ?>
                                        @endif
                                    @endforeach


                                    </tbody>
                                </table>
                            </div>
                        </div>


                        @php($orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order))
                        <div class="row d-flex justify-content-end mt-2">
                            <div class="col-md-8 col-lg-5">
                                <div class="bg-white border rounded">
                                    <div class="card-body p-2">
                                        @if($order['edited_status'])
                                            <div
                                                class="bg-opacity-primary-10 fs-14 px-12 py-2 text-dark rounded d-flex gap-2 align-items-center">
                                           <span>
                                               {{ translate('#Note : Total bill has been updated after the edits.') }}
                                           </span>
                                            </div>
                                        @endif
                                        <table class="calculation-table table table-borderless mb-0">
                                            <tbody class="totals">
                                            <tr>
                                                <td>
                                                    <div class="text-start">
                                                        <span
                                                            class="product-qty title-semidark">{{translate('Total_Item')}}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-end">
                                                       <span class="fs-15">
                                                           {{ $orderTotalPriceSummary['totalItemQuantity'] }}
                                                       </span>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <div class="text-start">
                                                        <span
                                                            class="product-qty title-semidark">{{translate('item_price')}}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-end">
                                                       <span class="fs-15">
                                                           {{ webCurrencyConverter(amount: $orderTotalPriceSummary['itemPrice']) }}
                                                       </span>
                                                    </div>
                                                </td>
                                            </tr>


                                            <tr>
                                                <td>
                                                    <div class="text-start">
                                                        <span
                                                            class="product-qty title-semidark">{{translate('item_discount')}}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-end">
                                                       <span class="fs-15">
                                                           {{ webCurrencyConverter(amount: $orderTotalPriceSummary['itemDiscount']) }}
                                                       </span>
                                                    </div>
                                                </td>
                                            </tr>


                                            @if($order->order_type != 'default_type')
                                                <tr>
                                                    <td>
                                                        <div class="text-start">
                                                           <span class="product-qty title-semidark">
                                                               {{translate('extra_discount')}}
                                                           </span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-end">
                                                           <span class="fs-15">
                                                               - {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['extraDiscount']) }}
                                                           </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif


                                            <tr>
                                                <td>
                                                    <div class="text-start">
                                                        <span
                                                            class="product-qty title-semidark">{{translate('subtotal')}}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-end">
                                                       <span class="fs-15">
                                                           {{ webCurrencyConverter(amount: $orderTotalPriceSummary['subTotal']) }}
                                                       </span>
                                                    </div>
                                                </td>
                                            </tr>


                                            <tr>
                                                <td>
                                                    <div class="text-start">
                                                       <span class="product-qty title-semidark">
                                                           {{translate('coupon_discount')}}
                                                       </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-end">
                                                       <span class="fs-15">
                                                           - {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['couponDiscount']) }}
                                                       </span>
                                                    </div>
                                                </td>
                                            </tr>


                                            @if($orderTotalPriceSummary['referAndEarnDiscount'] > 0)
                                                <tr>
                                                    <td>
                                                        <div class="text-start">
                                                       <span class="product-qty title-semidark">
                                                           {{ translate('referral_discount') }}
                                                       </span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-end">
                                                       <span class="fs-15">
                                                           - {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['referAndEarnDiscount']) }}
                                                       </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif


                                            <tr>
                                                <td>
                                                    <div class="text-start">
                                                       <span class="product-qty title-semidark">
                                                           {{translate('tax_fee')}}
                                                       </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-end">
                                                       <span class="fs-15">
                                                           {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['taxTotal']) }}
                                                       </span>
                                                    </div>
                                                </td>
                                            </tr>


                                            @if($order->order_type == 'default_type' && $order?->is_shipping_free == 0)
                                                <tr>
                                                    <td>
                                                        <div class="text-start">
                                                           <span class="product-qty title-semidark">
                                                               {{translate('shipping_Fee')}}
                                                           </span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-end">
                                                           <span class="fs-15">
                                                               {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['shippingTotal']) }}
                                                           </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif


                                            <tr class="border-top">
                                                <td>
                                                    <div class="text-start">
                                                       <span class="font-weight-bold">
                                                           <strong class="fs-16">{{translate('total')}}</strong>
                                                           <span class="fs-10 fw-medium">
                                                               {{ $orderTotalPriceSummary['tax_model'] == 'include' ? '('.translate('Tax_:_Inc.').')' : '' }}
                                                           </span>
                                                       </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-end">
                                                       <span class="font-weight-bold amount fs-16">
                                                           {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['totalAmount']) }}
                                                       </span>
                                                    </div>
                                                </td>
                                            </tr>

                                            @if(($orderTotalPriceSummary['edited_total_paid_amount'] ?? 0) > 0)
                                                <tr>
                                                    <td class="pt-0">
                                                        <div class="text-start">
                                                       <span class="product-qty title-semidark">
                                                           {{ translate('Paid Amount') }}
                                                       </span>
                                                        </div>
                                                    </td>
                                                    <td class="pt-0">
                                                        <div class="text-end">
                                                       <span class="fs-15">
                                                           {{ webCurrencyConverter(amount: $orderTotalPriceSummary['edited_total_paid_amount']) }}
                                                       </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif

                                            @if($order->edited_status == 1 && $order?->latestEditHistory)

                                                @if($order?->latestEditHistory?->order_due_amount > 0)
                                                    @if($order?->latestEditHistory?->order_due_payment_status == 'paid')
                                                        <tr>
                                                            <td class="pt-0">
                                                                <div class="text-start">
                                                                   <span class="product-qty title-semidark">
                                                                       {{ translate('Paid Amount') }}
                                                                   </span>
                                                                </div>
                                                            </td>
                                                            <td class="pt-0">
                                                                <div class="text-end">
                                                                   <span class="fs-15">
                                                                       {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_amount - $order?->latestEditHistory?->order_due_amount) }}
                                                                   </span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td class="pt-0">
                                                                <div class="text-start d-flex flex-column">
                                                                    <span class="product-qty title-semidark">
                                                                       {{ translate('Due Amount Paid By') }}
                                                                    </span>
                                                                    <span>({{ ucwords(str_replace('_', ' ', $order?->latestEditHistory?->order_due_payment_method)) }})</span>
                                                                </div>
                                                            </td>
                                                            <td class="pt-0">
                                                                <div class="text-end">
                                                                   <span class="fs-15">
                                                                     {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_due_amount)  }}
                                                                   </span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td class="pt-0">
                                                                <div class="text-start">
                                                                   <span class="font-weight-bold">
                                                                       <strong class="fs-16">
                                                                            {{ translate('Total Paid Amount') }}
                                                                       </strong>
                                                                   </span>
                                                                </div>
                                                            </td>
                                                            <td class="pt-0">
                                                                <div class="text-end">
                                                                    <span class="font-weight-bold amount fs-16">
                                                                       {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_amount)  }}
                                                                   </span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @else
                                                        <tr>
                                                            <td class="pt-0">
                                                                <div class="text-start">
                                                                   <span
                                                                       class="font-weight-bold d-flex align-items-center gap-1">
                                                                       <strong class="text-danger fs-16">
                                                                           {{ translate('Due Amount') }}
                                                                       </strong>
                                                                   </span>
                                                                </div>
                                                            </td>
                                                            <td class="pt-0">
                                                                <div class="text-end">
                                                                   <span class="font-weight-bold fs-16 text-danger">
                                                                     {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_due_amount)  }}
                                                                   </span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @endif
                                                @endif

                                                @if($order?->latestEditHistory?->order_return_amount > 0)
                                                    @if($order?->latestEditHistory?->order_return_payment_status == "returned")
                                                        <tr>
                                                            <td class="pt-0">
                                                                <div class="text-start">
                                                                   <span class="product-qty title-semidark">
                                                                       {{ translate('Paid Amount') }}
                                                                   </span>
                                                                </div>
                                                            </td>
                                                            <td class="pt-0">
                                                                <div class="text-end">
                                                                   <span class="fs-15">
                                                                     {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_amount + $order?->latestEditHistory?->order_return_amount)  }}
                                                                   </span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td class="pt-0">
                                                                <div class="text-start d-flex flex-column">
                                                                    <span class="font-weight-bold">
                                                                        <strong class="fs-16">
                                                                            {{ translate('Returned by') }}
                                                                        </strong>
                                                                    </span>
                                                                    <span>({{ ucwords(str_replace('_', ' ', $order?->latestEditHistory?->order_return_payment_method)) }})</span>
                                                                </div>
                                                            </td>
                                                            <td class="pt-0">
                                                                <div class="text-end">
                                                                    <span class="font-weight-bold amount fs-16">
                                                                       {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_return_amount)  }}
                                                                   </span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @else
                                                        <tr>
                                                            <td class="pt-0">
                                                                <div class="text-start">
                                                               <span
                                                                   class="font-weight-bold d-flex align-items-center gap-1">
                                                                   <strong class="text-danger fs-16">
                                                                       {{ translate('Amount To Return') }}
                                                                   </strong>
                                                               </span>
                                                                </div>
                                                            </td>
                                                            <td class="pt-0">
                                                                <div class="text-end">
                                                               <span class="font-weight-bold fs-16 text-danger">
                                                                     {{ webCurrencyConverter(amount: $order?->latestEditHistory?->order_return_amount)  }}
                                                               </span>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @endif
                                                @endif
                                            @endif

                                            @if ($order->order_type == 'POS' || $order->order_type == 'pos')
                                                <tr class="border-top">
                                                    <td>
                                                        <div class="text-start">
                                                           <span class="font-weight-bold">
                                                               <strong>{{translate('paid_amount')}}</strong>
                                                           </span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-end">
                                                           <span class="font-weight-bold amount">
                                                               {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['paidAmount']) }}
                                                           </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <tr class="">
                                                    <td>
                                                        <div class="text-start">
                                                       <span class="font-weight-bold">
                                                           <strong>{{translate('change_amount')}}</strong>
                                                       </span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="text-end">
                                                       <span class="font-weight-bold amount">
                                                           {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['changeAmount']) }}
                                                       </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif
                                            </tbody>
                                        </table>


                                        @if ($order['order_status']=='pending')
                                            <button
                                                class="btn btn-soft-danger btn-soft-border w-100 btn-sm text-danger font-semi-bold text-capitalize mt-3 call-route-alert"
                                                data-route="{{ route('order-cancel',[$order->id]) }}"
                                                data-message="{{translate('want_to_cancel_this_order?')}}">
                                                {{translate('cancel_order')}}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
    @if($order->order_status=='delivered')
        <div class="bottom-sticky_offset"></div>
        <div class="bottom-sticky_ele bg-white d-md-none p-3 ">
            <button class="btn btn--primary w-100 text_capitalize get-order-again-function" data-id="{{ $order->id }}">
                {{ translate('reorder') }}
            </button>
        </div>
    @endif

    @if($order->payment_method == 'offline_payment' && isset($order->offlinePayments))
        <div class="modal fade" id="verifyViewModal" tabindex="-1" aria-labelledby="verifyViewModalLabel"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content rtl">
                    <div class="modal-header d-flex justify-content-end  border-0 pb-0">
                        <button type="button" class="close pe-0" data-dismiss="modal">
                            <span aria-hidden="true" class="tio-clear"></span>
                        </button>
                    </div>


                    <div class="modal-body pt-0">
                        <h5 class="mb-3 text-center text-capitalize fs-16 font-semi-bold">
                            {{ translate('payment_verification') }}
                        </h5>


                        <div class="shadow-sm rounded p-3">
                            <h6 class="mb-3 text-capitalize fs-16 font-semi-bold">
                                {{translate('customer_information')}}
                            </h6>


                            <div class="d-flex flex-column gap-2 fs-12 mb-4">
                                <div class="d-flex align-items-center gap-2">
                                    <span class=" min-w-120">{{translate('name')}}</span>
                                    <span>:</span>
                                    <span class="text-dark">
                                       <a class="font-weight-medium fs-12 text-capitalize" href="Javascript:">
                                           {{$order->customer->f_name ?? translate('name_not_found') }}&nbsp;{{$order->customer->l_name ?? ''}}
                                       </a>
                                   </span>
                                </div>


                                <div class="d-flex align-items-center gap-2">
                                    <span class=" min-w-120">{{translate('phone')}}</span>
                                    <span>:</span>
                                    <span class="text-dark">
                                       <a class="font-weight-medium fs-12 text-capitalize"
                                          href="{{ $order?->customer?->phone ? 'tel:'.$order?->customer?->phone : 'javascript:' }}">
                                           {{ $order->customer->phone ?? translate('number_not_found') }}
                                       </a>
                                   </span>
                                </div>
                            </div>


                            <div class="mt-3 border-top pt-4">
                                <h6 class="mb-3 text-capitalize fs-16 font-semi-bold">
                                    {{ translate('payment_information') }}
                                </h6>


                                <div class="d-flex flex-column gap-2 fs-12">


                                    @foreach ($order->offlinePayments->payment_info as $key=>$value)
                                        @if ($key != 'method_id')
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="text-capitalize min-w-120">{{translate($key)}}</span>
                                                <span>:</span>
                                                <span class="font-weight-medium fs-12 ">
                                                   {{$value ?? "N/a"}}
                                               </span>
                                            </div>
                                        @endif
                                    @endforeach


                                    @if($order->payment_note)
                                        <div class="d-flex align-items-start gap-2">
                                            <span
                                                class="text-capitalize min-w-120">{{ translate('payment_none') }}</span>
                                            <span>:</span>
                                            <span class="font-weight-medium fs-12 "> {{ $order->payment_note }}  </span>
                                        </div>
                                    @endif


                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @if(
    $order?->latestEditHistory?->order_due_payment_method === 'offline_payment'
    && !empty($order?->latestEditHistory?->order_due_payment_info)
)
        <div class="modal fade" id="orderDuePaymentInfoModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content rtl">
                    <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                        <button type="button" class="close pe-0" data-dismiss="modal">
                            <span aria-hidden="true" class="tio-clear"></span>
                        </button>
                    </div>
                    <div class="modal-body pt-0">
                        <h5 class="mb-3 text-center fs-16 font-semi-bold">
                            {{ translate('payment_information') }}
                        </h5>
                        <div class="shadow-sm rounded p-3">
                            <div class="d-flex flex-column gap-2 fs-12">
                                @foreach($order?->latestEditHistory?->order_due_payment_info as $key => $value)
                                    @if($key !== 'method_id')
                                        <div class="d-flex align-items-center gap-2">
                                    <span class="text-capitalize min-w-120">
                                        {{ translate(str_replace('_', ' ', $key)) }}
                                    </span>
                                            <span>:</span>
                                            <span class="font-weight-medium">
                                        {{ $value ?? 'N/A' }}
                                    </span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @include('web-views.partials._choose-payment-method-order-details',[
        'order' => $order,
        'paymentGatewayList' => $paymentGatewayList,
    ])

    <span id="message-ratingContent"
          data-poor="{{ translate('poor') }}"
          data-average="{{ translate('average') }}"
          data-good="{{ translate('good') }}"
          data-good-message="{{ translate('the_delivery_service_is_good') }}"
          data-good2="{{ translate('very_Good') }}"
          data-good2-message="{{ translate('this_delivery_service_is_very_good_I_am_highly_impressed') }}"
          data-excellent="{{ translate('excellent') }}"
          data-excellent-message="{{ translate('best_delivery_service_highly_recommended') }}"
    ></span>
@endsection




@push('script')
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/spartan-multi-image-picker.js') }}"></script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/payment.js') }}"></script>
@endpush
