<?php
    use Modules\Auction\app\Enums\ApprovalStatus;
    use Modules\Auction\app\Enums\AuctionStatus;
    use Modules\Auction\app\Enums\ProductStatus;
    $timerEnd = $auctionProduct?->auction_current_status === 'upcoming'
        ? $auctionProduct?->start_time?->format('Y-m-d H:i:s')
        : $auctionProduct?->end_time?->format('Y-m-d H:i:s');
?>

<div class="bids-product-details-right">
    <div class="d-flex flex-column gap-15px">

        @if($isOwner && $auctionProduct?->approval_status == ApprovalStatus::APPROVED && in_array($auctionProduct?->auction_current_status, [AuctionStatus::UPCOMING, AuctionStatus::LIVE]))
            @include('auction.web-views.user-profile.partials._product-details._auction-status-select-card')
        @endif

        @if($auctionProduct?->auction_current_status == 'upcoming')
            @include('auction.web-views.user-profile.partials._product-details._countdown-card', [
                'label'    => translate('Auction Starts In'),
                'timerEnd' => $timerEnd,
            ])
        @elseif($auctionProduct?->auction_current_status == 'live')
            @include('auction.web-views.user-profile.partials._product-details._countdown-card', [
                'label'    => translate('Auction Ends In'),
                'timerEnd' => $timerEnd,
            ])
        @elseif($auctionProduct?->auction_current_status == 'unsold')
            <?php
                // Two distinct unsold cases for the owner perspective:
                //   • CASE 1 (no bids ever placed)         → existing "Auction is Expired" card.
                //   • CASE 2 (had bids, no winner claimed) → "Auction Ended – Item Not Sold"
                //      banner + Bidding Info + Pricing Info cards (same markup we already
                //      use in the owner-claimed sidebar). Non-owner viewers always see the
                //      simple "Auction is Expired" card to keep their UI unchanged.
                $isUnsoldWithBids = ($isOwner ?? false) && (int)($auctionProduct->total_bids ?? 0) > 0;
                $unsoldMinBidAmount = ($auctionProduct->starting_price ?? 0) + ($auctionProduct->minimum_increment_amount ?? 0);
            ?>
            @if($isUnsoldWithBids)
                @include('auction.web-views.user-profile.partials._product-details._status-note-card', [
                    'variant' => 'danger',
                    'title'   => translate('Auction Ended – Item Not Sold'),
                    'message' => translate('Update the details and relist to create the auction again.'),
                ])

                @include('auction.web-views.user-profile.partials._product-details._bidding-info-card')

                @include('auction.web-views.user-profile.partials._product-details._pricing-info-card', [
                    'showMinBid'   => true,
                    'minBidAmount' => $unsoldMinBidAmount,
                ])
            @else
                @include('auction.web-views.user-profile.partials._product-details._status-banner-button', [
                    'title' => translate('Auction is Expired'),
                ])
            @endif
        @elseif($ownerClaimExpired ?? false)
            <?php
                $claimExpiredAction = '<button type="button" class="btn bg-danger bg-opacity-10 w-100 fs-16 fw-semibold text-danger js-delete-auction-btn" data-auction-id="' . e($auctionProduct->id) . '">' . translate('Delete Auction') . '</button>';
            ?>
            @include('auction.web-views.user-profile.partials._product-details._status-note-card', [
                'variant'      => 'danger',
                'title'        => translate('Claim Period Expired'),
                'message'      => translate('The winner did not claim within the allowed time. You may delete this auction.'),
                'actionButton' => $claimExpiredAction,
            ])
        @else
            <?php
                $myAuctionStatus  = $auctionProduct?->my_auction_status;
                $isHighestBidder  = $auctionProduct->highestBid
                    && (int) $auctionProduct->highestBid->user_id === (int) auth('customer')->id();
            ?>
            @if($myAuctionStatus === \Modules\Auction\app\Enums\AuctionStatus::FINALIZING)
                @include('auction.web-views.user-profile.partials._product-details._status-note-card', [
                    'variant' => 'warning',
                    'title'   => translate('Auction ended — finalizing results'),
                    'message' => translate('The winner is being determined. Please check back shortly.'),
                ])
            @elseif($myAuctionStatus === 'claim_expired_lost' && $isHighestBidder)
                @include('auction.web-views.user-profile.partials._product-details._status-banner-button', [
                    'title'    => translate('Claim Expired'),
                    'subtitle' => translate('You won but did not claim within the allowed time.'),
                ])
            @elseif(in_array($myAuctionStatus, ['lost', 'claim_expired_lost']) && !in_array($myAuctionStatus, ['won']))
                @include('auction.web-views.user-profile.partials._product-details._status-banner-button', [
                    'title'    => translate('Auction Lost'),
                    'subtitle' => translate('You did not place the winning bid for this auction.'),
                ])
            @endif
        @endif
        {{-- "Claim Product" CTA: winning bidder only (owners don't claim their own auction;
             also hidden for bidders whose claim window expired). --}}
        @if($auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::READY_TO_CLAIM && !$isOwner && !$auctionProduct?->is_claim_expired_loser && $auctionProduct?->winner_user_id === auth('customer')->id())
            <div class="countdown-card p-15px card border-0 shadow-sm rounded">
                <div class="text-center">
                    @if(!empty($auctionProduct?->is_winner_rollover))
                        <div class="mb-20">
                            <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/congrt-bid.png') }}" alt="" class="w-70 h-70 rounded-circle mb-20 mx-auto border">
                            <h4 class="fs-20 fw-semibold text-success title-clr mb-10px">{{ translate('Great News!') }} 🎉</h4>
                            <p class="fs-14 title-semidark mb-0 text-center">
                                {{ translate('The previous winner missed the payment deadline. You are now the winning bidder! Complete your payment to claim your product.') }}
                            </p>
                        </div>
                    @else
                        <div class="mb-20">
                            <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/congrt-bid.png') }}" alt="" class="w-70 h-70 rounded-circle mb-20 mx-auto border">
                            <h4 class="fs-20 fw-semibold text-success title-clr mb-10px">{{ translate('Congratulations!') }} 🎉</h4>
                            <p class="fs-14 title-semidark mb-0 text-center">
                                {{ translate("You’re the winning bidder! Complete your payment to claim your product.") }}
                            </p>
                        </div>
                    @endif
                    <div class="light-box rounded p-xl-3 p-2">
                        @if(!empty($claimTimeLimitEnabled) && !empty($claimEndTime))
                            <span class="cz-countdown mb-10px flash-deal-countdown d-flex justify-content-center align-items-center gap-10px" data-countdown="{{ $claimEndTime }}">
                                <span class="cz-countdown-days">
                                    <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                                    <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('days')}}</span>
                                </span>
                                <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                                <span class="cz-countdown-hours">
                                    <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                                    <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('hours')}}</span>
                                </span>
                                <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                                <span class="cz-countdown-minutes">
                                    <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                                    <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('minutes')}}</span>
                                </span>
                                <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                                <span class="cz-countdown-seconds">
                                    <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                                    <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('seconds')}}</span>
                                </span>
                            </span>
                            <p class="fs-14 title-semidark mb-0 text-center">
                                {!! translate("Claim your product before time runs out. Click <strong>'Claim Product'</strong> to complete payment.") !!}
                            </p>
                        @else
                            <p class="fs-14 title-semidark mb-0 text-center">
                                {!! translate("No time limit to claim. Click <strong>'Claim Product'</strong> anytime to complete payment.") !!}
                            </p>
                        @endif
                    </div>
                    @if(!empty($isClaimEligible))
                        <div class="mt-20 max-w-180px w-100 mx-auto">
                            <a href="{{ route('auction.claim.checkout', ['auctionProductId' => $auctionProduct->id]) }}" class="btn btn-primary w-100">
                                {{ translate('Claim Product') }}
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- "Oops! Time's Up": bidder/customer only; owners can't miss the order time limit on their own auction. --}}
        @if(!$isOwner && !in_array($auctionProduct?->auction_current_status, [
            \Modules\Auction\app\Enums\AuctionStatus::READY_TO_CLAIM,
            \Modules\Auction\app\Enums\AuctionStatus::LIVE,
            \Modules\Auction\app\Enums\AuctionStatus::UPCOMING,
            \Modules\Auction\app\Enums\AuctionStatus::PURCHASE_COMPLETE,
            \Modules\Auction\app\Enums\AuctionStatus::READY_TO_DELIVERY,
            \Modules\Auction\app\Enums\AuctionStatus::ON_THE_WAY,
            \Modules\Auction\app\Enums\AuctionStatus::DELIVERED,
        ]) && !empty($auctionProduct?->winner_user_id) && $auctionProduct?->winner_user_id !== auth('customer')->id())
            @include('auction.web-views.user-profile.partials._product-details._status-banner-button', [
                'title'    => translate("Oops! Time’s Up"),
                'subtitle' => translate('You missed the time limit to place your order.'),
            ])
        @endif

        {{-- Auction Duration: owner → all states, customer → upcoming/live only --}}
        @if($isOwner || in_array($auctionProduct?->auction_current_status, [
            \Modules\Auction\app\Enums\AuctionStatus::UPCOMING,
            \Modules\Auction\app\Enums\AuctionStatus::LIVE,
        ]))
            @include('auction.web-views.user-profile.partials._product-details._auction-duration-card')
        @endif

        @if($auctionProduct?->winner_user_id !== auth('customer')->id() || $auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::READY_TO_CLAIM)
            @include(VIEW_FILE_NAMES['frontend_auction_profile_auction_info'], [
                'auctionProduct' => $auctionProduct,
                'isOwner' => $isOwner,
                'isParticipant' => $isParticipant,
                'raiseBidAmount' => $raiseBidAmount,
                'nextBidAmount' => $nextBidAmount,
            ])

            @include(VIEW_FILE_NAMES['frontend_auction_profile_product_info'], [
                'auctionProduct' => $auctionProduct,
            ])
        @endif

        @include('auction.web-views.user-profile.partials._product-details._offline-payment-card')

        <?php
            $ownerType  = $auctionProduct->owner_type;
            $ownerLabel = translate('Auction Author');
            $authorAuctionsUrl = route('auction.products.author', ['author_type' => $auctionProduct->owner_type, 'author_id' => $auctionProduct->owner_id]);
            if ($ownerType === 'seller' && isset($auctionProduct->seller->shop)) {
                $ownerName  = $auctionProduct->seller->shop->name;
                $ownerImage = getStorageImages(path: $auctionProduct->seller->shop->image_full_url, type: 'shop');
                $ownerUrl   = $authorAuctionsUrl;
            } elseif ($ownerType === 'admin') {
                $ownerName  = getInHouseShopConfig(key: 'name');
                $ownerImage = getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop');
                $ownerUrl   = $authorAuctionsUrl;
            } else {
                $ownerName  = $auctionProduct->customer?->name ?? translate('Unknown');
                $ownerImage = getStorageImages(path: $auctionProduct->customer?->image_full_url, type:'avatar');
                $ownerUrl   = null;
            }
        ?>

        @include('auction.web-views.user-profile.partials._product-details._author-card', [
            'ownerName'  => $ownerName,
            'ownerImage' => $ownerImage,
            'ownerUrl'   => $ownerUrl,
            'ownerLabel' => $ownerLabel,
            'ownerType'  => $ownerType,
            'labelClass' => 'title-semidark',
            'chatMode'   => 'conditional',
        ])

        @if($ownerType !== 'customer')
            @include('auction.web-views.product.details._chatting', [
                'seller'    => $auctionProduct->seller ?? null,
                'user_type' => $ownerType === 'seller' ? 'seller' : 'admin',
            ])
        @endif

        @include('auction.web-views.user-profile.partials._product-details._auction-timeline')
    </div>
</div>
