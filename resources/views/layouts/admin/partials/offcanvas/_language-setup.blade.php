@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Language') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseLangSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('setup_languages') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseLangSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_allows_you_to_manage_the_language_list_and_add_new_languages.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('to_add_a_new_language_to_the_list,_you_will_need_to_provide_its_name,_choose_the_country_code,_and_set_the_text_direction.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseLangSetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('language_list') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseLangSetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('the_language_list_provides_functionalities_to_view_language_translation_&_update_the_data_of_translation,_make_a_language_the_default,_and_update_the_requisite_information_for_each_language.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseLangSetup_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('view_translations') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseLangSetup_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('on_this_page,_you_can_see_the_translated_values_for_the_selected_language_based_on_the_current_default_language.') }}
                        {{ translate('you_can_edit_these_translations_directly_or_choose_to_auto-translate_them.') }}
                        {{ translate('to_automatically_translate_all_values_at_once,_click_translate_all,_and_the_translations_will_be_updated_shortly.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
