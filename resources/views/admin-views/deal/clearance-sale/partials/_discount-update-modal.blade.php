<div class="modal fade" id="discount-update-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="max-height: 80vh; overflow-y: auto;">
            <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                    data-bs-dismiss="modal" aria-label="Close">
                </button>
            </div>
            <div class="modal-body">
                <form action="{{route('admin.deal.clearance-sale.update-discount')}}" method="post" class="discount-amount-update">
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
                            <div class="input-group">
                                <input type="text" name="discount_amount" class="form-control" placeholder="{{ 'Ex : 10' }}">
                                <div class="input-group-append select-wrapper min-w-80">
                                    <input type="hidden" id="dynamic-currency-symbol" value="{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}">
                                    <select name="discount_type" id="discount_type" class="form-select shadow-none h-auto">
                                        <option value="percentage">%</option>
                                        <option value="flat">{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-end">
                        <button class="btn text-danger bg-danger bg-opacity-10 border-0 fw-semibold" data-bs-dismiss="modal"
                                type="reset">{{ translate('Cancel') }}</button>
                        <button class="btn btn-primary fw-semibold discount-amount-submit"
                                type="button">{{ translate('Update') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
