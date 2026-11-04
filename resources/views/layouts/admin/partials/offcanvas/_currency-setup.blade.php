@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Currency') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCurrencySetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('new_currency_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseCurrencySetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_provides_the_functionality_to_manage_the_list_of_available_currencies_and_to_add_new_currencies.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('adding_a_new_currency_requires_the_specification_of_its_name,_currency_symbol,_currency_code,_and_its_exchange_rate_relative_to_the_default_currency.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCurrencySetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('currency_list') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseCurrencySetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('the_currency_list_provides_functionalities_to_view_information_of_the_currencies,_make_a_currency_the_default,_and_update_the_requisite_information_for_each_currency.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCurrencySetup_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('switch_a_currency_as_the_default') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseCurrencySetup_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('to_make_a_different_currency_your_default,_you_need_to_update_the_exchange_rate_for_every_other_currency_in_your_list.') }}
                        {{ translate('this_is_because_exchange_rates_are_specific_to_each_currency_pairing.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
