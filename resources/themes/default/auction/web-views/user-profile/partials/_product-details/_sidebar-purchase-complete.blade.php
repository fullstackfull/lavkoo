{{-- Claimed (purchase_complete) right sidebar --}}
<div class="bids-product-details-right">
    <div class="d-flex flex-column gap-15px">

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex gap-3 align-items-center justify-content-between">
                    <h6 class="mb-0 fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                        {{ translate('Track Order') }}
                    </h6>
                    @if(!empty($auctionProduct->tracking_url))
                        <a href="{{ $auctionProduct->tracking_url }}"
                           target="_blank" rel="noopener noreferrer"
                           class="btn btn-outline-primary py-2 fs-12 px-3">
                            {{ translate('Track') }}
                        </a>
                    @else
                        <span tabindex="0" data-bs-toggle="tooltip" data-bs-title="{{ translate('Tracking URL not provided yet') }}">
                            <button type="button" disabled class="btn btn-outline-secondary py-2 fs-12 px-3" style="pointer-events:none;">
                                {{ translate('Track') }}
                            </button>
                        </span>
                    @endif
                </div>
            </div>
        </div>

        @if(filled($auctionProduct?->claim_note))
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="mb-2 fs-14 text-capitalize fw-semibold title-clr d-flex align-items-center gap-2">
                        <i class="fi fi-sr-comment-alt"></i>
                        {{ translate('Your Claim Note') }}
                    </h6>
                    <p class="mb-0 fs-14 title-semidark overflow-wrap-anywhere" style="white-space: pre-wrap;">{{ $auctionProduct->claim_note }}</p>
                </div>
            </div>
        @endif

        @if($claimPaymentMethod === 'offline_payment')
            <?php $offlineInfo = is_array($claimTransaction?->payment_info) ? $claimTransaction->payment_info : json_decode($claimTransaction?->payment_info ?? '{}', true); ?>
            @if(!empty($offlineInfo))
                <div class="p-15px card border-0 shadow-sm rounded">
                    <div class="fs-14 fw-semibold title-clr mb-3">{{ translate('Offline Payment Info') }}</div>
                    <div class="d-flex flex-column gap-15px">
                        @foreach($offlineInfo as $key => $item)
                            @if(isset($item) && $key !== 'method_id' && $key !== 'payment_note' && !is_array($item))
                                <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="minmax-xl-130px fs-14 title-semidark text-capitalize">{{ translate(str_replace('_', ' ', $key)) }}</div>
                                        <span class="title-semidark">:</span>
                                    </div>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $item }}</h4>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                        @if(!empty($offlineInfo['method_informations']))
                            @foreach($offlineInfo['method_informations'] as $fieldKey => $fieldValue)
                                <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="minmax-xl-130px fs-14 title-semidark text-capitalize">{{ translate(str_replace('_', ' ', $fieldKey)) }}</div>
                                        <span class="title-semidark">:</span>
                                    </div>
                                    <div class="info-option2">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $fieldValue }}</h4>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                        @if(!empty($offlineInfo['payment_note']))
                            <div class="d-flex align-items-start gap-2 justify-content-between w-100">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Payment Note') }}</div>
                                    <span class="title-semidark">:</span>
                                </div>
                                <div class="info-option2">
                                    <p class="fs-14 m-0 title-clr">
                                        @if(strlen($offlineInfo['payment_note']) > 250)
                                            {{ Str::limit($offlineInfo['payment_note'], 250) }}
                                        @else
                                            {{ $offlineInfo['payment_note'] }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @endif

        @include('auction.web-views.user-profile.partials._product-details._payment-info-card', ['titleWeight' => 'semibold'])

        @include('auction.web-views.user-profile.partials._product-details._billing-info-card', [
            'titleWeight'      => 'semibold',
            'showPaidByRow'    => true,
            'showDueRow'       => true,
            'showReturnPolicy' => false,
        ])

        @if(!empty($claimShippingInfo))
            @include('auction.web-views.user-profile.partials._product-details._address-card', [
                'title'        => translate('Shipping address'),
                'info'         => $claimShippingInfo,
                'titleWeight'  => 'semibold',
            ])
        @endif

        @if($claimIsBillingSame || !empty($claimBillingInfo))
            @include('auction.web-views.user-profile.partials._product-details._address-card', [
                'title'          => translate('Billing address'),
                'info'           => $claimBillingInfo ?? [],
                'isBillingSame'  => $claimIsBillingSame,
                'titleWeight'    => 'semibold',
            ])
        @endif

        <div class="fs-13 py-2 px-2 badge text-wrap text-start bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-5px">
            <i class="fi fi-sr-info text-warning"></i>
            {{ translate('Author will be responsible for this Auction product delivery') }}
        </div>

        @include('auction.web-views.user-profile.partials._product-details._author-card', [
            'ownerName'  => $claimOwnerName,
            'ownerImage' => $claimOwnerImage,
            'ownerUrl'   => $claimOwnerUrl,
            'ownerLabel' => $claimOwnerLabel,
            'ownerType'  => $claimOwnerType,
            'labelClass' => 'title-semidark',
            'chatMode'   => 'conditional',
        ])

        @if($claimOwnerType !== 'customer')
            @include('auction.web-views.product.details._chatting', [
                'seller'    => $auctionProduct->seller ?? null,
                'user_type' => $claimOwnerType === 'seller' ? 'seller' : 'admin',
            ])
        @endif

        @include('auction.web-views.user-profile.partials._product-details._pricing-info-card', [
            'showMinBid'   => false,
            'showShipping' => false,
        ])

        @include('auction.web-views.user-profile.partials._product-details._auction-timeline', [
            'titleWeight'  => 'semibold',
            'fallbackDate' => $claimedAt,
        ])

    </div>
</div>
