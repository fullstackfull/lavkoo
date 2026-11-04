@extends('layouts.admin.app')

@section('title', translate('Setup_AI_Usage_Limit_For_Vendors'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('AI_Setup') }}
            </h2>
        </div>

        @include('ai::admin-views.ai-setting._ai-setup-menu')

        <div class="card card-body">
            <form action="{{ route('admin.third-party.ai-setting.vendors-usage-limits-update') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate>
                @csrf
                <div class="view-details-container">
                    <div>
                        <h3 class="mb-1">
                            {{ translate('Vendor_Limits_On_Using_AI') }}
                        </h3>
                        <p class="mb-0 fs-12">
                            {{ translate('Set_how_many_times_AI_can_generate_data_from_the_vendor_panel_or_app') }}
                        </p>
                    </div>

                    <div class="mt-3 mt-sm-4">
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <div class="form-group mb-3">
                                        <label class="form-label" for="">
                                            {{ translate('Limit_For_Section_Wise_Data_Generation') }}
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                  aria-label="{{ translate('set_the_maximum_number_of_AI_generated_content_requests_allowed_for_vendors.') }}"
                                                  data-bs-title="{{ translate('set_the_maximum_number_of_AI_generated_content_requests_allowed_for_vendors.') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <input type="number" id="generate_limit" name="generate_limit"
                                               class="form-control no-negative-symbol" min="0"
                                               value="{{ showDemoModeInputValue(value: $AiSetting->generate_limit ?? 0) }}"
                                               placeholder="{{ translate('Type_Data_Generate_Limit') }}">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group mb-3">
                                        <label class="form-label" for="">
                                            {{ translate('Limit_For_Image_Based_Data_Generation') }}
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                                  aria-label="{{ translate('define_how_many_images_can_be_uploaded_using_the_AI_system_for_vendors.') }}"
                                                  data-bs-title="{{ translate('define_how_many_images_can_be_uploaded_using_the_AI_system_for_vendors.') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <input type="number" id="image_upload_limit" name="image_upload_limit"
                                               class="form-control no-negative-symbol" min="0"
                                               value="{{ showDemoModeInputValue(value: $AiSetting->image_upload_limit ?? 0) }}"
                                               placeholder="{{ translate('Type_Image_Upload_Limit') }}">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                            <button type="reset" class="btn btn-secondary w-120 px-4">
                                {{ translate('reset') }}
                            </button>
                            <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                    class="btn btn-primary w-120 px-4 {{ getDemoModeFormButton(type: 'class') }}">
                                {{ translate('save') }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div
        class="d-flex gap-2 bg-white p-2 position-fixed inset-inline-end-0 pointer shadow view-guideline-btn flex-column align-items-center"
        data-bs-toggle="offcanvas" data-bs-target="#offcanvasSetupGuide">
        <span class="bg-primary py-1 px-2 text-white rounded fs-12"><i class="fi fi-rr-redo"></i></span>
        <span class="view-guideline-btn-text text-dark fw-medium pb-2 text-nowrap">
            {{ translate('View_Guideline') }}
        </span>
    </div>
    @include('ai::admin-views.ai-setting._ai-setup-view-guideline')

@endsection
