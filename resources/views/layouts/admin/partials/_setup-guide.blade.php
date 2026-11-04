<?php
$checkSetupGuideRequirements = checkSetupGuideRequirements(panel: 'admin');
$checkSetupGuideCheckedSteps = collect($checkSetupGuideRequirements['steps'])->filter(function ($stepItem) {
    return $stepItem['checked'];
})->toArray();
?>

@if($checkSetupGuideRequirements['completePercent'] < 100 && auth('admin')->user()->admin_role_id == 1)
    <div class="setup-guide">
        <div
            class="setup-guide__button d-flex gap-2 justify-content-between align-items-center bg-white p-3 position-relative rounded-3 pointer shadow"
            data-bs-toggle="modal" data-bs-target="#guideModal">
            <span class="position-absolute top-0 start-100 rtl_start-0 translate-middle badge rounded-pill bg-danger border border-3 border-primary">
                {{ $checkSetupGuideRequirements['totalSteps'] }}
            </span>
            <div class="d-flex gap-2 align-items-center fw-bold text-dark">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/setup_guide.png') }}" alt="">
                <span class="d-none d-lg-flex">{{ translate('Setup_Guide') }}</span>
            </div>
            <div class="border bg-light icon-btn rounded-circle text-dark d-none d-lg-flex justify-content-center align-items-center">
                <i class="fi fi-sr-angle-right mt-1 trans3"></i>
            </div>
        </div>
    </div>

    <div class="modal" id="guideModal" tabindex="-1" aria-labelledby="guideModal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-end" style="--bs-modal-width: 400px">
            <div class="modal-content">
                <div class="modal-header justify-content-between p-12 p-sm-20 bg-section border-0">
                    <div class="">
                        <h3>{{ translate('Setup_and_Start_your_Selling') }}</h3>
                        <p>{{ translate('Setup_and_start_managing_your_business_seamlessly') }}</p>
                    </div>

                    <div class="progress-pie-chart" data-percent="{{ $checkSetupGuideRequirements['completePercent'] }}">
                        <div class="ppc-progress">
                            <div class="ppc-progress-fill"></div>
                        </div>
                        <div class="ppc-percents">
                            <div class="pcc-percents-wrapper">
                                <span class="fs-12 fw-bold text-dark">%</span>
                            </div>
                        </div>
                    </div>

                    <button type="button" data-bs-dismiss="modal" aria-label="Close"
                            class="btn-close position-absolute start-100 rtl_end-100 d-none d-sm-block bg-white rounded-circle opacity-100 top-0 m-2 p-2">
                    </button>
                </div>
                <div class="modal-body">

                    @if(count($checkSetupGuideCheckedSteps) < 2)
                        @foreach($checkSetupGuideRequirements['steps'] as $checkSetupGuideStepKey => $checkSetupGuideStep)
                            @if(!$checkSetupGuideStep['checked'])
                                <div class="setup-floating-text px-2 position-absolute top-0 inset-inline-start-100 w-100 d-none d-md-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/icons/setup-guide-arrow.svg') }}" alt="">
                                    <h2 class="fs-28 text-white mb-0 ps-3">
                                        {{ translate('1st_setup_your') }} <br> {{ $checkSetupGuideStep['title'] }}
                                    </h2>
                                </div>
                                @break
                            @endif
                        @endforeach
                    @endif

                    <div class="d-flex flex-column gap-3 overflow-y-auto" style="max-height: 340px;">
                        @foreach($checkSetupGuideRequirements['steps'] as $checkSetupGuideStepKey => $checkSetupGuideStep)
                            <div class="p-12 p-sm-20 bg-section rounded">
                                <div class="d-flex align-items-center gap-3">
                                    <input class="form-check-input checkbox--input m-0 opacity-100" type="checkbox"
                                           value="{{ $checkSetupGuideStepKey }}"
                                        {{ $checkSetupGuideStep['checked'] ? 'checked' : '' }} disabled>
                                    <a href="{{ $checkSetupGuideStep['route'] }}?offcanvasShow=offcanvasSetupGuide"
                                       class="text-dark-emphasis">
                                        {{ $checkSetupGuideStep['title'] }}
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @foreach($checkSetupGuideRequirements['steps'] as $checkSetupGuideStepKey => $checkSetupGuideStep)
                        @if(!$checkSetupGuideStep['checked'])
                            <div data-bs-dismiss="modal" aria-label="Close">
                                <a class="btn btn-primary btn-sm position-absolute end-40 bottom-20"
                                    href="{{ $checkSetupGuideStep['route'] }}?offcanvasShow=offcanvasSetupGuide">
                                    {{ translate('Lets_Start') }}
                                    <i class="fi fi-rr-arrow-small-right"></i>
                                </a>
                            </div>
                            @break
                        @endif
                    @endforeach

                </div>
            </div>
        </div>
    </div>
@endif


@if($checkSetupGuideRequirements['completePercent'] < 100 && auth('admin')->user()->admin_role_id == 1)
    @if(count($checkSetupGuideCheckedSteps) < 2 && !(request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide'))
        @foreach($checkSetupGuideRequirements['steps'] as $checkSetupGuideStepKey => $checkSetupGuideStep)
            @if(!$checkSetupGuideStep['checked'])
                <script>
                    'use strict';
                    document.addEventListener("DOMContentLoaded", function() {
                        let guideModal = new bootstrap.Modal(document.getElementById('guideModal'));
                        if (guideModal) {
                            setTimeout(() => {
                                guideModal.show();
                            }, 500)
                        }
                    });
                </script>
                @break
            @endif
        @endforeach
    @endif
@endif
