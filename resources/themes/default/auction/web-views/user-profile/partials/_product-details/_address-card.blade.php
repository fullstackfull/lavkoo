<?php
    $isBillingSame = $isBillingSame ?? false;
    $info          = $info ?? [];
    $titleWeight   = $titleWeight ?? 'bold';
    $titleMargin   = $titleWeight === 'semibold' ? 'mb-3' : 'mb-15';
?>
<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-{{ $titleWeight }} title-clr {{ $titleMargin }}">{{ $title }}</div>
    @if($isBillingSame)
        <div class="bg-light rounded text-center fs-14 title-semidark p-12px">
            {{ translate('Same as shipping address') }}
        </div>
    @else
        <div class="d-flex flex-column gap-15px">
            @if(!empty($info['contact_person_name']))
                <div class="d-flex align-items-center gap-2 w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="minmax-xs-60px fs-14 title-semidark">{{ translate('Name') }}</div>
                        <span class="title-semidark">:</span>
                    </div>
                    <div class="info-option2">
                        <h4 class="fs-14 m-0 title-clr fw-normal">{{ $info['contact_person_name'] }}</h4>
                    </div>
                </div>
            @endif
            @if(!empty($info['phone']))
                <div class="d-flex align-items-center gap-2 w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="minmax-xs-60px fs-14 title-semidark">{{ translate('Phone') }}</div>
                        <span class="title-semidark">:</span>
                    </div>
                    <div class="info-option2">
                        <h4 class="fs-14 m-0 title-clr fw-normal">{{ $info['phone'] }}</h4>
                    </div>
                </div>
            @endif
            @if(!empty($info['city']) || !empty($info['zip']))
                <div class="d-flex align-items-center gap-2 w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="minmax-xs-60px fs-14 title-semidark">{{ translate('City / Zip') }}</div>
                        <span class="title-semidark">:</span>
                    </div>
                    <div class="info-option2">
                        <h4 class="fs-14 m-0 title-clr fw-normal">{{ implode(' ', array_filter([$info['city'] ?? '', $info['zip'] ?? ''])) }}</h4>
                    </div>
                </div>
            @endif
            @if(!empty($info['address']))
                <div class="d-flex align-items-start gap-2 w-100">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="minmax-xs-60px fs-14 title-semidark">{{ translate('Address') }}</div>
                        <span class="title-semidark">:</span>
                    </div>
                    <div class="info-option2">
                        <h4 class="fs-14 m-0 title-clr fw-normal">{{ $info['address'] }}</h4>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
