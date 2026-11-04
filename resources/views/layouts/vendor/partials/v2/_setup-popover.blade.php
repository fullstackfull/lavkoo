@php
    $v2SetupData = function_exists('checkSetupGuideRequirements') ? checkSetupGuideRequirements(panel: 'vendor') : ['steps' => [], 'completePercent' => 100, 'totalSteps' => 0];
    $v2SetupDone = ($v2SetupData['completePercent'] ?? 100) >= 100;
    $v2SetupShow = !$v2SetupDone;
    $v2FirstIncomplete = null;
    foreach ($v2SetupData['steps'] ?? [] as $_s) {
        if (empty($_s['checked'])) { $v2FirstIncomplete = $_s; break; }
    }
    $v2Percent = (int)($v2SetupData['completePercent'] ?? 0);
@endphp

@if ($v2SetupShow)
    <div class="v2-setup-backdrop" id="v2-setup-backdrop" hidden></div>

    <div class="v2-setup-popover" id="v2-setup-popover" role="dialog" aria-label="{{ translate('Setup_Guide') }}" hidden>

        <button type="button" class="v2-setup-popover-close" data-v2-setup-close aria-label="{{ translate('close') }}">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 5l10 10M15 5 5 15"/></svg>
        </button>

        <div class="v2-setup-popover-head">
            <div class="v2-setup-popover-title-wrap">
                <h3 class="v2-setup-popover-title">{{ translate('Setup_and_Start_your_Selling') }}</h3>
                <p class="v2-setup-popover-sub">{{ translate('Setup_and_start_managing_your_business_seamlessly') }}</p>
            </div>
            {{-- Same CSS pie chart used by the existing _setup-guide modal; --}}
            {{-- styles come from public/assets/backend/admin/css/style.css --}}
            <div class="progress-pie-chart" data-percent="{{ $v2Percent }}">
                <div class="ppc-progress">
                    <div class="ppc-progress-fill"></div>
                </div>
                <div class="ppc-percents">
                    <div class="pcc-percents-wrapper">
                        <span class="fs-12 fw-bold text-dark">%</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="v2-setup-popover-body">
            @foreach ($v2SetupData['steps'] ?? [] as $step)
                <a class="v2-setup-step {{ $step['checked'] ? 'is-complete' : '' }}"
                   href="{{ $step['route'] }}?offcanvasShow=offcanvasSetupGuide">
                    <span class="v2-setup-step-check" aria-hidden="true">
                        @if ($step['checked'])
                            <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m4 10 4 4 8-8"/></svg>
                        @endif
                    </span>
                    <span class="v2-setup-step-title">{{ $step['title'] }}</span>
                </a>
            @endforeach
        </div>

        @if ($v2FirstIncomplete)
            <div class="v2-setup-popover-foot">
                <a class="btn btn-primary btn-sm"
                   href="{{ $v2FirstIncomplete['route'] }}?offcanvasShow=offcanvasSetupGuide">
                    {{ translate('Lets_Start') }}
                    <i class="fi fi-rr-arrow-small-right"></i>
                </a>
            </div>
        @endif
    </div>
@endif
