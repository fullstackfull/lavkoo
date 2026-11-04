<?php
    $titleWeight = $titleWeight ?? 'bold';
    $statusSelectDisabled = $auctionProduct?->auction_current_status !== \Modules\Auction\app\Enums\AuctionStatus::UPCOMING
        || $auctionProduct?->approval_status === \Modules\Auction\app\Enums\ApprovalStatus::PENDING;
    $isStatusActive = $auctionProduct->status == \Modules\Auction\app\Enums\ProductStatus::ACTIVE;
?>

@if($auctionProduct?->approval_status != \Modules\Auction\app\Enums\ApprovalStatus::PENDING)
    <div class="p-15px card border-0 shadow-sm rounded">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="fs-14 fw-{{ $titleWeight }} title-clr">{{ translate('Auction Status') }}</div>
            <div class="form-check form-switch m-0 ps-0">
                <input type="checkbox"
                       role="switch"
                       name="product_status"
                       id="js-owner-product-status-{{ $auctionProduct->id }}"
                       class="form-check-input m-0 js-owner-product-status {{ $statusSelectDisabled ? 'opacity-50' : '' }}"
                       style="cursor: pointer; width: 2.5em; height: 1.35em;"
                       data-auction-product-id="{{ $auctionProduct->id }}"
                       data-update-url="{{ route('auction.product-status.update') }}"
                       data-current-status="{{ $auctionProduct->status }}"
                       data-active-value="{{ \Modules\Auction\app\Enums\ProductStatus::ACTIVE }}"
                       data-inactive-value="{{ \Modules\Auction\app\Enums\ProductStatus::INACTIVE }}"
                    {{ $isStatusActive ? 'checked' : '' }}
                    {{ $statusSelectDisabled ? 'disabled' : '' }}>
            </div>
        </div>
    </div>
@endif
