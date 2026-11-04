@extends('layouts.front-end.app')

@section('title', translate('track_Order'))

@push('css_or_js')
    <meta property="og:image" content="{{$web_config['web_logo']['path']}}"/>
    <meta property="og:title" content="{{$web_config['company_name']}} "/>
    <meta property="og:url" content="{{env('APP_URL')}}">
    <meta property="og:description" content="{{ $web_config['meta_description'] }}">
    <meta property="twitter:card" content="{{$web_config['web_logo']['path']}}"/>
    <meta property="twitter:title" content="{{$web_config['company_name']}}"/>
    <meta property="twitter:url" content="{{env('APP_URL')}}">
    <meta property="twitter:description" content="{{ $web_config['meta_description'] }}">

    <link rel="stylesheet" media="screen"
          href="{{theme_asset(path: 'public/assets/front-end/vendor/nouislider/distribute/nouislider.min.css')}}"/>
    <link rel="stylesheet"
          href="{{ theme_asset(path: 'public/assets/front-end/plugin/intl-tel-input/css/intlTelInput.css') }}">

    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/payment.css') }}">
@endpush

@section('content')

    <?php
    $order = \App\Models\OrderDetail::where('order_id', $orderDetails->id)->get();
    ?>
    <div class="modal fade rtl z-1049 order-choose-payment-method-modal" id="order-details">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" id="order-view-details-modal">
                <div class="modal-header border-0 px-2 pt-2 pb-0 align-items-start">
                     <div class="d-flex w-100 flex-wrap align-items-center justify-content-between gap-md-3 gap-2 mx-3 mb-2 modal-header-section">
                        <div class="flex-grow-1">
                            <div class="d-flex flex-column gap-1 mb-2">
                                <h6 class="modal-title d-flex gap-2 align-items-center mb-0" id="reviewModalLabel">
                                    <span class="fs-18 fw-bold">{{translate('order')}} #{{ $orderDetails['id'] }}</span>
                                    @if($orderDetails['edited_status'] == 1)
                                        <span class="fw-normal fs-12">({{ translate('Edited') }})</span>
                                    @endif
                                </h6>

                                @if ($order_verification_status && $orderDetails->order_type == "default_type")
                                    <h5 class="fs-12 mb-0">{{translate('verification_code')}}
                                        : {{ $orderDetails['verification_code'] }}</h5>
                                @endif
                                <p class="fs-12 mb-0">{{date('D, d M, Y ',strtotime($orderDetails['created_at']))}}</p>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-2">
                            <div class="d-flex gap-2 align-items-center">
                                <p class="text-capitalize m-0 fs-14 opacity-70">{{ translate('Order status') }} :</p>
                                <span
                                    class="fs-12 px-2 py-1 lh-1 rounded bg-primary bg-opacity-10 text-primary border border-base">
                                {{ translate($orderDetails['order_status']) }}
                            </span>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <p class="text-capitalize m-0 fs-14 opacity-70">{{ translate('Payment status') }} :</p>
                                @if($orderDetails['payment_status']=="paid")
                                    <span class="fs-12 text-success">{{ translate('paid') }}</span>
                                @else
                                    <span class="fs-12 text-danger">{{ translate('unpaid') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end flex-grow-1">
                        <button type="button" class="btn bg-light text-muted fs-12 btn-circle p-0 lh-1 flex-shrink-0"
                                style="--size: 32px;" data-dismiss="modal" aria-label="Close">
                            <i class="fi fi-sr-cross d-flex"></i>
                        </button>
                    </div>
                </div>

                <?php
                $showOnlyPaymentInfo = ($orderDetails->edited_status == 1 && ($orderDetails?->latestEditHistory?->order_due_payment_method == "offline_payment" || $orderDetails?->latestEditHistory?->order_due_payment_method == "cash_on_delivery" || $orderDetails?->latestEditHistory?->order_due_payment_status == "paid")) ||
                    ($orderDetails->edited_status == 1 && $orderDetails?->latestEditHistory?->order_due_payment_status == 'unpaid' && $orderDetails?->latestEditHistory?->order_due_payment_method != "offline_payment" && $orderDetails?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $orderDetails?->latestEditHistory?->order_due_amount > 0) ||
                    ($orderDetails->edited_status == 1 && $orderDetails?->latestEditHistory?->order_return_payment_status === 'pending') ||
                    ($orderDetails->edited_status == 1 && $orderDetails?->latestEditHistory?->order_return_payment_status == "returned");

                $hasUnpaidDue = $orderDetails->edited_status == 1 &&
                    ($orderDetails?->latestEditHistory?->order_due_payment_status !== 'paid' &&
                        $orderDetails?->latestEditHistory?->order_due_amount > 0);
                ?>

                <div class="modal-body px-sm-4 pt-0" id="order-details-section">
                    <div class="row g-3 mb-2">
                        @if(($orderDetails['payment_method'] == 'cash_on_delivery' || $orderDetails?->latestEditHistory?->order_due_payment_method == 'cash_on_delivery') && $orderDetails['bring_change_amount'] > 0)
                        <div class="col-md-12">
                            <div class="__badge soft-primary py-2 fs-14 text-dark rounded">
                                {{ translate('Please bring') }}
                                <strong> {{ $orderDetails['bring_change_amount'] }} {{ $orderDetails['bring_change_amount_currency'] ?? '' }}</strong> {{ translate('in change when making the delivery') }}
                            </div>
                        </div>
                        @endif
                        <div class="{{ $showOnlyPaymentInfo ? 'col-md-6' : 'col-md-12' }}">
                            <div class="h-100 bg-light rounded-10 p-3 p-sm-4">
                                <div class="d-flex flex-column gap-2 h-100">
                                    <h6 class="fs-14 text-capitalize fw-semibold">{{ translate('Payment_info') }}</h6>
                                    <div class="d-flex flex-column gap-2 bg-white rounded py-3 px-3 h-100">
                                        <div class="fs-12 d-flex gap-2">
                                            <span class="text-muted">{{ translate('Payment_status') }} :</span>
                                            @if($orderDetails->edited_status == 1 && $orderDetails->edit_due_amount > 0 && $orderDetails?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $orderDetails?->latestEditHistory?->order_due_payment_status == "unpaid")
                                                <span
                                                    class="text-success fw-semibold">{{ translate('Partially_Paid') }}</span>
                                            @else
                                                <span
                                                    class="text-success fw-semibold">{{ translate($orderDetails['payment_status']) }}</span>
                                            @endif
                                        </div>
                                        <div class="fs-12 d-flex gap-2">
                                            <span class="text-muted">{{ translate('Amount') }} :</span>
                                            <span
                                                class="text-dark fw-semibold">{{ webCurrencyConverter(amount: $orderDetails['init_order_amount']) }}</span>
                                        </div>
                                        <div class="fs-12 d-flex gap-2">
                                            <span class="text-muted">{{ translate('Payment_method') }} :</span>
                                            <span
                                                class="text-dark fw-semibold">{{ translate($orderDetails['payment_method']) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if($orderDetails->edited_status == 1 && ($orderDetails?->latestEditHistory?->order_due_payment_method == "offline_payment" || $orderDetails?->latestEditHistory?->order_due_payment_method == "cash_on_delivery" || $orderDetails?->latestEditHistory?->order_due_payment_status == "paid"))
                            <div class="col-lg-6">
                                <div class="h-100 bg-light rounded-10 p-3 p-sm-4">
                                    <div class="d-flex flex-column gap-2 h-100">
                                        <h6 class="fs-14 text-capitalize fw-semibold">{{ translate('Another Payment Info') }}</h6>
                                        <div class="d-flex flex-column gap-2 bg-white rounded py-3 px-3 fs-12">
                                            <div class="d-flex gap-2">
                                                <span class="text-muted">{{ translate('Payment status') }} :</span>
                                                <span
                                                    class="text-success fw-semibold">{{translate($orderDetails?->latestEditHistory?->order_due_payment_status) }}</span>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <span class="text-muted">{{ translate('Due_amount') }} :</span>
                                                <span class="text-dark fw-semibold"> {{ webCurrencyConverter($orderDetails?->latestEditHistory?->order_due_amount) }}</span>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <span class="text-muted">{{ translate('Payment method') }} :</span>
                                                <span class="text-dark fw-semibold">{{translate( $orderDetails?->latestEditHistory?->order_due_payment_method) }} ({{ webCurrencyConverter($orderDetails?->latestEditHistory?->order_due_amount) }})</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @elseif($orderDetails->edited_status == 1 && $orderDetails?->latestEditHistory?->order_due_payment_status == 'unpaid' && $orderDetails?->latestEditHistory?->order_due_payment_method != "offline_payment" && $orderDetails?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $orderDetails?->latestEditHistory?->order_due_amount > 0)
                            <div class="col-lg-6">
                                <div class="h-100 light-box rounded-10 p-3 p-sm-4">
                                    <div
                                        class="d-flex flex-column justify-content-between align-items-center gap-2 text-center">
                                        <h4 class="fs-16 text-danger mb-0">{{ translate('Pay Due Bill') }}</h4>
                                        <h4 class="fs-16 text-dark fw-bold mb-0">{{ webCurrencyConverter(amount: $orderDetails['edit_due_amount']) }}</h4>
                                        <p class="fs-12 mb-0">
                                            {{ translate('after_editing_your_product_list,_the_order_total_has_increased._please_pay_the_amount_to_continue_processing_the_order.') }}
                                        </p>
                                        <button type="button"
                                                class="btn btn--primary lh-1 pay-now-btn">{{ translate('Pay_Now') }}</button>
                                    </div>
                                </div>
                            </div>
                        @elseif($orderDetails->edited_status == 1 && $orderDetails?->latestEditHistory?->order_return_payment_status === 'pending')
                            <div class="col-lg-6">
                                <div class="h-100 bg-light rounded-10 p-3 p-sm-4 text-center">
                                    <h4 class="fs-16 text-danger mb-0">{{ translate('Amount_to_Be_Returned') }}</h4>
                                    <h4 class="fs-16 text-dark fw-bold mb-0">{{ webCurrencyConverter($orderDetails['edit_return_amount']) }}</h4>
                                    <p class="fs-12 mb-0">{{ translate('after_editing_your_product_list,_you_will_receive_this_amount._please_wait_for_the_admin_to_process_the_returned.') }}</p>
                                </div>
                            </div>
                        @elseif($orderDetails->edited_status == 1 && $orderDetails?->latestEditHistory?->order_return_payment_status == "returned")
                            <div class="col-lg-6">
                                <div class="h-100 bg-light rounded-10 p-3 p-sm-4 text-center">
                                    <h6 class="fs-14 d-flex w-100 justify-content-between gap-2 mb-2">
                                        <span
                                            class="text-capitalize text-dark fw-semibold">{{translate('Return_Payment_info')}}</span>
                                        <span
                                            class="fs-12 fw-semibold text-{{$orderDetails['payment_status'] == 'paid' ? 'success' : 'danger'}} text-capitalize">{{$orderDetails['payment_status']}}</span>
                                    </h6>
                                    <div class="fs-12 d-flex justify-content-start gap-2">
                                        <span class="text-muted text-capitalize">{{translate('Return_Payment_method')}} :</span>
                                        <span
                                            class="text-dark text-capitalize fw-semibold">{{ $orderDetails?->latestEditHistory?->order_return_payment_method }}</span>
                                    </div>
                                    <div class="fs-12 d-flex justify-content-start gap-2">
                                        <span class="text-muted text-capitalize">{{translate('Return_Amount')}} :</span>
                                        <span
                                            class="text-dark text-capitalize fw-semibold">{{ webCurrencyConverter(amount: $orderDetails?->latestEditHistory?->order_return_amount) }}</span>
                                    </div>
                                    <div class="bg-white py-2 px-2 rounded text-start fs-14">
                                        #{{ translate('Note') }}
                                        :{{ $orderDetails?->latestEditHistory?->order_return_payment_note }}
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="product-table-wrap">
                        <div class="table-responsive">
                            <table
                                class="table __table text-capitalize text-start table-align-middle min-w400 text-dark fs-12 text-nowrap">
                                <thead class="light-box mb-3">
                                <tr>
                                    <th>{{ translate('SL') }}</th>
                                    <th class="min-w-300">{{ translate('Item_List') }}</th>
                                    <th class="text-center">{{ translate('Qty') }}</th>
                                    <th class="text-end">{{ translate('Price') }}</th>
                                    <th class="text-end">{{ translate('Discount') }}</th>
                                    <th class="text-end">{{ translate('Total') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $sub_total = 0; ?>
                                <?php $total_discount_on_product = 0; ?>
                                @foreach($order as $key => $order_details)
                                        <?php $productDetails = $order_details?->product ?? json_decode($order_details->product_details); ?>
                                        <?php $subtotal = ($order_details['price'] * $order_details['qty']) - $order_details['discount']; ?>
                                    <tr>
                                        <td>{{ $key+1 }}</td>
                                        <td>
                                            <div class="media align-items-center gap-3">
                                                <img
                                                    src="{{ getStorageImages(path: $order_details?->product?->thumbnail_full_url, type: 'product') }}"
                                                    class="rounded border w-50px aspect-1"
                                                    alt="{{ translate('product') }}">
                                                <div>
                                                    <h6 class="title-color mb-2 fs-12 fw-semibold">{{ Str::limit($productDetails->name,50) }}</h6>
                                                    <small>{{ translate('unit_price') }}
                                                        : {{ webCurrencyConverter($order_details['price']) }}</small><br>
                                                    @if($order_details->variant)
                                                        <small>{{ translate('variation') }}
                                                            : {{ $order_details['variant'] }}</small>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">{{ $order_details->qty }}</td>
                                        <td class="text-end">{{ webCurrencyConverter($order_details['price']*$order_details['qty']) }}</td>
                                        <td class="text-end">{{ webCurrencyConverter($order_details['discount']) }}</td>
                                        <td class="text-end">{{ webCurrencyConverter($subtotal) }}</td>
                                    </tr>
                                        <?php $sub_total += $order_details['price'] * $order_details['qty']; ?>
                                        <?php $total_discount_on_product += $order_details['discount']; ?>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php
                    $total_shipping_cost = $orderDetails['shipping_cost'];
                    $extra_discount = $orderDetails['extra_discount_type'] == 'percent' ? ($sub_total / 100) * $orderDetails['extra_discount'] : $orderDetails['extra_discount'];
                    $coupon_discount = $orderDetails['discount_amount'] ?? 0;
                    $total_amount = $sub_total + $total_shipping_cost + $orderDetails['total_tax_amount'] - $total_discount_on_product - $coupon_discount - $extra_discount;
                    ?>

                    <div class="light-box rounded-10 p-1">
                        <div class="table-responsive">
                            <table class="table table-borderless table-align-middle text-capitalize">
                                <thead>
                                <tr class="fs-14">
                                    <th>{{translate('sub_total')}}</th>
                                    @if ($orderDetails['order_type'] == 'default_type' && $orderDetails['is_shipping_free'] != 1)
                                        <th>{{translate('shipping')}}</th>
                                    @endif
                                    @if($orderDetails['tax_model'] == 'exclude')
                                        <th>{{translate('tax')}}</th>
                                    @endif
                                    <th>{{translate('discount')}}</th>
                                    <th>{{translate('coupon_discount')}}</th>
                                    @if ($orderDetails['order_type'] == 'POS')
                                        <th>{{translate('extra_discount')}}</th>
                                    @endif
                                    <th>{{translate('total')}} @if ($orderDetails->tax_model =='include')
                                            <small>({{translate('tax_incl.')}})</small>
                                        @endif
                                    </th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr class="fs-14 fw-bold">
                                    <td>{{ webCurrencyConverter($sub_total) }}</td>
                                    @if ($orderDetails['order_type'] == 'default_type' && $orderDetails['is_shipping_free'] != 1)
                                        <td>{{ webCurrencyConverter($total_shipping_cost) }}</td>
                                    @endif
                                    @if($orderDetails['tax_model'] == 'exclude')
                                        <td>{{ webCurrencyConverter($orderDetails['total_tax_amount']) }}</td>
                                    @endif
                                    <td>-{{ webCurrencyConverter($total_discount_on_product) }}</td>
                                    <td>-{{ webCurrencyConverter($coupon_discount) }}</td>
                                    @if ($orderDetails['order_type'] == 'POS')
                                        <td>-{{ webCurrencyConverter($extra_discount) }}</td>
                                    @endif
                                    <td>{{ webCurrencyConverter($total_amount) }}</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                        @if($orderDetails['edited_status'] == 1)
                            <div
                                class="p-2 p-sm-3 rounded text-center text-dark fs-14 bg-primary bg-opacity-10 mt-2 mx-2">
                                #{{ translate('Note_:_Total_bill_has_been_updated_after_the_edits.') }}
                            </div>
                        @endif
                    </div>
                </div>

                <div class="modal-body px-sm-4 pt-0 d-none" id="payment-method-section">
                    @include('web-views.order.partials._choose-payment-method-modal', ['order' => $orderDetails])
                </div>

                @if (isset($offlinePaymentMethods) && $offlinePaymentStatus['status'])
                    <div class="modal-body px-sm-4 pt-0 d-none" id="offline-payment-section">
                        <div class="">
                            <button type="button"
                                    class="btn btn-link text-primary p-0 fw-semibold fs-18 lh-1 back-to-payment-method">
                                <i class="fi fi-sr-angle-left"></i> {{ translate('Go_Back') }}
                            </button>
                        </div>
                        <form action="{{ route('customer.customer-order-edit-pay-amount') }}" method="post"
                              class="needs-validation form-loading-button-form">
                            @csrf
                            <div class="mx-xl-5">
                                <input type="hidden" name="payment_method" value="offline">
                                <input type="hidden" name="order_id" value="{{ $orderDetails['id'] }}">
                                <input type="hidden" name="payment_platform" value="web">
                                <div class="d-flex justify-content-center mb-2">
                                    <img width="52"
                                         src="{{ theme_asset(path: 'public/assets/front-end/img/select-payment-method.png') }}"
                                         alt="">
                                </div>
                                <p class="fs-14 text-center">
                                    {{ translate('pay_your_bill_using_any_of_the_payment_method_below_and_input_the_required_information_in_the_form') }}
                                </p>

                                <select class="form-control pay_offline_method"
                                        id="pay_offline_method_{{ $orderDetails['id'] }}"
                                        data-edit-due="{{ $orderDetails['edit_due_amount'] }}"
                                        data-order-id="{{ $orderDetails['id'] }}" name="payment_by" required>
                                    <option value="" disabled selected>{{ translate('select_Payment_Method') }}</option>
                                    @foreach ($offlinePaymentMethods as $method)
                                        <option value="{{ $method->id }}">{{ translate('payment_Method') }}
                                            : {{ $method->method_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="" id="payment_method_field_{{$orderDetails['id']}}"></div>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="container pt-4 pb-5 rtl">

        <div class="card border-0 box-shadow-lg">
            <div class="card-body py-5">
                <h6 class="text-end small font-bold fs-14">
                    <a href="{{ route('track-order.index') }}">
                        <span class="text-primary"><i class="tio-refresh"></i></span>
                        {{ translate('clear') }}
                    </a>
                </h6>

                <div class="mx-auto mw-1000">
                    <h2 class="text-center text-capitalize font-bold fs-25">{{ translate('track_order')}}</h2>

                    <form action="{{route('track-order.result')}}" type="submit" method="get" class="p-3">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-4 col-sm-6">
                                <input class="form-control form-control-sm prepended-form-control" type="text"
                                       name="order_id"
                                       placeholder="{{translate('order_id')}}" value="{{$orderDetails->id}}" required>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <input class="form-control form-control-sm prepended-form-control" type="tel"
                                       placeholder="{{translate('your_phone_number')}}" value="{{ $user_phone }}"
                                       name="phone_number"
                                       required>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn--primary btn-sm w-100 font-bold" type="submit"
                                        name="trackOrder">{{translate('track_order')}}</button>
                            </div>
                        </div>
                    </form>

                </div>
                <h6 class="font-weight-bold text-center m-0 pt-5 pb-4">
                    <span class="text-capitalize">{{ translate('your_order')}}</span> <span>:</span> <span
                        class="text-base">{{$orderDetails['id']}}</span>
                </h6>

                <?php
                $statusSVGs = [
                    'order_placed' => '<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                          <g clip-path="url(#clip0_12708_428)">
                                              <path d="M13.65 1.3H12.2382C11.9704 0.5434 11.2476 0 10.4 0C9.5524 0 8.8296 0.5434 8.5605 1.3H7.15C6.7912 1.3 6.5 1.5912 6.5 1.95V4.55C6.5 4.9088 6.7912 5.2 7.15 5.2H13.65C14.0088 5.2 14.3 4.9088 14.3 4.55V1.95C14.3 1.5912 14.0088 1.3 13.65 1.3Z" fill="#ADB0B7"/>
                                              <path d="M16.9001 2.6001H15.6001V4.5501C15.6001 5.6252 14.7252 6.5001 13.6501 6.5001H7.1501C6.075 6.5001 5.2001 5.6252 5.2001 4.5501V2.6001H3.9001C3.1838 2.6001 2.6001 3.1838 2.6001 3.9001V19.5001C2.6001 20.2294 3.1708 20.8001 3.9001 20.8001H16.9001C17.6294 20.8001 18.2001 20.2294 18.2001 19.5001V3.9001C18.2001 3.1708 17.6294 2.6001 16.9001 2.6001ZM10.2103 14.1103L7.6103 16.7103C7.4829 16.8364 7.3165 16.9001 7.1501 16.9001C6.9837 16.9001 6.8173 16.8364 6.6899 16.7103L5.3899 15.4103C5.1364 15.1568 5.1364 14.7447 5.3899 14.4912C5.6434 14.2377 6.0555 14.2377 6.309 14.4912L7.1501 15.331L9.2899 13.1912C9.5434 12.9377 9.9555 12.9377 10.209 13.1912C10.4625 13.4447 10.4638 13.8555 10.2103 14.1103ZM10.2103 8.9103L7.6103 11.5103C7.4829 11.6364 7.3165 11.7001 7.1501 11.7001C6.9837 11.7001 6.8173 11.6364 6.6899 11.5103L5.3899 10.2103C5.1364 9.9568 5.1364 9.5447 5.3899 9.2912C5.6434 9.0377 6.0555 9.0377 6.309 9.2912L7.1501 10.131L9.2899 7.9912C9.5434 7.7377 9.9555 7.7377 10.209 7.9912C10.4625 8.2447 10.4638 8.6555 10.2103 8.9103ZM14.9501 15.6001H12.3501C11.9913 15.6001 11.7001 15.3089 11.7001 14.9501C11.7001 14.5913 11.9913 14.3001 12.3501 14.3001H14.9501C15.3089 14.3001 15.6001 14.5913 15.6001 14.9501C15.6001 15.3089 15.3089 15.6001 14.9501 15.6001ZM14.9501 10.4001H12.3501C11.9913 10.4001 11.7001 10.1089 11.7001 9.7501C11.7001 9.3913 11.9913 9.1001 12.3501 9.1001H14.9501C15.3089 9.1001 15.6001 9.3913 15.6001 9.7501C15.6001 10.1089 15.3089 10.4001 14.9501 10.4001Z" fill="#ADB0B7"/>
                                          </g>
                                      </svg>',

                    'order_confirmed' => '<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                          <path d="M18.7328 17.3592L17.1098 5.99496C17.0447 5.5491 16.8218 5.14139 16.4816 4.84595C16.1414 4.5505 15.7065 4.38693 15.2559 4.38497H13.7337L13.428 3.46776C12.463 0.578526 8.33772 0.575767 7.37183 3.4678L7.0661 4.38497H5.54393C5.09341 4.38716 4.65861 4.55081 4.31845 4.84621C3.97829 5.14162 3.75531 5.54919 3.69 5.99496L2.06701 17.3592C2.03066 17.6247 2.05135 17.8949 2.12769 18.1517C2.20403 18.4086 2.33427 18.6462 2.50973 18.8488C2.68518 19.0513 2.90181 19.2141 3.14517 19.3263C3.38852 19.4384 3.653 19.4974 3.92096 19.4993L16.8789 19.4993C17.1468 19.4975 17.4113 19.4385 17.6547 19.3263C17.898 19.2141 18.1146 19.0513 18.2901 18.8487C18.4655 18.6462 18.5958 18.4086 18.6721 18.1517C18.7485 17.8949 18.7692 17.6247 18.7328 17.3592ZM7.98981 3.67267C8.74048 1.37597 12.0597 1.37647 12.81 3.67271L13.0474 4.38497H7.75237L7.98981 3.67267ZM10.3999 16.4095C9.23591 16.4077 8.12009 15.9445 7.29702 15.1215C6.47395 14.2984 6.01078 13.1826 6.00903 12.0186C6.25498 6.1927 14.5457 6.19441 14.7908 12.0186C14.789 13.1826 14.3259 14.2984 13.5028 15.1215C12.6797 15.9445 11.5639 16.4077 10.3999 16.4095Z" fill="#ADB0B7"/>
                                          <path d="M10.3999 8.27832C9.40826 8.27956 8.4576 8.67404 7.75641 9.37523C7.05522 10.0764 6.66075 11.0271 6.65952 12.0187C6.86552 16.9807 13.935 16.9793 14.1403 12.0187C14.139 11.027 13.7445 10.0764 13.0434 9.37521C12.3422 8.67403 11.3915 8.27956 10.3999 8.27832ZM12.6636 11.1177L10.4031 13.3782C10.342 13.4385 10.2596 13.4722 10.1738 13.4722C10.088 13.4722 10.0057 13.4385 9.94453 13.3782L8.81592 12.2496C8.75498 12.1882 8.72078 12.1052 8.72078 12.0187C8.72078 11.9322 8.75498 11.8492 8.81592 11.7878C8.84601 11.7576 8.88175 11.7337 8.92109 11.7174C8.96044 11.701 9.00262 11.6926 9.04522 11.6926C9.08782 11.6926 9.13 11.701 9.16935 11.7174C9.20869 11.7337 9.24443 11.7576 9.27452 11.7878L10.1755 12.6887L12.2018 10.6592C12.263 10.5999 12.345 10.5671 12.4302 10.5676C12.5154 10.5682 12.597 10.6021 12.6574 10.6621C12.7179 10.7222 12.7524 10.8035 12.7536 10.8887C12.7547 10.9739 12.7224 11.0561 12.6636 11.1177Z" fill="#ADB0B7"/>
                                      </svg>',

                    'preparing_for_shipment' => '<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                          <g clip-path="url(#clip0_19880_143058)">
                                              <path d="M13.0542 3.87279C13.0542 2.83059 12.2111 1.95801 11.1394 1.95801H1.91482C0.840775 1.95801 0 2.83287 0 3.87279V11.0959H13.0542V3.87279Z" fill="#ADB0B7"/>
                                              <path d="M0 14.9686C0 15.5952 0.299812 16.1501 0.763466 16.4992C0.953834 14.9281 2.29466 13.7065 3.91625 13.7065C5.66796 13.7065 7.09308 15.1317 7.09308 16.8834H13.0541V12.3145H0V14.9686Z" fill="#ADB0B7"/>
                                              <path d="M3.91625 14.9258C2.83652 14.9258 1.95813 15.8042 1.95813 16.8839C1.95813 17.9636 2.83656 18.842 3.91625 18.842C4.99595 18.842 5.87434 17.9636 5.87434 16.8839C5.87434 15.8042 4.99599 14.9258 3.91625 14.9258Z" fill="#ADB0B7"/>
                                              <path d="M16.3177 11.7051V5.87402H14.2729V14.3845C14.8129 13.9604 15.4927 13.7065 16.231 13.7065C17.9585 13.7065 19.3674 15.0928 19.4061 16.8112C20.2103 16.5847 20.8 15.8473 20.8 14.9686V12.3145H16.9271C16.5905 12.3144 16.3177 12.0416 16.3177 11.7051Z" fill="#ADB0B7"/>
                                              <path d="M20.8 11.0957V9.74692C20.8 7.81532 19.3859 6.21421 17.5365 5.92188V11.0957H20.8Z" fill="#ADB0B7"/>
                                              <path d="M16.231 18.842C17.3125 18.842 18.1891 17.9653 18.1891 16.8839C18.1891 15.8025 17.3125 14.9258 16.231 14.9258C15.1496 14.9258 14.2729 15.8025 14.2729 16.8839C14.2729 17.9653 15.1496 18.842 16.231 18.842Z" fill="#ADB0B7"/>
                                          </g>
                                      </svg>',

                    'order_is_on_the_way' => '<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                          <g clip-path="url(#clip0_19880_143080)">
                                              <path d="M7.77817 4.5708C7.77817 3.85812 7.19841 3.27832 6.48572 3.27832C5.77304 3.27832 5.19324 3.85812 5.19324 4.5708C5.19324 5.28349 5.77304 5.86329 6.48572 5.86329C7.19841 5.86329 7.77817 5.28349 7.77817 4.5708Z" fill="#ADB0B7"/>
                                              <path d="M8.73704 11.4254H7.21154C8.50183 10.2654 11.0609 7.58489 11.0609 4.57039C11.0609 2.04352 9.0126 0 6.48573 0C3.9589 0 1.91046 2.04352 1.91046 4.57039C1.91046 7.58489 4.46963 10.2654 5.75996 11.4254H2.61197V12.6442H8.53169C8.55704 12.2277 8.62606 11.8196 8.73704 11.4254ZM3.97445 4.57039C3.97445 3.18569 5.10099 2.05916 6.48569 2.05916C7.87039 2.05916 8.99688 3.18569 8.99688 4.57039C8.99688 5.9551 7.87039 7.08163 6.48569 7.08163C5.10099 7.08163 3.97445 5.9551 3.97445 4.57039Z" fill="#ADB0B7"/>
                                              <path d="M14.3144 14.2959C15.0282 14.2959 15.6069 13.7172 15.6069 13.0034C15.6069 12.2896 15.0282 11.7109 14.3144 11.7109C13.6006 11.7109 13.0219 12.2896 13.0219 13.0034C13.0219 13.7172 13.6006 14.2959 14.3144 14.2959Z" fill="#ADB0B7"/>
                                              <path d="M15.3405 19.5811C16.6893 18.3062 18.8896 15.7983 18.8896 13.003C18.8896 10.4761 16.8413 8.43262 14.3144 8.43262C11.7875 8.43262 9.73911 10.4761 9.73911 13.003C9.73911 15.7983 11.9395 18.3062 13.2884 19.5811H7.33037C6.54212 19.5811 5.90082 18.9398 5.90082 18.1515C5.90082 17.3632 6.54212 16.7219 7.33037 16.7219H9.51921C9.46257 16.6143 9.08643 15.8011 8.97918 15.5032H7.33037C5.87011 15.5032 4.68207 16.6912 4.68207 18.1515C4.68207 19.6118 5.87011 20.7998 7.33037 20.7998H18.1881V19.5811H15.3405ZM14.3144 10.4918C15.6991 10.4918 16.8256 11.6183 16.8256 13.003C16.8256 14.3877 15.6991 15.5142 14.3144 15.5142C12.9297 15.5142 11.8032 14.3877 11.8032 13.003C11.8032 11.6183 12.9297 10.4918 14.3144 10.4918Z" fill="#ADB0B7"/>
                                          </g>
                                      </svg>',

                    'order_canceled' => '<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                          <path d="M10.5 0.875C5.19275 0.875 0.875 5.19275 0.875 10.5C0.875 15.8073 5.19275 20.125 10.5 20.125C15.8073 20.125 20.125 15.8073 20.125 10.5C20.125 5.19275 15.8073 0.875 10.5 0.875Z" fill="#ADB0B7"/>
                                          <path d="M14.6187 13.3813C14.9604 13.7229 14.9604 14.2771 14.6187 14.6187C14.448 14.7896 14.2239 14.875 14 14.875C13.7761 14.875 13.552 14.7896 13.3813 14.6187L10.5 11.7373L7.61865 14.6187C7.44797 14.7896 7.22388 14.875 7 14.875C6.77612 14.875 6.55203 14.7896 6.38135 14.6187C6.03955 14.2771 6.03955 13.7229 6.38135 13.3813L9.2627 10.5L6.38135 7.61865C6.03955 7.27707 6.03955 6.72293 6.38135 6.38135C6.72272 6.03955 7.27728 6.03955 7.61865 6.38135L10.5 9.2627L13.3813 6.38135C13.7227 6.03955 14.2773 6.03955 14.6187 6.38135C14.9604 6.72293 14.9604 7.27707 14.6187 7.61865L11.7373 10.5L14.6187 13.3813Z" fill="white"/>
                                      </svg>',
                    'order_failed' => '<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                          <path d="M10.5 0.875C5.19275 0.875 0.875 5.19275 0.875 10.5C0.875 15.8073 5.19275 20.125 10.5 20.125C15.8073 20.125 20.125 15.8073 20.125 10.5C20.125 5.19275 15.8073 0.875 10.5 0.875Z" fill="#ADB0B7"/>
                                          <path d="M14.6187 13.3813C14.9604 13.7229 14.9604 14.2771 14.6187 14.6187C14.448 14.7896 14.2239 14.875 14 14.875C13.7761 14.875 13.552 14.7896 13.3813 14.6187L10.5 11.7373L7.61865 14.6187C7.44797 14.7896 7.22388 14.875 7 14.875C6.77612 14.875 6.55203 14.7896 6.38135 14.6187C6.03955 14.2771 6.03955 13.7229 6.38135 13.3813L9.2627 10.5L6.38135 7.61865C6.03955 7.27707 6.03955 6.72293 6.38135 6.38135C6.72272 6.03955 7.27728 6.03955 7.61865 6.38135L10.5 9.2627L13.3813 6.38135C13.7227 6.03955 14.2773 6.03955 14.6187 6.38135C14.9604 6.72293 14.9604 7.27707 14.6187 7.61865L11.7373 10.5L14.6187 13.3813Z" fill="white"/>
                                      </svg>',
                    'order_returned' => '<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                          <path d="M10.5 0.875C5.19275 0.875 0.875 5.19275 0.875 10.5C0.875 15.8073 5.19275 20.125 10.5 20.125C15.8073 20.125 20.125 15.8073 20.125 10.5C20.125 5.19275 15.8073 0.875 10.5 0.875Z" fill="#ADB0B7"/>
                                          <path d="M14.6187 13.3813C14.9604 13.7229 14.9604 14.2771 14.6187 14.6187C14.448 14.7896 14.2239 14.875 14 14.875C13.7761 14.875 13.552 14.7896 13.3813 14.6187L10.5 11.7373L7.61865 14.6187C7.44797 14.7896 7.22388 14.875 7 14.875C6.77612 14.875 6.55203 14.7896 6.38135 14.6187C6.03955 14.2771 6.03955 13.7229 6.38135 13.3813L9.2627 10.5L6.38135 7.61865C6.03955 7.27707 6.03955 6.72293 6.38135 6.38135C6.72272 6.03955 7.27728 6.03955 7.61865 6.38135L10.5 9.2627L13.3813 6.38135C13.7227 6.03955 14.2773 6.03955 14.6187 6.38135C14.9604 6.72293 14.9604 7.27707 14.6187 7.61865L11.7373 10.5L14.6187 13.3813Z" fill="white"/>
                                      </svg>',

                    'order_delivered' => '<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                          <g clip-path="url(#clip0_19880_143096)">
                                              <path d="M10.4813 16.4498C10.4814 15.3357 10.8062 14.2458 11.4161 13.3135C12.0259 12.3811 12.8942 11.6467 13.9149 11.2001C14.9355 10.7535 16.0642 10.6141 17.1629 10.7989C18.2615 10.9837 19.2825 11.4847 20.1009 12.2407C20.1279 10.2823 20.1117 8.32387 20.0521 6.36547C20.0196 5.01997 19.2599 3.66919 17.9871 3.00172C17.3331 2.656 16.6555 2.32328 15.9701 2.00803C13.262 2.88391 10.0905 4.03928 6.89164 5.46969C6.58409 5.60564 6.32061 5.82477 6.13091 6.10241C5.9412 6.38004 5.8328 6.70514 5.81792 7.04107C5.76795 8.58482 5.75251 10.1781 5.77283 11.7536C5.77446 11.7998 5.76124 11.8453 5.7351 11.8834C5.70896 11.9215 5.67128 11.9503 5.6276 11.9654C5.58392 11.9806 5.53653 11.9813 5.4924 11.9675C5.44827 11.9537 5.40972 11.9262 5.38242 11.8888C5.04388 11.4555 4.71319 11.0271 4.39036 10.6035C4.36653 10.5704 4.33111 10.5474 4.29114 10.5392C4.25118 10.531 4.20959 10.5381 4.17464 10.5592C3.92358 10.7087 3.67698 10.8562 3.43486 11.0016C3.29917 11.0828 3.1322 10.9744 3.13139 10.8074C3.12327 9.23807 3.16227 7.66466 3.24636 6.1396C3.2817 5.4965 3.68511 4.89078 4.28189 4.59585C7.34989 3.08053 10.623 1.84269 13.416 0.926597C12.766 0.672285 12.1197 0.439097 11.4888 0.218097C10.6381 -0.0740011 9.71426 -0.0740011 8.86358 0.218097C6.75839 0.944472 4.45942 1.89266 2.3648 3.00172C1.09242 3.66919 0.333546 5.01997 0.299827 6.36547C0.216952 9.05485 0.216952 11.7442 0.299827 14.4336C0.332327 15.7791 1.09242 17.1299 2.3648 17.7973C4.45942 18.9064 6.75839 19.8546 8.8648 20.5806C9.71548 20.8727 10.6393 20.8727 11.49 20.5806C11.6739 20.5172 11.8595 20.4518 12.047 20.3843C11.0383 19.3231 10.4776 17.9139 10.4813 16.4498Z" fill="#ADB0B7"/>
                                              <path d="M16.2122 12.1006C15.3519 12.1007 14.511 12.3559 13.7957 12.8339C13.0805 13.3119 12.523 13.9913 12.1939 14.7861C11.8647 15.581 11.7787 16.4555 11.9466 17.2993C12.1145 18.143 12.5288 18.918 13.1372 19.5263C13.7456 20.1346 14.5207 20.5488 15.3644 20.7165C16.2082 20.8843 17.0828 20.7981 17.8776 20.4688C18.6724 20.1395 19.3516 19.5819 19.8295 18.8665C20.3074 18.1512 20.5624 17.3102 20.5624 16.4499C20.5623 15.8787 20.4497 15.3131 20.2311 14.7854C20.0125 14.2577 19.692 13.7782 19.2881 13.3743C18.8841 12.9704 18.4046 12.6501 17.8768 12.4315C17.3491 12.213 16.7834 12.1005 16.2122 12.1006ZM18.6932 15.73L16.0786 18.4755C16.0074 18.5503 15.9219 18.61 15.8271 18.6509C15.7323 18.6918 15.6301 18.7131 15.5269 18.7135H15.5232C15.4206 18.7135 15.319 18.6928 15.2244 18.6529C15.1299 18.6129 15.0443 18.5544 14.9728 18.4807L13.5854 17.0516C13.4437 16.9056 13.3658 16.7093 13.3689 16.5058C13.3719 16.3024 13.4557 16.1086 13.6017 15.9669C13.7477 15.8252 13.944 15.7473 14.1474 15.7503C14.3508 15.7534 14.5447 15.8371 14.6864 15.9831L15.5188 16.8399L17.5829 14.6721C17.7248 14.5331 17.9152 14.4545 18.1139 14.453C18.3126 14.4515 18.5042 14.5272 18.6482 14.6641C18.7922 14.801 18.8775 14.9885 18.886 15.187C18.8946 15.3855 18.8257 15.5796 18.694 15.7284L18.6932 15.73Z" fill="#ADB0B7"/>
                                          </g>
                                      </svg>',
                ];

                $terminalStatuses = ['order_canceled', 'order_returned', 'order_failed'];

                $trackOrderArray = \App\Utils\OrderManager::getTrackOrderStatusHistory(orderId: $orderDetails['id'], isOrderOnlyDigital: $isOrderOnlyDigital);
                $statusesToShow = $trackOrderArray['is_digital_order']
                    ? ['order_placed', 'order_confirmed', 'order_delivered', 'order_canceled', 'order_returned', 'order_failed']
                    : ['order_placed', 'order_confirmed', 'preparing_for_shipment', 'order_is_on_the_way', 'order_delivered', 'order_canceled', 'order_returned', 'order_failed'];

                $visibleStatuses = collect($trackOrderArray['history'])
                    ->filter(function ($statusData, $statusKey) use ($statusesToShow, $terminalStatuses) {
                        return in_array($statusKey, $statusesToShow)
                            && (!in_array($statusKey, $terminalStatuses) || $statusData['status']);
                    });

                $totalItems = $visibleStatuses->count();
                $splitAt = ceil($totalItems / 2);
                $firstColumn = $visibleStatuses->take($splitAt);
                $secondColumn = $visibleStatuses->slice($splitAt);

                ?>


                <div class="mx-auto mw-1000">
                    <div class="order-track_wrapper d-md-flex d-none justify-content-between gap-3">
                        <ul class="list-inline w-100 order_track-list max-w-380px">
                            @foreach($firstColumn as $statusKey => $statusData)
                                    <?php
                                    $isTerminalStatus = in_array($statusKey, $terminalStatuses);
                                    ?>
                                <li class="position-relative z-1 {{ $statusData['status'] ? ($statusKey === 'order_placed' ? 'order-active-placed' : 'order-step-next') : '' }}">
                                    <div class="d-flex align-items-center gap-2 justify-content-between">
                                        <div class="d-flex align-items-center gap-3">
                                            <div
                                                class="icon-svg bg-soft-5 w-50px h-50px min-w-50px rounded-10 d-center">
                                                {!! $statusSVGs[$statusKey] !!}
                                            </div>
                                            <div class="media-body">
                                                <h6 class="fs-16 text-dark text-nowrap mb-0 text-capitalize">
                                                    {{ translate($statusData['label']) }}
                                                </h6>
                                                @if($statusData['date_time'])
                                                    <span class="title-semidark fs-14 m-0">
                                                    {{ $statusData['date_time']->format('h:i A, d M Y') }}
                                                </span>
                                                @endif

                                                @if($isTerminalStatus && $statusData['status'])
                                                    <span class="web-text-primary d-block fs-14 m-0">
                                                        @if($statusKey === 'order_canceled')
                                                            {{ translate('Order has been canceled') }}
                                                        @elseif($statusKey === 'order_returned')
                                                            {{ translate('Order has been returned') }}
                                                        @elseif($statusKey === 'order_failed')
                                                            {{ translate('Order processing failed') }}
                                                        @endif
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        @if(!$isTerminalStatus || !$statusData['status'])
                                            <div class="checked-status opacity-0">
                                                <img width="20" height="20"
                                                     src="{{ theme_asset('public/assets/front-end/img/icons/' . ($isTerminalStatus ? 'cross.png' : 'checked-circle.png')) }}"
                                                     alt="{{ translate('check') }}">
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        <div class="border-left"></div>

                        <ul class="list-inline w-100 order_track-list max-w-380px">
                            @foreach($secondColumn as $statusKey => $statusData)
                                    <?php
                                    $isTerminalStatus = in_array($statusKey, $terminalStatuses);
                                    ?>
                                <li class="position-relative z-1 {{ $statusData['status'] ? 'order-step-next' : '' }}">
                                    <div class="d-flex align-items-center gap-2 justify-content-between">
                                        <div class="d-flex align-items-center gap-3">
                                            <div
                                                class="icon-svg bg-soft-5 w-50px h-50px min-w-50px rounded-10 d-center">
                                                {!! $statusSVGs[$statusKey] !!}
                                            </div>
                                            <div class="media-body">
                                                <h6 class="fs-16 text-dark text-nowrap mb-0 text-capitalize">
                                                    {{ translate($statusData['label']) }}
                                                </h6>
                                                @if($statusData['date_time'])
                                                    <span class="title-semidark fs-14 m-0">
                                                {{ $statusData['date_time']->format('h:i A, d M Y') }}
                                            </span>
                                                @endif

                                                @if($statusKey === 'order_is_on_the_way' && $statusData['status'] && !$trackOrderArray['is_digital_order'])
                                                    <span class="title-semidark d-block fs-14 m-0">
                                                    {{ translate('Your deliveryman is coming') }}
                                                </span>
                                                @endif

                                                @if($isTerminalStatus && $statusData['status'])
                                                    <span class="web-text-primary d-block fs-14 m-0">
                                                    @if($statusKey === 'order_canceled')
                                                            {{ translate('Order Has been canceled') }}
                                                        @elseif($statusKey === 'order_returned')
                                                            {{ translate('Order has been returned') }}
                                                        @elseif($statusKey === 'order_failed')
                                                            {{ translate('Order processing failed') }}
                                                        @endif
                                                     </span>
                                                @endif
                                            </div>
                                        </div>
                                        @if($statusData['status'])
                                            <div class="checked-status opacity-0">
                                                <img width="20" height="20"
                                                     src="{{ theme_asset('public/assets/front-end/img/icons/' . ($isTerminalStatus ? 'cross.png' : 'checked-circle.png')) }}"
                                                     alt="{{ translate('check') }}">
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="order-track_wrapper d-md-none">
                        <ul class="list-inline order_track-list">
                            <?php
                            $allStatuses = $firstColumn->merge($secondColumn);
                            $terminalStatuses = ['order_canceled', 'order_returned', 'order_failed'];
                            ?>
                            @foreach ($allStatuses as $statusKey => $statusData)
                                    <?php
                                    $isTerminalStatus = in_array($statusKey, $terminalStatuses);
                                    ?>
                                <li class="position-relative z-1 {{ $statusData['status'] ? ($statusKey === 'order_placed' ? 'order-active-placed' : 'order-step-next') : '' }}">
                                    <div class="d-flex align-items-center gap-2 justify-content-between">
                                        <div class="d-flex align-items-center gap-3">
                                            <div
                                                class="icon-svg bg-soft-5 w-50px h-50px min-w-50px rounded-10 d-center">
                                                {!! $statusSVGs[$statusKey] !!}
                                            </div>

                                            <div class="media-body">
                                                <h6 class="fs-16 text-dark text-nowrap mb-0 text-capitalize">
                                                    {{ translate($statusData['label']) }}
                                                </h6>

                                                @if ($statusData['date_time'])
                                                    <span class="title-semidark fs-14 m-0">
                                                    {{ $statusData['date_time']->format('h:i A, d M Y') }}
                                                </span>
                                                @endif

                                                @if ($statusKey === 'order_is_on_the_way' && $statusData['status'] && !$trackOrderArray['is_digital_order'])
                                                    <span class="title-semidark d-block fs-14 m-0">
                                                    {{ translate('Your deliveryman is coming') }}
                                                </span>
                                                @endif

                                                @if ($isTerminalStatus && $statusData['status'])
                                                    <span class="web-text-primary d-block fs-14 m-0">
                                                @if ($statusKey === 'order_canceled')
                                                            {{ translate('Order has been canceled') }}
                                                        @elseif ($statusKey === 'order_returned')
                                                            {{ translate('Order has been returned') }}
                                                        @elseif ($statusKey === 'order_failed')
                                                            {{ translate('Order processing failed') }}
                                                        @endif
                                                </span>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($statusData['status'])
                                            <div class="checked-status opacity-0">
                                                <img width="20" height="20"
                                                     src="{{ theme_asset('public/assets/front-end/img/icons/' . ($isTerminalStatus ? 'cross.png' : 'checked-circle.png')) }}"
                                                     alt="{{ translate('check') }}">
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="text-center pt-4">
                    <a class="btn btn--primary btn-sm text-capitalize" href="#order-details"
                       data-toggle="modal">{{ translate('view_order_details')}}</a>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="digital_product_order_otp_verify" tabindex="-1"
         aria-labelledby="digital_product_order_otp_verifyLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                </div>
            </div>
        </div>
    </div>


    <span id="route-digital-product-download-otp-reset"
          data-url="{{ route('digital-product-download-otp-reset') }}"></span>

@endsection

@push('script')
    <script
        src="{{theme_asset(path: 'public/assets/front-end/vendor/nouislider/distribute/nouislider.min.js')}}"></script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/tracking.js') }}"></script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/payment.js') }}"></script>
@endpush

@push('phone_input_assets')
    @include('layouts.front-end.partials._phone-input-assets')
@endpush
