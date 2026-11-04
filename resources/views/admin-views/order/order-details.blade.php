@extends('layouts.admin.app')

@section('title', translate('order_Details'))

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
                <a href="{{ $previousOrder ? route('admin.orders.details', [$previousOrder['id']]) : 'javascript:' }}"
                   class="btn btn-circle text-primary bg-primary bg-opacity-10 border-0 {{ $previousOrder ? '' : 'disabled opacity-40' }}">
                    <i class="fi fi-sr-angle-left d-flex"></i>
                </a>
                <a href="{{ $nextOrder ? route('admin.orders.details', [$nextOrder['id']]) : 'javascript:' }}"
                   class="btn btn-circle text-primary bg-primary bg-opacity-10 border-0 {{ $nextOrder ? '' : 'disabled opacity-40' }}">
                    <i class="fi fi-sr-angle-right d-flex"></i>
                </a>
            </div>
        </div>

        <div class="row g-3" id="printableArea">
            <div class="col-xl-8">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex flex-wrap flex-md-nowrap gap-10 justify-content-between mb-4">
                            <div class="d-flex flex-column gap-3 flex-1">
                                <h4 class="text-capitalize fs-16 fw-bold d-flex align-items-center gap-3 flex-wrap mb-0">
                                    <span class="d-flex align-items-center gap-2">
                                        <span>
                                            {{ translate('Order_Details') }} #{{ $order['id'] }}
                                        </span>

                                        @if($order?->edited_status == 1)
                                            <span class="badge badge-info text-bg-info">
                                                {{ translate('Edited') }}
                                            </span>
                                        @endif
                                    </span>
                                </h4>
                                <div class="fs-12">
                                    {{ date('d M, Y , h:i A', strtotime($order['created_at'])) }}
                                </div>

                                <div class="d-flex flex-column gap-3">
                                    <div class="d-flex flex-wrap gap-2">
                                        @if ($linkedOrders->count() >0)
                                            <div class="text-dark fs-12 d-flex align-items-center rounded">
                                                {{translate('linked_orders')}}
                                                ({{ $linkedOrders->count()}}) :
                                            </div>
                                            @foreach($linkedOrders as $linked)
                                                <a href="{{ route('admin.orders.details', [$linked['id']]) }}"
                                                   class="text-primary text-underline fw-bold">#{{$linked['id'] }}</a>
                                            @endforeach
                                        @endif
                                    </div>
                                    @if (!is_null($order['order_note']) && !empty($order['order_note']))
                                        <div
                                            class="bg-section fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                        <span>
                                            # {{ translate('Note') }}:
                                            {{ \Illuminate\Support\Str::limit($order['order_note'], 250) }}
                                            @if(strlen($order['order_note']) > 250)
                                                <a href="javascript:void(0)"
                                                   class="text-primary ms-1"
                                                   data-bs-toggle="modal"
                                                   data-bs-target="#orderNoteModal">
                                                    {{ translate('Read more') }}
                                                </a>

                                                <div class="modal fade" id="orderNoteModal" tabindex="-1">
                                                    <div class="modal-dialog modal-dialog-centered modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h4 class="modal-title mb-0">{{ translate('Order Note') }}</h4>
                                                                <button type="button" class="btn-close me-0"
                                                                        data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p class="mb-0">{{ $order['order_note'] }}</p>
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
                                            class="bg-success bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
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
                                    @if ($order->verificationImages && count($order->verificationImages)>0 && $order->verification_status ==1)
                                        <div>
                                            <button class="btn btn-primary btn-sm px-4" data-bs-toggle="modal"
                                                    data-bs-target="#order_verification_modal">
                                                <i class="fi fi-sr-shield-trust"></i> {{ translate('order_verification') }}
                                            </button>
                                        </div>
                                    @endif

                                    @if($isOrderEditable['status'] === true)
                                        <buton type="button" class="btn btn-outline-primary btn-sm"
                                               data-bs-toggle="modal"
                                               data-bs-target="#confirm-edit-order-modal">
                                            <i class="fi fi-sr-pencil"></i> {{ translate('Edit_Products') }}
                                        </buton>
                                    @else
                                        <buton type="button" class="btn btn-outline-primary btn-sm opacity-50"
                                               data-bs-toggle="tooltip" data-bs-placement="top"
                                               data-bs-title="{{ $isOrderEditable['message'] }}" disabled>
                                            <i class="fi fi-sr-pencil"></i> {{ translate('Edit_Products') }}
                                        </buton>
                                    @endif

                                    <a class="btn btn-primary btn-sm" target="_blank"
                                       href={{ route('admin.orders.generate-invoice', [$order['id']]) }}>
                                        <img alt="" class="me-1"
                                             src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/uil_invoice.svg') }}">
                                        {{translate('print_Invoice')}}
                                    </a>
                                </div>
                                <div class="d-flex flex-column gap-2 mt-3">
                                    <div
                                        class="order-status d-flex justify-content-sm-end gap-10 text-capitalize fs-12">
                                        <span class="text-dark">{{translate('status')}}: </span>
                                        @if($order['order_status'] == 'pending')
                                            <x-k.badge tone="info">{{translate(str_replace('_',' ',$order['order_status']))}}</x-k.badge>
                                        @elseif($order['order_status']=='failed')
                                            <x-k.badge tone="danger">{{translate('Failed to Deliver')}}</x-k.badge>
                                        @elseif($order['order_status']=='processing' || $order['order_status']=='out_for_delivery')
                                            <x-k.badge tone="warning">{{translate(str_replace('_',' ',$order['order_status'] == 'processing' ? 'Packaging' : $order['order_status']))}}</x-k.badge>
                                        @elseif($order['order_status']=='delivered' || $order['order_status']=='confirmed')
                                            <x-k.badge tone="success">{{translate(str_replace('_',' ',$order['order_status']))}}</x-k.badge>
                                        @else
                                            <x-k.badge tone="danger">{{translate(str_replace('_',' ',$order['order_status']))}}</x-k.badge>
                                        @endif
                                    </div>

                                    @if(getWebConfig('order_verification'))
                                        <span class="d-flex justify-content-sm-end gap-10 fs-12">
                                            <b>
                                                {{translate('order_verification_code')}} : {{$order['verification_code'] }}
                                            </b>
                                        </span>
                                    @endif

                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
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
                                @php($discount=0)
                                @php($row=0)
                                @foreach($order->details as $key=>$detail)
                                    @php($isProductUnavailable = $detail->productAllStatus === null)
                                    @php($productDetails = $detail?->productAllStatus ?? json_decode($detail->product_details, true))
                                    @if($productDetails)
                                        <tr class="">
                                            <td><span class="k-num">{{ ++$row }}</span></td>
                                            <td>
                                                <div class="{{ $isProductUnavailable ? 'table-row-disabled' : '' }}"
                                                     data-bs-toggle="tooltip"
                                                     title="{{ $isProductUnavailable ? translate('This_product_has_been_deleted') : '' }}">

                                                    <div class="media align-items-center gap-10">
                                                        <a target="_blank"
                                                           href="{{ route('admin.products.view', ['addedBy' => $productDetails['added_by'] == 'seller' ? 'vendor' : 'in-house', 'id' => $productDetails['id']]) }}">
                                                            <img class="avatar avatar-60 rounded img-fit"
                                                                 src="{{ getStorageImages(path:$detail?->productAllStatus?->thumbnail_full_url, type: 'backend-product') }}"
                                                                 alt="{{translate('image_Description')}}">
                                                        </a>
                                                        <div>
                                                            <a target="_blank"
                                                               class="text-dark fs-12 fw-bold"
                                                               href="{{ route('admin.products.view', ['addedBy' => $productDetails['added_by'] == 'seller' ? 'vendor' : 'in-house', 'id' => $productDetails['id']]) }}"
                                                               @if(!$isProductUnavailable && strlen($productDetails['name']) > 30)
                                                                   data-bs-toggle="tooltip"
                                                               title="{{ $productDetails['name'] }}"
                                                                @endif
                                                            >
                                                                {{ Str::limit($productDetails['name'], 30) }}
                                                            </a>

                                                            <div class="fs-10">
                                                                <strong>{{translate('unit_price')}} :</strong>
                                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['price'])) }}
                                                            </div>
                                                            @if ($detail->variant)
                                                                <div class="max-w-150px text-wrap fs-10">
                                                                    <strong>{{translate('variation')}} :</strong>
                                                                    {{$detail['variant']}}
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    @if(isset($productDetails['digital_product_type']) && $productDetails['digital_product_type'] == 'ready_after_sell')
                                                        <button type="button" class="btn btn-sm btn-primary mt-2"
                                                                title="{{translate('file_upload')}}"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#fileUploadModal-{{ $detail->id }}">
                                                            <i class="fi fi-rr-file"></i> {{translate('file')}}
                                                        </button>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="k-table__num"><span class="k-num">{{$detail['qty']}}</span></td>
                                            <td class="k-table__num">
                                                <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['price']*$detail['qty']), currencyCode: getCurrencyCode()) }}</span>
                                            </td>
                                            <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $detail['discount']), currencyCode: getCurrencyCode())}}</span></td>
                                            @php($subtotal=($detail['price']*$detail['qty'])-$detail['discount'])
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
                                @php($productDetails = $detail?->productAllStatus ?? json_decode($detail->product_details, true))
                                @if(isset($productDetails['digital_product_type']) && $productDetails['digital_product_type'] == 'ready_after_sell')
                                    @php($product_details = json_decode($detail?->product_details, true))
                                    <div class="modal fade" id="fileUploadModal-{{ $detail->id }}" tabindex="-1"
                                         aria-labelledby="exampleModalLabel" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <form
                                                    action="{{ route('admin.orders.digital-file-upload-after-sell') }}"
                                                    method="post" enctype="multipart/form-data"
                                                    class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate"
                                                    novalidate="novalidate">
                                                    @csrf
                                                    <div class="modal-header border-0 px-2 pt-2 pb-0 d-flex justify-content-end">
                                                        <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                                                                style="--size: 1.5rem;" data-bs-dismiss="modal" aria-label="Close">
                                                            <i class="fi fi-sr-cross-small fs-16 d-flex"></i>
                                                        </button>
                                                    </div>
                                                    <div class="modal-body pt-0">
                                                        @if(($product_details['added_by'] == 'admin') && $detail->seller_id == 1)
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
                                                        @else
                                                            <h4 class="mt-3 text-center">{{translate('admin_have_no_permission_for_vendors_digital_product_upload')}}</h4>
                                                        @endif
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary"
                                                                data-bs-dismiss="modal">{{translate('close')}}</button>
                                                        @if(($product_details['added_by'] == 'admin') && $detail->seller_id == 1)
                                                            <button type="submit"
                                                                    class="btn btn-primary">{{translate('upload')}}</button>
                                                        @endif
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <hr/>
                        @php($orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order))
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

                                    @if($order?->edited_status)
                                        <div class="d-flex justify-content-end mt-2">
                                            <div
                                                class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center w-100">
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

                        @if(!empty($order?->orderEditHistory) && count($order?->orderEditHistory) > 0)
                            <hr/>
                            <div>
                                <h4 class="fs-14 fw-bold mb-3">{{ translate('Edit Log') }}</h4>

                                <div class="d-flex flex-column gap-20">
                                    @foreach($order?->orderEditHistory as $orderEditHistory)
                                        <div>
                                            <div class="fs-12 mb-2">
                                                {{ translate('Order Edited by') }} {{ $orderEditHistory['edited_user_name'] }}
                                                ({{ translate($orderEditHistory['edit_by']) }})
                                            </div>
                                            <div class="fs-12 mb-0 text-dark">
                                                {{ $orderEditHistory?->created_at->format('d M, Y h:i') }}
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

                <div class="card card-body">
                    <h4 class="fw-bold d-flex align-items-center gap-2 mb-3">
                        {{ translate('Payment') }}
                        @if($order['payment_status']=='paid')
                            <span
                                class="badge badge-success text-bg-success fw-semibold rounded d-flex align-items-center py-1 px-2">
                                {{ translate('Paid') }}
                            </span>
                        @else
                            <span
                                class="badge badge-danger text-bg-danger fw-semibold rounded d-flex align-items-center py-1 px-2">
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
                                        <span class="fw-semibold">
                                            @if(strlen($order?->payment_note) > 250)
                                                {{ Str::limit($order?->payment_note, 250) }}
                                                <a href="javascript:void(0)"
                                                   class="text-primary ms-1"
                                                   data-bs-toggle="modal"
                                                   data-bs-target="#paymentNoteModal">
                                                   {{ translate('Read more') }}
                                                </a>
                                            @else
                                                {{ $order?->payment_note ?? 'N/A' }}
                                            @endif
                                        </span>
                                    </div>
                                @endif
                                <div class="modal fade" id="paymentNoteModal" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">{{ translate('Payment Note') }}</h5>
                                                <button type="button" class="btn-close"
                                                        data-bs-dismiss="modal"></button>
                                            </div>

                                            <div class="modal-body">
                                                <p class="mb-0" style="white-space: pre-wrap;">
                                                    {{ $order?->payment_note ?? 'N/a' }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if ($order['payment_method'] == 'offline_payment' && $order['payment_status'] == 'unpaid')
                            <div class="row g-2 mt-2">
                                <div class="col-12">
                                    <button type="button"
                                            class="btn btn-primary btn-sm w-100"
                                            data-bs-toggle="modal"
                                            data-bs-target="#markAsPaidModal-{{ $order['id'] }}">
                                        {{ translate('Mark_as_Paid') }}
                                    </button>
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
                                            class="badge badge-success text-bg-success fw-semibold rounded d-flex align-items-center py-1 px-2 w-max-content">
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
                                            class="badge badge-success text-bg-success fw-semibold rounded d-flex align-items-center py-1 px-2 w-max-content">
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
                                            class="badge badge-danger text-bg-danger fw-semibold rounded d-flex align-items-center py-1 px-2 w-max-content">
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
                                            class="badge badge-danger text-bg-danger fw-semibold rounded d-flex align-items-center py-1 px-2 w-max-content">
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
                                                        <span class="fw-semibold">
                                                        @if($orderDuePaymentInfoKey === 'payment_note' && strlen($orderDuePaymentInfo) > 250)
                                                                {{ Str::limit($orderDuePaymentInfo, 250) }}
                                                                <a href="javascript:void(0)"
                                                                   class="text-primary ms-1"
                                                                   data-bs-toggle="modal"
                                                                   data-bs-target="#paymentNoteModal-{{$orderDuePaymentInfoKey}}">
                                                                    {{ translate('Read more') }}
                                                                    </a>
                                                            @else
                                                                {{ $orderDuePaymentInfo ?? 'N/A' }}
                                                            @endif
                                                        </span>
                                                    </div>
                                                @endif
                                                <div class="modal fade"
                                                     id="paymentNoteModal-{{ $orderDuePaymentInfoKey }}" tabindex="-1"
                                                     aria-hidden="true">
                                                    <div
                                                        class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">{{ translate('Payment_Note') }}</h5>
                                                                <button type="button" class="btn-close"
                                                                        data-bs-dismiss="modal"></button>
                                                            </div>

                                                            <div class="modal-body">
                                                                <p class="mb-0" style="white-space: pre-wrap;">
                                                                    {{ $order?->latestEditHistory?->order_due_payment_info['payment_note'] ?? 'N/A' }}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="row g-2 mt-2">
                                        <div class="col-12">
                                            <button type="button"
                                                    class="btn btn-primary btn-sm w-100"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#markAsPaidModal-{{ $order['id'] }}">
                                                {{ translate('Mark_as_Paid') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif
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

                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0 text-center fw-bold">
                            {{ translate('order_&_Shipping_Info') }}
                        </h3>

                        @if($order?->shipping_responsibility == 'sellerwise_shipping')
                            <span class="d-block fs-12 mt-1 text-center">
                                ({{ translate('Order Placed Via Vendor-wise Shipping') }})
                            </span>
                        @endif
                    </div>
                    <div class="card-body d-flex flex-column gap-4">
                        <?php
                        $paymentMethod = $order?->payment_method;
                        $editDueAmount = $order['edit_due_amount'] ?? 0;
                        $editReturnAmount = $order['edit_return_amount'] ?? 0;
                        ?>
                        @if ($editDueAmount > 0 && $order?->latestEditHistory?->order_due_payment_status === 'unpaid' && $order?->latestEditHistory?->order_due_payment_method != '')
                            <div class="bg-section rounded-10 p-12 p-sm-20 text-center">
                                <h4 class="text-danger mb-3">{{ translate('Amount_Due') }}</h4>
                                <h3 class="fw-bold">
                                    {{ webCurrencyConverter(amount: $editDueAmount) }}
                                </h3>
                                <p class="fs-12 mb-0">
                                    {{ translate('after_editing,_the_order_amount_has_increased.') }}
                                    {{ translate('please_collect_the_due_amount_and_mark_the_order_as_paid.') }}
                                </p>
                                <button type="button"
                                        class="btn btn-primary mt-3"
                                        data-bs-toggle="modal"
                                        data-bs-target="#markAsPaidModal-{{ $order['id'] }}">
                                    {{ translate('Mark_as_Paid') }}
                                </button>
                            </div>
                        @elseif ($editDueAmount > 0 && !in_array($paymentMethod, ['cash_on_delivery']) && $order?->latestEditHistory?->order_due_payment_method != "cash_on_delivery")
                            <div class="bg-section rounded-10 p-12 p-sm-20 text-center">
                                <h4 class="text-danger mb-3">{{ translate('Amount_Due') }}</h4>
                                <h3 class="fw-bold">
                                    {{ webCurrencyConverter(amount: $editDueAmount) }}
                                </h3>
                                <p class="fs-12 mb-0">
                                    {{ translate('after_editing,_the_order_amount_has_increased.') }}
                                    {{ translate('to_collect_the_due_amount,_switch_the_payment_method_to_cash_on_delivery_(cod)_or_contact_with_customer_to_pay_the_bill_from_order_details_page') }}
                                </p>
                                <button type="button"
                                        class="btn btn-primary mt-3"
                                        data-bs-toggle="modal"
                                        data-bs-target="#switchToCODModal">
                                    {{ translate('Switch_to_COD') }}
                                </button>
                            </div>
                        @elseif ($editReturnAmount > 0)
                            <div class="bg-section rounded-10 p-12 p-sm-20 text-center">
                                <h4 class="text-danger mb-3">{{ translate('Need_to_Return') }}</h4>
                                <h3 class="fw-bold">
                                    {{ webCurrencyConverter(amount: $editReturnAmount) }}
                                </h3>
                                <p class="fs-12 mb-0">
                                    {{ translate('after_editing,_the_order_amount_has_decreased.') }}
                                    {{ translate('after_returning_the_amount_to_customer,_make_sure_to_update_the_status_to_paid.') }}
                                </p>
                                <button type="button"
                                        class="btn btn-primary mt-3"
                                        data-bs-toggle="modal"
                                        data-bs-target="#returnDueAmountModal-{{ $order['id'] }}">
                                    {{ translate('Return_Due_Amount') }}
                                </button>
                            </div>
                        @endif
                        <div class="text-capitalize">
                            <label
                                class="form-label fw-bold mb-2">{{translate('change_order_status')}}</label>
                            <div class="select-wrapper">
                                <select name="order_status" id="order_status"
                                        class="status form-select" data-id="{{$order['id']}}">

                                    <option
                                        value="pending" {{$order->order_status == 'pending'?'selected':''}} > {{translate('pending')}}</option>
                                    <option
                                        value="confirmed" {{$order->order_status == 'confirmed'?'selected':''}} > {{translate('confirmed')}}</option>
                                    <option
                                        value="processing" {{$order->order_status == 'processing'?'selected':''}} >{{translate('packaging')}} </option>
                                    <option class="text-capitalize"
                                            value="out_for_delivery" {{$order->order_status == 'out_for_delivery'?'selected':''}} >{{translate('out_for_delivery')}} </option>
                                    <option
                                        value="delivered" {{$order->order_status == 'delivered'?'selected':''}} >{{translate('delivered')}} </option>
                                    <option
                                        value="returned" {{$order->order_status == 'returned'?'selected':''}} > {{translate('returned')}}</option>
                                    <option
                                        value="failed" {{$order->order_status == 'failed'?'selected':''}} >{{translate('failed_to_Deliver')}} </option>
                                    <option
                                        value="canceled" {{$order->order_status == 'canceled'?'selected':''}} >{{translate('canceled')}} </option>
                                </select>
                            </div>
                        </div>
                        <div
                            class="d-flex justify-content-between align-items-center gap-10 form-control py-10 h-auto flex-wrap  text-capitalize">
                            <span class="text-dark">
                                {{translate('payment_status')}}
                            </span>
                            <div class="d-flex justify-content-end align-items-center gap-2">
                                <span
                                    class="text-primary fw-bold">{{ $order->payment_status=='paid' ? translate('paid'):translate('unpaid')}}</span>
                                <label
                                    class="switcher payment-status-text">
                                    <input class="switcher_input payment-status" type="checkbox" name="status"
                                           data-id="{{$order->id}}"
                                           value="{{$order->payment_status}}"
                                        {{ $order->payment_status == 'paid' ? 'checked':''}} >
                                    <span class="switcher_control switcher_control_add
                                        {{ $order->payment_status=='paid' ? 'checked':'unchecked'}}"></span>
                                </label>
                            </div>
                        </div>
                        <?php $disableDeliveryType = !$physicalProduct && $shippingAddress; ?>
                        @if($physicalProduct || $shippingAddress)
                            <ul class="list-unstyled list-unstyled-py-4 d-flex flex-column gap-4 mb-0 pe-0">
                                <li class="">
                                    @if ($order->shipping_type == 'order_wise')
                                        <label class="form-label fw-bold mb-2">
                                            {{translate('shipping_Method')}}
                                            ({{$order->shipping ? translate(str_replace('_',' ',$order->shipping->title)) :translate('no_shipping_method_selected')}}
                                            )
                                        </label>
                                    @endif
                                    <div class="select-wrapper"
                                         @if($disableDeliveryType)
                                             data-bs-toggle="tooltip"
                                         data-bs-placement="top"
                                         title="{{ translate('You cannot select delivery type as the order only contain digital product') }}"
                                        @endif
                                    >
                                        <select class="form-select"
                                                name="delivery_type"
                                                id="choose_delivery_type"
                                            {{ ($order->order_status == 'delivered' || $disableDeliveryType) ? 'disabled' : '' }}>
                                            <option value="0">
                                                {{ translate('choose_delivery_type') }}
                                            </option>
                                            <option value="self_delivery"
                                                {{ $order->delivery_type == 'self_delivery' ? 'selected' : '' }}>
                                                {{ translate('by_self_delivery_man') }}
                                            </option>
                                            <option value="third_party_delivery"
                                                {{ $order->delivery_type == 'third_party_delivery' ? 'selected' : '' }}>
                                                {{ translate('by_third_party_delivery_service') }}
                                            </option>
                                        </select>
                                    </div>
                                </li>

                                @if(!$disableDeliveryType)
                                    <li class="choose_delivery_man">
                                        <label class="form-label fw-bold mb-2">
                                            {{translate('delivery_man')}}
                                        </label>
                                        <select class="custom-select"
                                                name="delivery_man_id"
                                                id="addDeliveryMan"
                                                data-order-id="{{$order['id']}}"
                                                data-placeholder="{{ translate('Select Deliveryman') }}"
                                            {{ $order->order_status == 'delivered' ? 'disabled' : '' }}
                                        >
                                            <option value="" readonly>--{{ translate('Select Deliveryman') }}--</option>
                                            @foreach($deliveryMen as $deliveryMan)
                                                <option
                                                    value="{{$deliveryMan['id']}}" {{$order['delivery_man_id']==$deliveryMan['id']?'selected':''}}>
                                                    {{$deliveryMan['f_name'].' '.$deliveryMan['l_name'].' ('.(isset($deliveryMan['country_code']) ? $deliveryMan['country_code'] : '').$deliveryMan['phone'].' )'}}
                                                </option>
                                            @endforeach
                                        </select>
                                        @if (isset($order->deliveryMan))
                                            <div class="p-2 bg-section rounded mt-4">
                                                <div class="media m-1 gap-3">
                                                    <img class="avatar rounded-circle"
                                                         src="{{ getStorageImages(path: $order->deliveryMan?->image_full_url, type: 'backend-profile') }}"
                                                         alt="{{translate('Image')}}">
                                                    <div class="media-body">
                                                        <h5 class="mb-1">{{ $order->deliveryMan?->f_name.' '.$order->deliveryMan?->l_name}}</h5>
                                                        <a href="tel:{{$order->deliveryMan?->phone}}"
                                                           class="fs-12 text-dark">{{ (isset($deliveryMan['country_code']) ? $deliveryMan['country_code'] : '') . ($order->deliveryMan?->phone ?? '') }}</a>
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
                                        <li class="choose_delivery_man">
                                            <label class="form-label fw-semibold">
                                                {{translate('delivery_man_incentive')}} ({{ getCurrencySymbol() }})
                                                <span class="tooltip-icon cursor-pointer"
                                                      data-bs-toggle="tooltip"
                                                      data-bs-placement="top"
                                                      aria-label="{{translate('encourage_your_deliveryman_by_giving_him_incentive').' '.translate('this_amount_will_be_count_as_admin_expense').'.'}}"
                                                      data-bs-title="{{translate('encourage_your_deliveryman_by_giving_him_incentive').' '.translate('this_amount_will_be_count_as_admin_expense').'.'}}">
                                                  <i class="fi fi-sr-info"></i>
                                            </span>
                                            </label>
                                            <div class="d-flex gap-2 align-items-center">
                                                <input type="number"
                                                       value="{{ usdToDefaultCurrency(amount: $order->deliveryman_charge) }}"
                                                       name="deliveryman_charge" data-order-id="{{$order['id']}}"
                                                       class="form-control" placeholder="{{translate('ex').': 20'}}"
                                                       {{$order['order_status']=='delivered' ? 'readonly':''}} required>
                                                <button
                                                    class="btn btn-primary h-40 {{$order['order_status']=='delivered' ? 'disabled deliveryman-charge-alert':'deliveryman-charge'}}">{{translate('update')}}</button>
                                            </div>
                                        </li>
                                        <li class="choose_delivery_man">
                                            <label
                                                class="form-label fw-semibold">{{translate('expected_delivery_date')}}</label>
                                            <input type="date" data-order-id="{{$order['id']}}"
                                                   value="{{ $order->expected_delivery_date }}"
                                                   name="expected_delivery_date" id="expected_delivery_date"
                                                   class="form-control set-today-date-minimum" {{ $order->order_status == 'delivered' ? 'disabled' : 'required' }}>
                                        </li>
                                    @endif
                                    <li class="mt-1" id="by_third_party_delivery_service_info">
                                        <div class="p-2 bg-section rounded">
                                            <div class="media overflow-hidden m-1 gap-3">
                                                <img class="avatar rounded-circle"
                                                     src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/third-party-delivery.png')}}"
                                                     alt="{{translate('image')}}">
                                                <div class="media-body w-100">
                                                    <h5 class="">{{$order->delivery_service_name ?? translate('not_assign_yet')}}</h5>
                                                    <span
                                                        class="fs-12 text-dark text-wrap d-block">{{translate('track_ID').' '.':'.' '.$order->third_party_delivery_tracking_id}}</span>
                                                </div>
                                            </div>
                                        </div>
                                        @if(getWebConfig(name: 'delivery_syria')['status'] ?? false)
                                            <div class="mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary w-100"
                                                        data-bs-toggle="modal" data-bs-target="#delivery_syria_dispatch_modal">
                                                    {{ translate('dispatch_to_delivery_syria') }}
                                                </button>
                                            </div>
                                        @endif
                                    </li>
                                @endif
                            </ul>
                        @endif
                    </div>
                </div>
                @php($billing=$order['billing_address_data'])

                <div class="card">
                    @if(!empty((array) $shippingAddress))
                        <div class="card-body">
                            <div class="d-flex gap-2 align-items-center justify-content-between mb-3">
                                <h4 class="d-flex gap-2 fs-14 fw-bold mb-0">
                                    <img
                                        src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/vendor-information.png')}}"
                                        alt="">
                                    {{translate('shipping_address')}}
                                </h4>
                                @if($order['order_status'] != 'delivered')
                                    <button
                                        class="btn btn-outline-primary icon-btn"
                                        title="Edit"
                                        data-bs-toggle="modal"
                                        data-bs-target="#shippingAddressUpdateModal">
                                        <i class="fi fi-sr-pencil" style="font-size: 15px;"></i>
                                    </button>
                                @endif
                            </div>
                            <table class="overflow-wrap-anywhere">
                                <tbody>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('name')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                                <span class="fw-semibold">
                                                {{$shippingAddress->contact_person_name}}
                                                </span>
                                        {{ $order->is_guest ? '('. translate('guest_customer') .')':''}}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('contact')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="fw-semibold px-0 py-2">{{$shippingAddress->phone}}</td>
                                </tr>
                                @if ($order->is_guest && $shippingAddress->email)
                                    <tr>
                                        <td class="px-0 py-2 text-nowrap">{{translate('email')}}</td>
                                        <td class="px-3 py-2">:</td>
                                        <td class="px-0 py-2" class="fw-semibold">{{$shippingAddress->email}}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('country')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2" class="fw-semibold">{{$shippingAddress->country}}</td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('city')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2" class="fw-semibold">{{$shippingAddress->city}}</td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('zip_code')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="fw-semibold px-0 py-2">{{$shippingAddress->zip}}</td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{ translate('Address') }}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <div class="d-flex align-items-start gap-2">
                                            <span>{{$shippingAddress->address  ?? translate('empty')}}</span>
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

                @if (getWebConfig('map_api_status') == 1 && isset($shippingAddress?->latitude) && isset($shippingAddress?->longitude))
                    <div class="card card-body">
                        <div class="d-flex justify-content-between gap-3 align-items-center flex-wrap">
                            <h3 class="mb-0">{{ translate('Shipping_Location') }}</h3>
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal"
                                    data-bs-target="#locationModal">{{ translate('On_Map') }}</button>
                        </div>
                    </div>
                @endif
                @if(!empty((array) $billing))
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex gap-2 align-items-center justify-content-between mb-3">
                                <h4 class="d-flex gap-2 fs-14 fw-bold mb-0">
                                    <img
                                        src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/vendor-information.png')}}"
                                        alt="">
                                    {{translate('billing_address')}}
                                </h4>
                                @if($order['order_status'] != 'delivered')
                                    <button
                                        class="btn btn-outline-primary icon-btn"
                                        title="{{ translate('edit') }}"
                                        data-bs-toggle="modal"
                                        data-bs-target="#billingAddressUpdateModal">
                                        <i class="fi fi-sr-pencil" style="font-size: 15px;"></i>
                                    </button>
                                @endif
                            </div>
                            <table class="overflow-wrap-anywhere">
                                <tbody>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('name')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2">
                                        <span class="fw-semibold">
                                            {{$billing->contact_person_name}}
                                        </span>
                                        {{ $order->is_guest ? '('. translate('guest_customer') .')':''}}
                                    </td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('contact')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2 fw-semibold">{{$billing->phone}}</td>
                                </tr>
                                @if ($order->is_guest && $billing->email)
                                    <tr>
                                        <td class="px-0 py-2 text-nowrap">{{translate('email')}}</td>
                                        <td class="px-3 py-2">:</td>
                                        <td class="px-0 py-2 fw-semibold">{{$billing->email}}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('country')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2 fw-semibold">{{$billing->country}}</td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('city')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="px-0 py-2 fw-semibold">{{$billing->city}}</td>
                                </tr>
                                <tr>
                                    <td class="px-0 py-2 text-nowrap">{{translate('zip_code')}}</td>
                                    <td class="px-3 py-2">:</td>
                                    <td class="fw-semibold">{{$billing->zip}}</td>
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
                                        src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/vendor-information.png')}}"
                                        alt="">
                                    {{translate('customer_information')}}
                                </h4>
                            </div>

                            <div class="media flex-wrap gap-3 gap-sm-4">
                                <a class="d-block"
                                   href="{{ route('admin.customer.view', ['user_id' => $order->customer['id']]) }}"
                                   target="_blank">
                                    <img class="avatar rounded-circle avatar-70 object-fit-cover"
                                         src="{{ getStorageImages(path: $order->customer->image_full_url , type: 'backend-basic') }}"
                                         alt="{{translate('Image')}}">
                                </a>
                                <div class="media-body d-flex flex-column gap-1">
                                    <a class="text-dark"
                                       href="{{ route('admin.customer.view', ['user_id' => $order->customer['id']]) }}"
                                       target="_blank">
                                        <span
                                            class="fw-semibold">{{ $order->customer['f_name'].' '.$order->customer['l_name']}} </span>
                                    </a>


                                    @if($order?->customer?->email !== 'walking@customer.com')
                                        <span class="text-dark fs-12"> <span class="fw-bold">{{ $orderCount }}</span> {{translate('orders')}}</span>
                                        <span class="text-dark break-all fs-12">
                                        <a href="tel:{{ $order->customer['phone'] }}"
                                           class="fw-semibold text-dark text-decoration-none">
                                            {{ $order->customer['phone'] }}
                                        </a>
                                    </span>
                                        <span class="text-dark break-all fs-12">
                                        <a href="mailto:{{ $order->customer['email'] }}"
                                           class="text-dark text-decoration-none">
                                            {{ $order->customer['email'] }}
                                        </a>
                                    </span>
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
                                <a class="d-block" target="_blank"
                                   href="{{ route('admin.business-settings.inhouse-shop') }}">
                                    <img class="avatar rounded avatar-70 img-fit-contain "
                                         src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop') }}"
                                         alt="">
                                </a>
                                <div class="media-body d-flex flex-column gap-2">
                                    <a class="fs-14 fw-semibold mb-0 text-dark hover-c1"
                                       href="{{ route('admin.business-settings.inhouse-shop') }}"
                                       target="_blank">{{ getInHouseShopConfig(key: 'name') }}</a>
                                    <span class="text-dark fs-12"><strong>{{ $totalDelivered }}</strong> {{translate('orders_Served')}}</span>
                                </div>
                            @else
                                @if(!empty($order->seller->shop))
                                    <a class="d-block" target="_blank"
                                       href="{{ route('admin.vendors.view', ['id' => $order->seller->id]) }}">
                                        <img class="avatar rounded avatar-70 img-fit"
                                             src="{{ getStorageImages(path:$order->seller->shop->image_full_url , type: 'backend-basic') }}"
                                             alt="">
                                    </a>
                                    <div class="media-body d-flex flex-column gap-2">
                                        <a class="fs-14 fw-semibold mb-0 text-dark hover-c1" target="_blank"
                                           href="{{ route('admin.vendors.view', ['id' => $order->seller->id]) }}">
                                            {{ $order->seller->shop->name }}
                                        </a>
                                        <span class="text-dark fs-12"><strong>{{ $totalDelivered }}</strong> {{translate('orders_Served')}}</span>
                                        <span class="text-dark fs-12">
                                        <a class="text-decoration-none text-dark" href="tel:{{ $order->seller->shop->contact }}">
                                            <strong>{{ $order->seller->shop->contact }}</strong>
                                        </a>
                                    </span>
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

    @if ($order->verificationImages && count($order->verificationImages)>0)
        <div class="modal fade" id="order_verification_modal" tabindex="-1" aria-labelledby="order_verification_modal"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header d-flex justify-content-between align-items-center pb-4">
                        <h3 class="mb-0">{{translate('order_verification_images')}}</h3>
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                data-bs-dismiss="modal" aria-label="Close">
                        </button>
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
                                                data-bs-dismiss="modal">{{translate('close')}}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if(!empty((array) $shippingAddress))
        <div class="modal fade" id="shippingAddressUpdateModal" tabindex="-1"
             aria-labelledby="shippingAddressUpdateModal"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-4 d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">{{translate('shipping_address')}}</h3>
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4 px-sm-5 pt-0">
                        <form action="{{route('admin.orders.address-update')}}" method="post">
                            @csrf
                            <div class="d-flex flex-column align-items-center gap-2">
                                <input name="address_type" value="shipping" hidden>
                                <input name="order_id" value="{{$order->id}}" hidden>
                                <div class="row gx-3 gy-4">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="name"
                                                   class="form-label">{{translate('contact_person_name')}}</label>
                                            <input type="text" name="name" id="name" class="form-control"
                                                   value="{{$shippingAddress? $shippingAddress->contact_person_name : ''}}"
                                                   placeholder="{{ translate('ex') }}: {{translate('john_doe')}}"
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="phone_number"
                                                   class="form-label">{{translate('phone_number')}}</label>
                                            <input class="form-control form-control-user"
                                                   type="tel"
                                                   value="{{$shippingAddress ? $shippingAddress->phone  : ''}}"
                                                   placeholder="{{ translate('ex').': 017xxxxxxxx' }}"
                                                   name="phone_number"
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="country" class="form-label">{{translate('country')}}</label>
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
                                            <label for="city" class="form-label">{{translate('city')}}</label>
                                            <input type="text" name="city" id="city"
                                                   value="{{$shippingAddress ? $shippingAddress->city : ''}}"
                                                   class="form-control"
                                                   placeholder="{{ translate('ex') }}:{{translate('dhaka')}}" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-group">
                                            <label for="zip_code" class="form-label">{{translate('zip')}}</label>
                                            @if($zipRestrictStatus == 1)
                                                <select name="zip" class="form-control" data-live-search="true"
                                                        required>
                                                    @forelse($zipCodes as $code)
                                                        <option
                                                            value="{{ $code->zipcode }}"{{ isset($shippingAddress) && $code->zipcode == $shippingAddress->zip ? 'selected'  : ''}}>{{ $code->zipcode }}</option>
                                                    @empty
                                                        <option value="">{{ translate('No_zip_to_deliver') }}</option>
                                                    @endforelse
                                                </select>
                                            @else
                                                <input type="text" class="form-control"
                                                       value="{{$shippingAddress ? $shippingAddress->zip  : ''}}"
                                                       id="zip"
                                                       name="zip"
                                                       placeholder="{{ translate('ex') }}: 1216" {{$shippingAddress?'required':''}}>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-group">
                                            <label for="address" class="form-label">{{translate('address')}}</label>
                                            <textarea name="address" id="address" name="address" rows="3"
                                                      class="form-control"
                                                      placeholder="{{ translate('ex') }} : {{translate('street_1,_street_2,_street_3,_street_4')}}">{{$shippingAddress ? $shippingAddress->address : ''}}</textarea>
                                        </div>
                                    </div>
                                    <input type="hidden" id="latitude"
                                           name="latitude" class="form-control d-inline"
                                           placeholder="{{ translate('Ex') }} : -94.22213"
                                           value="{{$shippingAddress->latitude ?? 0}}" required readonly>
                                    <input type="hidden"
                                           name="longitude" class="form-control"
                                           placeholder="{{ translate('Ex') }} : 103.344322" id="longitude"
                                           value="{{$shippingAddress->longitude ??0}}" required readonly>
                                    @if(getWebConfig('map_api_status') ==1 )
                                        <div class="col-12 ">
                                            <input id="pac-input" class="form-control rounded w-200 mt-1"
                                                   title="{{translate('search_your_location_here')}}" type="text"
                                                   placeholder="{{translate('search_here')}}"/>
                                            <div class="dark-support rounded w-100 h-200 mb-2"
                                                 id="location_map_canvas_shipping"></div>
                                        </div>
                                    @endif
                                    <div class="col-12">
                                        <div class="d-flex justify-content-end gap-3">
                                            <button type="button" class="btn btn-secondary px-5"
                                                    data-bs-dismiss="modal">{{translate('cancel')}}</button>
                                            <button type="submit"
                                                    class="btn btn-primary px-5">{{translate('update')}}</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($billing)
        <div class="modal fade" id="billingAddressUpdateModal" tabindex="-1" aria-labelledby="billingAddressUpdateModal"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-4 d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">{{translate('billing_address')}}</h3>
                        <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                                data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body px-4 px-sm-5 pt-0">
                        <div class="d-flex flex-column align-items-center gap-2">
                            <form action="{{route('admin.orders.address-update')}}" method="post">
                                @csrf
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <input name="address_type" value="billing" hidden>
                                    <input name="order_id" value="{{$order->id}}" hidden>
                                    <div class="row gx-3 gy-4">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="name"
                                                       class="form-label">{{translate('contact_person_name')}}</label>
                                                <input type="text" name="name" id="name" class="form-control"
                                                       value="{{$billing? $billing->contact_person_name : ''}}"
                                                       placeholder="{{ translate('ex') }}: {{translate('john_doe')}}"
                                                       required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="phone_number"
                                                       class="form-label">{{translate('phone_number')}}</label>
                                                <input class="form-control form-control-user"
                                                       type="tel" value="{{$billing ? $billing->phone  : ''}}"
                                                       placeholder="{{ translate('ex').': 017xxxxxxxx' }}"
                                                       name="phone_number" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="country"
                                                       class="form-label">{{translate('country')}}</label>
                                                <select name="country" id="country" class="form-control">
                                                    @forelse($countries as $country)
                                                        <option
                                                            value="{{ $country['name'] }}" {{ isset($billing) && $country['name'] == $billing->country ? 'selected'  : ''}}>{{ $country['name'] }}</option>
                                                    @empty
                                                        <option
                                                            value="">{{ translate('No_country_to_deliver') }}</option>
                                                    @endforelse
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="city" class="form-label">{{translate('city')}}</label>
                                                <input type="text" name="city" id="city"
                                                       value="{{$billing ? $billing->city : ''}}" class="form-control"
                                                       placeholder="{{ translate('ex') }}:{{translate('dhaka')}}"
                                                       required>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="zip_code" class="form-label">{{translate('zip')}}</label>
                                                @if($zipRestrictStatus == 1)
                                                    <select name="zip" class="form-control" data-live-search="true"
                                                            required>
                                                        @forelse($zipCodes as $code)
                                                            <option
                                                                value="{{ $code->zipcode }}"{{ isset($billing) && $code->zipcode == $billing->zip ? 'selected'  : ''}}>{{ $code->zipcode }}</option>
                                                        @empty
                                                            <option
                                                                value="">{{ translate('no_zip_to_deliver') }}</option>
                                                        @endforelse
                                                    </select>
                                                @else
                                                    <input type="text" class="form-control"
                                                           value="{{$billing ? $billing->zip  : ''}}" id="zip"
                                                           name="zip"
                                                           placeholder="{{ translate('ex').': 1216' }}" {{$billing?'required':''}}>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group">
                                                <label for="address"
                                                       class="form-label">{{translate('address')}}</label>
                                                <textarea name="address" id="billing_address" rows="3"
                                                          class="form-control"
                                                          placeholder="{{ translate('ex') }} : {{translate('street_1,_street_2,_street_3,_street_4')}}">{{$billing ? $billing->address : ''}}</textarea>
                                            </div>
                                        </div>
                                        <input type="hidden" id="billing_latitude"
                                               name="latitude" class="form-control d-inline"
                                               placeholder="{{ translate('ex') }} : -94.22213"
                                               value="{{$billing->latitude ?? 0}}" required readonly>
                                        <input type="hidden"
                                               name="longitude" class="form-control"
                                               placeholder="{{ translate('ex') }} : 103.344322" id="billing_longitude"
                                               value="{{$billing->longitude ?? 0}}" required readonly>
                                        @if(getWebConfig('map_api_status') ==1 )
                                            <div class="col-12 ">
                                                <input id="billing-pac-input" class="form-control rounded w-200 mt-1"
                                                       title="{{translate('search_your_location_here')}}" type="text"
                                                       placeholder="{{translate('search_here')}}"/>
                                                <div class="rounded w-100 h-200 mb-2"
                                                     id="location_map_canvas_billing"></div>
                                            </div>
                                        @endif
                                        <div class="col-12">
                                            <div class="d-flex justify-content-end gap-3">
                                                <button type="button" class="btn btn-secondary px-5"
                                                        data-bs-dismiss="modal">{{translate('cancel')}}</button>
                                                <button type="submit"
                                                        class="btn btn-primary px-5">{{translate('update')}}</button>
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

    <div class="modal fade" id="locationModal" tabindex="-1" role="dialog" aria-labelledby="locationModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <h3 class="modal-title text-center flex-grow-1"
                        id="locationModalLabel">{{translate('location_on_Map')}}</h3>
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body">
                    <div>
                        <div class="row">
                            <div class="col-md-12 rounded border p-3">
                                <div class="h3 text-cyan-blue text-center">{{ translate('order') }}
                                    #{{ $order->id }}</div>
                                <ul class="nav nav-tabs border-0 media-tabs nav-justified order-track-info">
                                    <li class="nav-item">
                                        <div class="nav-link active-status">
                                            <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                <div class="media-tab-media mx-sm-auto mb-3">
                                                    <img
                                                        src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/order-placed.png') }}"
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
                                                    class="nav-link {{ ($order['order_status']=='confirmed') || ($order['order_status']=='processing') || ($order['order_status']=='processed') || ($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered')?'active-status' : ''}}">
                                                    <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                        <div class="media-tab-media mb-3 mx-sm-auto">
                                                            <img
                                                                src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/order-confirmed.png') }}"
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
                                                                    {{ date('h:i A, d M Y',strtotime(\App\Utils\order_status_history(order: $order, status: 'confirmed'))) }}
                                                                </span>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </li>
                                            <li class="nav-item">
                                                <div
                                                    class="nav-link {{ ($order['order_status']=='processing') || ($order['order_status']=='processed') || ($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered')?'active-status' : ''}}">
                                                    <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                        <div class="media-tab-media mb-3 mx-sm-auto">
                                                            <img alt=""
                                                                 src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/shipment.png') }}">
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
                                                    class="nav-link {{ ($order['order_status']=='out_for_delivery') || ($order['order_status']=='delivered')?'active-status' : ''}}">
                                                    <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                        <div class="media-tab-media mb-3 mx-sm-auto">
                                                            <img
                                                                src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/on-the-way.png') }}"
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
                                                    class="nav-link {{ ($order['order_status']=='delivered')?'active-status' : ''}}">
                                                    <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                        <div class="media-tab-media mb-3 mx-sm-auto">
                                                            <img
                                                                src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/delivered.png') }}"
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
                                                    $productData = json_decode($detail->product_details, true);
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
                                                                 src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/shipment.png') }}">
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
                                                    class="nav-link {{ ($order['order_status']=='confirmed' && $digitalProductProcessComplete)?'active-status' : ''}}">
                                                    <div class="d-flex flex-sm-column gap-3 gap-sm-0">
                                                        <div class="media-tab-media mb-3 mx-sm-auto">
                                                            <img
                                                                src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/delivered.png') }}"
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
                                                            src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/'.$order['order_status'].'.png') }}"
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
                                                            src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/track-order/order-failed.png') }}"
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

                            @if(!empty((array) $shippingAddress))
                                <div class="col-md-12 modal_body_map mt-5 ps-0 pe-0">
                                    <div class="mb-2">
                                        <img src="{{ dynamicAsset('assets/new/back-end/img/location-blue.png') }}"
                                             alt="">
                                        <span>{{ $shippingAddress ? $shippingAddress->address : ($billing ? $billing->address : '') }}</span>
                                    </div>
                                    @if(getWebConfig('map_api_status') ==1 )
                                        <div class="location-map" id="location-map">
                                            <div class="w-100 h-200" id="location_map_canvas"></div>
                                        </div>
                                    @endif
                                </div>
                            @endif

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(getWebConfig(name: 'delivery_syria')['status'] ?? false)
        @include('admin-views.order._delivery-syria-dispatch-modal')
    @endif

    <div class="modal" id="third_party_delivery_service_modal" role="dialog" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{translate('update_third_party_delivery_info')}}</h5>
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12">
                            <form action="{{route('admin.orders.update-deliver-info')}}" method="POST">
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
                                    <button class="btn btn-primary" type="submit">{{translate('update')}}</button>
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
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none"
                            style="--size: 2rem;" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fi fi-sr-cross"></i>
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/modal/warning-new.png') }}"
                             width="70" class="aspect-1 mb-20" id="" alt="">
                        <h2 class="modal-title mb-3" id="">{{ translate('Are_you_sure') }}?</h2>
                        <div class="text-center" id="">
                            {{ translate('you_want_to_update_the_status_of_this_order?_this_action_will_notify_the_customer_and_cannot_be_undone.') }}
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <button type="button" class="btn btn-primary min-w-120"
                                data-bs-dismiss="modal">{{ translate('Yes_change_it!') }}</button>
                        <button type="button" class="btn btn-danger min-w-120"
                                data-bs-dismiss="modal">{{ translate('Cancel') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade pt-5" id="quick-view" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" id="quick-view-modal"></div>
        </div>
    </div>

    @include('admin-views.order.partials.modal.order-edit-due-amount-mark-as-paid', ['order' => $order])
    @include('admin-views.order.partials.modal.order-edit-due-amount-switch-to-cod', ['order' => $order])
    @include('admin-views.order.partials.modal.order-edit-return-amount-modal', ['order' => $order])
    @include('admin-views.order.partials.modal._confirm-edit-order')
    @include('admin-views.order.partials.offcanvas._edit-products-offcanvas', ['order' => $order])

    <span id="message-status-title-text"
          data-text="{{$order['payment_method'] != 'cash_on_delivery' && $order['order_status']=='delivered' ? translate("Order_is_already_delivered_and_transaction_amount_has_been_disbursed_changing_status_can_be_the_reason_of_miscalculation") : translate("are_you_sure_change_this") }}"></span>
    <span id="message-status-subtitle-text"
          data-text="{{ $order['payment_method'] != 'cash_on_delivery' && $order['order_status']=='delivered' ? translate('think_before_you_proceed') : translate("you_will_not_be_able_to_revert_this") }}!"></span>
    <span id="payment-status-message" data-title="{{translate('confirm_payments_before_change_the_status').'.'}}"
          data-message="{{ translate('Change the status to Paid only when you receive the customers payment and have verified it.') }}"></span>
    <span id="message-status-confirm-text" data-text="{{ translate("yes_change_it") }}!"></span>
    <span id="message-status-cancel-text" data-text="{{ translate("cancel") }}"></span>
    <span id="message-status-success-text" data-text="{{ translate("status_change_successfully") }}"></span>
    <span id="message-status-warning-text"
          data-text="{{ translate("account_has_been_deleted_you_can_not_change_the_status") }}"></span>
    <span id="message-order-status-delivered-text"
          data-text="{{ translate("order_is_already_delivered_you_can_not_change_it") }}!"></span>
    <span id="message-order-status-paid-first-text"
          data-text="{{ translate("before_delivered_you_need_to_make_payment_status_paid") }}!"></span>
    <span id="order-status-url" data-url="{{route('admin.orders.status')}}"></span>
    <span id="payment-status-url" data-url="{{ route('admin.orders.payment-status') }}"></span>

    <span id="message-deliveryman-add-success-text"
          data-text="{{ translate("delivery_man_successfully_assigned/changed") }}"></span>
    <span id="message-deliveryman-add-error-text"
          data-text="{{ translate("deliveryman_man_can_not_assign_or_change_in_that_status") }}"></span>
    <span id="message-deliveryman-add-invalid-text"
          data-text="{{ translate("deliveryman_man_can_not_assign_or_change_in_that_status") }}"></span>
    <span id="delivery-type" data-type="{{ $order->delivery_type }}"></span>
    <span id="add-delivery-man-url" data-url="{{url('/admin/orders/add-delivery-man/'.$order['id'])}}/"></span>

    <span id="message-deliveryman-charge-success-text"
          data-text="{{ translate("deliveryman_charge_add_successfully") }}"></span>
    <span id="message-deliveryman-charge-error-text"
          data-text="{{ translate("failed_to_add_deliveryman_charge") }}"></span>
    <span id="message-deliveryman-charge-invalid-text" data-text="{{ translate("add_valid_data") }}"></span>
    <span id="add-date-update-url" data-url="{{route('admin.orders.amount-date-update')}}"></span>

    <span id="customer-name" data-text="{{$order->customer['f_name']??""}} {{$order->customer['l_name']??""}}}"></span>
    <span id="is-shipping-exist" data-status="{{$shippingAddress ? 'true':'false'}}"></span>
    <span id="shipping-address" data-text="{{$shippingAddress->address??''}}"></span>
    <span id="shipping-latitude" data-latitude="{{$shippingAddress->latitude??'-33.8688'}}"></span>
    <span id="shipping-longitude" data-longitude="{{$shippingAddress->longitude??'151.2195'}}"></span>
    <span id="billing-latitude" data-latitude="{{$billing->latitude??'-33.8688'}}"></span>
    <span id="billing-longitude" data-longitude="{{$billing->longitude??'151.2195'}}"></span>
    <span id="location-icon"
          data-path="{{ dynamicAsset(path: 'public/assets/front-end/img/customer_location.png')}}"></span>
    <span id="customer-image"
          data-path="{{dynamicStorage(path: 'storage/app/public/profile/')}}{{$order->customer->image??""}}"></span>
    <span id="deliveryman-charge-alert-message"
          data-message="{{translate('when_order_status_delivered_you_can`t_update_the_delivery_man_incentive').'.'}}"></span>
    <span id="payment-status-alert-message"
          data-message="{{translate('when_payment_status_paid_then_you_can`t_change_payment_status_paid_to_unpaid').'.'}}"></span>
    <span id="amount-cannot-be-empty-message"
          data-message="{{translate('Amount cannot be empty or zero').'.'}}"></span>

    <span id="get-search-product-for-edit-order" data-action="{{ route('admin.orders.search-for-edit-order-product') }}"
          data-order-id="{{ $order['id'] }}"></span>
    <span id="edit-order-product-modal-view"
          data-action="{{ route('admin.orders.edit-order-product-modal-view') }}"></span>
@endsection

@push('script')
    @if(getWebConfig('map_api_status') == 1)
        <script
            src="https://maps.googleapis.com/maps/api/js?key={{ getWebConfig('map_api_key') }}&callback=mapCallBackFunction&loading=async&libraries=places&v=3.56"
            defer>
        </script>
    @endif

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/pdf-worker.min.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/file-upload/multiple-document-upload.js') }}"></script>

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/order.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/order-edit.js') }}"></script>
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
