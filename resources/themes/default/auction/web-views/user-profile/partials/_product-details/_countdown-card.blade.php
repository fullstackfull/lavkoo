<div class="countdown-card countdown-card2 p-15px card border-0 shadow-sm rounded">
    <div class="text-center">
        <div class="fs-16 title-clr mb-10px">{{ $label }}</div>
        <span class="cz-countdown flash-deal-countdown bg-light rounded p-12px d-flex justify-content-center align-items-center gap-10px"
              data-countdown="{{ $timerEnd }}">
                <span class="cz-countdown-days d-flex align-items-end">
                    <span class="cz-countdown-value m-value d-center text-danger fw-bold fs-18 rounded"></span>
                    <span class="cz-countdown-text text-nowrap fs-16 fw-bold text-danger text-lowercase">{{ translate('d')}}</span>
                </span>
                <span class="cz-countdown-value fs-18 text-danger fw-bold">:</span>
                <span class="cz-countdown-hours d-flex align-items-end">
                    <span class="cz-countdown-value m-value d-center text-danger fw-bold fs-18 20 rounded"></span>
                    <span class="cz-countdown-text text-nowrap fs-16 fw-bold text-danger text-lowercase">{{ translate('h')}}</span>
                </span>
                <span class="cz-countdown-value fs-18 text-danger fw-bold">:</span>
                <span class="cz-countdown-minutes d-flex align-items-end">
                    <span class="cz-countdown-value m-value d-center text-danger fw-bold fs-18 rounded"></span>
                    <span class="cz-countdown-text text-nowrap fs-16 fw-bold text-danger text-lowercase">{{ translate('m')}}</span>
                </span>
                <span class="cz-countdown-value fs-18 text-danger fw-bold">:</span>
                <span class="cz-countdown-seconds d-flex align-items-end">
                    <span class="cz-countdown-value m-value d-center text-danger fw-bold fs-18 rounded"></span>
                    <span class="cz-countdown-text text-nowrap fs-16 fw-bold text-danger text-lowercase">{{ translate('s')}}</span>
                </span>
            </span>
    </div>
</div>
