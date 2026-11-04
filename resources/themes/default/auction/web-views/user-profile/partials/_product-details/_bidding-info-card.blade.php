<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-bold title-clr mb-15">{{ translate('Bidding Info') }}</div>
    <div class="d-flex flex-column gap-15px">
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Highest Bid') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $auctionProduct->current_highest_bid_amount ?? 0) }}</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Total Bid') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $auctionProduct->total_bids }}</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Total View') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $auctionProduct->total_views }}</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Fee Provide') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $auctionProduct->total_participants }}</h4>
            </div>
        </div>
    </div>
</div>
