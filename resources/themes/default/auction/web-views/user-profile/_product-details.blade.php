@extends("auction.web-views.user-profile._profile-master")

@section('title', $auctionProduct['name'])

@section('profile_content')
    <span id="all-msg-container" data-afterextend="{{ translate('See Less') }}" data-seemore="{{ translate('See More') }}"></span>

    @if($isAuctionInactiveState)
        @include('auction.web-views.user-profile.partials._product-details._owner-inactive-state')
    @else
        <div>
            @if($isPurchaseComplete || $isMyAuctionClaimed)
                @include('auction.web-views.user-profile.partials._product-details._claimed-header')
            @endif
            <div class="bid-product-details__wrapper d-flex gap-3">
                {{-- Live-bidding body for all non-purchase_complete states. Both purchase_complete
                     perspectives ($isPurchaseComplete bidder, $isMyAuctionClaimed owner) fall through to the claimed body. --}}
                @if(!$isPurchaseComplete && !$isMyAuctionClaimed)
                    @include('auction.web-views.user-profile.partials._product-details._body-live')
                @else
                    @include('auction.web-views.user-profile.partials._product-details._body-claimed')
                @endif

                @if(!$isPurchaseComplete)
                    @if($isMyAuctionClaimed)
                        @include('auction.web-views.user-profile.partials._product-details._sidebar-owner-claimed')
                    @else
                        @include('auction.web-views.user-profile.partials._product-details._sidebar-non-claimed')
                    @endif
                @else
                    @include('auction.web-views.user-profile.partials._product-details._sidebar-purchase-complete')
                @endif
            </div>
        </div>
    @endif

    @if($auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::LIVE && !$isOwner)
        @if(getWebConfig(name: 'auction_entry_fee_amount_status') && getWebConfig(name: 'auction_entry_fee_amount_value') > 0)
            @include("auction.web-views.product._participate-entry-info", [
                'entry_fee_redirect' => route('auction.profile-view.product-details', ['slug' => $auctionProduct->slug])
            ])
        @endif

        @include("auction.web-views.product.details._place-bid-modal", ['auctionProduct' => $auctionProduct])
    @endif

    @include("auction.web-views.partials._customer-withdraw-offcanvas", [
        'customerWithdrawalMethods' => $customerWithdrawalMethods ?? collect(),
        'existingWithdraw'          => $customerWithdrawRequest ?? null,
        'withdrawAmount'            => isset($deliveredBreakdown) ? round($deliveredBreakdown->vendorReceivable, 2) : null,
    ])

    @if($isMyAuctionClaimed && $claimPaymentMethod === 'cash_on_delivery' && isset($deliveredBreakdown))
        @include("auction.web-views.partials._cod-commission-payment-modal", [
            'auctionProduct'      => $auctionProduct,
            'deliveredBreakdown'  => $deliveredBreakdown,
            'digital_payment'     => $digital_payment    ?? [],
            'payment_gateways_list' => $payment_gateways_list ?? [],
            'offline_payment'     => $offline_payment    ?? [],
            'offline_payment_methods' => $offline_payment_methods ?? collect(),
        ])
    @endif
@endsection

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/plyr/plyr.css') }}"/>
@endpush

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/libs/plyr/plyr.js') }}"></script>
    @include('auction.web-views.user-profile.partials._product-details._scripts')

    @if($isOwner)
        @include('auction.web-views.user-profile.partials._product-details._owner-action-forms')
    @endif
@endpush
