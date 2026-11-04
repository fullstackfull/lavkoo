<div class="modal fade" id="discount-update-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="max-height: 80vh; overflow-y: auto;">
            <div class="modal-header">
                <button type="button" class="close p-0 fz-22" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true"><i class="tio-clear"></i></span>
                </button>
            </div>
            <div class="modal-body">
                <form action="{{route('vendor.clearance-sale.update-discount')}}" method="post" class="discount-amount-update">
                    @csrf
                    <input type="hidden" name="product_id">
                    <input type="hidden" name="id">
                    <div class="mb-30">
                        <div class="d-flex align-items-center gap-3">
                            <img class="avatar avatar-xl border rounded border" width="75"
                                src="{{ dynamicAsset( path: 'public/assets/back-end/img/160x160/img2.jpg') }}"
                                alt="">
                            <div class="media-body d-flex flex-column gap-1">
                                <h4 class="fs-14 mb-1 product-name line-1 text-start">
                                     {{ ('Product Name') }}
                                </h4>
                                <div class="fs-12 text-dark d-flex flex-column gap-1 overflow-x-auto">
                                    <div class="d-flex gap-1 align-items-start flex-column flex-wrap flex-lg-row">
                                        <div class="d-flex gap-1 align-items-center">
                                            <span class="opacity-75 text-nowrap">
                                                {{ translate('Price') }} :
                                            </span>
                                            <span class="fw-semibold modal-product-price"></span>
                                        </div>
                                        <div class="opacity-xs d-none d-lg-block modal-product-physical">|</div>

                                        <div class="d-flex gap-1 align-items-center modal-product-physical">
                                            <span class="opacity-75 text-nowrap">
                                                {{ translate('Current_Stock') }} :
                                            </span>
                                            <span class="modal-product-stock"></span>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1 align-items-start flex-column flex-wrap flex-lg-row">
                                        <div class="d-flex gap-1 align-items-center">
                                            <span class="opacity-75 text-nowrap">
                                                {{ translate('Category') }} :
                                            </span>
                                            <span class="modal-product-category"></span>
                                        </div>
                                        <div class="opacity-xs d-none d-lg-block modal-product-physical">|</div>
                                        <div class="d-flex gap-1 align-items-center modal-product-physical">
                                            <span class="opacity-75 text-nowrap">
                                                {{ translate('Brand') }} :
                                            </span>
                                            <span class="modal-product-brand"></span>
                                        </div>
                                        <div class="opacity-xs d-none d-lg-block">|</div>
                                        <div class="d-flex gap-1 align-items-center">
                                            <span class="opacity-75 text-nowrap">
                                                {{ translate('Shop') }} :
                                            </span>
                                            <span class="text-primary modal-product-shop"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-30">
                            <label class="form-label title-color font-weight-medium fz-14 title-color font-medium">
                                {{ translate('Discount Amount') }}
                                <span id="discount-symbol">(%)</span>
                            </label>
                            <div class="custom-group-btn border w-100 justify-content-between">
                                <input type="hidden" id="dynamic-currency-symbol" value="{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}">
                                <div class="flex-sm-grow-1">
                                    <input type="number" class="form-control border-0" placeholder="Ex : 10" name="discount_amount">
                                </div>
                                <div class="flex-shrink-0 min-w-80">
                                    <select name="discount_type" id="discount_type" class="custom-select ltr border-0">
                                        <option value="percentage">%</option>
                                        <option value="flat">{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="btn--container justify-content-end">
                        <button class="btn btn-danger-light font-weight-semibold" data-dismiss="modal"
                                type="reset">{{ translate('Cancel') }}</button>
                        <button class="btn btn--primary font-weight-semibold discount-amount-submit"
                                type="button">{{ translate('Update') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
