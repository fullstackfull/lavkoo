<div class="w-100 mb-20 justify-content-between flex-wrap d-flex gap-2 align-items-end">
    <div>
        <h3 class="fs-18 fw-semibold d-flex align-items-center gap-10px title-clr text-capitalize mb-1">
            {{ translate('Auction') }} {{ translate('ID') .' #'. $auctionProduct['id'] }}
            <span class="badge {{ $claimDeliveryBadgeClass }} bg-opacity-10 rounded-pill text-capitalize">
                {{ $claimDeliveryStatusLabel }}
            </span>
        </h3>
        <span class="title-semidark fs-14">{{ translate('Claimed date') }} : {{ $claimedAt }}</span>
    </div>
    <div>
        <a href="{{ route('auction.generate-invoice', $auctionProduct->id) }}"
           class="btn btn-sm btn-primary gap-5px d-center"
           title="{{ translate('download_invoice') }}">
            <i class="fi fi-rr-file-download fs-13"></i> {{ translate('Invoice') }}
        </a>
    </div>
</div>
