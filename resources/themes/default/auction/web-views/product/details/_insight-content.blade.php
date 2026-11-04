<div class="d-flex flex-wrap gap-xxl-20px gap-3">
    <div class="bg-white rounded-2 px-10 py-2 lh-sm d-flex align-items-center gap-1">
        <span class="fs-16 title-semidark text-capitalize">
            {{ translate('Total Bids') }}
        </span>
        <span class="fs-16 fw-semibold title-clr text-capitalize">
            {{ $bidAnalytics['active_bids_count'] }}
        </span>
    </div>
    @if($bidAnalytics['avg_increase'] > 0)
        <div class="bg-white rounded-2 px-10 py-2 lh-sm d-flex align-items-center gap-1">
            <span class="fs-16 title-semidark text-capitalize">
                {{ translate('Avg Bid Increase') }}
            </span>
            <span class="fs-16 fw-semibold title-clr text-capitalize">
                {{ webCurrencyConverter(amount: $bidAnalytics['avg_increase']) }}
            </span>
        </div>
    @endif
    @if($bidAnalytics['highest_jump'] > 0)
        <div class="bg-white rounded-2 px-10 py-2 lh-sm d-flex align-items-center gap-1">
            <span class="fs-16 title-semidark text-capitalize">
                {{ translate('Highest Jump') }}
            </span>
            <span class="fs-16 fw-semibold title-clr text-capitalize">
                +{{ webCurrencyConverter(amount: $bidAnalytics['highest_jump']) }}
            </span>
        </div>
    @endif
    @if(auth('customer')->check() && $bidAnalytics['my_rank'] !== null)
        <div class="bg-white rounded-2 px-10 py-2 lh-sm d-flex align-items-center gap-1">
            <span class="fs-16 title-semidark text-capitalize">{{ translate('Your Rank') }}</span>
            <span class="d-flex align-items-center gap-1 fs-16 fw-semibold title-clr text-capitalize">
                {{ $bidAnalytics['my_rank'] }} {{ translate('of') }} {{ $bidAnalytics['active_bids_count'] }}
                <span data-bs-toggle="tooltip"
                      data-bs-placement="right"
                      data-bs-title="{{ translate('You are currently ranked') }} {{ $bidAnalytics['my_rank'] }} {{ translate('among') }} {{ $bidAnalytics['active_bids_count'] }} {{ $bidAnalytics['my_rank'] === 1 ? translate('bidders') : translate('bidders. Place a higher bid to improve your position.') }}">
                    <i class="fi fi-sr-info text-light-gray fs-14"></i>
                </span>
            </span>
        </div>
    @endif
    @if($auctionProduct?->end_time)
        <div class="lh-sm d-flex align-items-center gap-1">
            <span class="fs-16 title-semidark text-capitalize">
                {{ translate('Auction Expired at') }}
                <strong class="title-clr">{{ $auctionProduct->end_time->format('d M Y, h:i A') }}</strong>
            </span>
        </div>
    @endif
    @if(!empty($claimEndTime))
        <div class="lh-sm d-flex align-items-center gap-1">
            <span class="fs-16 title-semidark text-capitalize">
                {{ translate('Order Placement Closed at') }}
                <strong class="title-clr">{{ \Carbon\Carbon::parse($claimEndTime)->format('d M Y, h:i A') }}</strong>
            </span>
        </div>
    @endif
</div>
