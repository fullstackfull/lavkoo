<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-semibold title-clr mb-3">
        {{ translate('Shipping Address') }}
    </div>
    <div class="d-flex flex-column gap-15px">
        @foreach($auctionProduct?->shipping_address_info as $addressInfoKey => $addressInfo)
            @if(!empty($addressInfo))
                <div class="d-flex align-items-center gap-2 w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="minmax-xs-60px fs-14 title-semidark">
                            {{ translate($addressInfoKey) }}
                        </div>
                        <span class="title-semidark">:</span>
                    </div>
                    <div class="info-option2">
                        <h4 class="fs-14 m-0 title-clr fw-normal">
                            {{ $addressInfo }}
                        </h4>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
@if($auctionProduct->billing_same_as_shipping || !empty($auctionProduct->billing_address_info))
<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-semibold title-clr mb-3">
        {{ translate('Billing Address') }}
    </div>
    @if($auctionProduct->billing_same_as_shipping)
        <div class="bg-light rounded text-center fs-14 title-semidark p-12px">
            {{ translate('Same As Shipping Address') }}
        </div>
    @else
        <div class="d-flex flex-column gap-15px">
            @foreach($auctionProduct->billing_address_info as $addressInfoKey => $addressInfo)
                @if(!empty($addressInfo))
                    <div class="d-flex align-items-center gap-2 w-100">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="minmax-xs-60px fs-14 title-semidark">
                                {{ translate($addressInfoKey) }}
                            </div>
                            <span class="title-semidark">:</span>
                        </div>
                        <div class="info-option2">
                            <h4 class="fs-14 m-0 title-clr fw-normal">
                                {{ $addressInfo }}
                            </h4>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif
</div>
@endif
