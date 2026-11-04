<?php
    $titleWeight    = $titleWeight ?? 'bold';
    $titleMargin    = $titleWeight === 'semibold' ? 'mb-3' : 'mb-15';
    $cardPadding    = $cardPadding ?? 'p-xxl-20px p-3';
    $fallbackLabel  = $fallbackLabel ?? translate('Auction Claimed At');
    $fallbackDate   = $fallbackDate ?? translate('N/A');
    $timelineEntries = ($auctionProduct?->auctionEditHistory ?? collect())->sortByDesc('created_at');
    $timelineLabels  = [
        \Modules\Auction\app\Models\AuctionEditHistory::TYPE_CREATE   => translate('Auction Created'),
        \Modules\Auction\app\Models\AuctionEditHistory::TYPE_UPDATE   => translate('Auction Updated'),
        \Modules\Auction\app\Models\AuctionEditHistory::TYPE_APPROVED => translate('Auction Approved'),
        \Modules\Auction\app\Models\AuctionEditHistory::TYPE_DENIED   => translate('Auction Denied'),
    ];
    $timelineIcons = [
        \Modules\Auction\app\Models\AuctionEditHistory::TYPE_CREATE   => 'fi-rr-plus-small text-primary',
        \Modules\Auction\app\Models\AuctionEditHistory::TYPE_UPDATE   => 'fi-rr-pencil text-warning',
        \Modules\Auction\app\Models\AuctionEditHistory::TYPE_APPROVED => 'fi-rr-check text-success',
        \Modules\Auction\app\Models\AuctionEditHistory::TYPE_DENIED   => 'fi-rr-cross text-danger',
    ];
?>
<div class="{{ $cardPadding }} card border-0 shadow-sm rounded">
    <div class="fs-14 fw-{{ $titleWeight }} title-clr {{ $titleMargin }}">{{ translate('Auction Timeline') }}</div>
    @if($timelineEntries->isNotEmpty())
        <div class="d-flex flex-column gap-3 w-100">
            @foreach($timelineEntries as $entry)
                <?php
                    $entryLabel  = $timelineLabels[$entry->type]  ?? translate(ucwords(str_replace('_', ' ', $entry->type)));
                    $entryIcon   = $timelineIcons[$entry->type]   ?? 'fi-rr-info text-secondary';
                    $entryDate   = $entry->created_at?->format('d M Y, h:i A') ?? translate('N/A');
                ?>
                <div>
                    <div class="fs-12 title-semidark mb-2">{{ $entryLabel }} {{ translate('at') }}</div>
                    <div class="d-flex justify-content-start">
                        <div class="fs-14 title-clr fw-semibold ltr">{{ $entryDate }}</div>
                    </div>
                </div>
                @if(!$loop->last)
                @endif
            @endforeach
        </div>
    @else
        <div class="d-flex flex-column gap-1 align-items-start w-100">
            <span class="fs-14 fw-normal title-semidark">{{ $fallbackLabel }}</span>
            <div class="d-flex justify-content-start">
                <h4 class="fs-14 m-0 title-clr fw-semibold ltr">{{ $fallbackDate }}</h4>
            </div>
        </div>
    @endif
</div>
