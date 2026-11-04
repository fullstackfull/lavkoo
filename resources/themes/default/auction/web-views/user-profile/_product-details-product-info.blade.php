<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-semibold title-clr">
        {{ translate('Product Info') }}
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

        @if(!empty($auctionProduct['item_condition']))
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark d-flex align-items-center gap-1">
                        {{ translate('Item condition') }}
                        <span class="mx-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="{{ translate('Indicates_the_physical_condition_of_this_item,_e.g.,_new,_used,_or_refurbished.') }}">
                            <i class="fi fi-sr-info text-light-gray fs-12"></i>
                        </span>
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

        @if(!empty($auctionProduct?->brand?->name))
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark">{{ translate('Brand') }}</div>
                    <span>:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-16 m-0 title-clr fw-semibold">
                        {{ $auctionProduct?->brand?->name }}
                    </h4>
                </div>
            </div>
        @endif

        @if(!empty($auctionProduct?->taxVats))
            @foreach($auctionProduct?->taxVats as $auctionTaxKey => $auctionTaxVats)
                @if(!empty($auctionTaxVats?->tax?->name))
                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="minmax-xs-100px fs-14 title-semidark">
                                {{ $auctionTaxVats?->tax?->name }}
                            </div>
                            <span>:</span>
                        </div>
                        <div class="info-option2">
                            <h4 class="fs-16 m-0 title-clr fw-semibold">
                                {{ $auctionTaxVats?->tax?->tax_rate }}%
                            </h4>
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </div>
</div>
