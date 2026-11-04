@extends('layouts.vendor.app')

@section('title', translate('order_Details'))

@push('css_or_js')
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor-product.css') }}" rel="stylesheet">
@endpush

@section('content')
    <div class="content container-fluid">

        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="fs-20 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" height="20" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/all-orders.png') }}" alt="">
                <span>{{translate('order_Details')}}</span>
            </h2>
            <div class="d-flex gap-1 align-items-center">
                <a href="{{ $previousOrder ?  route('vendor.orders.details', [$previousOrder['id']]) : 'javascript:' }}"
                   class="btn btn-circle text-primary bg-primary-light {{ $previousOrder ? '' : 'disabled opacity-40' }}">
                    <i class="fi fi-sr-angle-left d-flex"></i>
                </a>
                <a href="{{ $nextOrder ? route('vendor.orders.details', [$nextOrder['id']]) : 'javascript:' }}"
                   class="btn btn-circle text-primary bg-primary-light {{ $nextOrder ? '' : 'disabled opacity-40' }}">
                    <i class="fi fi-sr-angle-right d-flex"></i>
                </a>
            </div>
        </div>

        <div class="row g-2" id="printableArea">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-10 flex-md-nowrap justify-content-between mb-4">
                            <div class="d-flex flex-column gap-3 flex-1">
                                <h4 class="text-capitalize fs-16 fw-bold">
                                    {{ translate('Order_Details') }} #{{ $order['id'] ??  '' }}
                                    @if(($order['order_type'] ??  '') == 'POS')
                                        <span>({{ 'POS' }})</span>
                                    @endif
                                </h4>
                                <div class="fs-12">
                                    {{date('d M, Y , h:i A', strtotime($order['created_at'] ?? null))}}
                                </div>
                            </div>
                            <div class="text-sm-end flex-grow-1">
                                <div class="d-flex flex-wrap gap-2 justify-content-sm-end">
                                    <a class="btn btn--primary" target="_blank"
                                       href="{{ route('vendor.orders.generate-invoice',[$order['id'] ?? '']) }}">
                                        <img src="{{ dynamicAsset(path:  'public/assets/back-end/img/icons/uil_invoice.svg') }}"
                                             alt="" class="me-1">
                                        {{ translate('print_Invoice') }}
                                    </a>
                                </div>
                                <div class="d-flex flex-column gap-2 mt-3">
                                    <div class="order-status d-flex justify-content-sm-end gap-10 text-capitalize fs-12">
                                        <span class="title-color">{{ translate('status') }}: </span>
                                        @if(($order['order_status'] ?? '')=='pending')
                                            <x-k.badge tone="info">{{ translate(str_replace('_',' ',($order['order_status'] ?? ''))) }}</x-k.badge>
                                        @elseif(($order['order_status'] ?? '')=='failed')
                                            <x-k.badge tone="danger">{{ translate(str_replace('_',' ',($order['order_status'] ?? ''))) }}</x-k.badge>
                                        @elseif(($order['order_status'] ?? '')=='processing' || ($order['order_status'] ?? '')=='out_for_delivery')
                                            <x-k.badge tone="warning">{{ translate(str_replace('_',' ',($order['order_status'] ?? ''))) }}</x-k.badge>
                                        @elseif(($order['order_status'] ?? '')=='delivered' || ($order['order_status'] ?? '')=='confirmed')
                                            <x-k.badge tone="success">{{ translate(str_replace('_',' ',($order['order_status'] ?? ''))) }}</x-k.badge>
                                        @else
                                            <x-k.badge tone="danger">{{ translate(str_replace('_',' ',($order['order_status'] ??  ''))) }}</x-k.badge>
                                        @endif
                                    </div>

                                    <div class="payment-method d-flex justify-content-sm-end gap-10 text-capitalize fs-12">
                                        <span class="title-color">{{ translate('payment_Method') }} :</span>
                                        <strong>  {{ translate(str_replace('_',' ',($order['payment_method'] ??  ''))) }}</strong>
                                    </div>
                                    @if(isset($order['transaction_ref']) && (($order['payment_method'] ?? '') != 'cash_on_delivery') && (($order['payment_method'] ?? '') != 'pay_by_wallet') && ! isset($order['offline_payments']))
                                        <div
                                            class="reference-code d-flex justify-content-sm-end gap-10 text-capitalize fs-12">
                                            <span class="title-color">{{ translate('reference_Code') }} :</span>
                                            <strong>{{ translate(str_replace('_',' ',($order['transaction_ref'] ?? ''))) }} {{ (($order['payment_method'] ??  '') == 'offline_payment') ? '('. ($order['payment_by'] ?? '').')':'' }}</strong>
                                        </div>
                                    @endif
                                    <div class="payment-status d-flex justify-content-sm-end gap-10 fs-12">
                                        <span class="title-color">{{ translate('payment_Status') }}:</span>
                                        @if(($order['payment_status'] ?? '')=='paid')
                                            <span class="text-success font-weight-bold">
                                                {{ translate('paid') }}
                                            </span>
                                        @else
                                            <span class="text-danger font-weight-bold">
                                                {{ translate('unpaid') }}
                                            </span>
                                        @endif
                                    </div>
                                    @if(getWebConfig('order_verification') && (($order['order_type'] ?? '') == "default_type"))
                                        <span class="d-flex justify-content-sm-end gap-10 fs-12">
                                            <b>
                                                {{translate('order_verification_code')}} :  {{$order['verification_code'] ??  '' }}
                                            </b>
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                <tr>
                                    <th>{{translate('SL')}}</th>
                                    <th>{{translate('item_details')}}</th>
                                    <th class="k-table__num">{{ translate('Qty') }}</th>
                                    <th class="k-table__num">{{translate('item_price')}}</th>
                                    <th class="k-table__num">{{translate('discount')}}</th>
                                    <th class="k-table__num">{{translate('total_price')}}</th>
                                </tr>
                                </thead>

                                <tbody>
                                @php($itemPrice=0)
                                @php($subtotal=0)
                                @php($total=0)
                                @php($shipping=0)
                                @php($discount=0)
                                @php($extraDiscount=0)
                                @php($productPrice=0)
                                @php($totalProductPrice=0)
                                @php($couponDiscount=0)
                                @foreach(($order['details'] ?? []) as $key => $detail)

                                    <?php
                                        $isProductUnavailable = (($detail['productAllStatus'] ?? null) === null);
                                        $productDetails = json_decode($detail['product_details'] ?? null, true);
                                        if (! empty($detail['productAllStatus'])) {
                                            $getCurrentThumbnailImage = checkImageStatus(path: $detail['productAllStatus']['thumbnail'] ?? null, storagePath: $detail['productAllStatus']['thumbnail_storage_type'] ?? 'public');
                                        } else {
                                            $getCurrentThumbnailImage = checkImageStatus(path: $productDetails['thumbnail'] ?? null, storagePath: $productDetails['thumbnail_storage_type'] ?? 'public');
                                        }
                                    ?>

                                    @if($productDetails)
                                        <tr>
                                            <td><span class="k-num">{{++$key}}</span></td>
                                            <td>
                                                <div  class="{{ $isProductUnavailable ? 'opacity-50 cus-disabled' : '' }}"  data-toggle="tooltip"  data-placement="bottom"
                                                      title="{{ $isProductUnavailable ? translate('This_product_has_been_deleted') : '' }}">
                                                    <div class="media align-items-center gap-10">
                                                        <img class="avatar avatar-60 rounded img-fit"
                                                             src="{{ getStorageImages(path: $getCurrentThumbnailImage, type:'backend-product') }}"
                                                             alt="{{translate('image_description')}}">
                                                        <div>
                                                            <a class="title-color fs-12" href="{{ route('vendor.products.view', [$productDetails['id'] ?? '']) }}"
                                                               @if(! $isProductUnavailable && strlen($productDetails['name'] ?? '') > 10)
                                                                   data-toggle="tooltip" title="{{ $productDetails['name'] ?? '' }}" @endif
                                                            >
                                                                {{ Str::limit($productDetails['name'] ?? '', 30) }}
                                                            </a>
                                                            <div class="fs-10">
                                                                <strong>{{ translate('unit_price') }} :</strong>
                                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount:  $detail['price'] ?? 0)) }}
                                                            </div>
                                                            @if (! empty($detail['variant'] ?? null))
                                                                <div class="max-w-150px text-wrap fs-10">
                                                                    <strong>{{ translate('variation') }} :</strong> {{$detail['variant'] ?? ''}}
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    @if(isset($productDetails['digital_product_type']) && ($productDetails['digital_product_type'] ?? '') == 'ready_after_sell')
                                                        <button type="button" class="btn btn-sm btn--primary mt-2"
                                                                title="File Upload" data-toggle="modal"
                                                                data-target="#fileUploadModal-{{ $detail['id'] ??  '' }}">
                                                            <i class="tio-file-outlined"></i> {{ translate('file') }}
                                                        </button>
                                                    @endif

                                                </div>
                                            </td>
                                            <td class="k-table__num"><span class="k-num">{{$detail['qty'] ?? 0}}</span></td>
                                            <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount:  usdToDefaultCurrency(amount:   ($detail['price'] ?? 0)*($detail['qty'] ?? 0)), currencyCode: getCurrencyCode()) }}</span></td>
                                            <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount:  usdToDefaultCurrency(amount:   $detail['discount'] ?? 0), currencyCode: getCurrencyCode()) }}</span></td>
                                            @php($itemPrice+=(($detail['price'] ?? 0)*($detail['qty'] ?? 0)))
                                            @php($subtotal=(($detail['price'] ?? 0)*($detail['qty'] ?? 0))-($detail['discount'] ?? 0))
                                            @php($productPrice = ($detail['price'] ?? 0)*($detail['qty'] ?? 0))
                                            @php($totalProductPrice+=$productPrice)
                                            <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount:  usdToDefaultCurrency(amount:  $subtotal), currencyCode: getCurrencyCode()) }}</span></td>
                                        </tr>
                                        @php($discount+=($detail['discount'] ?? 0))
                                        @php($total+=$subtotal)
                                    @endif
                                @endforeach
                                </tbody>
                            </table>

                            @foreach(($order['details'] ?? []) as $key=>$detail)
                                <?php
                                    $productDetails = json_decode($detail['product_details'] ?? null, true);
                                ?>
                                @if(isset($productDetails['product_type']) && ($productDetails['product_type'] ?? '') == 'digital')
                                    <div class="modal fade" id="fileUploadModal-{{ $detail['id'] ??  '' }}"
                                         tabindex="-1" aria-labelledby="exampleModalLabel"
                                         aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <form
                                                    action="{{ route('vendor.orders.digital-file-upload-after-sell') }}"
                                                    method="post" enctype="multipart/form-data" class="form-advance-validation non-ajax-form-validate" novalidate="novalidate">
                                                    @csrf
                                                    <div class="modal-header border-0 px-2 pt-2 pb-0 d-flex justify-content-end">
                                                        <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                                                                style="--size: 1.5rem;" data-dismiss="modal" aria-label="Close">
                                                            <i class="fi fi-sr-cross-small fs-16 d-flex"></i>
                                                        </button>
                                                    </div>
                                                    <div class="modal-body pt-0">
                                                        @if(($productDetails['digital_product_type'] ?? '') == 'ready_after_sell')
                                                            <div class="mb-3">
                                                                <h2 class="mb-1 fs-18">
                                                                    {{ translate('File_Upload') }} <span class="text-danger">*</span>
                                                                </h2>
                                                                <p class="fs-12 mb-0">
                                                                    {{ translate('Upload_the_product_file_that_customers_will_receive.') }}
                                                                </p>
                                                            </div>
                                                            <div class="d-flex justify-content-center position-relative lg document-upload-container single_mx-100 h-100">
                                                                <div class="document-file-assets"
                                                                    data-picture-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/picture.svg') }}"
                                                                    data-document-icon="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/document.svg') }}"
                                                                    data-blank-thumbnail="{{ dynamicAsset(path: 'public/assets/back-end/img/file-placeholder.png') }}">
                                                                </div>

                                                                    <?php
                                                                    $fileTypeForDigitalReadyUrl = 'file';
                                                                    if ($detail?->digital_file_after_sell_full_url && isset($detail->digital_file_after_sell_full_url['key'])) {
                                                                        $digitalReadyUrlFileKey = $detail->digital_file_after_sell_full_url['key'] ?? '';
                                                                        $extDigitalReadyUrl = strtolower(pathinfo($digitalReadyUrlFileKey, PATHINFO_EXTENSION));

                                                                        $mapDigitalReadyUrl = [
                                                                            'jpg' => 'image',
                                                                            'jpeg' => 'image',
                                                                            'png' => 'image',
                                                                            'gif' => 'image',
                                                                            'pdf' => 'pdf',
                                                                            'zip' => 'zip',
                                                                        ];
                                                                        $fileTypeForDigitalReadyUrl = $mapDigitalReadyUrl[$extDigitalReadyUrl] ?? 'file';
                                                                    }
                                                                    ?>

                                                                <div class="document-existing-file"
                                                                    data-file-url="{{ $detail?->digital_file_after_sell_full_url && isset($detail?->digital_file_after_sell_full_url['path']) ? $detail?->digital_file_after_sell_full_url['path'] : '' }}"
                                                                    data-file-name="{{ $digitalReadyUrlFileKey ?? '' }}"
                                                                    data-file-type="{{ $fileTypeForDigitalReadyUrl }}">
                                                                </div>

                                                                <div class="position-absolute end-0 top-0 p-2 z-2 after_upload_buttons d-none">
                                                                    <div class="d-flex gap-3 align-items-center">
                                                                        <button type="button" class="btn btn-primary icon-btn doc_edit_btn" style="--size: 26px;">
                                                                            <i class="fi fi-sr-pencil"></i>
                                                                        </button>
                                                                        <a type="button" class="btn btn-success icon-btn doc_download_btn" style="--size: 26px;">
                                                                            <i class="fi fi-sr-download"></i>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                                <div class="document-upload-wrapper lg mw-100 doc-upload-wrapper">
                                                                    <input type="file" class="document_input z-index-1"
                                                                        name="digital_file_after_sell"
                                                                        data-max-size="{{ getFileUploadMaxSize(type: 'file') }}"
                                                                        data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize(type: 'file').' '.'MB' }}"
                                                                        accept=".jpg,.jpeg,.png,.gif,.zip,.pdf,.xlsx"/>
                                                                    <div class="textbox">
                                                                        <img class="svg"
                                                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/doc-upload-icon.svg') }}"
                                                                            alt="">
                                                                        <p class="mb-3">
                                                                            {{ translate('Select_a_file_or') }}
                                                                            <span class="fw-semibold">
                                                                            {{ translate('Drag_and_Drop_here') }}
                                                                        </span>
                                                                        </p>
                                                                        <button type="button" class="btn btn-outline-primary">
                                                                            {{ translate('Select_File') }}
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <input type="hidden" value="{{ $detail['id'] ?? '' }}"
                                                                   name="order_id">
                                                        @endif

                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary"
                                                                data-dismiss="modal">{{ translate('close') }}</button>
                                                        @if(($productDetails['digital_product_type'] ?? '') == 'ready_after_sell')
                                                            <button type="submit" class="btn btn--primary">
                                                                {{ translate('upload') }}
                                                            </button>
                                                        @endif
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        <hr>
                        @php($orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order))
                        <div class="row justify-content-md-end mb-3">
                            <div class="col-lg-6">
                                <div class="px-sm-4 overflow-x-auto">
                                    <table class="table table-borderless table-sm mb-0 text-sm-right text-nowrap fs-12 title-color">
                                        <tbody>
                                        <tr>
                                            <td class="text-end text-capitalize">{{ translate('item_price') }}</td>
                                            <td class="text-end title-color">
                                                <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['itemPrice'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-end text-capitalize">{{ translate('item_discount') }}</td>
                                            <td class="text-end title-color">
                                                - <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['itemDiscount'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-end">{{ translate('extra_discount') }}</td>
                                            <td class="text-end title-color">
                                                <span>- {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['extraDiscount'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-end text-capitalize">{{ translate('sub_total') }}</td>
                                            <td class="text-end title-color">
                                                <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['subTotal'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-end">{{ translate('coupon_discount') }}</td>
                                            <td class="text-end title-color">
                                                <span>- {{ setCurrencySymbol(amount:  usdToDefaultCurrency(amount: $orderTotalPriceSummary['couponDiscount'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        @if(($orderTotalPriceSummary['referAndEarnDiscount'] ?? 0) > 0)
                                            <tr>
                                                <td class="text-end">{{ translate('referral_discount') }}</td>
                                                <td class="text-end title-color">
                                                    <span>- {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['referAndEarnDiscount'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                                </td>
                                            </tr>
                                        @endif
                                        <tr>
                                            <td class="text-end text-uppercase">{{ translate('vat') }}/{{ translate('tax') }}</td>
                                            <td class="text-end title-color">
                                                <span>{{ setCurrencySymbol(amount:  usdToDefaultCurrency(amount: $orderTotalPriceSummary['taxTotal'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-end">
                                                <strong>{{ translate('total') }}</strong>
                                                <span class="fs-10 fw-medium">
                                                        {{ (($orderTotalPriceSummary['tax_model'] ?? '') == 'include') ? '('. translate('Tax_: _Inc.').')' : '' }}
                                                    </span>
                                            </td>
                                            <td class="text-end title-color">
                                                <strong>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['totalAmount'] ?? 0), currencyCode: getCurrencyCode()) }}</strong>
                                            </td>
                                        </tr>
                                        @if ((($order['order_type'] ?? '') == 'pos') || (($order['order_type'] ?? '') == 'POS'))
                                            <tr>
                                                <td class="text-end"><span>{{ translate('paid_amount') }}</span></td>
                                                <td class="text-end title-color">
                                                    <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['paidAmount'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><span>{{ translate('change_amount') }}</span></td>
                                                <td class="text-end title-color">
                                                    <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount:  $orderTotalPriceSummary['changeAmount'] ?? 0), currencyCode: getCurrencyCode()) }}</span>
                                                </td>
                                            </tr>
                                        @endif
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 d-flex flex-column gap-3">
                @php($shippingAddress = $order['shipping_address_data'] ?? null)
                <div class="card">
                    <div class="card-body d-flex flex-column gap-4">
                        <h4 class="d-flex gap-2 fs-14 font-weight-bold mb-0">
                            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/shop-info.png') }}" alt="">
                            {{ translate('order_&_shipping_info') }}
                        </h4>
                        <div>
                            <label
                                class="font-weight-bold title-color fz-14 mb-2">{{translate('change_order_status')}}</label>
                            <select name="order_status" id="order_status" class="status form-control"
                                    data-id="{{$order['id']}}">
                                <option
                                    value="pending" {{$order->order_status == 'pending'?'selected':''}} > {{translate('pending')}}</option>
                                <option
                                    value="confirmed" {{$order->order_status == 'confirmed'?'selected':''}} > {{translate('confirmed')}}</option>
                                <option
                                    value="processing" {{$order->order_status == 'processing'?'selected':''}} >{{translate('packaging')}} </option>

                                @if($order?->shipping_responsibility == 'sellerwise_shipping' || $order->order_status == 'delivered')
                                    <option
                                        value="out_for_delivery" {{$order->order_status == 'out_for_delivery'?'selected':''}} >{{translate('out_for_delivery')}} </option>
                                    <option
                                        value="delivered" {{$order->order_status == 'delivered'? 'selected':''}} >{{translate('delivered')}} </option>
                                    <option
                                        value="returned" {{$order->order_status == 'returned'?'selected':''}} > {{translate('returned')}}</option>
                                    <option
                                        value="failed" {{$order->order_status == 'failed'?'selected':''}} >{{translate('failed_to_deliver')}} </option>
                                    <option
                                        value="canceled" {{$order->order_status == 'canceled'?'selected':''}} >{{translate('canceled')}} </option>
                                @endif
                            </select>
                            @if($order?->shipping_responsibility == 'inhouse_shipping' && $order->order_status != 'delivered')
                                <div class="d-flex gap-2 alert alert-soft-warning mt-3 mb-0" role="alert">
                                    <i class="fi fi-sr-info"></i>
                                    <p class="fs-12 mb-0 text-dark">
                                        {{ translate('This order was placed with the inhouse shipping method and must be delivered using the shipping option selected by the customer during checkout.') }}
                                    </p>
                                </div>
                            @endif
                        </div>
                        <div
                            class="d-flex justify-content-between align-items-center gap-10 form-control flex-wrap h-100">
                            <span class="title-color">
                                {{translate('payment_status')}}
                            </span>
                            <div class="d-flex justify-content-end min-w-100 align-items-center gap-2">
                                <span
                                    class="text--primary font-weight-bold">{{ $order->payment_status=='paid' ? translate('paid'):translate('unpaid')}}</span>
                                <label
                                    class="switcher payment-status-text {{$order['payment_status'] == 'paid' ? 'payment-status-alert' : ''}}">
                                    <input class="switcher_input payment-status" type="checkbox" name="status"
                                           data-id="{{$order->id}}"
                                           value="{{$order->payment_status}}"
                                        {{ $order->payment_status=='paid' ? 'disabled' : ''}}
                                        {{ $order->payment_status=='paid' ? 'checked':''}} >
                                    <span class="switcher_control switcher_control_add
                                        {{ $order->payment_status=='paid' ? 'checked':'unchecked'}}"></span>
                                </label>
                            </div>
                        </div>
                        @if($order?->shipping_responsibility == 'sellerwise_shipping' && ($physicalProduct ?? false))
                            <ul class="list-unstyled d-flex flex-column gap-3 mb-0 pe-0">
                                <li>
                                    <select class="form-control text-capitalize" name="delivery_type"
                                            id="choose_delivery_type"
                                            {{ $order->order_status == 'delivered' ? 'disabled' : '' }}>
                                        <option value="0">
                                            {{translate('choose_delivery_type')}}
                                        </option>
                                        <option
                                            value="self_delivery" {{$order->delivery_type=='self_delivery'?'selected':''}}>
                                            {{translate('by_self_delivery_man')}}
                                        </option>
                                        <option
                                            value="third_party_delivery" {{$order->delivery_type=='third_party_delivery'?'selected':''}} >
                                            {{translate('by_third_party_delivery_service')}}
                                        </option>
                                    </select>
                                </li>
                                <li class="choose_delivery_man">
                                    <label for="" class="font-weight-bold title-color fz-14">
                                        {{translate('delivery_man')}}
                                    </label>
                                    <select class="form-control text-capitalize js-select2-custom"
                                            name="delivery_man_id" id="addDeliveryMan"
                                            data-placeholder="{{ translate('Select Deliveryman') }}"
                                            data-order-id="{{$order['id']}}" {{ $order->order_status == 'delivered'? 'disabled' : '' }}>
                                        <option value="" readonly>--{{ translate('Select Deliveryman') }}--
                                        </option>
                                        @foreach(($deliveryMen ?? []) as $deliveryMan)
                                            <option
                                                value="{{$deliveryMan['id']}}" {{$order['delivery_man_id']==$deliveryMan['id']?'selected':''}}>
                                                {{$deliveryMan['f_name'].' '.$deliveryMan['l_name'].' ('.$deliveryMan['phone'].')'}}
                                            </option>
                                        @endforeach
                                    </select>
                                </li>
                            </ul>
                        @endif
                    </div>
                </div>
                @if(!empty((array) $shippingAddress))
                    <div class="card">
                        <div class="card-body">
                            <h4 class="d-flex gap-2 fs-14 font-weight-bold mb-3">
                                {{translate('shipping_address')}}
                            </h4>
                            <div class="d-flex flex-column gap-2 fs-12">
                                <div><span class="font-weight-bold">{{ $shippingAddress->contact_person_name ?? '' }}</span></div>
                                @if(!empty($shippingAddress->phone))
                                    <a class="title-color" href="tel:{{ $shippingAddress->phone }}">{{ $shippingAddress->phone }}</a>
                                @endif
                                <div>{{ $shippingAddress->address ?? '' }}</div>
                                <div>{{ ($shippingAddress->city ?? '') }} {{ ($shippingAddress->zip ?? '') }}</div>
                            </div>
                        </div>
                    </div>
                @endif
                <div class="card">

                    @if(! empty($order['customer'] ?? null))
                        <div class="card-body">
                            <div class="d-flex gap-2 align-items-center justify-content-between mb-3">
                                <h4 class="d-flex gap-2 fs-14 fw-bold mb-0">
                                    <img
                                        src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/vendor-information.png')}}"
                                        alt="">
                                    {{translate('customer_information')}}
                                </h4>
                            </div>
                            <div class="media flex-wrap gap-3 gap-sm-4">
                                <div class="">
                                    <img class="avatar rounded-circle avatar-70 object-fit-cover"
                                         src="{{ getStorageImages(path: ($order['customer']['image_full_url'] ?? null) , type: 'backend-basic') }}"
                                         alt="{{translate('Image')}}">
                                </div>
                                <div class="media-body d-flex flex-column gap-1">
                                    <span class="text-dark">
                                        <span class="fw-semibold">{{ ($order['customer']['f_name'] ?? '') . ' ' . ($order['customer']['l_name'] ?? '') }} </span>
                                    </span>

                                    @if((($order['customer']['email'] ?? '') !== 'walking@customer.com'))
                                        <span class="text-dark fs-12"> <span class="fw-bold">{{ $orderCount ??  0 }}</span> {{translate('orders')}}</span>
                                        <span class="text-dark break-all fs-12">
                                            <span class="fw-semibold">
                                                {{ $order['customer']['phone'] ?? '' }}
                                            </span>
                                        </span>
                                        <span class="text-dark break-all fs-12">{{$order['customer']['email'] ?? ''}}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="card-body">
                            <div class="media align-items-center">
                                <span>{{ translate('no_customer_found') }}</span>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="card">
                    <div class="card-body">
                        <h4 class="d-flex gap-2 fs-14 fw-bold mb-3">
                            <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/shop-information.png') }}"
                                 alt="">
                            {{ translate('shop_Information') }}
                        </h4>
                        <div class="media d-flex gap-3 align-items-center">
                            @if($order->seller_is == 'admin')
                                <div class="">
                                    <img class="avatar rounded avatar-70 img-fit-contain "
                                         src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop') }}"
                                         alt="">
                                </div>
                                <div class="media-body d-flex flex-column gap-2">
                                    <h5 class="fs-14 mb-0">{{ getInHouseShopConfig(key: 'name') }}</h5>
                                    <span class="text-dark fs-12"><strong>{{ $totalDelivered }}</strong> {{translate('orders_Served')}}</span>
                                </div>
                            @else
                                @if(!empty($order->seller->shop))
                                    <div class="">
                                        <img class="avatar rounded avatar-70 img-fit"
                                             src="{{ getStorageImages(path:$order->seller->shop->image_full_url , type: 'backend-basic') }}"
                                             alt="">
                                    </div>
                                    <div class="media-body d-flex flex-column gap-2">
                                        <h5 class="fs-14 mb-0">{{ $order->seller->shop->name }}</h5>
                                        <span class="text-dark fs-12"><strong>{{ $totalDelivered }}</strong> {{translate('orders_Served')}}</span>
                                        <span
                                            class="text-dark fs-12"> <strong>{{ $order->seller->shop->contact }}</strong></span>
                                        <div class="d-flex align-items-start gap-2">
                                            <img
                                                src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/location.png')}}"
                                                class="mt-1" alt="">
                                            {{ $order->seller->shop->address }}
                                        </div>
                                    </div>
                                @else
                                    <div class="text-center p-4">
                                        <img class="w-25"
                                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/empty-state-icon/shop-not-found.png')}}"
                                             alt="{{translate('image_description')}}">
                                        <p class="mb-0 fs-12">{{ translate('no_shop_found').'!'}}</p>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <span id="payment-status-message" data-title="{{ translate('confirm_payments_before_change_the_status.') }}"
          data-message="{{ translate('you_want_to_change_this_payment_status').'?' }}"></span>
    <span id="payment-status-alert-message" data-message="{{ translate('you_can_not_change_the_payment_status_after_paid') }}"></span>
    <span id="message-status-title-text" data-text="{{ translate("are_you_sure_change_this") }}"></span>
    <span id="message-status-subtitle-text" data-text="{{ translate("you_will_not_be_able_to_revert_this") }}!"></span>
    <span id="message-status-confirm-text" data-text="{{ translate("yes_change_it") }}!"></span>
    <span id="message-status-cancel-text" data-text="{{ translate("cancel") }}"></span>
    <span id="message-status-success-text" data-text="{{ translate("status_change_successfully") }}"></span>
    <span id="order-status-url" data-url="{{ route('vendor.orders.status') }}"></span>
    <span id="payment-status-url" data-url="{{ route('vendor.orders.payment-status') }}"></span>
    <span id="message-deliveryman-add-success-text"
          data-text="{{ translate("delivery_man_successfully_assigned") }}"></span>
    <span id="message-deliveryman-add-error-text"
          data-text="{{ translate("deliveryman_can_not_assign_in_this_order") }}"></span>
    <span id="message-deliveryman-add-invalid-text" data-text="{{ translate("add_valid_data") }}"></span>
    <span id="delivery-type" data-type="{{ $order->delivery_type }}"></span>
    <span id="add-delivery-man-url" data-url="{{url('/vendor/orders/add-delivery-man/'.$order['id'])}}/"></span>
    <span id="add-date-update-url" data-url="{{route('vendor.orders.amount-date-update')}}"></span>
    <span id="amount-cannot-be-empty-message" data-message="{{ translate('amount_cannot_be_empty_or_zero') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf-worker.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/multiple-document-upload.js') }}"></script>
    <script src="{{dynamicAsset(path:  'public/assets/back-end/js/vendor/order.js')}}"></script>
@endpush
