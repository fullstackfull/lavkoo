<?php
    // mode controls which action button row to render:
    //   'delete-recreate' → Delete + Recreate (used for UNSOLD/CANCELED)
    //   'delete-edit'     → Delete + Edit (used for PENDING/REJECTED)
    //   'cancel-edit'     → Cancel + (Edit if UPCOMING) — used for UPCOMING/LIVE w/ no bids
    // size: 'default' (fs-16 + px-3) | 'sm' (fs-14, no extra padding) — sm fits the
    //       narrow right-column header card on REJECTED/CANCELED so buttons stay inline.
    $recreateLabel = $recreateLabel ?? translate('Recreate Auction');
    $size          = $size ?? 'default';
    $btnSizeClass  = $size === 'sm' ? 'fs-14 px-2' : 'fs-16 px-3';
?>
<div class="d-flex flex-nowrap align-items-center gap-2 mt-3">
    @if($mode === 'delete-edit')
        <button type="button"
                class="btn bg-danger bg-opacity-10 {{ $btnSizeClass }} fw-semibold text-danger js-delete-auction-btn"
                data-auction-id="{{ $auctionProduct->id }}">
            {{ translate('Delete Auction') }}
        </button>
        <a href="{{ route('auction.auction-update-product', $auctionProduct->id) }}" class="btn btn-primary {{ $btnSizeClass }}">
            {{ translate('Edit Auction') }}
        </a>
    @elseif($mode === 'delete-recreate')
        <button type="button"
                class="btn bg-danger bg-opacity-10 {{ $btnSizeClass }} fw-semibold text-danger js-delete-auction-btn"
                data-auction-id="{{ $auctionProduct->id }}">
            {{ translate('Delete Auction') }}
        </button>
        <a href="{{ route('auction.auction-recreate-product', $auctionProduct->id) }}"
           class="btn btn-primary {{ $btnSizeClass }}">
            {{ $recreateLabel }}
        </a>
    @elseif($mode === 'cancel-edit')
        @php
            $isUpcomingOrPending = $auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::UPCOMING
                || $auctionProduct?->approval_status === \Modules\Auction\app\Enums\ApprovalStatus::PENDING;
        @endphp
        @if($isUpcomingOrPending)
            <button type="button"
                    class="btn bg-danger bg-opacity-10 {{ $btnSizeClass }} fw-semibold text-danger js-delete-auction-btn"
                    data-auction-id="{{ $auctionProduct->id }}">
                {{ translate('Delete Auction') }}
            </button>
            <a href="{{ route('auction.auction-update-product', $auctionProduct->id) }}" class="btn btn-primary {{ $btnSizeClass }}">
                {{ translate('Edit Auction') }}
            </a>
        @else
            <button type="button"
                    class="btn bg-danger bg-opacity-10 {{ $btnSizeClass }} fw-semibold text-danger js-cancel-auction-btn"
                    data-auction-id="{{ $auctionProduct->id }}">
                {{ translate('Cancel Auction') }}
            </button>
        @endif
    @endif
</div>
