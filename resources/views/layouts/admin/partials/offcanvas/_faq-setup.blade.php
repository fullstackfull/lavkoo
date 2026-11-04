@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('FAQs') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFaqSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('FAQ_Setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseFaqSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('to_add_a_new_frequently_asked_question_(faq)_to_the_list_in_order_to_fulfill_user_requirements,_please_follow_these_steps:') }}
                    </p>
                    <ul class="d-flex flex-column gap-2 fs-12">
                        <li>
                            {{ translate('enter_the_question.') }}
                        </li>
                        <li>
                            {{ translate('provide_a_concise_and_relevant_answer_within_the_specified_character_limit.') }}
                        </li>
                        <li>
                            {{ translate('set_the_priority_of_this_faq_by_assigning_a_value_between_1_(highest)_and_10_(lowest).') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFaqSetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('List_of_FAQ') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseFaqSetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('once_faqs_have_been_added,_they_will_be_displayed_in_the_list_of_faqs.') }}
                        {{ translate('this_section_allows_you_to_modify_the_status,_update_the_information_and_priority_of_each_faq._additionally,_you_can_delete_faqs_from_the_list_if_necessary.') }}
                    </p>

                </div>
            </div>
        </div>

    </div>
</div>
