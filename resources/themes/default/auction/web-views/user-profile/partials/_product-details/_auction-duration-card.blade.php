<?php
    $titleWeight = $titleWeight ?? 'bold';
    $labelMin    = $labelMin ?? 'minmax-sm-120px';
    $startDate   = $startDate ?? ($auctionProduct->start_time?->format('d M Y, h:i A') ?? translate('N/A'));
    $endDate     = $endDate   ?? ($auctionProduct->end_time?->format('d M Y, h:i A')   ?? translate('N/A'));
?>
<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-{{ $titleWeight }} title-clr {{ $titleWeight === 'semibold' ? 'mb-3' : 'mb-15' }}">{{ translate('Auction Duration') }}</div>
    <div class="d-flex flex-column gap-15px">
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="{{ $labelMin }} fs-14 title-semidark">{{ translate('Start Date') }}</div>
                <span class="{{ $titleWeight === 'semibold' ? '' : 'title-semidark' }}">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $startDate }}</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="{{ $labelMin }} fs-14 title-semidark">{{ translate('End Date') }}</div>
                <span class="{{ $titleWeight === 'semibold' ? '' : 'title-semidark' }}">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $endDate }}</h4>
            </div>
        </div>
    </div>
</div>
