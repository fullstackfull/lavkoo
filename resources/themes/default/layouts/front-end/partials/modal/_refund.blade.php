<div class="modal fade" id="refundModal{{$id}}" tabindex="-1" aria-labelledby="refundRequestModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="text-capitalize flex-grow-1 m-0 fw-semibold">{{translate('refund_request')}}</h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body d-flex flex-column gap-3">
                <div class="media gap-3">
                    @if (isset($order_details?->productAllStatus))
                        <div class="position-relative">
                            <img class="d-block get-view-by-onclick rounded"
                                 data-link="{{ route('product',$order_details?->productAllStatus->slug)}}"
                                 src="{{ getStorageImages(path: $order_details?->productAllStatus->thumbnail_full_url, type: 'product') }}"
                                 alt="{{ translate('product') }}" width="100">

                            @if($order_details?->productAllStatus->discount > 0)
                                <span class="price-discount badge badge-primary position-absolute top-1 left-1">
                                    @if ($order_details?->productAllStatus->discount_type == 'percent')
                                        -{{round($order_details?->productAllStatus->discount)}}%
                                    @elseif($order_details?->productAllStatus->discount_type =='flat')
                                        -{{ webCurrencyConverter(amount: $order_details?->productAllStatus->discount) }}
                                    @endif
                                </span>
                            @endif
                        </div>
                        <div class="media-body">

                            <a href="{{route('product',[$order_details?->productAllStatus->slug])}}">
                                <h6 class="mb-1 lh-2">
                                    {{Str::limit($product['name'],40)}}
                                </h6>
                            </a>
                            @if($order_details->variant)
                                <div>
                                    <small class="title-semidark">
                                        {{translate('variant')}} : {{$order_details->variant}}
                                    </small>
                                </div>
                            @endif

                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <small class="title-semidark">{{translate('price')}} :
                                    <span class="text-dark">{{ webCurrencyConverter(amount: $order_details->price)}}</span>
                                </small>
                                <span class="line-cus"></span>
                                <small class="title-semidark">{{translate('qty')}} : <span class="text-dark">{{$order_details->qty}}</span></small>
                            </div>
                            <div>
                                <small class="title-semidark">
                                    {{ $order_details->created_at->format('d M Y, h:i a') }}
                                </small>
                            </div>
                        </div>
                    @else
                        <div class="media-body">
                            <h6 class="mb-1">{{translate('product_not_found')}}</h6>
                        </div>
                    @endif
                </div>
                <div class="row g-3">
                    <div class="col-lg-6 col-md-6">
                        <div class="">
                            <div class="border rounded light-box">
                                <div class="p-3 fs-12 d-flex flex-column gap-3">
                                    <h6 class="fs-14 mb-0 fw-bold">{{translate('Price info')}}</h6>
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="title-semidark text-capitalize">{{translate('total_price')}}</div>
                                        <div>{{ webCurrencyConverter(amount: $order_details->price) }}</div>
                                    </div>
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="title-semidark text-capitalize">{{translate('product_discount')}}</div>
                                        <div>-{{ webCurrencyConverter(amount: $order_details->discount) }}</div>
                                    </div>
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="title-semidark">vat/tax</div>
                                        <div>{{ webCurrencyConverter(amount: $order_details->tax) }}</div>
                                    </div>
                                    @php($refundDetailsSummery = \App\Utils\OrderManager::getRefundDetailsForSingleOrderDetails(orderDetailsId: $order_details['id']))
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="title-semidark text-capitalize">{{translate('sub_total')}}</div>
                                        <div>{{ webCurrencyConverter(amount: $refundDetailsSummery['sub_total']) }}</div>
                                    </div>
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="title-semidark text-capitalize">{{translate('coupon_discount')}}</div>
                                        <div> -{{ webCurrencyConverter(amount: $refundDetailsSummery['coupon_discount']) }}</div>
                                    </div>
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="title-semidark text-capitalize">{{translate('referral_discount')}}</div>
                                        <div> -{{ webCurrencyConverter(amount: $refundDetailsSummery['referral_discount']) }}</div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between flex-sm-nowrap flex-wrap gap-1 border-top py-2 mx-3 fs-12">
                                    <div class="title-semidark text-dark  font-weight-bold fs-16 text-capitalize">{{translate('total_refundable_amount')}}</div>
                                    <div class="font-weight-bold  text-dark fs-18">{{ webCurrencyConverter(amount: $refundDetailsSummery['total_refundable_amount']) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6">
                        <form action="{{route('refund-store')}}" method="post" enctype="multipart/form-data" class="form-advance-validation  form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                            <div class="border rounded light-box p-3">
                                @csrf
                                <h6 class="d-flex gap-2 fs-16 align-items-center cursor-pointer mb-10px pb-1">
                                    {{translate('give_a_refund_reason')}} <span class="text-danger">*</span>
                                </h6>
                                <div class="mb-20">
                                    <input type="hidden" name="order_details_id" value="{{ $order_details->id }}">
                                    <input type="hidden" name="amount" value="{{ $refundDetailsSummery['total_refundable_amount'] }}">
                                    <textarea rows="4" class="form-control" name="refund_reason"  data-required-msg="{{ translate('refund_reason_is_required') }}" required
                                              placeholder="{{translate('write_here')}}..."></textarea>
                                </div>
                                <div class="">
                                    <h6 class="fs-16 mb-10px">{{translate('upload_images')}}</h6>
                                    <div class="mt-2">
                                        <div class="mt-2">
                                            <div class="d-flex flex-wrap upload_images_area">

                                                <div class="d-flex flex-wrap filearray"></div>
                                                <div class="selected-files-container"></div>

                                                <label class="py-0 bg-white d-flex align-items-center m-0 cursor-pointer">
                                                    <span class="position-relative">
                                                        <img class="border rounded border-primary-light h-70px"
                                                             src="{{ getStorageImages(path: null, type: 'logo',source: 'public/assets/front-end/img/image-place-holder.png') }}"
                                                             alt="">
                                                    </span>
                                                    <input type="file" class="msgfilesValue h-100 position-absolute w-100 " hidden
                                                           data-max-size="{{ getFileUploadMaxSize() }}"
                                                           data-required-msg="{{ translate('only_image_file_is_allowed') }}"
                                                           multiple accept="{{ getFileUploadFormats(skip: '.svg') }}">
                                                </label>

                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn--primary text-capitalize">
                                    {{translate('send_request')}}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
