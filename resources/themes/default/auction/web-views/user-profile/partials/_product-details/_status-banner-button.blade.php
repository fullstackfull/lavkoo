<?php
    // Button-styled status banner used for bidder-side terminal states
    // (Auction Lost, Claim Expired, Oops! Time's Up, Auction is Expired).
    // Different from _status-note-card.blade.php which uses a centered div banner;
    // this one uses a full-width button-as-banner with optional subtitle.
    $variant = $variant ?? 'danger';
?>
<div class="p-15px card border-0 shadow-sm rounded">
    <div class="bg-{{ $variant }} bg-opacity-10 rounded w-100 text-center py-2 px-3 fs-16 fw-semibold text-{{ $variant }}">
        {{ $title }}
        @if(!empty($subtitle))
            <span class="title-semidark fs-14 fw-normal d-block mt-1">{{ $subtitle }}</span>
        @endif
    </div>
</div>
