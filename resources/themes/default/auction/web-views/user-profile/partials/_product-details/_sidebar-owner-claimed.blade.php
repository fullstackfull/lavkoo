{{-- Owner (My Auctions) sidebar: my auction has been claimed/paid by the buyer (auction is in purchase_complete state). --}}
<div class="bids-product-details-right">
    <div class="d-flex flex-column gap-15px">

        <div class="p-15px card border-0 shadow-sm rounded">
            <div class="fs-14 fw-bold title-clr mb-15">{{ translate('Delivery Status') }}</div>
            <?php
                $deliveryStatuses = [
                    \Modules\Auction\app\Enums\DeliveryStatus::READY_TO_DELIVERY => translate('Ready_to_Delivery'),
                    \Modules\Auction\app\Enums\DeliveryStatus::ON_THE_WAY        => translate('On The Way'),
                    \Modules\Auction\app\Enums\DeliveryStatus::DELIVERED         => translate('Delivered'),
                ];
            ?>
            <select name="delivery_status"
                    class="form-select form-select-sm js-owner-delivery-status"
                    data-auction-product-id="{{ $auctionProduct->id }}"
                    data-update-url="{{ route('auction.delivery-status.update') }}"
                    data-current-status="{{ $claimDeliveryStatus ?: \Modules\Auction\app\Enums\AuctionStatus::PURCHASE_COMPLETE }}"
                    @disabled($claimDeliveryStatus === \Modules\Auction\app\Enums\DeliveryStatus::DELIVERED)>
                @if($claimDeliveryStatus === null)
                    <option value="{{ \Modules\Auction\app\Enums\AuctionStatus::PURCHASE_COMPLETE }}" selected disabled>
                        {{ translate('Purchase Complete') }}
                    </option>
                @endif
                @foreach($deliveryStatuses as $value => $label)
                    <option value="{{ $value }}" @selected($claimDeliveryStatus === $value)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="p-15px card border-0 shadow-sm rounded">
            <div class="fs-14 fw-bold title-clr mb-15">
                {{ translate('Payment Status') }}
            </div>
            <?php
                $paymentStatuses = [
                    \Modules\Auction\app\Enums\PaymentStatus::PAID => translate('Paid'),
                    \Modules\Auction\app\Enums\PaymentStatus::UNPAID => translate('Unpaid'),
                ];
            ?>
            <select name="payment_status"
                    class="form-select form-select-sm js-owner-payment-status"
                    data-auction-product-id="{{ $auctionProduct->id }}"
                    data-update-url="{{ route('auction.payment-status.update') }}"
                    data-current-status="{{ $claimPaymentStatus ?? \Modules\Auction\app\Enums\PaymentStatus::UNPAID }}"
                    @disabled($claimIsPaid || $claimPaymentMethod === 'offline_payment')>
                @foreach($paymentStatuses as $value => $label)
                    <option value="{{ $value }}" @selected($claimPaymentStatus === $value)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
            @if($claimPaymentMethod === 'offline_payment' && !$claimIsPaid)
                <p class="fs-12 text-muted mt-1 mb-0">
                    <i class="fi fi-sr-lock fs-10"></i>
                    {{ translate('Payment status for offline payments can only be updated by admin') }}
                </p>
            @endif
        </div>

        @include('auction.web-views.user-profile.partials._product-details._billing-info-card')

        @if($claimDeliveryStatus === \Modules\Auction\app\Enums\DeliveryStatus::DELIVERED && $deliveredBreakdown)
            @include('auction.web-views.user-profile.partials._product-details._commission-withdraw-panel')
        @endif

        @if(!empty($claimShippingInfo))
            @include('auction.web-views.user-profile.partials._product-details._address-card', [
                'title' => translate('Shipping address'),
                'info'  => $claimShippingInfo,
            ])
        @endif

        @if($claimIsBillingSame || !empty($claimBillingInfo))
            @include('auction.web-views.user-profile.partials._product-details._address-card', [
                'title'          => translate('Billing address'),
                'info'           => $claimBillingInfo ?? [],
                'isBillingSame'  => $claimIsBillingSame,
            ])
        @endif


        @if($auctionProduct->owner_type === \Modules\Auction\app\Enums\OwnerType::CUSTOMER
            && auth('customer')->check()
            && (int) $auctionProduct->owner_id === (int) auth('customer')->id())
            <form action="{{ route('auction.tracking-url.update', ['id' => $auctionProduct->id]) }}" method="POST" class="p-15px card border-0 shadow-sm rounded">
                @csrf
                <div class="fs-14 d-flex align-items-center gap-1 fw-bold title-clr mb-15">
                    {{ translate('Upload Tracking URL') }}
                    <span data-bs-toggle="tooltip" data-bs-title="{{ translate('Provide a tracking URL so the buyer can follow the shipment') }}">
                        <i class="fi fi-sr-info text-light-gray fs-12"></i>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-10px gap-15px">
                    <input type="url" name="tracking_url" class="form-control"
                           value="{{ old('tracking_url', $auctionProduct->tracking_url ?? '') }}"
                           placeholder="{{ translate('Ex : https://www.tracking.example/123') }}"
                           maxlength="2048">
                    <button type="submit" class="btn btn-primary fs-13 rounded px-3">
                        {{ translate('Save') }}
                    </button>
                </div>
            </form>
        @endif

        @if(!empty($auctionProduct->tracking_url))
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex gap-3 align-items-center justify-content-between">
                        <h6 class="mb-0 fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                            {{ translate('Track Order') }}
                        </h6>
                        <a href="{{ $auctionProduct->tracking_url }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary py-2 fs-12 px-3">
                            {{ translate('Track') }}
                        </a>
                    </div>
                </div>
            </div>
        @endif

        @include('auction.web-views.user-profile.partials._product-details._payment-info-card', ['titleWeight' => 'bold'])

        @include('auction.web-views.user-profile.partials._product-details._bidding-info-card')

        @include('auction.web-views.user-profile.partials._product-details._auction-duration-card')

        @include('auction.web-views.user-profile.partials._product-details._pricing-info-card', [
            'showMinBid'   => true,
            'minBidAmount' => $claimMinBidAmount,
            'taxMode'      => 'amount-only',
        ])

        @include('auction.web-views.user-profile.partials._product-details._author-card', [
            'cardClass'  => 'bs-border shadow-sm',
            'ownerName'  => $claimWinnerName,
            'ownerImage' => $claimWinnerImage,
            'ownerUrl'   => null,
            'ownerLabel' => translate('Auction Claimer'),
            'badgeImage' => dynamicAsset(path: 'public/assets/front-end/auction/images/icons/winning-badge.svg'),
            'chatMode'   => in_array($auctionProduct?->owner_type, ['admin', 'seller']) ? 'always' : 'none',
        ])

        @include('auction.web-views.user-profile.partials._product-details._author-card', [
            'cardClass'  => 'bs-border shadow-sm',
            'ownerName'  => $claimOwnerName,
            'ownerImage' => $claimOwnerImage,
            'ownerUrl'   => $claimOwnerUrl,
            'ownerLabel' => $claimOwnerLabel,
            'chatMode'   => 'none',
        ])

        @include('auction.web-views.user-profile.partials._product-details._auction-timeline', [
            'fallbackDate' => $claimedAt,
        ])

    </div>
</div>
