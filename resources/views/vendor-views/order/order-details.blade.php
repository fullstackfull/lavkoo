@extends('layouts.vendor.app')
@section('title',translate('order_Details'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet"
          href="{{ dynamicAsset(path: 'public/assets/back-end/plugins/intl-tel-input/css/intlTelInput.css') }}">
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor-product.css') }}" rel="stylesheet">
@endpush

@section('content')
    @php($shippingAddress = $order['shipping_address_data'] ?? null)
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="fs-20 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" height="20"
                     src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/all-orders.png') }}" alt="">
                <span>{{translate('order_Details')}}</span>
            </h2>
            <div class="d-flex gap-1 align-items-center">
                <a href="{{ $previousOrder ? route('vendor.orders.details', [$previousOrder['id']]) : 'javascript:' }}"
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
            <div class="col-xl-8">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-10 flex-md-nowrap justify-content-between mb-4">
                            <div class="d-flex flex-column gap-10 flex-1">
                                <h4 class="text-capitalize fs-16 fw-bold d-flex align-items-center gap-3 flex-wrap mb-0">
                                    <span>{{ translate('Order_Details') }}</span>
                                    <span class="d-flex align-items-center gap-2">
                                        <span>#{{ $order['id'] }}</span>
                                         @if($order?->edited_status == 1)
                                            <x-k.badge tone="info">{{ translate('Edited') }}</x-k.badge>
                                        @endif
                                    </span>
                                </h4>
                                <div class="fs-12">
                                    {{date('d M, Y , h:i A', strtotime($order['created_at']))}}
                                </div>

                                <div class="d-flex flex-column gap-3">

                                    @if (!empty($order['order_note']))
                                        <div
                                            class="bg-section fs-12 p-3 text-dark rounded d-flex gap-2 align-items-center">
                                        <span>
                                            # {{ translate('Note') }}:
                                            {{ \Illuminate\Support\Str::limit($order['order_note'], 250) }}
                                            @if(strlen($order['order_note']) > 250)
                                                <a href="javascript:void(0)"
                                                   class="text-primary ms-1"
                                                   data-toggle="modal"
                                                   data-target="#orderNoteModal">
                                                    {{ translate('Read more') }}
                                                </a>

                                                <div class="modal fade" id="orderNoteModal" tabindex="-1"
                                                     aria-labelledby="orderNoteModal"
                                                     aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header pb-4">
                                                                <h3 class="mb-0">
                                                                    {{ translate('Order Note') }}
                                                                </h3>
                                                                <button type="button" class="btn-close border-0"
                                                                        data-dismiss="modal" aria-label="Close"><i
                                                                        class="tio-clear"></i></button>
                                                            </div>
                                                            <div class="modal-body px-4 px-sm-5 pt-0">
                                                                <p class="mb-0 fs-14">{{ $order['order_note'] }}</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        </span>
                                        </div>
                                    @endif

                                    @if($order['payment_method'] == 'cash_on_delivery' && $order['bring_change_amount'] > 0)
                                        <div
                                            class="badge badge--success title-color fs-12 font-weight-normal text-wrap p-3 d-flex gap-2 align-items-center">
                                        <span>
                                            {{ translate('Please_bring') }}
                                            <span class="fw-semibold">
                                                {{ $order['bring_change_amount'] }} {{ $order['bring_change_amount_currency'] ?? '' }}
                                            </span>
                                            {{ translate('in_change_for_the_customer_when_making_the_delivery') }}
                                        </span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="text-sm-end flex-grow-1">
                                <div class="d-flex flex-wrap gap-10 justify-content-start justify-content-lg-end">
                                    @if (isset($order->verificationImages) && $order->verification_status ==1)
                                        <div>
                                            <button class="btn btn--primary btn-sm px-4" data-toggle="modal"
                                                    data-target="#order_verification_modal"><i
                                                    class="tio-verified"></i> {{translate('order_verification')}}
                                            </button>
                                        </div>
                                    @endif
                                    @if($isOrderEditable['status'] === true)
                                        <buton type="button"
                                               class="btn text-primary border-primary bg-transparent btn-sm"
                                               data-toggle="modal"
                                               data-target="#confirm-edit-order-modal">
                                            <i class="fi fi-sr-pencil fs-12 d-flex"></i> {{ translate('Edit_Products') }}
                                        </buton>
                                    @else
                                        <buton type="button"
                                               class="btn text-primary border-primary bg-transparent btn-sm opacity--70"
                                               data-toggle="tooltip" data-placement="top"
                                               title="{{ $isOrderEditable['message'] }}" disabled>
                                            <i class="fi fi-sr-pencil fs-12 d-flex"></i> {{ translate('Edit_Products') }}
                                        </buton>
                                    @endif
                                    <a class="btn btn--primary btn-sm text-nowrap" target="_blank"
                                       href="{{ route('vendor.orders.generate-invoice', [$order['id']]) }}">
                                        <i class="tio-print me-1"></i> {{ translate('print__Invoice') }}
                                    </a>
                                </div>
                                <div class="d-flex flex-column gap-2 mt-3">
                                    <div
                                        class="order-status d-flex justify-content-sm-end gap-10 text-capitalize fs-12">
                                        <span class="title-color">{{translate('status')}}: </span>
                                        @if($order['order_status']=='pending')
                                            <x-k.badge tone="info">{{str_replace('_',' ', translate($order['order_status']) )}}</x-k.badge>
                                        @elseif($order['order_status']=='failed')
                                            <x-k.badge tone="danger">{{str_replace('_',' ', translate('failed_To_Deliver'))}}</x-k.badge>
                                        @elseif($order['order_status']=='processing' || $order['order_status']=='out_for_delivery')
                                            <x-k.badge tone="warning">{{str_replace('_',' ', $order['order_status'] == 'processing' ? translate('Packaging') : translate($order['order_status']))}}</x-k.badge>
                                        @elseif($order['order_status']=='delivered' || $order['order_status']=='confirmed')
                                            <x-k.badge tone="success">{{translate(str_replace('_',' ',$order['order_status']))}}</x-k.badge>
                                        @else
                                            <x-k.badge tone="danger">{{translate(str_replace('_',' ',$order['order_status']))}}</x-k.badge>
                                        @endif
                                    </div>

                                    @if(isset($order['transaction_ref']) && $order->payment_method != 'cash_on_delivery' && $order->payment_method != 'pay_by_wallet' && !isset($order->offlinePayments))
                                        <div
                                            class="reference-code d-flex justify-content-sm-end gap-10 text-capitalize fs-12">
                                            <span class="title-color">{{translate('reference_Code')}} :</span>
                                            <strong>{{translate(str_replace('_',' ',$order['transaction_ref']))}} {{ $order->payment_method == 'offline_payment' ? '('.$order->payment_by.')':'' }}</strong>
                                        </div>
                                    @endif

                                    @if(getWebConfig(name: 'order_verification'))
                                        <span class="d-flex justify-content-sm-end gap-10 fs-12">
                                            <b>
                                                {{translate('order_verification_code')}} : {{$order['verification_code'] }}
                                            </b>
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive datatable-custom">
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
                                @php($item_price=0)
                                @php($total_price=0)
                                @php($subtotal=0)
                                @php($total=0)
                                @php($shipping=0)
                                @php($discount=0)
                                @php($row=0)

                                @foreach($order->details as $key=>$detail)
                                        <?php
                                        $isProductUnavailable = $detail->productAllStatus === null;
                                        if ($detail->productAllStatus) {
                                            $productDetails = $detail?->productAllStatus;
                                        } else {
                                            $productDetails = json_decode($detail->product_details);
                                        }
                                        ?>
                                    @if($productDetails)
                                        <tr>
                                            <td><span class="k-num">{{ ++$row }}</span></td>
                                            <td>
                                                <div
                                                    class="{{ $isProductUnavailable ? 'opacity-50 cus-disabled' : '' }}"
                                                    data-toggle="tooltip" data-placement="bottom"
                                                    title="{{ $isProductUnavailable ? translate('This_product_has_been_deleted') : '' }}">
                                                    <div class="media align-items-center gap-10">
                                                        <img class="avatar avatar-60 rounded img-fit" alt=""
                                                             src="{{ getStorageImages(path:$detail?->productAllStatus?->thumbnail_full_url, type: 'backend-product') }}">
                                                        <div>
                                                            <a class="title-color fs-12"
                                                               href="{{ route('vendor.products.view', [$productDetails->id]) }}"
                                                               @if(!$isProductUnavailable && strlen($productDetails->name) > 30)
                                                                   data-toggle="tooltip"
                                                               title="{{ $productDetails->name }}" @endif
                                                            >
                                                                {{ Str::limit($productDetails->name, 30) }}
                                                            </a>
                                                            <div class="fs-10">
                                                                <strong>{{translate('unit_price')}} :</strong>
                                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['price'])) }}
                                                            </div>
                                                            @if ($detail->variant)
                                                                <div class="fs-10"><strong>{{translate('variation')}}
                                                                        :</strong> {{$detail['variant']}}</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    @if(isset($productDetails->digital_product_type) && $productDetails->digital_product_type == 'ready_after_sell')
                                                        <button type="button" class="btn btn-sm btn--primary mt-2"
                                                                title="File Upload" data-toggle="modal"
                                                                data-target="#fileUploadModal-{{ $detail->id }}"
                                                        >
                                                            <i class="tio-file-outlined"></i> {{translate('file')}}
                                                        </button>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="k-table__num"><span class="k-num">{{$detail['qty']}}</span></td>
                                            <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['price']*$detail['qty']), currencyCode: getCurrencyCode())}}</span></td>
                                            <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['discount']), currencyCode: getCurrencyCode())}}</span></td>

                                            @php($subtotal=$detail['price']*$detail['qty']-$detail['discount'])
                                            <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $subtotal), currencyCode: getCurrencyCode())}}</span></td>
                                        </tr>
                                        @php($item_price+=$detail['price']*$detail['qty'])
                                        @php($discount+=$detail['discount'])
                                        @php($total+=$subtotal)
                                    @endif
                                @endforeach
                                </tbody>
                            </table>

                            @foreach($order->details as $key=>$detail)
                                    <?php
                                    if ($detail->productAllStatus) {
                                        $productDetails = $detail?->productAllStatus;
                                    } else {
                                        $productDetails = json_decode($detail->product_details);
                                    }
                                    ?>
                                @if(isset($productDetails?->digital_product_type) && $productDetails->digital_product_type == 'ready_after_sell')
                                    <div class="modal fade" id="fileUploadModal-{{ $detail->id }}" tabindex="-1"
                                         aria-labelledby="exampleModalLabel" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <form
                                                    action="{{ route('vendor.orders.digital-file-upload-after-sell') }}"
                                                    method="post" enctype="multipart/form-data"
                                                    class="form-advance-validation non-ajax-form-validate"
                                                    novalidate="novalidate">
                                                    @csrf
                                                    <div class="modal-header border-0 px-2 pt-2 pb-0 d-flex justify-content-end">
                                                        <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                                                                style="--size: 1.5rem;" data-dismiss="modal" aria-label="Close">
                                                            <i class="fi fi-sr-cross-small fs-16 d-flex"></i>
                                                        </button>
                                                    </div>
                                                    <div class="modal-body pt-0">
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
                                                        <input type="hidden" value="{{ $detail->id }}"
                                                               name="order_id">
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary"
                                                                data-dismiss="modal">{{translate('close')}}</button>
                                                        <button type="submit"
                                                                class="btn btn--primary">{{translate('upload')}}</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        @php($orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order))
                        <hr/>
                        <div class="row justify-content-end">
                            <div class="col-12 col-sm-auto">
                                <div class="overflow-x-auto min-w-300 min-w-100-mobile">
                                    <table class="table table-borderless table-sm mb-0 text-sm-right text-nowrap fs-12">
                                        <tbody>
                                        <tr>
                                            <td class="text-start text-dark text-capitalize">
                                                <span>{{ translate('item_price') }}</span></td>
                                            <td class="text-end text-dark">
                                                <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['itemPrice']), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-start text-dark text-capitalize">
                                                <span>{{ translate('item_discount') }}</span></td>
                                            <td class="text-end text-dark">
                                                -
                                                <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['itemDiscount']), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-start text-dark text-capitalize">
                                                <span>{{ translate('sub_total') }}</span></td>
                                            <td class="text-end text-dark">
                                                <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['subTotal']), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-start text-dark">
                                                <span>{{ translate('coupon_Discount') }}</span>
                                                <br>
                                                {{(!in_array($order['coupon_code'], [0, NULL]) ? '('.translate('expense_bearer_').($order['coupon_discount_bearer']=='inhouse' ? 'admin' : ($order['coupon_discount_bearer'] == 'seller' ? 'vendor' : $order['coupon_discount_bearer'])).')': '' )}}
                                            </td>
                                            <td class="text-end text-dark">
                                                    <span>-
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['couponDiscount']), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>
                                        @if($orderTotalPriceSummary['referAndEarnDiscount'] > 0)
                                            <tr>
                                                <td class="text-start text-dark">
                                                    <span>{{ translate('referral_discount') }}</span></td>
                                                <td class="text-end text-dark">
                                                    <span>-
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['referAndEarnDiscount']), currencyCode: getCurrencyCode()) }}</span>
                                                </td>
                                            </tr>
                                        @endif

                                        <tr>
                                            <td class="text-start text-dark text-uppercase"><span>{{ translate('vat') }}/{{ translate('tax') }}</span>
                                            </td>
                                            <td class="text-end text-dark">
                                                <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['totalTaxAmount']), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td class="text-start text-dark text-capitalize">
                                                <span>{{ translate('shipping_fee') }}</span>
                                                @if($order['is_shipping_free'])
                                                    <br>
                                                    ({{ translate('expense_bearer_').($order['free_delivery_bearer'] == 'seller' ? 'vendor' : $order['free_delivery_bearer']) }}
                                                    )
                                                @endif
                                            </td>
                                            <td class="text-end text-dark">
                                                    <span>
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['shippingTotal'])) }}
                                                    </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-start text-dark text-capitalize">
                                                <strong>{{ translate('total') }}</strong>
                                                <span
                                                    class="fs-10 fw-medium">{{ $orderTotalPriceSummary['tax_model'] == 'include' ? '('.translate('Tax_:_Inc.').')' : '' }}</span>
                                            </td>
                                            <td class="text-end text-dark">
                                                <strong>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['totalAmount']), currencyCode: getCurrencyCode()) }}</strong>
                                            </td>
                                        </tr>
                                        @if ($order->order_type == 'pos' || $order->order_type == 'POS')
                                            <tr>
                                                <td class="text-start text-dark text-capitalize">
                                                    <span>{{ translate('paid_Amount') }}</span></td>
                                                <td class="text-end text-dark">
                                                    <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['paidAmount']), currencyCode: getCurrencyCode()) }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-start text-dark text-capitalize">
                                                    <span>{{ translate('change_Amount') }}</span></td>
                                                <td class="text-end text-dark">
                                                    <span>{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['changeAmount']), currencyCode: getCurrencyCode()) }}</span>
                                                </td>
                                            </tr>
                                        @endif

                                        @if(($orderTotalPriceSummary['edited_total_paid_amount'] ?? 0) > 0)
                                            <tr>
                                                <td class="text-start text-dark text-capitalize">
                                                    {{ translate('Paid Amount') }}
                                                </td>
                                                <td class="text-end text-dark">
                                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['edited_total_paid_amount']), currencyCode: getCurrencyCode()) }}
                                                </td>
                                            </tr>
                                        @endif

                                        @if($order['edited_status'] == 1 && $order?->latestEditHistory)

                                            @if($order?->latestEditHistory?->order_due_amount > 0)
                                                @if($order?->latestEditHistory?->order_due_payment_status == 'paid')
                                                    <tr>
                                                        <td class="text-start text-dark text-capitalize">
                                                            {{ translate('Paid Amount') }}
                                                        </td>
                                                        <td class="text-end text-dark">
                                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_amount - $order?->latestEditHistory?->order_due_amount), currencyCode: getCurrencyCode()) }}
                                                        </td>
                                                    </tr>

                                                    <tr>
                                                        <td class="text-start text-dark text-capitalize d-flex flex-column">
                                                            <span>{{ translate('Due Amount Paid By') }}</span>
                                                            <span>({{ ucwords(str_replace('_', ' ', $order?->latestEditHistory?->order_due_payment_method)) }})</span>
                                                        </td>
                                                        <td class="text-end text-dark">
                                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_due_amount), currencyCode: getCurrencyCode()) }}
                                                        </td>
                                                    </tr>

                                                    <tr>
                                                        <td class="text-start text-dark text-capitalize d-flex flex-column">
                                                            <strong>{{ translate('Total Paid Amount') }}</strong>
                                                        </td>
                                                        <td class="text-end text-dark">
                                                            <strong>
                                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_amount), currencyCode: getCurrencyCode()) }}
                                                            </strong>
                                                        </td>
                                                    </tr>
                                                @else
                                                    <tr>
                                                        <td class="text-start text-danger text-capitalize">
                                                            <strong>{{ translate('due_Amount') }}</strong>
                                                        </td>
                                                        <td class="text-end text-danger">
                                                            <strong>
                                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_due_amount), currencyCode: getCurrencyCode()) }}
                                                            </strong>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endif

                                            @if($order?->latestEditHistory?->order_return_amount > 0)
                                                @if($order?->latestEditHistory?->order_return_payment_status == "returned")
                                                    <tr>
                                                        <td class="text-start text-dark text-capitalize">
                                                            {{ translate('Paid Amount') }}
                                                        </td>
                                                        <td class="text-end text-dark">
                                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_amount + $order?->latestEditHistory?->order_return_amount), currencyCode: getCurrencyCode()) }}
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-start text-dark text-capitalize">
                                                            <strong>{{ translate('Returned by') }}</strong>
                                                            <span>({{ ucwords(str_replace('_', ' ', $order?->latestEditHistory?->order_return_payment_method)) }})</span>
                                                        </td>
                                                        <td class="text-end text-dark">
                                                            <strong>
                                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_return_amount), currencyCode: getCurrencyCode()) }}
                                                            </strong>
                                                        </td>
                                                    </tr>
                                                @else
                                                    <tr>
                                                        <td class="text-start text-danger text-capitalize">
                                                            <strong>{{ translate('Amount To Return') }}</strong>
                                                        </td>
                                                        <td class="text-end text-danger">
                                                            <strong>
                                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_return_amount), currencyCode: getCurrencyCode()) }}
                                                            </strong>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endif
                                        @endif

                                        </tbody>
                                    </table>
                                    @if($order->edited_status == 1)
                                        <div class="d-flex justify-content-end mt-2">
                                            <div
                                                class="bg-warning bg-opacity-10 fs-12 px-12 py-2 text-dark rounded d-flex gap-2 align-items-center w-100">
                                                <i class="fi fi-sr-info text-warning"></i>
                                                <span>
                                                {{ translate('total_bill_has_been_updated_after_the_edits.') }}
                                            </span>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @if($order?->orderEditHistory?->isNotEmpty())
                            <hr/>
                            <div>
                                <h4 class="fs-14 fw-bold mb-3">{{ translate('Edit Log') }}</h4>
                                <div class="d-flex flex-column gap-20">
                                    @foreach($order->orderEditHistory as $history)
                                        <div>
                                            <div class="fs-12 mb-2">
                                                {{ translate('Order Edited by') }}
                                                {{ $history['edited_user_name'] }}
                                                ({{ translate($history['edit_by']) }})
                                            </div>
                                            <div class="fs-12 mb-0 text-dark">
                                                {{ $history?->created_at->format('d M, Y h:i') }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-xl-4 d-flex flex-column gap-3">
                {{-- What the order is worth to this seller, and how it got here.
                     Read from the same services the seller app reads, so the panel
                     and the app cannot disagree about a margin or a deadline. --}}
                @include('vendor-views.order.partials._seller-earning')

                <div class="card card-body">
                    <h4 class="fw-bold d-flex align-items-center gap-2 mb-3">
                        {{ translate('Payment') }}
                        @if($order['payment_status']=='paid')
                            <span
                                class="badge text-success bg-success bg-opacity-10 fw-semibold rounded d-flex align-items-center py-1 px-2">
                                {{ translate('Paid') }}
                            </span>
                        @else
                            <span
                                class="badge text-danger bg-danger bg-opacity-10 fw-semibold rounded d-flex align-items-center py-1 px-2">
                                {{ translate('Unpaid') }}
                            </span>
                        @endif
                    </h4>
                    <div class="d-grid gap-2 fs-12">
                        <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                <span class="w-120 flex-shrink-0 text-capitalize">
                                    {{ translate('Payment_Method') }}
                                </span>
                            <span>:</span>
                            <span class="fw-semibold">{{ translate($order['payment_method']) }}</span>
                        </div>

                        @if($order->payment_method != 'cash_on_delivery' && $order->payment_method != 'pay_by_wallet' && !isset($order->offlinePayments))
                            <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                <span class="w-120 flex-shrink-0 text-capitalize">
                                    {{ translate('Reference_Code') }}
                                </span>
                                <span>:</span>
                                <span class="fw-semibold">
                                    {{ str_replace('_',' ',$order['transaction_ref']) }} {{ $order->payment_method == 'offline_payment' ? '('.$order->payment_by.')':'' }}
                                </span>
                            </div>
                        @endif

                        <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                            <span class="w-120 flex-shrink-0 text-capitalize">{{ translate('Order_Amount') }}</span>
                            <span>:</span>
                            <span class="fw-semibold">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order['init_order_amount']), currencyCode: getCurrencyCode()) }}
                            </span>
                        </div>
                    </div>

                    @if($order->payment_method == 'offline_payment' && isset($order->offlinePayments))
                        <div class="bg-section p-2 mt-3 rounded">
                            <h5 class="fs-12 mb-3">{{ translate('Customer_Payment_info') }}</h5>
                            <div class="d-grid gap-2 fs-12">
                                @foreach ($order->offlinePayments->payment_info as $key=>$item)
                                    @if (isset($item) && $key != 'method_id')
                                        <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                            <span class="w-120 flex-shrink-0 text-capitalize text-capitalize">
                                                {{ translate($key) }}
                                            </span>
                                            <span>:</span>
                                            <span class="fw-semibold">{{ $item ?? 'N/a' }}</span>
                                        </div>
                                    @endif
                                @endforeach
                                @if(isset($order?->payment_note) && $order?->payment_method == 'offline_payment')
                                    <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                        <span class="w-120 flex-shrink-0 text-capitalize text-capitalize">
                                            {{ translate('Payment_Note') }}
                                        </span>
                                        <span>:</span>
                                        <span class="fw-semibold">{{ $order?->payment_note ?? 'N/a' }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid')
                            <div class="row g-2 mt-2">
                                <div class="col-12">
                                    <div class="d-flex align-items-center gap-2 alert alert-soft-warning" role="alert">
                                        <i class="fi fi-sr-info"></i>
                                        <p class="fs-12 mb-0 text-dark">
                                            {{ translate('This payment has not been verified yet.') }}
                                            {{ translate('Please wait for admin verification to complete before taking further actions.') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif
                    <?php
                    $hasUnpaidDue = $order['edit_due_amount'] > 0 && $order?->latestEditHistory && $order?->latestEditHistory?->order_due_payment_status === 'unpaid';
                    $filteredEditPaymentHistory = $orderEditPaymentHistory->filter(function ($item) {
                        return $item->order_due_payment_status === 'paid'
                            || $item->order_return_payment_status === 'returned';
                    });
                    ?>

                    @if(count($filteredEditPaymentHistory) > 0 || $hasUnpaidDue)
                        <div class="payment-logs-info-section fs-12 bg-section p-2 mt-3 rounded">
                            <div class="d-flex gap-2 align-items-center">
                                <span class="fw-semibold">{{ translate('Another_Amount_Info') }}</span>
                            </div>

                            @foreach($filteredEditPaymentHistory as $orderEditHistoryItem)
                                @if($orderEditHistoryItem['order_due_payment_status'] == 'paid')
                                    <div class="d-grid gap-2 my-1">
                                        <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                        <span class="w-120 flex-shrink-0 text-capitalize">
                                            {{ translate('Pay By') }}
                                        </span>
                                            <span>:</span>
                                            <span class="fw-semibold d-flex flex-wrap gap-2">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderEditHistoryItem['order_due_amount']), currencyCode: getCurrencyCode()) }}
                                                @if(!empty($orderEditHistoryItem['order_due_payment_method']))
                                                    ({{ translate($orderEditHistoryItem['order_due_payment_method']) }})
                                                @endif
                                        <span
                                            class="badge text-success bg-success bg-opacity-10 fw-semibold rounded d-flex align-items-center py-1 px-2 w-max-content">
                                            {{ translate('Paid') }}
                                        </span>
                                    </span>
                                        </div>
                                    </div>
                                @else
                                    <div class="d-grid gap-2 my-1">
                                        <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                        <span class="w-120 flex-shrink-0 text-capitalize">
                                            {{ translate('Return_Amount') }}
                                        </span>
                                            <span>:</span>
                                            <span class="fw-semibold d-flex flex-wrap gap-2">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderEditHistoryItem['order_return_amount']), currencyCode: getCurrencyCode()) }}
                                                @if(!empty($orderEditHistoryItem['order_return_payment_method']))
                                                    ({{ translate($orderEditHistoryItem['order_return_payment_method']) }}
                                                    )
                                                @endif
                                        <span
                                            class="badge text-success bg-success bg-opacity-10 fw-semibold rounded d-flex align-items-center py-1 px-2 w-max-content">
                                                    {{ translate('Returned') }}
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                @endif
                            @endforeach

                            @if ($order['edit_return_amount'] > 0 && $order?->latestEditHistory && $order?->latestEditHistory?->order_return_payment_status === 'pending')
                                <div class="d-grid gap-2 my-2">
                                    <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                    <span class="w-120 flex-shrink-0 text-capitalize">
                                        {{ translate('Return Amount') }}
                                    </span>
                                        <span>:</span>
                                        <span class="fw-semibold d-flex flex-wrap gap-2">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_return_amount), currencyCode: getCurrencyCode()) }}
                                            @if(!empty($order?->latestEditHistory?->order_return_payment_method))
                                                <span class="fs-12">({{ translate($order?->latestEditHistory?->order_return_payment_method) }})</span>
                                            @endif
                                        <span
                                            class="badge text-danger bg-danger bg-opacity-10 fw-semibold rounded d-flex align-items-center py-1 px-2 w-max-content">
                                            {{ translate($order?->latestEditHistory?->order_return_payment_status) }}
                                        </span>
                                    </span>
                                    </div>
                                </div>
                            @endif

                            @if ($order['edit_due_amount'] > 0 && $order?->latestEditHistory && $order?->latestEditHistory?->order_due_payment_status === 'unpaid')
                                <div class="d-grid gap-2 my-2">
                                    <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                    <span class="w-120 flex-shrink-0 text-capitalize">
                                        {{ translate('Due Amount') }}
                                    </span>
                                        <span>:</span>
                                        <span class="fw-semibold d-flex flex-wrap gap-2">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->latestEditHistory?->order_due_amount), currencyCode: getCurrencyCode()) }}
                                            @if(!empty($order?->latestEditHistory?->order_due_payment_method))
                                                <span class="fs-12">({{ translate($order?->latestEditHistory?->order_due_payment_method) }})</span>
                                            @endif
                                        <span
                                            class="badge text-danger bg-danger bg-opacity-10 fw-semibold rounded d-flex align-items-center py-1 px-2 w-max-content">
                                            {{ translate('Unpaid') }}
                                        </span>
                                    </span>
                                    </div>
                                </div>

                                @if($order?->latestEditHistory?->order_due_payment_method == 'offline_payment' && !empty($order?->latestEditHistory?->order_due_payment_info))
                                    <div class="bg-section p-2 mt-2 rounded">
                                        <h5 class="fs-12 mb-3">{{ translate('Receiving_Account_Details') }}</h5>
                                        <div class="d-grid gap-2 fs-12">
                                            @foreach($order?->latestEditHistory?->order_due_payment_info as $orderDuePaymentInfoKey => $orderDuePaymentInfo)
                                                @if(!in_array($orderDuePaymentInfoKey, ['method_id', 'id']))
                                                    <div class="d-flex gap-3 align-items-center overflow-wrap-anywhere">
                                                        <span
                                                            class="w-120 flex-shrink-0 text-capitalize text-capitalize">
                                                            {{ translate($orderDuePaymentInfoKey) }}
                                                        </span>
                                                        <span>:</span>
                                                        <span
                                                            class="fw-semibold">{{ $orderDuePaymentInfo ?? 'N/a' }}</span>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endif

                            @if ($order['payment_method'] == 'offline_payment' && $order?->latestEditHistory?->order_due_payment_method == 'offline_payment' && $order?->latestEditHistory?->order_due_payment_status == 'unpaid')
                                <div class="row g-2 mt-2">
                                    <div class="col-12">
                                        <div class="d-flex align-items-center gap-2 alert alert-soft-warning"
                                             role="alert">
                                            <i class="fi fi-sr-info"></i>
                                            <p class="fs-12 mb-0 text-dark">
                                                {{ translate('This payment has not been verified yet.') }}
                                                {{ translate('Please wait for admin verification to complete before taking further actions.') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if((count($filteredEditPaymentHistory) + ($hasUnpaidDue ? 1 : 0)) >= 1)
                            <button type="button"
                                    class="btn text-primary fw-medium bg-transparent border-0 shadow-none p-0 w-max-content mx-auto mt-3"
                                    id="paymentInfoCollapse">
                                {{ translate('See_More') }}
                            </button>
                        @endif
                    @endif
                </div>
                <?php
                $paymentMethod = $order?->payment_method;
                $editDueAmount = $order['edit_due_amount'] ?? 0;
                $editReturnAmount = $order['edit_return_amount'] ?? 0;
                ?>
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0 text-center fw-bold">{{translate('order_&_Shipping_Info')}}</h4>
                    </div>
                    <div class="card-body d-flex flex-column gap-4">
                        @if ($editDueAmount > 0 && !in_array($paymentMethod, ['cash_on_delivery']) && ($order?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $order?->latestEditHistory?->order_due_payment_method == ''))
                            <div class="bg-section rounded-10 p-12 p-sm-20 text-center">
                                <h5 class="text-danger mb-3">{{ translate('Amount_Due') }}</h5>
                                <h4 class="fw-bold">{{ webCurrencyConverter(amount: $order['edit_due_amount']) }}</h4>
                                <p class="fs-12 mb-0">
                                    {{ translate('after_editing,_the_order_amount_has_increased.') }}
                                    {{ translate('to_collect_the_due_amount,_switch_the_payment_method_to_cash_on_delivery') }}
                                    (COD)
                                    {{ translate('or_contact_with_customer_to_pay_the_bill_from_order_details_page') }}
                                </p>
                                <button type="button" class="btn btn--primary mt-3" data-toggle="modal"
                                        data-target="#switchToCODModal">{{ translate('Switch_to_COD') }}</button>
                            </div>
                        @elseif(($editReturnAmount ?? 0) > 0)
                            <div class="bg-section rounded-10 p-12 p-sm-20 text-center">
                                <h5 class="text-danger mb-3">{{ translate('Need_to_Return') }}</h5>
                                <h4 class="fw-bold">{{ webCurrencyConverter(amount: $editReturnAmount) }}</h4>
                                <p class="fs-12 mb-0">
                                    {{ translate('The_order_amount_was_reduced_after_editing.') }}
                                    {{ translate('Please_contact_the_admin_to_return_the_amount_to_the_customer.') }}
                                </p>
                            </div>
                        @endif

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

                                @if($order?->shipping_responsibility == 'sellerwise_shipping')
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
                        <?php $disableDeliveryType = !$physicalProduct && $shippingAddress; ?>
                        @if($physicalProduct || $shippingAddress)
                            <ul class="list-unstyled d-flex flex-column mb-0 pe-0">
                                @if ($order->shipping_type == 'order_wise')
                                    <li>
                                        <label class="font-weight-bold title-color fz-14 mb-2">
                                            {{translate('shipping_method')}}
                                            ({{$order->shipping ? $order->shipping->title : translate('no_shipping_method_selected')}}
                                            )
                                        </label>
                                    </li>
                                @endif

                                @if($order?->shipping_responsibility == 'inhouse_shipping')
                                <div class="d-flex gap-2 alert alert-soft-warning" role="alert">
                                    <i class="fi fi-sr-info"></i>
                                    <p class="fs-12 mb-0 text-dark">
                                        {{ translate('This order was placed with the inhouse shipping method and must be delivered using the shipping option selected by the customer during checkout.') }}
                                    </p>
                                </div>
                                @endif

                                @if ($order?->shipping_responsibility == 'sellerwise_shipping')
                                    <li>
                                        <select class="form-control text-capitalize" name="delivery_type"
                                                id="choose_delivery_type"
                                                {{ $order->order_status == 'delivered' || $disableDeliveryType ? 'disabled' : '' }}
                                                @if($disableDeliveryType)
                                                    data-toggle="tooltip"
                                                data-placement="top"
                                                title="{{ translate('You cannot select delivery type as the order only contain digital product') }}"
                                            @endif
                                        >
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
                                    @if(!$disableDeliveryType)
                                        <li id="choose_delivery_man" class="mt-3 choose_delivery_man">
                                            <label for="" class="font-weight-bold title-color fz-14">
                                                {{translate('delivery_man')}}
                                            </label>
                                            <select class="form-control text-capitalize js-select2-custom"
                                                    name="delivery_man_id" id="addDeliveryMan"
                                                    data-placeholder="{{ translate('Select Deliveryman') }}"
                                                    data-order-id="{{$order['id']}}" {{ $order->order_status == 'delivered'? 'disabled' : '' }}>
                                                <option value="" readonly>--{{ translate('Select Deliveryman') }}--
                                                </option>
                                                @foreach($deliveryMen as $deliveryMan)
                                                    <option
                                                        value="{{$deliveryMan['id']}}" {{$order['delivery_man_id']==$deliveryMan['id']?'selected':''}}>
                                                        {{$deliveryMan['f_name'].' '.$deliveryMan['l_name'].' ('.$deliveryMan['phone'].' )'}}
                                                    </option>
                                                @endforeach
                                            </select>

                                            @if (isset($order->deliveryMan))
                                                <div class="p-2 bg-section rounded mt-4">
                                                    <div class="media m-1 gap-3">
                                                        <img class="avatar rounded-circle"
                                                             src="{{getStorageImages(path:$order?->deliveryMan->image_full_url,type: 'backend-profile')}}"
                                                             alt="Image">
                                                        <div class="media-body">
                                                            <h5 class="mb-1">{{  $order->deliveryMan?->f_name.' '.$order->deliveryMan?->l_name}}</h5>
                                                            <a href="tel:{{ $order->deliveryMan?->phone}}"
                                                               class="fs-12 title-color">{{$order->deliveryMan?->phone }}</a>
                                                        </div>
                                                    </div>
                                                </div>
                                            @else
                                                <div class="p-3 bg-section rounded mt-4">
                                                    <div class="media m-1 gap-2 align-items-center">
                                                        <img class="avatar rounded-circle"
                                                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/delivery-man.png')}}"
                                                             alt="{{translate('Image')}}">
                                                        <div class="media-body">
                                                            <div
                                                                class="fs-12">{{translate('no_delivery_man_assigned')}}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                        </li>
                                        @if (isset($order->deliveryMan))
                                            <li class="choose_delivery_man mt-3">
                                                <label class="font-weight-bold title-color d-flex fz-14">
                                                    {{translate('delivery_man_incentive')}} ({{ getCurrencySymbol() }})
                                                    <span class="input-label-secondary cursor-pointer"
                                                          data-toggle="tooltip"
                                                          data-placement="right"
                                                          title="{{translate('encourage_your_deliveryman_by_giving_him_incentive').' '.translate('this_amount_will_be_count_as_vendor_expense').'.'}}">
                                                    <img width="16"
                                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg')}}"
                                                         alt="">
                                                </span>
                                                </label>
                                                <div class="d-flex gap-2 align-items-center">
                                                    <input type="number"
                                                           value="{{ usdToDefaultCurrency(amount: $order->deliveryman_charge) }}"
                                                           name="deliveryman_charge" data-order-id="{{$order['id']}}"
                                                           class="form-control" placeholder="{{translate('ex').': 20'}}"
                                                           {{$order['order_status']=='delivered' ? 'readonly':''}} required>
                                                    <button
                                                        class="btn btn--primary {{$order['order_status']=='delivered' ? 'disabled deliveryman-charge-alert':'deliveryman-charge'}}">{{translate('update')}}</button>
                                                </div>
                                            </li>
                                            <li class="choose_delivery_man mt-3">
                                                <label class="font-weight-bold title-color fz-14">
                                                    {{translate('expected_delivery_date')}}
                                                </label>
                                                <input type="date"
                                                       value="{{ $order->expected_delivery_date }}"
                                                       data-order-id="{{$order['id']}}"
                                                       name="expected_delivery_date"
                                                       id="expected_delivery_date"
                                                       class="form-control set-today-date-minimum deliveryDateUpdate" {{ $order->order_status == 'delivered'? 'disabled' : 'required' }} >
                                            </li>
                                        @endif
                                        <li class="mt-1" id="by_third_party_delivery_service_info">
                                            <div class="p-2 bg-light rounded mt-3">
                                                <div class="media m-1 gap-3">
                                                    <img class="avatar rounded-circle"
                                                         src="{{dynamicAsset(path: 'public/assets/back-end/img/third-party-delivery.png')}}"
                                                         alt="{{translate('image')}}">
                                                    <div class="media-body">
                                                        <h5 class="">{{$order->delivery_service_name ?? translate('not_assign_yet')}}</h5>
                                                        <span
                                                            class="fs-12 title-color">{{translate('track_ID')}} :  {{$order->third_party_delivery_tracking_id}}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    @endif
                                @endif
                            </ul>
                        @endif
                    </div>
                </div>
                <div class="card">
                    @if(!empty((array) $shippingAddress))
                        <div class="card-body">
                            <div class="d-flex gap-2 align-items-center justify-content-between mb-3">
                                <h4 class="d-flex gap-2 fs-14 fw-bold mb-0">
                                    <img
                                        src="{{dynamicAsset(path: 'public/assets/back-end/img/vendor-information.png')}}"
                                        alt="">
                                    {{translate('shipping_address')}}
                                </h4>
                                @if($order['order_status'] != 'delivered')
                                    <button class="btn btn-outline-primary btn-sm square-btn"
                                            title="{{translate('edit')}}"
                                            data-toggle="modal"
                                            data-target="#shippingAddressUpdateModal">
                                        <i class="tio-edit" style="font-size: 15px;"></i>
                                    </button>
                                @endif
                            </div>
                            <table class="overflow-wrap-anywhere">
                                <tbody>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('name')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$shippingAddress->contact_person_name}}</strong> {{ $order->is_guest ? '('. translate('guest_customer') .')':''}}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('contact')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$shippingAddress->phone}}</strong>
                                    </td>
                                </tr>
                                @if ($order->is_guest && $shippingAddress->email)
                                    <tr>
                                        <td class="px-0 py-2 text-nowrap">{{translate('email')}}</td>
                                        <td class="px-3 py-2">:</td>
                                        <td class="px-0 py-2">
                                            <strong>{{$shippingAddress->email}}</strong>
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('country')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$shippingAddress->country}}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('city')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2"><strong>{{$shippingAddress->city}}</strong></td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('zip_code')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$shippingAddress->zip}}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{ translate('Address') }}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <div class="d-flex align-items-start gap-2">
                                            <span>{{$shippingAddress->address}}</span>
                                        </div>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    @elseif($physicalProduct)
                        <div class="card-body">
                            <div class="media align-items-center">
                                <span>{{translate('no_shipping_address_found')}}</span>
                            </div>
                        </div>
                    @endif
                </div>
                @if (getWebConfig('map_api_status') == 1 && isset($shippingAddress->latitude) && isset($shippingAddress->longitude))
                    <div class="card card-body">
                        <div class="d-flex justify-content-between gap-3 align-items-center flex-wrap">
                            <h4 class="mb-0">{{ translate('Shipping_Location') }}</h4>
                            <button type="button" class="btn btn-outline-primary px-3 py-1" data-toggle="modal"
                                    data-target="#locationModal">{{ translate('On_Map') }}</button>
                        </div>
                    </div>
                @endif
                @php($billing=$order['billing_address_data'])
                @if(!empty((array) $billing))
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex gap-2 align-items-center justify-content-between mb-3">
                                <h4 class="d-flex gap-2 fs-14 fw-bold mb-0">
                                    <img
                                        src="{{dynamicAsset(path: 'public/assets/back-end/img/vendor-information.png')}}"
                                        alt="">
                                    {{translate('billing_address')}}
                                </h4>
                                @if($order['order_status'] !== 'delivered')
                                    <button class="btn btn-outline-primary btn-sm square-btn"
                                            title="Edit"
                                            data-toggle="modal"
                                            data-target="#billingAddressUpdateModal">
                                        <i class="tio-edit" style="font-size: 15px;"></i>
                                    </button>
                                @endif
                            </div>

                            <table class="overflow-wrap-anywhere">
                                <tbody>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('name')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$billing->contact_person_name}}</strong> {{ $order->is_guest ? '('. translate('guest_customer') .')':''}}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('contact')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$billing->phone}}</strong>
                                    </td>
                                </tr>
                                @if ($order->is_guest && $billing->email)
                                    <tr>
                                        <td class="px-0 py-2 text-nowrap">{{translate('email')}}</td>
                                        <td class="px-3 py-2">:</td>
                                        <td class="px-0 py-2">
                                            <strong>{{$billing->email}}</strong>
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('country')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$billing->country}}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('city')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$billing->city}}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('zip_code')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <strong>{{$billing->zip}}</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{ translate('Address') }}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <div class="d-flex align-items-start gap-2">
                                            <span>{{$billing->address}}</span>
                                        </div>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
                @if(!$order->is_guest && $order->customer)
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex gap-2 align-items-center justify-content-between mb-3">
                                <h4 class="d-flex gap-2 fs-14 fw-bold mb-0">
                                    <img
                                        src="{{dynamicAsset(path: 'public/assets/back-end/img/vendor-information.png')}}"
                                        alt="">
                                    {{translate('customer_information')}}
                                </h4>
                            </div>
                            <div class="media flex-wrap gap-3 gap-sm-4">
                                <div class="">
                                    <img class="avatar rounded-circle avatar-70 object-fit-cover"
                                         src="{{ getStorageImages(path: $order->customer->image_full_url , type: 'backend-basic') }}"
                                         alt="{{translate('Image')}}">
                                </div>
                                <div class="media-body d-flex flex-column gap-1">
                                    <span class="text-dark">
                                        <span
                                            class="fw-semibold">{{$order->customer['f_name'].' '.$order->customer['l_name']}} </span>
                                    </span>

                                    @if($order?->customer?->email !== 'walking@customer.com')
                                        <span class="text-dark fs-12"> <span class="fw-bold">{{ $orderCount }}</span> {{translate('orders')}}</span>
                                        <span
                                            class="text-dark break-all fs-12"><span
                                                class="fw-semibold">{{$order->customer['phone']}}</span></span>
                                        <span class="text-dark break-all fs-12">{{$order->customer['email']}}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
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

    @if (isset($order->verificationImages))
        <div class="modal fade" id="order_verification_modal" tabindex="-1" aria-labelledby="order_verification_modal"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header pb-4">
                        <h3 class="mb-0">{{translate('order_verification_images')}}</h3>
                        <button type="button" class="btn-close border-0" data-dismiss="modal" aria-label="Close"><i
                                class="tio-clear"></i></button>
                    </div>
                    <div class="modal-body px-4 px-sm-5 pt-0">
                        <div class="d-flex flex-column align-items-center gap-2">
                            <div class="row gx-2">
                                @foreach ($order->verificationImages as $image)
                                    <div class="col-lg-4 col-sm-6 ">
                                        <div class="mb-2 mt-2 border-1">
                                            <img
                                                src="{{ getStorageImages(path: $image->image_full_url , type: 'backend-basic') }}"
                                                class="w-100" alt="">
                                        </div>
                                    </div>
                                @endforeach
                                <div class="col-12">
                                    <div class="d-flex justify-content-end gap-3">
                                        <button type="button" class="btn btn-secondary px-5"
                                                data-dismiss="modal">{{translate('close')}}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @if($order['order_status'] != 'delivered')
        <div class="modal fade" id="shippingAddressUpdateModal" tabindex="-1"
             aria-labelledby="shippingAddressUpdateModal"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header pb-4">
                        <h3 class="mb-0 text-center w-100">{{translate('shipping_address')}}</h3>
                        <button type="button" class="btn-close border-0" data-dismiss="modal" aria-label="Close"><i
                                class="tio-clear"></i></button>
                    </div>
                    <div class="modal-body px-4 px-sm-5 pt-0">
                        <form action="{{route('vendor.orders.address-update')}}" method="post">
                            @csrf
                            <div class="d-flex flex-column align-items-center gap-2">
                                <input name="address_type" value="shipping" hidden>
                                <input name="order_id" value="{{$order->id}}" hidden>
                                <div class="row gx-2">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="name"
                                                   class="title-color">{{translate('contact_person_name')}}</label>
                                            <input type="text" name="name" id="name" class="form-control"
                                                   value="{{$shippingAddress? $shippingAddress->contact_person_name : ''}}"
                                                   placeholder="{{ translate('ex').' '.':'.' '.translate('john_doe')}}"
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="phone_number"
                                                   class="title-color">{{translate('phone_number')}}</label>
                                            <input class="form-control form-control-user"
                                                   type="tel"
                                                   value="{{$shippingAddress ? $shippingAddress->phone  : ''}}"
                                                   name="phone_number"
                                                   placeholder="{{ translate('ex').': 017xxxxxxxx' }}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="country" class="title-color">{{translate('country')}}</label>
                                            <select name="country" id="country" class="form-control">
                                                @forelse($countries as $country)
                                                    <option
                                                        value="{{ $country['name'] }}" {{ isset($shippingAddress) && $country['name'] == $shippingAddress->country ? 'selected'  : ''}}>{{ $country['name'] }}</option>
                                                @empty
                                                    <option value="">{{ translate('No_country_to_deliver') }}</option>
                                                @endforelse
                                            </select>

                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label for="city" class="title-color">{{translate('city')}}</label>
                                            <input type="text" name="city" id="city"
                                                   value="{{$shippingAddress ? $shippingAddress->city : ''}}"
                                                   class="form-control"
                                                   placeholder="{{ translate('ex').' '.':'.' '.translate('dhaka')}}"
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label for="zip_code" class="title-color">{{translate('zip')}}</label>
                                            @if($zipRestrictStatus == 1)
                                                <select name="zip" class="form-control" data-live-search="true"
                                                        required>
                                                    @forelse($zipCodes as $code)
                                                        <option
                                                            value="{{ $code->zipcode }}"{{isset($shippingAddress) && $code->zipcode == $shippingAddress->zip ? 'selected'  : ''}}>{{ $code->zipcode }}</option>
                                                    @empty
                                                        <option value="">{{ translate('No_zip_to_deliver') }}</option>
                                                    @endforelse
                                                </select>
                                            @else
                                                <input type="text" class="form-control"
                                                       value="{{$shippingAddress ? $shippingAddress->zip  : ''}}"
                                                       id="zip"
                                                       name="zip"
                                                       placeholder="{{ translate('ex').' '.':'.' '.'1216'}}" {{$shippingAddress?'required':''}}>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-group">
                                            <label for="address" class="title-color">{{translate('address')}}</label>
                                            <textarea name="address" id="address" name="address" rows="3"
                                                      class="form-control"
                                                      placeholder="{{ translate('ex').' '.':'.' '.translate('street_1,_street_2,_street_3,_street_4')}}">{{$shippingAddress ? $shippingAddress->address : ''}}</textarea>
                                        </div>
                                    </div>
                                    <input type="hidden" id="latitude"
                                           name="latitude" class="form-control d-inline"
                                           placeholder="{{ translate('ex').' '.':'.' '.'-94.22213' }}"
                                           value="{{$shippingAddress->latitude ?? 0}}" required readonly>
                                    <input type="hidden"
                                           name="longitude" class="form-control"
                                           placeholder="{{ translate('ex').' '.':'.' '. '103.344322'}}" id="longitude"
                                           value="{{$shippingAddress->longitude??0}}" required readonly>
                                    @if(getWebConfig('map_api_status') ==1 )
                                        <div class="col-12 ">
                                            <input id="pac-input" class="form-control rounded __map-input mt-1"
                                                   title="{{translate('search_your_location_here')}}" type="text"
                                                   placeholder="{{translate('search_here')}}"/>
                                            <div class="dark-support rounded w-100 __h-200px mb-5"
                                                 id="location_map_canvas_shipping"></div>
                                        </div>
                                    @endif
                                    <div class="col-12">
                                        <div class="d-flex justify-content-end gap-3">
                                            <button type="button" class="btn btn-secondary px-5"
                                                    data-dismiss="modal">{{translate('cancel')}}</button>
                                            <button type="submit"
                                                    class="btn btn--primary px-5">{{translate('update')}}</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @if($billing)
            <div class="modal fade" id="billingAddressUpdateModal" tabindex="-1"
                 aria-labelledby="billingAddressUpdateModal"
                 aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header pb-4">
                            <h3 class="mb-0 text-center w-100">{{translate('billing_address')}}</h3>
                            <button type="button" class="btn-close border-0" data-dismiss="modal" aria-label="Close"><i
                                    class="tio-clear"></i></button>
                        </div>
                        <div class="modal-body px-4 px-sm-5 pt-0">
                            <div class="d-flex flex-column align-items-center gap-2">
                                <form action="{{route('vendor.orders.address-update')}}" method="post">
                                    @csrf
                                    <div class="d-flex flex-column align-items-center gap-2">
                                        <input name="address_type" value="billing" hidden>
                                        <input name="order_id" value="{{$order->id}}" hidden>
                                        <div class="row gx-2">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="name"
                                                           class="title-color">{{translate('contact_person_name')}}</label>
                                                    <input type="text" name="name" id="name" class="form-control"
                                                           value="{{$billing? $billing->contact_person_name : ''}}"
                                                           placeholder="{{ translate('ex') }}: {{translate('john_doe')}}"
                                                           required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="phone_number"
                                                           class="title-color">{{translate('phone_number')}}</label>
                                                    <input class="form-control form-control-user"
                                                           type="tel" value="{{$billing ? $billing->phone  : ''}}"
                                                           name="phone_number"
                                                           placeholder="{{ translate('ex').': 017xxxxxxxx' }}" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="country"
                                                           class="title-color">{{translate('country')}}</label>
                                                    <select name="country" id="country" class="form-control">
                                                        @forelse($countries as $country)
                                                            <option
                                                                value="{{ $country['name'] }}" {{ isset($billing) && $country['name'] == $billing->country ? 'selected'  : ''}}>{{ $country['name'] }}</option>
                                                        @empty
                                                            <option value="">
                                                                {{ translate('No_country_to_deliver') }}
                                                            </option>
                                                        @endforelse
                                                    </select>

                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="city" class="title-color">{{translate('city')}}</label>
                                                    <input type="text" name="city" id="city"
                                                           value="{{$billing ? $billing->city : ''}}"
                                                           class="form-control"
                                                           placeholder="{{ translate('ex') .' '.':'.' '.translate('dhaka')}}"
                                                           required>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="zip_code"
                                                           class="title-color">{{translate('zip')}}</label>
                                                    @if($zipRestrictStatus == 1)
                                                        <select name="zip" class="form-control" data-live-search="true"
                                                                required>
                                                            @forelse($zipCodes as $code)
                                                                <option
                                                                    value="{{ $code->zipcode }}"{{isset($billing) && $code->zipcode == $billing->zip ? 'selected'  : ''}}>{{ $code->zipcode }}</option>
                                                            @empty
                                                                <option
                                                                    value="">{{ translate('no_zip_to_deliver') }}</option>
                                                            @endforelse
                                                        </select>
                                                    @else
                                                        <input type="text" class="form-control"
                                                               value="{{$billing ? $billing->zip  : ''}}" id="zip"
                                                               name="zip"
                                                               placeholder="{{ translate('ex').' '.':'.' '.'1216' }}" {{$billing?'required':''}}>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="form-group">
                                                    <label for="address"
                                                           class="title-color">{{translate('address')}}</label>
                                                    <textarea name="address" id="billing_address" rows="3"
                                                              class="form-control"
                                                              placeholder="{{ translate('ex') .' '.':'.' '.translate('street_1,_street_2,_street_3,_street_4')}}">{{$billing ? $billing->address : ''}}</textarea>
                                                </div>
                                            </div>
                                            <input type="hidden" id="billing_latitude"
                                                   name="latitude" class="form-control d-inline"
                                                   placeholder="{{ translate('ex').' '.':'.' '.'-94.22213'}}"
                                                   value="{{$billing->latitude ?? 0}}" required readonly>
                                            <input type="hidden"
                                                   name="longitude" class="form-control"
                                                   placeholder="{{ translate('ex').' '.':'.' '. '103.344322'}}"
                                                   id="billing_longitude"
                                                   value="{{$billing->longitude ?? 0}}" required readonly>
                                            @if(getWebConfig('map_api_status') ==1 )
                                                <div class="col-12 ">
                                                    <input id="billing-pac-input"
                                                           class="form-control rounded __map-input mt-1"
                                                           title="{{translate('search_your_location_here')}}"
                                                           type="text"
                                                           placeholder="{{translate('search_here')}}"/>
                                                    <div class="rounded w-100 __h-200px mb-5"
                                                         id="location_map_canvas_billing"></div>
                                                </div>
                                            @endif
                                            <div class="col-12">
                                                <div class="d-flex justify-content-end gap-3">
                                                    <button type="button" class="btn btn-secondary px-5"
                                                            data-dismiss="modal">{{translate('cancel')}}</button>
                                                    <button type="submit"
                                                            class="btn btn--primary px-5">{{translate('update')}}</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif
    <div class="modal fade" id="locationModal" tabindex="-1" role="dialog" aria-labelledby="locationModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header pb-0 pt-4">
                    <button type="button" class="close position-absolute right-3 top-3" data-dismiss="modal"
                            aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-header justify-content-center pt-0 pb-0">
                    <h3 class="modal-title"
                        id="locationModalLabel">{{translate('location_data')}}</h3>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 rounded border p-3">
                            <div class="h3 text-cyan-blue text-center">{{ translate('order') }} #{{ $order->id }}</div>
                            <ul class="nav nav-tabs border-0 media-tabs nav-justified order-track-info">
                                <li class="nav-item">
                                    <div class="nav-link active-status">
                                        <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                            <div class="media-tab-media mx-sm-auto mb-3">
                                                <img
                                                    src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/order-placed.png') }}"
                                                    alt="">
                                            </div>
                                            <div class="media-body">
                                                <div class="text-sm-center text-start">
                                                    <h6 class="media-tab-title text-nowrap mb-0 text-capitalize fs-14">{{ translate('order_placed') }}</h6>
                                                </div>
                                                <div
                                                    class="d-flex align-items-center justify-content-sm-center gap-1 mt-2">
                                                <span
                                                    class="text-muted fs-12">{{date('h:i A, d M Y',strtotime($order->created_at))}}</span>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </li>


                                @if ($order['order_status']!='returned' && $order['order_status']!='failed' && $order['order_status']!='canceled')
                                    @if(!$isOrderOnlyDigital)
                                        <li class="nav-item ">
                                            <div
                                                class="nav-link {{($order['order_status']=='confirmed') || ($order['order_status']=='processing') || ($order['order_status']=='processed') || ($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered')?'active-status' : ''}}">
                                                <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                    <div class="media-tab-media mb-3 mx-sm-auto">
                                                        <img
                                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/order-confirmed.png') }}"
                                                            alt="">
                                                    </div>
                                                    <div class="media-body">
                                                        <div class="text-sm-center text-start">
                                                            <h6 class="media-tab-title text-nowrap mb-0 text-capitalize fs-14">{{ translate('order_confirmed') }}</h6>
                                                        </div>
                                                        @if(($order['order_status']=='confirmed') || ($order['order_status']=='processing') || ($order['order_status']=='processed') || ($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered') && \App\Utils\order_status_history(order: $order, status: 'confirmed'))
                                                            <div
                                                                class="d-flex align-items-center justify-content-sm-center mt-2 gap-1">
                                                            <span class="text-muted fs-12">
                                                                {{ date('h:i A, d M Y', strtotime(\App\Utils\order_status_history(order: $order, status: 'confirmed')))}}
                                                            </span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                        <li class="nav-item">
                                            <div
                                                class="nav-link {{($order['order_status']=='processing') || ($order['order_status']=='processed') || ($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered')?'active-status' : ''}}">
                                                <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                    <div class="media-tab-media mb-3 mx-sm-auto">
                                                        <img alt=""
                                                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/shipment.png') }}">
                                                    </div>
                                                    <div class="media-body">
                                                        <div class="text-sm-center text-start">
                                                            <h6 class="media-tab-title text-nowrap mb-0 text-capitalize fs-14">
                                                                {{ translate('preparing_shipment') }}
                                                            </h6>
                                                        </div>
                                                        @if( ($order['order_status']=='processing') || ($order['order_status']=='processed') || ($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered')  && \App\Utils\order_status_history(order: $order, status: 'processing'))
                                                            <div
                                                                class="d-flex align-items-center justify-content-sm-center mt-2 gap-2">
                                                            <span class="text-muted fs-12">
                                                                {{date('h:i A, d M Y',strtotime(\App\Utils\order_status_history(order: $order, status: 'processing')))}}
                                                            </span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                        <li class="nav-item">
                                            <div
                                                class="nav-link {{($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered')?'active-status' : ''}}">
                                                <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                    <div class="media-tab-media mb-3 mx-sm-auto">
                                                        <img
                                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/on-the-way.png') }}"
                                                            alt="">
                                                    </div>
                                                    <div class="media-body">
                                                        <div class="text-sm-center text-start">
                                                            <h6 class="media-tab-title text-nowrap mb-0 fs-14">{{ translate('order_is_on_the_way') }}</h6>
                                                        </div>
                                                        @if( ($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered') && \App\Utils\order_status_history(order: $order, status: 'out_for_delivery'))
                                                            <div
                                                                class="d-flex align-items-center justify-content-sm-center mt-2 gap-2">
                                                            <span class="text-muted fs-12">
                                                                {{date('h:i A, d M Y',strtotime(\App\Utils\order_status_history(order: $order, status: 'out_for_delivery')))}}
                                                            </span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                        <li class="nav-item">
                                            <div
                                                class="nav-link {{($order['order_status']=='delivered')?'active-status' : ''}}">
                                                <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                    <div class="media-tab-media mb-3 mx-sm-auto">
                                                        <img
                                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/delivered.png') }}"
                                                            alt="">
                                                    </div>
                                                    <div class="media-body">
                                                        <div class="text-sm-center text-start">
                                                            <h6 class="media-tab-title text-nowrap mb-0 fs-14">{{ translate('order_Shipped') }}</h6>
                                                        </div>
                                                        @if(($order['order_status']=='delivered') && \App\Utils\order_status_history(order: $order, status: 'delivered'))
                                                            <div
                                                                class="d-flex align-items-center justify-content-sm-center mt-2 gap-2">
                                                            <span class="text-muted fs-12">
                                                                {{date('h:i A, d M Y',strtotime(\App\Utils\order_status_history(order: $order, status: 'delivered')))}}
                                                            </span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    @else

                                            <?php
                                            $digitalProductProcessComplete = true;
                                            foreach ($order->orderDetails as $detail) {
                                                $productData = json_decode($detail->product_details);
                                                if (isset($productData->digital_product_type) && $productData->digital_product_type == 'ready_after_sell' && $detail->digital_file_after_sell == null) {
                                                    $digitalProductProcessComplete = false;
                                                }
                                            }
                                            ?>

                                        <li class="nav-item">
                                            <div
                                                class="nav-link {{ ($order['order_status']=='confirmed') ? 'active-status' : ''}}">
                                                <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                    <div class="media-tab-media mb-3 mx-sm-auto">
                                                        <img alt=""
                                                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/shipment.png') }}">
                                                    </div>
                                                    <div class="media-body">
                                                        <div class="text-sm-center text-start">
                                                            <h6 class="media-tab-title text-nowrap mb-0 text-capitalize fs-14">
                                                                {{ translate('processing') }}
                                                            </h6>
                                                        </div>
                                                        @if($order['order_status']=='confirmed' && \App\Utils\order_status_history(order: $order, status: 'confirmed'))
                                                            <div
                                                                class="d-flex align-items-center justify-content-sm-center mt-2 gap-2">
                                                            <span class="text-muted fs-12">
                                                                {{date('h:i A, d M Y',strtotime(\App\Utils\order_status_history(order: $order, status: 'confirmed')))}}
                                                            </span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                        <li class="nav-item">
                                            <div
                                                class="nav-link {{($order['order_status']=='confirmed' && $digitalProductProcessComplete)?'active-status' : ''}}">
                                                <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                    <div class="media-tab-media mb-3 mx-sm-auto">
                                                        <img
                                                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/delivered.png') }}"
                                                            alt="">
                                                    </div>
                                                    <div class="media-body">
                                                        <div class="text-sm-center text-start">
                                                            <h6 class="media-tab-title text-nowrap mb-0 fs-14">{{ translate('delivery_complete') }}</h6>
                                                        </div>

                                                        @if(($order['order_status']=='confirmed') && $digitalProductProcessComplete && \App\Utils\order_status_history(order: $order, status: 'confirmed'))
                                                            <div
                                                                class="d-flex align-items-center justify-content-sm-center mt-2 gap-2">
                                                            <span class="text-muted fs-12">
                                                                {{date('h:i A, d M Y',strtotime(\App\Utils\order_status_history(order: $order, status: 'confirmed')))}}
                                                            </span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    @endif
                                @elseif(in_array($order['order_status'], ['returned', 'canceled']))
                                    <li class="nav-item">
                                        <div class="nav-link active-status">
                                            <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                <div class="media-tab-media mx-sm-auto mb-3">
                                                    <img
                                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/'.$order['order_status'].'.png') }}"
                                                        alt="">
                                                </div>
                                                <div class="media-body">
                                                    <div class="text-sm-center text-start">
                                                        <h6 class="media-tab-title text-nowrap mb-0 text-capitalize fs-14">
                                                            {{ translate('order') }} {{ translate($order['order_status']) }}
                                                        </h6>
                                                    </div>
                                                    @if(\App\Utils\order_status_history(order: $order, status: $order['order_status']))
                                                        <div
                                                            class="d-flex align-items-center justify-content-sm-center gap-1 mt-2">
                                                        <span class="text-muted fs-12">
                                                            {{ date('h:i A, d M Y', strtotime(\App\Utils\order_status_history(order: $order, status: $order['order_status']))) }}
                                                        </span>
                                                        </div>
                                                    @endif

                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                @else
                                    <li class="nav-item">
                                        <div class="nav-link active-status">
                                            <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                <div class="media-tab-media mx-sm-auto mb-3">
                                                    <img
                                                        src="{{ dynamicAsset(path: 'public/assets/back-end/img/track-order/order-failed.png') }}"
                                                        alt="">
                                                </div>
                                                <div class="media-body">
                                                    <div class="text-sm-center text-start">
                                                        <h6 class="media-tab-title text-nowrap mb-0 text-capitalize fs-14">{{ translate('Failed_to_Deliver') }}</h6>
                                                    </div>
                                                    <div
                                                        class="d-flex align-items-center justify-content-sm-center gap-1 mt-2">
                                                <span class="text-muted fs-12">
                                                    {{ translate('sorry_we_can_not_complete_your_order') }}
                                                </span>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                    </li>
                                @endif
                            </ul>
                        </div>

                        <div class="col-md-12 modal_body_map mt-5 ps-0 pe-0">
                            <div class="mb-2">
                                <img src="{{ dynamicAsset('assets/back-end/img/location-blue.png') }}" alt="">
                                <span>{{ $shippingAddress ? $shippingAddress->address : ($billing ? $billing->address : '') }}</span>
                            </div>
                            <div class="location-map" id="location-map">
                                <div class="w-100 h-200" id="location_map_canvas"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal" id="third_party_delivery_service_modal" role="dialog" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{translate('update_third_party_delivery_info')}}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12">
                            <form action="{{route('vendor.orders.update-deliver-info')}}" method="POST">
                                @csrf
                                <input type="hidden" name="order_id" value="{{$order['id']}}">
                                <div class="card-body">
                                    <div class="form-group">
                                        <label for="">{{translate('delivery_service_name')}}</label>
                                        <input class="form-control" type="text" name="delivery_service_name"
                                               value="{{$order['delivery_service_name']}}" id="" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="">{{translate('tracking_id')}} ({{translate('optional')}})</label>
                                        <input class="form-control" type="text" name="third_party_delivery_tracking_id"
                                               value="{{$order['third_party_delivery_tracking_id']}}" id="">
                                    </div>
                                    <button class="btn btn--primary" type="submit">{{translate('update')}}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="changeOrderStatusModal" tabindex="-1" aria-labelledby="changeOrderStatusModal"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 p-2 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                            style="--size: 2rem;" data-dismiss="modal" aria-label="Close">
                        <i class="fi fi-sr-cross"></i>
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/modal/warning-new.png') }}"
                             width="70" class="aspect-1 mb-20" id="" alt="">
                        <h3 class="modal-title mb-3" id="">{{ translate('Are_you_sure') }}?</h3>
                        <div class="text-center" id="">
                            {{ translate('you_want_to_update_the_status_of_this_order?_this_action_will_notify_the_customer_and_cannot_be_undone.') }}
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn--primary min-w-120"
                                data-dismiss="modal">{{ translate('Yes_change_it!') }}</button>
                        <button type="button" class="btn btn-danger min-w-120"
                                data-dismiss="modal">{{ translate('Cancel') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade pt-5" id="quick-view" tabindex="-1" style="z-index:2000;">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" id="quick-view-modal"></div>
        </div>
    </div>

    @include('vendor-views.order.partials.modal.order-edit-due-amount-mark-as-paid',['order' => $order])
    @include('vendor-views.order.partials.modal.order-edit-due-amount-switch-to-cod',['order' => $order])

    @include('vendor-views.order.partials.modal._confirm-edit-order')
    @include('vendor-views.order.partials.offcanvas._edit-products-offcanvas')

    <span id="payment-status-message" data-title="{{ translate('confirm_payments_before_change_the_status.') }}"
          data-message="{{ translate('Change the status to Paid only when you receive the customers payment and have verified it.') }}"></span>

    <span id="message-status-title-text" data-text="{{ translate("are_you_sure_change_this") }}"></span>
    <span id="message-status-subtitle-text" data-text="{{ translate("you_will_not_be_able_to_revert_this") }}!"></span>
    <span id="message-status-confirm-text" data-text="{{ translate("yes_change_it") }}!"></span>
    <span id="message-status-cancel-text" data-text="{{ translate("cancel") }}"></span>
    <span id="message-status-success-text" data-text="{{ translate("status_change_successfully") }}"></span>
    <span id="message-status-warning-text"
          data-text="{{ translate("account_has_been_deleted_you_can_not_change_the_status") }}"></span>

    <span id="message-order-status-delivered-text"
          data-text="{{ translate("order_is_already_delivered_you_can_not_change_it") }}!"></span>
    <span id="message-order-status-paid-first-text"
          data-text="{{ translate("before_delivered_you_need_to_make_payment_status_paid") }}!"></span>
    <span id="order-status-url" data-url="{{ route('vendor.orders.status') }}"></span>
    <span id="payment-status-url" data-url="{{ route('vendor.orders.payment-status') }}"></span>

    <span id="message-deliveryman-add-success-text"
          data-text="{{ translate("delivery_man_successfully_assigned/changed") }}"></span>
    <span id="message-deliveryman-add-error-text"
          data-text="{{ translate("deliveryman_man_can_not_assign_or_change_in_that_status") }}"></span>
    <span id="message-deliveryman-add-invalid-text"
          data-text="{{ translate("deliveryman_man_can_not_assign_or_change_in_that_status") }}"></span>
    <span id="delivery-type" data-type="{{ $order->delivery_type }}"></span>
    <span id="add-delivery-man-url" data-url="{{url('/vendor/orders/add-delivery-man/'.$order['id'])}}/"></span>

    <span id="message-deliveryman-charge-success-text"
          data-text="{{ translate("deliveryman_charge_add_successfully") }}"></span>
    <span id="message-deliveryman-charge-error-text"
          data-text="{{ translate("failed_to_add_deliveryman_charge") }}"></span>
    <span id="message-deliveryman-charge-invalid-text" data-text="{{ translate("add_valid_data") }}"></span>
    <span id="add-date-update-url" data-url="{{route('vendor.orders.amount-date-update')}}"></span>

    <span id="customer-name" data-text="{{$order->customer['f_name']??""}} {{$order->customer['l_name']??""}}}"></span>
    <span id="is-shipping-exist" data-status="{{$shippingAddress ? 'true':'false'}}"></span>
    <span id="shipping-address" data-text="{{$shippingAddress->address??''}}"></span>
    <span id="shipping-latitude" data-latitude="{{$shippingAddress->latitude??'-33.8688'}}"></span>
    <span id="shipping-longitude" data-longitude="{{$shippingAddress->longitude??'151.2195'}}"></span>
    <span id="billing-latitude" data-latitude="{{$billing->latitude??'-33.8688'}}"></span>
    <span id="billing-longitude" data-longitude="{{$billing->longitude??'151.2195'}}"></span>
    <span id="location-icon"
          data-path="{{dynamicAsset(path: 'public/assets/front-end/img/customer_location.png')}}"></span>
    <span id="customer-image"
          data-path="{{dynamicStorage(path: 'storage/app/public/profile/')}}{{$order->customer->image??""}}"></span>
    <span id="deliveryman-charge-alert-message"
          data-message="{{translate('when_order_status_delivered_you_can`t_update_the_delivery_man_incentive').'.'}}"></span>
    <span id="payment-status-alert-message"
          data-message="{{translate('when_payment_status_paid_then_you_can`t_change_payment_status_paid_to_unpaid').'.'}}"></span>
    <span id="get-search-product-for-edit-order"
          data-action="{{ route('vendor.orders.search-for-edit-order-product') }}"
          data-order-id="{{ $order['id'] }}"></span>
    <span id="edit-order-product-modal-view"
          data-action="{{ route('vendor.orders.edit-order-product-modal-view') }}"></span>
@endsection
@push('script')
    @if(getWebConfig('map_api_status') ==1 )
        <script
            src="https://maps.googleapis.com/maps/api/js?key={{getWebConfig('map_api_key')}}&callback=mapCallBackFunction&loading=async&libraries=places&v=3.56"
            defer>
        </script>
    @endif

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf-worker.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/multiple-document-upload.js') }}"></script>

    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/vendor/order.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/order-edit.js') }}"></script>
    <script>
        openOffcanvasAfterModal('#confirm-edit-order', '#confirm-edit-order-modal', '#offcanvasEditProducts');

        document.addEventListener('DOMContentLoaded', function () {
            const toggleBtn = document.getElementById('paymentInfoCollapse');
            const paymentSection = document.querySelector('.payment-logs-info-section');

            if (!toggleBtn || !paymentSection) return;

            toggleBtn.addEventListener('click', function () {
                const isExpanded = paymentSection.classList.toggle('expanded');

                toggleBtn.innerText = isExpanded
                    ? "{{ translate('See_Less') }}"
                    : "{{ translate('See_More') }}";
            });
        });
    </script>
@endpush
