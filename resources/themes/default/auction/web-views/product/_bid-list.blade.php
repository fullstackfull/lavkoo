@forelse($bids as $index => $bid)
    @php
        $badge  = $auctionProduct->bidBadgeState($bid);
        $isMyBid   = auth('customer')->check() && auth('customer')->id() == $bid->user_id;

        $nextBid   = $bids[$index + 1] ?? null;
        $isRaise   = $nextBid ? $bid->bid_amount >= $nextBid->bid_amount : true;

        $customerName  = $bid->customer?->name ?? translate('Unknown');
        $customerImage = getStorageImages(path: $bid->customer?->image_full_url, type:'avatar');

        if ($badge === 'withdrawn')      $rowClass = 'bid-withdrawn-active';
        elseif ($badge === 'leading')    $rowClass = 'leading-big-active';
        elseif ($badge === 'winner')     $rowClass = 'bid-winner-active';
        elseif ($badge === 'claim_expired') $rowClass = 'bid-withdrawn-active';
        else                             $rowClass = '';
    @endphp

    <div class="{{ $rowClass }} d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
        <div class="d-flex align-items-center gap-20px max-w-230px">
            <div class="serial w-40px h-40px min-w-40px d-center">
                {{ $index + 1 }}.
            </div>
            <div class="d-flex align-items-center gap-10px">
                <div class="w-50px h-50px rounded-circle d-block border overflow-hidden flex-shrink-0">
                    <img src="{{ $customerImage ?? $placeholder }}"
                         alt="{{ $customerName }}"
                         class="w-100 h-100 object-cover rounded-circle">
                </div>
                <div>
                    <h6 class="mb-1">
                        <span class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                            {{ $customerName }}
                        </span>
                    </h6>
                    <p class="fs-12 title-semidark m-0">
                        {{ $bid->bid_time?->diffForHumans() ?? '' }}
                    </p>
                </div>
            </div>
        </div>
        <div>
            <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                {{ webCurrencyConverter(amount: $bid->bid_amount) }}
                @if($isRaise)
                    <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                @else
                    <i class="fi fi-sr-arrow-small-down text-danger fs-16"></i>
                @endif
            </h6>
            @if($badge === 'withdrawn')
                <div class="mt-2 d-flex align-items-center gap-1 text-nowrap fs-12 fw-bold text-danger bg-white rounded-pill py-1 px-10">
                    <i class="fi fi-sr-ban fs-12"></i>
                    {{ translate('Withdrawn') }}
                </div>
            @elseif($badge === 'leading')
                <div class="mt-2 d-flex align-items-center gap-1 text-nowrap fs-12 fw-bold text-success bg-white rounded-pill py-1 px-10">
                    <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/leading-badge.svg') }}" alt="">
                    {{ translate('Leading Bid') }}
                </div>
            @elseif($badge === 'winner')
                <div class="mt-2 d-flex align-items-center gap-1 text-nowrap fs-12 fw-bold text-warning bg-white rounded-pill py-1 px-10">
                    <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/winning-badge.svg') }}" alt="">
                    {{ translate('Winner') }}
                </div>
            @elseif($badge === 'claim_expired')
                <div class="mt-2 d-flex align-items-center gap-1 text-nowrap fs-12 fw-bold text-danger bg-white rounded-pill py-1 px-10">
                    <i class="fi fi-sr-clock fs-12"></i>
                    {{ translate('Claim Expired') }}
                </div>
            @endif
        </div>
    </div>

    @if(!$loop->last)
        <div class="border-bottom"></div>
    @endif

@empty
    <div class="bg-light rounded text-center px-3 py-5 title-semidark fs-14">
        <img width="55" height="55" src="{{ dynamicAsset(path: 'public/assets/front-end/img/empty-icons/auction-pending-empty.svg') }}" alt="" class="mb-3"> <br>
        {{ translate('No bids placed yet.') }}
    </div>
@endforelse
