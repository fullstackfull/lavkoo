<?php
    // Shared "Detail Description" tab body + "See More" toggle button.
    //   $centerButton → adds mx-auto to the See More button (used by the live body's
    //                   product-description tab; original pending and claimed variants don't center it).
    $centerButton = $centerButton ?? false;
?>
<div class="details-content-wrap show-more--content d-flex flex-column gap-15px overflow-hidden active">
    @if($auctionProduct['details'])
        <div>
            <h4 class="fs-16 fw-semibold mb-12px">
                {{ translate('Detail Description') }}
            </h4>
            <div class="fs-14 pragraph-clr m-0 p-details-description" style="max-height: 280px">
                {!! $auctionProduct['details'] !!}
            </div>
        </div>
    @endif
</div>
<div class="text-center mt-3">
    <button type="button" class="product-details-see-more see-more-details btn px-3 py-2 btn-outline-primary{{ $centerButton ? ' mx-auto' : '' }}">
        {{ translate('See More') }}
    </button>
</div>
