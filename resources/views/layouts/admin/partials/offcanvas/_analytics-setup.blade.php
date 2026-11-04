@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Marketing_Tool') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAnalyticsSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Marketing_Tools') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseAnalyticsSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('marketing_tools_are_software,_platforms,_or_strategies_used_by_businesses_to_plan,_execute,_manage,_and_analyze_their_marketing_efforts') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('purpose_of_setting_up_marketing_tools_for_an_ecommerce_system') }}
                    </p>
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                             {{ translate('attracting_potential_customers_(acquisition)') }}
                        </li>
                        <li>
                             {{ translate('engaging_and_converting_visitors') }}
                        </li>
                        <li>
                             {{ translate('retaining_and_engaging_existing_customers') }}
                        </li>
                        <li>
                             {{ translate('analyzing_performance_and_optimizing_strategies') }}
                        </li>
                    </ul>
                    <p class="fs-12">
                        {{ translate('currently_in_the_system_-_google_analytics,_google_tag_manager,_linkedin_insight_tag,_meta_pixel,_pinterest_pixel,_snapchat_pixel,_tiktok_pixel,_x(twitter)_pixel_are_available_as_option_of_the_marketing_tools.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
