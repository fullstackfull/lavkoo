@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('clean_database') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCeanDB_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('clean_database') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseCeanDB_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('this_section_shows_how_data_is_organized_across_different_parts_of_the_database.') }}
                         {{ translate('before_cleaning_any_data,_carefully_verify_its_dependencies,_as_it_could_affect_other_sections_due_to_the_sensitive_nature_of_this_information.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
