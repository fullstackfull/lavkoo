<?php
    // mode controls which info rows + actions render inside the product header card.
    //   'pending'  → pending approval (legacy alias): item condition / brand / category / return policy + Cancel + Edit
    //   'inactive' → unified pre-active layout (PENDING / REJECTED / CANCELED); action set driven by $inactiveActions
    //   'live'     → regular live/upcoming/etc.: shipping_fee + return_policy + status-driven owner actions
    //   'claimed'  → purchase_complete (bidder or owner-claimed): shipping_fee + return_policy, no actions
    $mode             = $mode ?? 'live';
    $inactiveActions  = $inactiveActions ?? null;
    $isInactiveMode   = $mode === 'pending' || $mode === 'inactive';
    $effectiveActions = $mode === 'pending' ? 'cancel-edit' : $inactiveActions;
?>
<div class="card border-0 shadow-sm p-xxl-20px p-3 mb-20">
    <div class="row g-3 {{ $isInactiveMode ? 'align-items-start' : 'flex-wrap' }}">
        <div class="col-sm-4">
            @include('auction.web-views.user-profile.partials._product-details._image-slider')
        </div>
        <div class="col-sm-8">
            <div class="{{ $isInactiveMode ? 'w-100' : '' }}">
                @if($mode !== 'claimed')
                <div class="fs-12 title-semidark fw-medium mb-1">
                    {{ translate('Auction') }} {{ translate('ID') .' #'. $auctionProduct['id'] }}
                </div>
                @endif
                <h2 class="mb-12px fs-16 line--limit-2 title-clr fw-semibold">
                    {{ $auctionProduct['name'] }}
                </h2>
    
                <div class="d-flex flex-column gap-2 auction-details-right-content">
                    @if($isInactiveMode)
                        @if(!empty($auctionProduct['item_condition']))
                            <div class="d-flex align-items-center gap-10px">
                                <div class="minmax-xs-100px fs-14 title-semidark">{{ translate('Item condition') }}</div>
                                <span>:</span>
                                <div class="info-option2">
                                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $auctionProduct['item_condition'] }}</h4>
                                </div>
                            </div>
                        @endif
    
                        @if(!empty($auctionProduct?->brand?->name))
                            <div class="d-flex align-items-center gap-10px">
                                <div class="minmax-xs-100px fs-14 title-semidark">{{ translate('Brand') }}</div>
                                <span>:</span>
                                <div class="info-option2">
                                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $auctionProduct?->brand?->name }}</h4>
                                </div>
                            </div>
                        @endif
    
                        @if(!empty($auctionProduct?->category?->name))
                            <div class="d-flex align-items-center gap-10px">
                                <div class="minmax-xs-100px fs-14 title-semidark">{{ translate('Category') }}</div>
                                <span>:</span>
                                <div class="info-option2">
                                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $auctionProduct?->category?->name }}</h4>
                                </div>
                            </div>
                        @endif
                    @else
                        @if($mode === 'claimed')
                            @if(!empty($auctionProduct['item_condition']))
                                <div class="d-flex align-items-center gap-10px">
                                    <div class="minmax-xs-100px fs-14 title-semidark">{{ translate('Item condition') }}</div>
                                    <span>:</span>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold text-capitalize">{{ str_replace('_', ' ', $auctionProduct['item_condition']) }}</h4>
                                    </div>
                                </div>
                            @endif
                            @if(!empty($auctionProduct?->category?->name))
                                <div class="d-flex align-items-center gap-10px">
                                    <div class="minmax-xs-100px fs-14 title-semidark">{{ translate('Category') }}</div>
                                    <span>:</span>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $auctionProduct?->category?->name }}</h4>
                                    </div>
                                </div>
                            @endif
                        @endif
                        @if($auctionProduct?->shipping_fee)
                            <div class="d-flex align-items-center gap-10px">
                                <div class="minmax-xs-100px fs-14 title-semidark">
                                    {{ translate('Shipping Fee') }}
                                </div>
                                <span>:</span>
                                <div class="info-option2">
                                    <h4 class="fs-14 m-0 title-clr fw-semibold">
                                        {{ webCurrencyConverter(amount: $auctionProduct?->shipping_fee) }}
                                    </h4>
                                </div>
                            </div>
                        @endif
                    @endif
    
                    @if(trim(strip_tags((string) ($auctionProduct?->return_policy ?? ''))) !== '')
                        <div class="d-flex align-items-{{ $isInactiveMode ? 'start' : 'start' }} gap-10px">
                            <div class="minmax-xs-100px fs-14 title-semidark">{{ translate('Return Policy') }}</div>
                            <span>:</span>
                            <div class="info-option2">
                                <h4 class="fs-14 m-0 title-clr fw-semibold line--limit-2 text-break" data-bs-toggle="tooltip" data-bs-title="{{ $auctionProduct?->return_policy }}">{{ $auctionProduct?->return_policy }}</h4>
                            </div>
                        </div>
                    @endif
                </div>
    
                @if($isInactiveMode && $effectiveActions)
                    @include('auction.web-views.user-profile.partials._product-details._owner-action-buttons', [
                        'mode' => $effectiveActions,
                        'size' => 'sm',
                    ])
                @endif

                @if($mode === 'live')
                    @if($isOwner && $auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::UNSOLD)
                        @include('auction.web-views.user-profile.partials._product-details._owner-action-buttons', ['mode' => 'delete-recreate', 'recreateLabel' => translate('Re Create Auction')])
                    @elseif($isOwner && $auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::UPCOMING)
                        @include('auction.web-views.user-profile.partials._product-details._owner-action-buttons', ['mode' => 'delete-edit'])
                    @elseif($isOwner && $auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::LIVE && ($auctionProduct->participants_count ?? 0) === 0)
                        @include('auction.web-views.user-profile.partials._product-details._owner-action-buttons', ['mode' => 'cancel-edit'])
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
