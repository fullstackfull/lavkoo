@if($currentStatus === 'live')
    <div class="countdown-card p-15px card bs-border shadow-sm rounded">
        <div class="text-center">
            <div class="fs-16 title-clr mb-20">{{ translate('Auction Ends In') }}</div>
            <span class="cz-countdown mb-20 flash-deal-countdown d-flex justify-content-center align-items-center gap-10px"
                  data-countdown="{{ $countdownTarget }}">
                    <span class="cz-countdown-days">
                        <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                        <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('days') }}</span>
                    </span>
                    <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                    <span class="cz-countdown-hours">
                        <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                        <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('hours') }}</span>
                    </span>
                    <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                    <span class="cz-countdown-minutes">
                        <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                        <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('minutes') }}</span>
                    </span>
                    <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                    <span class="cz-countdown-seconds">
                        <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                        <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('seconds') }}</span>
                    </span>
                </span>
            <div class="progress">
                <div class="progress-bar flash-deal-progress-bar auction-progress-bar" role="progressbar"
                     style="width: {{ $progressWidth }}%;"
                     aria-valuenow="{{ $progressWidth }}" aria-valuemin="0" aria-valuemax="100"
                     data-start="{{ $auctionProduct->start_time?->timestamp }}"
                     data-end="{{ $auctionProduct->end_time?->timestamp }}"></div>
            </div>
        </div>
    </div>
@elseif($currentStatus === 'upcoming')
    <div class="countdown-card p-15px card bs-border shadow-sm rounded">
        <div class="text-center">
            <div class="fs-16 title-clr mb-20">{{ translate('Auction Starts In') }}</div>
            <span class="cz-countdown mb-20 flash-deal-countdown d-flex justify-content-center align-items-center gap-10px"
                  data-countdown="{{ $countdownTarget }}">
                    <span class="cz-countdown-days">
                        <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                        <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('days') }}</span>
                    </span>
                    <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                    <span class="cz-countdown-hours">
                        <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                        <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('hours') }}</span>
                    </span>
                    <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                    <span class="cz-countdown-minutes">
                        <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                        <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('minutes') }}</span>
                    </span>
                    <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                    <span class="cz-countdown-seconds">
                        <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                        <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('seconds') }}</span>
                    </span>
                </span>
            <div class="progress">
                <div class="progress-bar flash-deal-progress-bar auction-progress-bar" role="progressbar"
                     style="width: 0%;"
                     aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
                     data-start="{{ $auctionProduct->created_at?->timestamp }}"
                     data-end="{{ $auctionProduct->start_time?->timestamp }}"></div>
            </div>
        </div>
    </div>
@elseif($currentStatus === 'unsold')
    <div class="p-15px card bs-border shadow-sm rounded">
        <div class="rounded p-3 text-center bg-danger bg-opacity-10">
            <h6 class="fs-15 fw-semibold text-danger mb-1">{{ translate('Auction Ended – Item Not Sold') }}</h6>
            @if(!empty($isOwner))
                <p class="fs-13 title-semidark m-0">{{ translate('update_the_details_and_relist_to_create_the_auction_again.') }}</p>
            @endif
        </div>
    </div>
@else
    @if($auctionProduct->isFinalizing() && !$auctionProduct->is_claim_expired_loser)
        <div class="p-15px card bs-border shadow-sm rounded">
            <div class="bg-warning bg-opacity-10 rounded p-3 text-center">
                <h6 class="fs-15 fw-semibold text-warning mb-1">{{ translate('Auction ended — finalizing results') }}</h6>
                <p class="fs-13 title-semidark m-0">{{ translate('The winner is being determined. Please check back shortly.') }}</p>
            </div>
        </div>
    @else
        @php
            $leadBidInfo = $auctionProduct?->lead_bid_info;
            $isClaimer = is_array($leadBidInfo)
                && auth('customer')->check()
                && (int) ($leadBidInfo['user_id'] ?? 0) === (int) auth('customer')->id();
            $claimExpiresAt = is_array($leadBidInfo) && !empty($leadBidInfo['claim_expires_at'])
                ? \Carbon\Carbon::parse($leadBidInfo['claim_expires_at'])
                : null;
        @endphp

        @if($isClaimer && $claimExpiresAt && $claimExpiresAt->isFuture())
            <div class="countdown-card p-15px card bs-border shadow-sm rounded">
                <div class="text-center">
                    <div class="fs-16 title-clr mb-20">{{ translate('Claim Expires In') }}</div>
                    <span class="cz-countdown mb-20 flash-deal-countdown d-flex justify-content-center align-items-center gap-10px"
                          data-countdown="{{ $claimExpiresAt->toIso8601String() }}">
                        <span class="cz-countdown-days">
                            <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                            <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('days') }}</span>
                        </span>
                        <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                        <span class="cz-countdown-hours">
                            <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                            <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('hours') }}</span>
                        </span>
                        <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                        <span class="cz-countdown-minutes">
                            <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                            <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('minutes') }}</span>
                        </span>
                        <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                        <span class="cz-countdown-seconds">
                            <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                            <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('seconds') }}</span>
                        </span>
                    </span>
                    <div class="progress">
                        <div class="progress-bar flash-deal-progress-bar auction-progress-bar" role="progressbar"
                             style="width: 0%;"
                             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
                             data-start="{{ $auctionProduct->end_time?->timestamp }}"
                             data-end="{{ $claimExpiresAt->timestamp }}"></div>
                    </div>
                </div>
            </div>
        @else
            <div class="p-15px card bs-border shadow-sm rounded">
                <button type="button" class="btn bg-danger bg-opacity-10 w-100 fs-16 fw-semibold text-danger">
                    {{ translate('Auction is Expired') }}
                </button>
            </div>
        @endif
    @endif
@endif
