<?php
    // Compact user card used for auction author, admin owner, and the Auction Claimer (winner).
    //   $chatMode controls the trailing chat button:
    //     'none'         → no button (default for the simple owner-claimed author card)
    //     'conditional'  → button shown only when $ownerType !== 'customer' and viewer ≠ owner
    //     'always'       → button always shown (used by the Auction Claimer card)
    //   $badgeImage      → optional URL displayed inline next to the name (winning badge etc.)
    $labelClass = $labelClass ?? 'title-clr';
    $cardClass  = $cardClass ?? 'border-0 shadow-sm';
    $ownerType  = $ownerType ?? null;
    $badgeImage = $badgeImage ?? null;
    $chatMode   = $chatMode ?? 'none';
?>
<div class="card {{ $cardClass }}">
    <div class="card-body">
        <div class="d-flex gap-3 align-items-center justify-content-between">
            <div class="d-flex gap-10px align-items-center">
                @if($ownerUrl)
                    <a href="{{ $ownerUrl }}" class="rounded w-50px h-50px min-w-50px overflow-hidden d-block rounded-circle flex-shrink-0">
                        <img src="{{ $ownerImage }}" alt="{{ $ownerName }}" class="w-100 h-100 object-cover">
                    </a>
                @else
                    <div class="rounded w-50px h-50px min-w-50px overflow-hidden{{ $badgeImage ? ' text-center' : '' }} rounded-circle{{ $badgeImage ? '' : ' flex-shrink-0' }}">
                        <img src="{{ $ownerImage }}" alt="{{ $ownerName }}" class="w-100 h-100 object-cover">
                    </div>
                @endif
                <div>
                    @if($ownerUrl)
                        <a href="{{ $ownerUrl }}" class="text-decoration-none">
                            <h6 class="mb-1 fs-14 text-capitalize fw-semibold title-clr line--limit-1">{{ $ownerName }}</h6>
                        </a>
                    @elseif($badgeImage)
                        <h6 class="mb-1 d-flex align-items-center gap-1 fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                            {{ $ownerName }}
                            <img width="16" height="16" src="{{ $badgeImage }}" alt="">
                        </h6>
                    @else
                        <h6 class="mb-1 fs-14 text-capitalize fw-semibold title-clr line--limit-1">{{ $ownerName }}</h6>
                    @endif
                    <p class="m-0 fs-13 {{ $labelClass }}">{{ $ownerLabel }}</p>
                </div>
            </div>
            @if($chatMode === 'always')
                <button type="button" class="btn btn-primary py-2 fs-12 d-flex align-items-center gap-2 px-3">
                    <i class="fi fi-rr-comment fs-14"></i>
                </button>
            @elseif($chatMode === 'conditional' && $ownerType !== 'customer' && auth('customer')->id() != $auctionProduct?->owner_id)
                @if(auth('customer')->check())
                    <button type="button"
                            class="btn btn-primary py-2 fs-12 d-flex align-items-center gap-2 px-3"
                            data-bs-toggle="modal" data-bs-target="#chatting_modal">
                        <i class="fi fi-rr-comment fs-14"></i>
                    </button>
                @else
                    <a href="{{ route('customer.auth.login') }}"
                       class="btn btn-primary py-2 fs-12 d-flex align-items-center gap-2 px-3">
                        <i class="fi fi-rr-comment fs-14"></i>
                    </a>
                @endif
            @endif
        </div>
    </div>
</div>
