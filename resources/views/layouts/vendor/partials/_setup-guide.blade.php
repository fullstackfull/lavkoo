<?php
$checkSetupGuideRequirements = checkSetupGuideRequirements(panel: 'vendor');
$checkSetupGuideCheckedSteps = collect($checkSetupGuideRequirements['steps'])->filter(function ($stepItem) {
    return $stepItem['checked'];
})->toArray();
?>

@if($checkSetupGuideRequirements['completePercent'] < 100)
    <div class="setup-guide">
        <div
            class="setup-guide__button d-flex gap-2 justify-content-between align-items-center bg-white p-3 position-relative radius-10 pointer shadow"
            data-toggle="modal" data-target="#guideModal">
            <span
                class="position-absolute top-0 start-100 rtl_start-0 translate-middle badge rounded-pill bg-danger border border-3 text-white border-primary icon-btn fs-12">
                {{ $checkSetupGuideRequirements['totalSteps'] }}
            </span>
            <div class="d-flex gap-2 align-items-center font-weight-bold text-dark">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/setup_guide.png') }}" alt="">
                <span class="d-none d-lg-flex">{{ translate('Setup_Guide') }}</span>
            </div>
            <div class="border bg-light icon-btn rounded-circle text-dark d-none d-lg-flex lh-1">
                <i class="fi fi-sr-angle-right trans3"></i>
            </div>
        </div>
    </div>

    <div class="modal fade" id="guideModal" tabindex="-1" aria-labelledby="guideModal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-end" style="max-width: 400px">
            <div class="modal-content">
                <div class="modal-header justify-content-between p-12 p-sm-20 bg-light rounded border-0 gap-3">
                    <div class="">
                        <h3>{{ translate('Setup_and_Start_your_Selling') }}</h3>
                        <p>{{ translate('Setup_and_start_managing_your_business_seamlessly') }}</p>
                    </div>

                    <div class="progress-pie-chart"
                         data-percent="{{ $checkSetupGuideRequirements['completePercent'] }}">
                        <div class="ppc-progress">
                            <div class="ppc-progress-fill"></div>
                        </div>
                        <div class="ppc-percents">
                            <div class="pcc-percents-wrapper">
                                <span class="fs-12 fw-bold text-dark">%</span>
                            </div>
                        </div>
                    </div>

                    <button type="button" data-dismiss="modal" aria-label="Close"
                            class="close position-absolute start-100 bg-white rounded-circle opacity-100 top-0 m-2 p-1">
                        <i class="fi fi-sr-cross-small position-relative top-01"></i>
                    </button>
                </div>

                <div class="modal-body">
                    @if(count($checkSetupGuideCheckedSteps) < 2)
                        @foreach($checkSetupGuideRequirements['steps'] as $checkSetupGuideStepKey => $checkSetupGuideStep)
                            @if(!$checkSetupGuideStep['checked'])
                                <div class="modal-instruction-content d-none d-lg-block position-absolute top-0">
                                    <img class="mb-3"
                                         src="{{ dynamicAsset(path: 'public/assets/back-end/img/svg/modal-arrow.svg') }}"
                                         alt="">
                                    <h3 class="fs-28 max-w-250 text-white ms-5 text-start">
                                        {{ translate('1st_setup_your') }} <br> {{ $checkSetupGuideStep['title'] }}
                                    </h3>
                                </div>
                                @break
                            @endif
                        @endforeach
                    @endif
                    <div class="d-flex flex-column gap-3 overflow-y-auto" style="max-height: 340px;">
                        @foreach($checkSetupGuideRequirements['steps'] as $checkSetupGuideStepKey => $checkSetupGuideStep)
                            <div class="p-12 p-sm-20 bg-light rounded">
                                <div class="d-flex align-items-center gap-3">
                                    <input type="checkbox" value="{{ $checkSetupGuideStepKey }}" class="form-check-input-disabled-checkbox"
                                           {{ $checkSetupGuideStep['checked'] ? 'checked' : '' }} disabled>
                                    <a href="{{ $checkSetupGuideStep['route'] }}?offcanvasShow=offcanvasSetupGuide"
                                       class="text-dark">
                                        {{ $checkSetupGuideStep['title'] }}
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @foreach($checkSetupGuideRequirements['steps'] as $checkSetupGuideStepKey => $checkSetupGuideStep)
                        @if(!$checkSetupGuideStep['checked'])
                            <div data-bs-dismiss="modal" aria-label="Close">
                                <a class="btn btn--primary btn-sm position-absolute end-40 bottom-20"
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
