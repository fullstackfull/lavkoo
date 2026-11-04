<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-semibold title-clr">
        {{ translate('Payment Info') }}
    </div>
    <div>
        @if($auctionProduct?->category?->name)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark">
                        {{ translate('Category') }}
                    </div>
                    <span>:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-16 m-0 title-clr fw-semibold">
                        {{ $auctionProduct?->category?->name }}
                    </h4>
                </div>
            </div>
        @endif

        @if(!empty($auctionProduct?->product_type))
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark">
                        {{ translate('Product Type') }}
                    </div>
                    <span>:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-16 m-0 title-clr fw-semibold text-capitalize">
                        {{ translate($auctionProduct?->product_type) }}
                    </h4>
                </div>
            </div>
        @endif

        @if(!empty($auctionProduct['item_condition']))
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark">
                        {{ translate('Item condition') }}
                    </div>
                    <span>:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-16 m-0 title-clr fw-semibold">
                        {{ $auctionProduct['item_condition'] }}
                    </h4>
                </div>
            </div>
        @endif
    </div>
</div>
