<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <div>
            <h3 class="mb-1">{{ translate('AI_Configuration_Guideline') }}</h3>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePurpose" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Purpose') }} </span>
                </button>
            </div>

            <div class="collapse mt-3 show" id="collapsePurpose">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('To_configure_your_preferred_AI_provider_(e.g.,_OpenAI)_by_entering_the_necessary_credentials_and_managing_usage_limits_for_AI_based_features_like_content_generation_or_image_processing') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAiFeatureToggle" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('AI_Feature_Toggle') }} </span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseAiFeatureToggle">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('Use_this_switch_to_turn_AI_features_on_or_off_for_your_platform.') }}
                    </p>
                    <ul class="fs-12">
                        <li>
                            {{ translate('When_ON') }}: {{ translate('AI_tools_like_content_and_image_generation_will_work.') }}
                        </li>
                        <li>
                            {{ translate('When_OFF') }}: {{ translate('all_AI_features_will_stop_working_until_you_turn_it_back_on.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAiFeatureEnableOpenAlConfigurationToggle" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Enable OpenAl Configuration') }} </span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseAiFeatureEnableOpenAlConfigurationToggle">
                <div class="card card-body">
                    <ul class="fs-12">
                        <li>
                            {{ translate('Go to the OpenAl API platform and') }}
                            <a target="_blank" href="{{ 'https://platform.openai.com/docs/overview' }}">{{ translate('Sign up') }}</a>
                            <span class="px-1">{{ translate('or') }}</span>
                            <a target="_blank" href="{{ 'https://platform.openai.com/docs/overview' }}">{{ translate('Log in.') }}</a>
                        </li>
                        <li>
                            {{ translate('Create a new API key and use it in the OpenAI API key section.') }}
                        </li>
                        <li>
                            {{ translate('Get your OpenAI Organization ID and enter it here for access and billing.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseVendorLimitsOnUsingAIToggle" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Vendor Limits On Using AI') }} </span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseVendorLimitsOnUsingAIToggle">
                <div class="card card-body">
                    <div class="mb-4">
                        <h6 class="fw-semibold fs-14">{{ translate('Limit_For_Section_Wise_Data_Generation') }}</h6>
                        <p class="fs-12">{{ translate('Set how many times AI can generate data for each element of the vendor panel or app') }}</p>
                    </div>
                    <div>
                        <h6 class="fw-semibold fs-14">{{ translate('Limit_For_Image_Based_Data_Generation') }}</h6>
                        <p class="fs-12">{{ translate('Set how many times AI can generate data from an image upload in vendor panel or vendor app') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseTip" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Tip') }} </span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseTip">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('you_need_to_enter_the_correct_api_details_and_limits_so_the_AI_tools_(like_text_or_image_generation)_can_work_without_errors.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>
